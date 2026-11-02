<?php

namespace App\Services\Padel\Concerns;

use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\User;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ManagesCheckInAndTurnstile
{
    /**
     * Verifikasi Check-In di Frontdesk Kasir / Gate.
     * Dilengkapi toleransi double-scan ramah kasir (HTTP 200) dan alert serah-terima alat (Equipments Handover).
     *
     * @throws HttpException
     */
    public function checkIn(string $code, User $staffUser): array
    {
        $booking = PadelBooking::with(['court', 'user', 'equipments.equipment'])
            ->where(function ($q) use ($code) {
                $q->where('qr_code_hash', $code)
                    ->orWhere('booking_code', $code);
            })
            ->first();

        if (! $booking) {
            throw new HttpException(404, 'Tiket tidak ditemukan. Pastikan QR Code atau Kode Booking benar.');
        }

        $now = now();

        // Status tidak aktif dicek DULUAN: dulu scan tiket REFUNDED/CANCELLED setelah jam main ikut mengubahnya
        // jadi EXPIRED (riwayat refund/batal hilang dari laporan).
        if (in_array($booking->status, ['CANCELLED', 'EXPIRED', 'REFUNDED', 'REFUND_PENDING', 'COMPLETED'])) {
            throw new HttpException(400, "Tiket tidak aktif atau telah dibatalkan. Status saat ini: {$booking->status}.");
        }

        // Validasi jika sesi sudah lewat dan pemain belum pernah check-in -> Otomatis EXPIRED (hanya tiket lunas)
        if ($now->gt($booking->end_time) && $booking->checked_in_at === null) {
            if ($booking->status === 'PAID') {
                PadelBooking::whereKey($booking->id)->where('status', 'PAID')->whereNull('checked_in_at')->update(['status' => 'EXPIRED']);
                Cache::forget('kelola_pemesanan_tab_counts');
                app(RealtimeSignal::class)->bookingDates($booking->booking_date);
            }
            throw new HttpException(400, "Tiket kedaluwarsa (Expired). Sesi bermain pada pukul {$booking->start_time->format('H:i')} - {$booking->end_time->format('H:i')} WIB telah selesai.");
        }

        // Sesi tagihan coaching academy (Modul 31) boleh check-in walau belum lunas — dilunasi belakangan di kasir.
        $isCoachingTab = $booking->status === 'PENDING_PAYMENT' && \App\Services\Coaching\CoachingBillingService::isTabBooking($booking);
        if (($booking->status === 'PENDING' || $booking->status === 'PENDING_PAYMENT') && ! $isCoachingTab) {
            throw new HttpException(400, "Tiket belum lunas. Silakan selesaikan pembayaran terlebih dahulu.");
        }

        // LOCKED = keranjang yang belum dibayar, atau jadwal hasil reschedule yang tagihan selisihnya belum
        // lunas (QR sengaja ditahan). Dulu tetap bisa check-in lewat input kode booking — pemain main tanpa
        // melunasi selisih.
        if ($booking->status === 'LOCKED') {
            $unpaid = (float) ($this->pendingBillForBooking($booking)?->amount ?? 0);
            throw new HttpException(400, (int) $booking->reschedule_count > 0
                ? 'Masih ada tagihan selisih reschedule'.($unpaid > 0 ? ' Rp '.number_format($unpaid, 0, ',', '.') : '').' yang belum lunas. Lunasi dulu di POS Walk-In (klik slot "Bayar" di grid jadwal) sebelum check-in.'
                : 'Tiket belum dibayar. Silakan selesaikan pembayaran terlebih dahulu.');
        }

        // Ambil daftar peralatan sewa (flat per order atau per booking)
        // Alat order booking + tambahan alat yang dibeli terpisah untuk booking ini (Tambah Alat ke Booking).
        $equipmentList = [];
        foreach (\App\Services\Padel\BookingAddonService::equipmentRowsOf($booking)->with('equipment')->get() as $oe) {
            $equipmentList[] = [
                'name' => $oe->equipment ? $oe->equipment->name : 'Peralatan Padel',
                'quantity' => $oe->quantity,
            ];
        }

        $earliestCheckIn = $booking->start_time->copy()->subMinutes(45);

        // Validasi waktu awal check-in (-45 menit)
        if ($now->lt($earliestCheckIn)) {
            $earlyMinutes = (int) $now->diffInMinutes($booking->start_time);
            throw new HttpException(400, "Akses check-in baru dibuka 45 menit sebelum sesi dimulai. Jadwal main masih {$earlyMinutes} menit lagi (pukul {$booking->start_time->format('H:i')} WIB).");
        }

        // 🛡️ TOLERANSI DOUBLE-SCAN KASIR: Kembalikan 200 OK dengan alert kuning/informasi
        if ($booking->status === 'CHECKED_IN' || $booking->checked_in_at !== null) {
            $formattedTime = $booking->checked_in_at ? $booking->checked_in_at->format('H:i') : 'sebelumnya';
            return [
                'already_checked_in' => true,
                'message' => "Member atas nama {$booking->user->name} sudah melakukan check-in sebelumnya pada pukul {$formattedTime} WIB.",
                'booking_code' => $booking->booking_code,
                'player_name' => $booking->user->name,
                'court_name' => $booking->court->name,
                'schedule' => $booking->booking_date->format('d M Y') . ', ' . $booking->start_time->format('H:i') . ' - ' . $booking->end_time->format('H:i') . ' WIB',
                'checked_in_at' => $booking->checked_in_at ? $booking->checked_in_at->toISOString() : $now->toISOString(),
                'equipments' => $equipmentList,
                'gate_marshall' => $staffUser->name,
            ];
        }

        // Scan Pertama: Ubah status menjadi CHECKED_IN + potong stock alat SEWA (RACKET/TOWEL) pada
        // momen serah-terima fisik. Dibungkus 1 transaksi biar atomik — kalau potong stock gagal di
        // tengah jalan, perubahan status booking ikut ter-rollback juga.
        DB::transaction(function () use ($booking, $now, $staffUser, $equipmentList, $isCoachingTab) {
            // Update bersyarat: kalau di sela-sela booking ini baru saja di-reschedule (jadi LOCKED, tagihan
            // selisih belum lunas) atau dibatalkan, check-in gagal — bukan menimpa statusnya jadi CHECKED_IN.
            $updated = PadelBooking::whereKey($booking->id)
                ->where('status', $isCoachingTab ? 'PENDING_PAYMENT' : 'PAID')
                ->whereNull('checked_in_at')
                ->update(['status' => 'CHECKED_IN', 'checked_in_at' => $now]);
            if ($updated === 0) {
                throw new HttpException(409, 'Status tiket baru saja berubah (dipindah jadwal / dibatalkan). Scan ulang tiketnya.');
            }
            $booking->forceFill(['status' => 'CHECKED_IN', 'checked_in_at' => $now])->syncOriginal();
            app(RealtimeSignal::class)->bookingDates($booking->booking_date);

            $this->deductRentalEquipmentStock($booking);

            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: 'booking.checked_in',
                description: "Check-in booking {$booking->booking_code} atas nama ".($booking->user?->name ?? '-').' di '.($booking->court?->name ?? '-'),
                subject: $booking,
                meta: array_filter([
                    'kode_booking' => $booking->booking_code,
                    'pemain' => $booking->user?->name,
                    'lapangan' => $booking->court?->name,
                    'jadwal' => $booking->start_time->format('d M Y H:i').'-'.$booking->end_time->format('H:i'),
                    'alat_diserahkan' => array_map(fn ($e) => $e['name'].' x'.$e['quantity'], $equipmentList) ?: null,
                ]),
                causer: $staffUser,
            );
        });

        Cache::forget('kelola_pemesanan_tab_counts');

        return [
            'already_checked_in' => false,
            'message' => "Check-in berhasil! Akses lapangan dibuka. Silakan serahkan peralatan sewa kepada pemain.",
            'booking_code' => $booking->booking_code,
            'player_name' => $booking->user->name,
            'court_name' => $booking->court->name,
            'schedule' => $booking->booking_date->format('d M Y') . ', ' . $booking->start_time->format('H:i') . ' - ' . $booking->end_time->format('H:i') . ' WIB',
            'checked_in_at' => $now->toISOString(),
            'equipments' => $equipmentList,
            'gate_marshall' => $staffUser->name,
        ];
    }

    /**
     * Potong stock_quantity untuk alat SEWA (RACKET/TOWEL) milik booking ini saat serah-terima di check-in.
     * BALL tidak disentuh di sini karena sudah dipotong lebih awal saat checkout (consumable, "dibeli habis").
     * Idempotent: hanya memproses baris PadelBookingEquipment yang stock_deducted_at-nya masih NULL, jadi
     * aman dipanggil berkali-kali (mis. double-scan check-in) tanpa memotong stock dua kali.
     */
    protected function deductRentalEquipmentStock(PadelBooking $booking): void
    {
        $pendingItems = \App\Services\Padel\BookingAddonService::equipmentRowsOf($booking)->with('equipment')->whereNull('stock_deducted_at')->get();

        foreach ($pendingItems as $item) {
            if (! $item->equipment || ! in_array(strtoupper($item->equipment->type), ['RACKET', 'TOWEL'])) {
                continue;
            }

            // Lock row equipment SEBELUM baca/tulis stock_quantity — anti-race kalau ada beberapa
            // check-in yang butuh alat sama diproses hampir bersamaan di gate/kasir berbeda.
            $equipment = CourtEquipment::where('id', $item->equipment_id)->lockForUpdate()->first();
            if (! $equipment) {
                continue;
            }

            // Jangan sampai stock minus kalau kebetulan sisa stok sudah tidak cukup (mis. alat lain
            // hilang/rusak belum di-adjust admin) — kurangi maksimal sampai 0, jangan blokir check-in
            // customer yang sudah bayar hanya gara-gara selisih pembukuan stok alat.
            $deduction = min((int) $equipment->stock_quantity, (int) $item->quantity);
            if ($deduction > 0) {
                $equipment->decrement('stock_quantity', $deduction);
            }

            $item->update(['stock_deducted_at' => now()]);
        }
    }

    /**
     * Tandai alat SEWA (RACKET/TOWEL) milik booking ini sudah dikembalikan fisik ke frontdesk, dan
     * kembalikan (+qty) ke stock_quantity. BALL tidak pernah bisa diretur (consumable, sudah dibeli habis
     * sejak checkout) sehingga baris BALL otomatis dilewati di sini.
     * Idempotent: baris yang returned_at-nya sudah terisi dilewati, aman dipanggil berkali-kali.
     *
     * @return array{restored_count: int, items: array, booking_code: string}
     */
    public function returnEquipment(string $bookingId, User $staffUser): array
    {
        $booking = PadelBooking::findOrFail($bookingId);

        return DB::transaction(function () use ($booking, $staffUser) {
            $pendingReturns = \App\Services\Padel\BookingAddonService::equipmentRowsOf($booking)->with('equipment')
                ->whereNotNull('stock_deducted_at')
                ->whereNull('returned_at')
                ->get();

            $restoredItems = [];
            foreach ($pendingReturns as $item) {
                if (! $item->equipment || ! in_array(strtoupper($item->equipment->type), ['RACKET', 'TOWEL'])) {
                    continue;
                }

                $equipment = CourtEquipment::where('id', $item->equipment_id)->lockForUpdate()->first();
                if (! $equipment) {
                    continue;
                }

                $equipment->increment('stock_quantity', $item->quantity);
                $item->update(['returned_at' => now()]);

                $restoredItems[] = [
                    'name' => $equipment->name,
                    'quantity' => $item->quantity,
                ];
            }

            if (empty($restoredItems)) {
                throw new HttpException(422, 'Tidak ada alat sewa (raket/handuk) yang tertunda untuk dikembalikan pada tiket ini.');
            }

            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: 'equipment.returned',
                description: "Menerima pengembalian alat sewa booking {$booking->booking_code}: "
                    .collect($restoredItems)->map(fn ($i) => $i['name'].' x'.$i['quantity'])->implode(', '),
                subject: $booking,
                meta: ['kode_booking' => $booking->booking_code, 'alat_kembali' => $restoredItems],
                causer: $staffUser,
            );

            return [
                'restored_count' => count($restoredItems),
                'items' => $restoredItems,
                'booking_code' => $booking->booking_code,
            ];
        });
    }

    /**
     * Tandai sesi bermain selesai (Complete) saat pemain keluar lapangan / mengembalikan raket.
     */
    public function completeBooking(string $bookingId, User $staffUser): PadelBooking
    {
        $booking = PadelBooking::findOrFail($bookingId);
        $previousStatus = $booking->status;

        // Hanya pemain yang sudah check-in yang bisa "selesai". Dulu booking LOCKED (tagihan selisih belum lunas)
        // atau yang sudah batal/refund bisa ditandai COMPLETED — tagihannya yatim & laporan salah.
        $updated = PadelBooking::whereKey($booking->id)->where('status', 'CHECKED_IN')->update(['status' => 'COMPLETED']);
        if ($updated === 0) {
            throw new HttpException(422, "Booking {$booking->booking_code} belum check-in (status: {$previousStatus}), tidak bisa ditandai selesai.");
        }
        $booking->refresh();
        Cache::forget('kelola_pemesanan_tab_counts');
        app(RealtimeSignal::class)->bookingDates($booking->booking_date);

        \App\Services\Audit\ActivityLogger::record(
            module: 'PADEL',
            event: 'booking.completed',
            description: "Menandai booking {$booking->booking_code} selesai bermain",
            subject: $booking,
            changes: ['status' => ['old' => $previousStatus, 'new' => 'COMPLETED']],
            causer: $staffUser,
        );

        return $booking;
    }

    /**
     * Otomatis menyinkronkan status booking yang telah lewat jadwal bermain:
     * 1. Status PAID & end_time < now() & checked_in_at IS NULL -> EXPIRED (No-show / Tiket Hangus)
     * 2. Status CHECKED_IN & end_time < now() -> COMPLETED (Selesai Bermain)
     * 3. Rilis expired locks (> 10 menit)
     */
    public function syncExpiredAndCompletedBookings(): array
    {
        $now = now();

        // 1. Booking yang lunas atau reschedule berbayar tapi tidak datang sampai sesi berakhir -> EXPIRED (No-Show)
        // id diambil dulu (bukan langsung mass update) supaya kode booking-nya bisa dicatat di log aktivitas.
        $noShows = PadelBooking::whereIn('status', ['PAID', 'LOCKED'])
            ->where('end_time', '<', $now)
            ->whereNull('checked_in_at')
            ->where(function ($q) {
                $q->where('status', 'PAID')
                    ->orWhere('reschedule_count', '>', 0)
                    ->orWhereHas('order.payments', function ($pq) {
                        $pq->where('status', 'SUCCESS');
                    });
            })
            ->pluck('booking_code', 'id');
        $expiredCount = $noShows->isEmpty() ? 0 : PadelBooking::whereIn('id', $noShows->keys())
            ->whereIn('status', ['PAID', 'LOCKED'])
            ->whereNull('checked_in_at')
            ->update(['status' => 'EXPIRED']);

        // Tagihan selisih reschedule milik booking yang hangus ditutup (+ sesi Snap dibatalkan). Kalau customer
        // tetap membayar setelahnya, uangnya otomatis dibuatkan refund — bukan diam-diam jadi omzet.
        if ($expiredCount > 0) {
            foreach (PadelBooking::whereIn('id', $noShows->keys())->where('status', 'EXPIRED')->where('reschedule_count', '>', 0)->get() as $expired) {
                DB::transaction(fn () => $this->closeRescheduleBills($expired, 'BOOKING_EXPIRED_NO_SHOW'));
            }
        }

        // 2. Pemain yang sudah check-in dan sesinya telah lewat -> COMPLETED
        $finished = PadelBooking::where('status', 'CHECKED_IN')
            ->where('end_time', '<', $now)
            ->pluck('booking_code', 'id');
        $completedCount = $finished->isEmpty() ? 0 : PadelBooking::whereIn('id', $finished->keys())
            ->where('status', 'CHECKED_IN')
            ->update(['status' => 'COMPLETED']);

        if ($expiredCount > 0) {
            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: 'booking.no_show_expired',
                description: "Sistem menandai {$expiredCount} booking lunas sebagai hangus (tidak datang sampai sesi berakhir)",
                meta: ['kode_booking' => $noShows->values()->take(100)->all()],
                asSystem: true,
            );
        }

        if ($completedCount > 0) {
            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: 'booking.auto_completed',
                description: "Sistem menandai {$completedCount} booking yang sudah check-in sebagai selesai bermain",
                meta: ['kode_booking' => $finished->values()->take(100)->all()],
                asSystem: true,
            );
        }

        // 3. Rilis slot LOCKED yang kedaluwarsa
        $releasedLocks = $this->releaseExpiredLocks();

        if ($expiredCount > 0 || $completedCount > 0 || $releasedLocks > 0) {
            Cache::forget('kelola_pemesanan_tab_counts');
        }
        // Update massal di atas tidak lewat observer model → layar jadwal diberi tahu manual.
        if ($expiredCount > 0 || $completedCount > 0) {
            app(RealtimeSignal::class)->bookings($noShows->keys()->merge($finished->keys()));
        }

        return [
            'expired' => $expiredCount,
            'completed' => $completedCount,
            'released_locks' => $releasedLocks,
        ];
    }
}
