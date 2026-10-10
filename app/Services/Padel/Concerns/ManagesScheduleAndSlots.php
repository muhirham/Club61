<?php

namespace App\Services\Padel\Concerns;

use App\Exceptions\SlotConflictException;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ManagesScheduleAndSlots
{
    /**
     * Mengambil matriks ketersediaan seluruh lapangan (06:00 - 23:00) secara timezone-aware.
     * Privasi terproteksi penuh (identitas pemesan di-masking).
     */
    public function getScheduleMatrix(string $date, string $timezone = 'Asia/Jakarta'): array
    {
        // Sinkronkan booking kedaluwarsa & rilis lock yang hangus secara otomatis
        $this->syncExpiredAndCompletedBookings();

        $parsedDate = Carbon::parse($date, $timezone);
        $dateStr = $parsedDate->format('Y-m-d');
        $peakHours = app(\App\Services\Padel\PeakHourService::class);

        // Aturan "jam lewat" SAMA dengan POS Walk-In (BookOfflineCourt): jam sebelum jam berjalan
        // hari ini = PAST, jam yang sedang berjalan masih boleh dipesan. Tanggal lampau = semua PAST.
        $nowLocal = Carbon::now($timezone);
        $todayStr = $nowLocal->format('Y-m-d');
        $currentHour = (int) $nowLocal->format('H');

        $courts = PadelCourt::where('is_active', true)->orderBy('name')->get();

        // Semua booking yang masih aktif setelah GC di atas berjalan. JANGAN disaring pakai umur
        // created_at: booking hasil reschedule dengan tagihan selisih (LOCKED, reschedule_count > 0)
        // dan pembayaran yang masih ditunggu Midtrans sengaja dibiarkan hidup oleh GC — kalau
        // disaring umur, slotnya tampil kosong & bisa dipesan orang lain (double booking).
        // Aturan ini sama dengan grid POS Walk-In & Monitoring Lapangan.
        $activeBookings = PadelBooking::whereDate('booking_date', $dateStr)
            ->whereIn('status', self::activeSlotStatuses())
            ->get();

        $minOpenHour = 6;
        $maxCloseHour = 23;

        if ($courts->isNotEmpty()) {
            $minOpenHour = $courts->min(function ($c) {
                return (int) substr($c->open_time ?: '06:00', 0, 2);
            }) ?? 6;

            $maxCloseHour = $courts->max(function ($c) {
                $val = $c->close_time ?: '23:00';
                return ($val === '00:00' || $val === '24:00') ? 24 : (int) substr($val, 0, 2);
            }) ?? 23;

            $minOpenHour = max(0, min($minOpenHour, 23));
            $maxCloseHour = max($minOpenHour + 1, min($maxCloseHour, 24));
        }

        // Bulk prefetch Distributed Cache Locks untuk seluruh lapangan & jam dalam 1 query
        $allMatrixKeys = [];
        foreach ($courts as $c) {
            for ($h = $minOpenHour; $h < $maxCloseHour; $h++) {
                $allMatrixKeys[] = "padel_lock:{$c->id}:{$dateStr}:" . sprintf('%02d00', $h);
            }
        }
        $bulkMatrixLocks = Cache::many($allMatrixKeys);

        $resultCourts = [];

        foreach ($courts as $court) {
            $slots = [];
            $courtOpen = (int) substr($court->open_time ?: '06:00', 0, 2);
            $courtCloseVal = $court->close_time ?: '23:00';
            $courtClose = ($courtCloseVal === '00:00' || $courtCloseVal === '24:00') ? 24 : (int) substr($courtCloseVal, 0, 2);

            // Jam operasional dinamis: minOpenHour sampai maxCloseHour
            for ($hour = $minOpenHour; $hour < $maxCloseHour; $hour++) {
                $startHourStr = sprintf('%02d:00', $hour);
                $endHourStr = sprintf('%02d:00', $hour + 1);

                $slotStart = Carbon::parse("{$dateStr} {$startHourStr}", $timezone);
                $slotEnd = Carbon::parse("{$dateStr} {$endHourStr}", $timezone);

                $isOpenForCourt = ($hour >= $courtOpen && $hour < $courtClose);

                // Cek apakah slot ini tabrakan dengan booking aktif menggunakan rumus batas terbuka ketat (< dan >)
                $collidingBooking = $isOpenForCourt ? $activeBookings->first(function ($booking) use ($court, $slotStart, $slotEnd) {
                    if ($booking->court_id !== $court->id) {
                        return false;
                    }
                    $bStart = $booking->start_time->format('Y-m-d H:i:s');
                    $bEnd = $booking->end_time->format('Y-m-d H:i:s');
                    $sStart = $slotStart->format('Y-m-d H:i:s');
                    $sEnd = $slotEnd->format('Y-m-d H:i:s');

                    return $bStart < $sEnd && $bEnd > $sStart;
                }) : null;

                // Cek juga Distributed Cache Lock (Tier 1) via bulk prefetch
                $cacheLockKey = "padel_lock:{$court->id}:{$dateStr}:" . $slotStart->format('Hi');
                $isCacheLocked = $isOpenForCourt && ! empty($bulkMatrixLocks[$cacheLockKey]);

                if (! $isOpenForCourt) {
                    $status = 'CLOSED';
                } elseif ($dateStr < $todayStr || ($dateStr === $todayStr && $hour < $currentHour)) {
                    $status = 'PAST';
                } elseif ($collidingBooking) {
                    $status = in_array($collidingBooking->status, ['LOCKED', 'PENDING_PAYMENT', 'PENDING']) ? 'LOCKED' : 'BOOKED';
                } elseif ($isCacheLocked) {
                    $status = 'LOCKED';
                } else {
                    $status = 'AVAILABLE';
                }

                $isPrime = $peakHours->isPeak($slotStart); // jam peak diatur di Master Data
                $price = $isPrime ? (float)$court->hourly_rate_prime : (float)$court->hourly_rate_regular;
                $originalPrice = $isPrime ? (float)$court->hourly_rate_prime * 1.25 : (float)$court->hourly_rate_regular * 1.5;

                $slots[] = [
                    'time' => "{$startHourStr} - {$endHourStr}",
                    'start_time' => $slotStart->toISOString(),
                    'end_time' => $slotEnd->toISOString(),
                    'local_start' => $startHourStr,
                    'local_end' => $endHourStr,
                    'status' => $status,
                    'is_prime_time' => $isPrime,
                    'original_price' => round($originalPrice),
                    'price' => round($price),
                ];
            }

            $resultCourts[] = [
                'court_id' => $court->id,
                'court_name' => $court->name,
                'type' => $court->type,
                'description' => $court->description ?: ($court->type === 'INDOOR' ? 'Indoor • Central AC' : 'Outdoor • Open Air Court'),
                'open_time' => $court->open_time ?: '06:00',
                'close_time' => $court->close_time ?: '23:00',
                'slots' => $slots,
            ];
        }

        return [
            'date' => $dateStr,
            'timezone' => $timezone,
            'open_hour' => sprintf('%02d:00', $minOpenHour),
            'close_hour' => sprintf('%02d:00', $maxCloseHour),
            'courts' => $resultCourts,
        ];
    }

    /**
     * Mengambil katalog peralatan dan add-on sewa.
     */
    public function getEquipments(): Collection
    {
        return CourtEquipment::where('is_active', true)->orderBy('type')->get();
    }

    /**
     * Mengunci multi-slot jam lapangan secara ATOMIK (All-or-Nothing).
     * Mencegah Race Condition / Concurrency Collision dengan Two-Tier Defense:
     * 1. Distributed Cache Multi-Lock (Sorted Keys anti-deadlock)
     * 2. ACID DB Pessimistic Lock (SELECT ... FOR UPDATE)
     *
     * @throws SlotConflictException
     * @throws HttpException
     */
    public function holdBatchSlots(array $slots, string $bookingDate, User $user, ?string $coachId = null): array
    {
        if (empty($slots)) {
            throw new HttpException(422, 'Daftar slot tidak boleh kosong.');
        }

        $bookingDateParsed = Carbon::parse($bookingDate)->startOfDay();
        if ($bookingDateParsed->isPast() && ! $bookingDateParsed->isToday()) {
            throw new HttpException(422, 'Tanggal booking tidak boleh di masa lampau.');
        }

        // 1. SORTING ARRAY SECARA KRONOLOGIS (Anti-Deadlock pada Concurrent Transactions)
        usort($slots, function ($a, $b) {
            $cmp = strcmp($a['court_id'], $b['court_id']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['start_time'], $b['start_time']);
        });

        // Gabungkan jam berurutan di lapangan yang sama jadi SATU booking (web customer sudah begini, POS walk-in
        // dan API belum). Booking 2 jam yang tersimpan sebagai 2 baris bisa di-reschedule terpisah = jadwal yang
        // dipesan berurutan terpecah ke jam berbeda — itu tidak boleh.
        $merged = [];
        foreach ($slots as $slot) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]['court_id'] === $slot['court_id'] && $merged[$last]['end_time'] === $slot['start_time']) {
                $merged[$last]['end_time'] = $slot['end_time'];

                continue;
            }
            $merged[] = $slot;
        }
        $slots = $merged;

        // Validasi waktu masing-masing slot
        foreach ($slots as $slot) {
            // Grid jadwal per jam bulat (sama dengan aturan reschedule). Jam seperti 19:30 dulu lolos dan memakai
            // kunci cache yang berbeda dari slot 19:00 — bentrok baru ketahuan di lapisan database.
            if (! preg_match('/^([01]\d|2[0-3]):00(:00)?$/', (string) $slot['start_time'])
                || ! preg_match('/^(([01]\d|2[0-3]):00|24:00)(:00)?$/', (string) $slot['end_time'])) {
                throw new HttpException(422, 'Jam booking harus jam bulat (contoh 19:00 - 20:00). Pilih slot dari jadwal.');
            }

            $start = Carbon::parse("{$bookingDate} {$slot['start_time']}");
            $end = Carbon::parse("{$bookingDate} {$slot['end_time']}");
            if ($end->lessThanOrEqualTo($start)) {
                throw new HttpException(422, "Waktu selesai ({$slot['end_time']}) harus lebih besar dari waktu mulai ({$slot['start_time']}).");
            }

            // Sama dengan grid: jam yang sudah lewat hari ini tidak bisa dipesan (jam berjalan masih boleh).
            if ($start->copy()->startOfHour()->lt(now()->startOfHour())) {
                throw new HttpException(422, "Jam {$slot['start_time']} sudah lewat. Silakan pilih jam lain.");
            }
        }

        // Bersihkan lock kedaluwarsa secara proaktif sebelum memegang slot baru
        $this->releaseExpiredLocks();

        // TIER 1: ACQUIRE DISTRIBUTED CACHE LOCKS SECARA BERURUTAN (Tiap Interval 1 Jam)
        $acquiredLocks = [];
        try {
            foreach ($slots as $slot) {
                $startDt = Carbon::parse("{$bookingDate} {$slot['start_time']}");
                $endDt = Carbon::parse("{$bookingDate} {$slot['end_time']}");

                $currLock = $startDt->copy();
                while ($currLock->lt($endDt)) {
                    $lockKey = "padel_lock:{$slot['court_id']}:{$bookingDate}:" . $currLock->format('Hi');
                    $lock = Cache::lock($lockKey, app(\App\Services\Padel\BookingTimeService::class)->holdSeconds());

                    if (! $lock->get()) {
                        throw new SlotConflictException(
                            "Slot lapangan pada jam {$currLock->format('H:i')} sedang di-hold pemain lain. Transaksi multi-slot dibatalkan penuh.",
                            $slot['court_id'],
                            "{$slot['start_time']} - {$slot['end_time']}"
                        );
                    }

                    $acquiredLocks[] = $lock;
                    $currLock->addHour();
                }
            }

            // TIER 2: PESSIMISTIC DB LOCK DALAM TRANSAKSI ACID
            // Waktu tahan slot diatur admin; disimpan per booking supaya perubahan pengaturan tidak memengaruhi hold yang sedang berjalan.
            $holdExpiresAt = app(\App\Services\Padel\BookingTimeService::class)->holdExpiresAt();

            return DB::transaction(function () use ($slots, $bookingDate, $user, $coachId, $holdExpiresAt) {
                $createdBookings = [];
                $totalCourtFee = 0;
                $batchId = 'BATCH-PAD-' . strtoupper(Str::random(8));

                foreach ($slots as $slot) {
                    // Kunci baris lapangan dulu: semua jalur yang mengisi jadwal lapangan ini (hold online, walk-in,
                    // reschedule) antre di baris yang sama, jadi cek bentrok di bawah tidak bergantung pada level
                    // isolasi database. Slot sudah diurutkan per court_id → urutan kunci konsisten (anti-deadlock).
                    $court = PadelCourt::where('id', $slot['court_id'])->where('is_active', true)->lockForUpdate()->first();
                    if (! $court) {
                        throw new HttpException(404, "Lapangan ID {$slot['court_id']} tidak ditemukan.");
                    }

                    $startDt = Carbon::parse("{$bookingDate} {$slot['start_time']}");
                    $endDt = Carbon::parse("{$bookingDate} {$slot['end_time']}");

                    // Validasi jam operasional lapangan (tolak booking di luar jam buka/tutup)
                    $courtOpen = (int) substr($court->open_time ?: '06:00', 0, 2);
                    $courtCloseVal = $court->close_time ?: '23:00';
                    $courtClose = ($courtCloseVal === '00:00' || $courtCloseVal === '24:00') ? 24 : (int) substr($courtCloseVal, 0, 2);

                    $slotStartH = (int) $startDt->format('H');
                    $slotEndH = ($endDt->format('H:i') === '00:00' && $endDt->isNextDay($startDt)) ? 24 : (int) $endDt->format('H');

                    if ($slotStartH < $courtOpen || $slotEndH > $courtClose) {
                        throw new HttpException(422, "Slot {$court->name} pada jam {$slot['start_time']} - {$slot['end_time']} berada di luar jam operasional ({$court->open_time} - {$court->close_time} WIB).");
                    }

                    // RUMUS OVERLAP MATEMATIS KETAT: (< dan >) dengan proteksi anti-stale locks
                    $hasConflict = PadelBooking::where('court_id', $court->id)
                        ->whereDate('booking_date', $bookingDate)
                        // releaseExpiredLocks() sudah dijalankan sebelum transaksi ini — yang tersisa
                        // semuanya booking hidup (lihat catatan di getScheduleMatrix()).
                        ->whereIn('status', self::activeSlotStatuses())
                        ->where('start_time', '<', $endDt->format('Y-m-d H:i:s'))
                        ->where('end_time', '>', $startDt->format('Y-m-d H:i:s'))
                        ->lockForUpdate() // Kunci baris database secara eksklusif
                        ->exists();

                    if ($hasConflict) {
                        throw new SlotConflictException(
                            "Slot {$court->name} pada jam {$slot['start_time']} - {$slot['end_time']} sudah terisi atau terkunci. Transaksi multi-slot di-rollback.",
                            $court->name,
                            "{$slot['start_time']} - {$slot['end_time']}"
                        );
                    }

                    // Tarif akumulasi per jam (transisi reguler vs peak) — jam peak diatur di Master Data.
                    $courtFee = app(\App\Services\Padel\PeakHourService::class)->courtFee($court, $startDt, $endDt)['fee'];
                    $totalCourtFee += $courtFee;

                    $bookingCode = 'BK-PAD-' . strtoupper(Str::random(8));

                    $booking = PadelBooking::create([
                        'booking_code' => $bookingCode,
                        'user_id' => $user->id,
                        'court_id' => $court->id,
                        'coach_id' => $coachId,
                        'booking_date' => $bookingDate,
                        'start_time' => $startDt,
                        'end_time' => $endDt,
                        'court_fee' => $courtFee,
                        'coach_fee' => 0.00,
                        'equipment_fee' => 0.00,
                        'total_amount' => $courtFee,
                        'status' => 'LOCKED',
                        'expires_at' => $holdExpiresAt,
                        'qr_code_hash' => hash_hmac('sha256', $bookingCode . $user->id . $court->id . $startDt->toISOString(), config('app.key')),
                    ]);

                    $createdBookings[] = $booking;
                }

                return [
                    'batch_id' => $batchId,
                    'expires_at' => $holdExpiresAt->toISOString(),
                    'hold_seconds_remaining' => app(\App\Services\Padel\BookingTimeService::class)->holdSeconds(),
                    'bookings' => $createdBookings,
                    'subtotal' => $totalCourtFee,
                ];
            });
        } catch (\Throwable $e) {
            // Rollback seluruh Cache Locks jika transaksi gagal
            foreach ($acquiredLocks as $lock) {
                try {
                    $lock->release();
                } catch (\Throwable $releaseEx) {}
            }
            throw $e;
        }
    }

    /**
     * Melepaskan kunci slot sukarela saat user membatalkan dari keranjang atau membatalkan pesanan pending.
     */
    public function releaseSlots(array $bookingIds, User $user, bool $onlyLocked = false): int
    {
        $orderNumbersToCancel = [];

        $count = DB::transaction(function () use ($bookingIds, $user, $onlyLocked, &$orderNumbersToCancel) {
            $bookings = PadelBooking::whereIn('id', $bookingIds)
                ->where('user_id', $user->id)
                ->whereIn('status', $onlyLocked ? ['LOCKED'] : ['LOCKED', 'PENDING_PAYMENT', 'PENDING'])
                ->lockForUpdate()
                ->get();

            $c = 0;
            foreach ($bookings as $booking) {
                // Guard: Jika booking sudah berstatus PAID karena webhook concurrent, jangan batalkan
                if ($booking->status === 'PAID') {
                    continue;
                }

                // Booking hasil reschedule admin yang menunggu pelunasan selisih (LOCKED) sudah dibayar
                // sebagian — bukan keranjang yang boleh "dilepas" customer (uangnya bisa ikut hangus).
                if ((int) $booking->reschedule_count > 0) {
                    continue;
                }

                if ($booking->order_id) {
                    $order = \App\Models\Pos\Order::where('id', $booking->order_id)
                        ->orWhere('order_number', $booking->order_id)
                        ->lockForUpdate()
                        ->first();
                    if ($order && $order->payment_status === 'PAID') {
                        // Order sudah dibayar lunas, jangan dibatalkan
                        continue;
                    }
                    if ($order && $order->payment_status !== 'PAID') {
                        $order->update(['payment_status' => 'CANCELLED']);
                        $orderNumbersToCancel[] = $order->order_number;
                    }
                }

                $currLock = $booking->start_time->copy();
                $endLock = $booking->end_time->copy();
                while ($currLock->lt($endLock)) {
                    $lockKey = "padel_lock:{$booking->court_id}:{$booking->booking_date->format('Y-m-d')}:" . $currLock->format('Hi');
                    Cache::forget($lockKey);
                    try {
                        Cache::lock($lockKey)->forceRelease();
                    } catch (\Throwable $e) {}
                    $currLock->addHour();
                }

                $this->reverseBookingBenefits($booking);

                $booking->update(['status' => 'CANCELLED']);
                $c++;
            }

            return $c;
        });

        // Panggil Midtrans Cancel API secara non-blocking di luar transaksi DB
        if (! empty($orderNumbersToCancel)) {
            $midtrans = app(\App\Services\Payment\MidtransService::class);
            foreach (array_unique($orderNumbersToCancel) as $orderNumber) {
                try {
                    $midtrans->cancelTransaction($orderNumber);
                } catch (\Throwable $e) {
                    // Best-effort
                }
            }
        }

        return $count;
    }

    /**
     * Garbage Collection: Merilis semua slot LOCKED yang ditinggal > 10 menit
     * atau PENDING_PAYMENT / PENDING yang tidak diselesaikan dalam 15 menit.
     */
    /**
     * Sebelum booking PENDING_PAYMENT dihanguskan, tanya dulu ke Midtrans. Tanpa ini, customer
     * yang sudah bayar tapi webhook-nya tidak sampai akan kehilangan booking (slot dilepas ke
     * orang lain) padahal uangnya sudah masuk.
     *   - Lunas di Midtrans      → dilunasi sekarang, TIDAK dihanguskan.
     *   - Masih pending / Midtrans tidak bisa dihubungi → tunda dulu, paling lama
     *     BookingTimeService::GATEWAY_UNCERTAIN_MAX_MINUTES setelah batas bayar booking itu (semua sesi Midtrans
     *     dibuat berakhir sebelum batas bayar, jadi "pending" setelahnya hanya notifikasi yang telat).
     *   - Expire / cancel / tidak dikenal Midtrans → dihanguskan seperti biasa.
     */
    private function withoutBookingsPaidAtGateway(\Illuminate\Support\Collection $bookings): \Illuminate\Support\Collection
    {
        $reconciler = app(\App\Services\Payment\MidtransReconciliationService::class);
        $times = app(\App\Services\Padel\BookingTimeService::class);
        $decisions = [];

        return $bookings->filter(function (PadelBooking $booking) use ($reconciler, $times, &$decisions) {
            if (! $booking->order_id) {
                return true;
            }

            if (! array_key_exists($booking->order_id, $decisions)) {
                $order = \App\Models\Pos\Order::find($booking->order_id);
                $hasGatewayPayment = $order && \App\Models\Pos\Payment::where('order_id', $order->id)
                    ->where('payment_gateway', 'MIDTRANS')
                    ->exists();

                // Cache 60 detik: fungsi ini ikut terpanggil dari endpoint publik (jadwal/hold slot).
                $decisions[$booking->order_id] = $hasGatewayPayment
                    ? $reconciler->reconcileOrder($order, cacheSeconds: 60)
                    : \App\Services\Payment\MidtransReconciliationService::NOT_PAID;
            }

            return match ($decisions[$booking->order_id]) {
                \App\Services\Payment\MidtransReconciliationService::PAID => false,
                \App\Services\Payment\MidtransReconciliationService::PENDING,
                \App\Services\Payment\MidtransReconciliationService::ERROR => now()->gt($times->paymentDeadlineFor($booking)->copy()->addMinutes(\App\Services\Padel\BookingTimeService::GATEWAY_UNCERTAIN_MAX_MINUTES)),
                default => true,
            };
        })->values();
    }

    /**
     * Kembalikan jam kuota member & jam voucher sponsor yang dipotong saat checkout untuk booking yang batal / hangus
     * tanpa dibayar. Idempoten: kolom pemakaian dinolkan & disimpan. Dulu hanya dipanggil saat customer membatalkan
     * sendiri — booking yang hangus (pembersih otomatis) atau dibatalkan gateway membuat jam member hilang.
     * Wajib dipanggil di dalam transaksi DB.
     */
    public function reverseBookingBenefits(PadelBooking $booking): void
    {
        $changed = false;

        if ($booking->membership_balance_id && (float) $booking->member_hours_consumed > 0) {
            app(\App\Services\Membership\MembershipBalanceService::class)->adjustQuota(
                balanceId: $booking->membership_balance_id,
                changeType: 'REVERSAL',
                quantity: (float) $booking->member_hours_consumed,
                notes: 'Reversal pembatalan/expired booking Padel ' . $booking->booking_code,
                relatedType: PadelBooking::class,
                relatedId: $booking->id
            );
            $booking->member_hours_consumed = 0.00;
            $changed = true;
        }

        if ($booking->sponsor_member_voucher_id && (float) $booking->sponsor_hours_consumed > 0) {
            $sponsorVoucher = \App\Models\Sponsor\SponsorMemberVoucher::where('id', $booking->sponsor_member_voucher_id)
                ->lockForUpdate()
                ->first();
            if ($sponsorVoucher) {
                $sponsorVoucher->hours_used = max(0, (float) $sponsorVoucher->hours_used - (float) $booking->sponsor_hours_consumed);
                $sponsorVoucher->save();
            }
            $booking->sponsor_hours_consumed = 0.00;
            $changed = true;
        }

        if ($changed) {
            $booking->save();
        }
    }

    /** Status booking yang menempati slot lapangan (setelah GC releaseExpiredLocks berjalan). */
    protected static function activeSlotStatuses(): array
    {
        return ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN'];
    }

    public function releaseExpiredLocks(): int
    {
        // Batas waktu per booking (`expires_at`, diatur di admin). Booking lama / buatan kasir tanpa `expires_at` memakai
        // aturan lama: dihitung dari created_at dengan durasi pengaturan saat ini.
        $times = app(\App\Services\Padel\BookingTimeService::class);
        $holdThreshold = now()->subMinutes($times->holdMinutes());
        $paymentThreshold = now()->subMinutes($times->paymentWindowMinutes());
        // Jeda setelah batas bayar: notifikasi Midtrans bisa telat beberapa detik s/d 90 detik.
        $paymentDeadlineWithGrace = now()->subMinutes(\App\Services\Padel\BookingTimeService::GRACE_MINUTES);

        $orderNumbersToCancel = [];

        // 1. Slot LOCKED tanpa checkout (waktu tahan habis)
        $expiredHolds = PadelBooking::where('status', 'LOCKED')
            ->where(fn ($q) => $q->where('expires_at', '<', now())
                ->orWhere(fn ($legacy) => $legacy->whereNull('expires_at')->where('created_at', '<', $holdThreshold)))
            ->where('reschedule_count', 0)
            ->where(function ($query) {
                $query->whereNull('order_id')
                    ->orWhereDoesntHave('order.payments', function ($q) {
                        $q->where('status', 'SUCCESS');
                    });
            })
            ->get();

        // 2. Slot PENDING_PAYMENT / PENDING yang tidak dibayar sampai batas bayar (+ jeda)
        $expiredPendingPayments = PadelBooking::whereIn('status', ['PENDING_PAYMENT', 'PENDING'])
            ->where(fn ($q) => $q->where('expires_at', '<', $paymentDeadlineWithGrace)
                ->orWhere(fn ($legacy) => $legacy->whereNull('expires_at')->where('created_at', '<', $paymentThreshold)))
            ->where('reschedule_count', 0)
            ->where(function ($query) {
                $query->whereNull('order_id')
                    ->orWhereDoesntHave('order.payments', function ($q) {
                        $q->where('status', 'SUCCESS');
                    });
            })
            ->get();

        $expiredPendingPayments = $this->withoutBookingsPaidAtGateway($expiredPendingPayments);

        $expiredBookings = $expiredHolds->merge($expiredPendingPayments);

        $count = 0;
        foreach ($expiredBookings as $b) {
            // Update BERSYARAT: di sela pengecekan di atas (yang bisa memanggil Midtrans beberapa detik) booking ini bisa
            // saja baru di-checkout / dibayar. Dulu langsung ditimpa EXPIRED → order dibatalkan & sesi Midtrans di-cancel
            // padahal customer sedang membayar.
            $expired = DB::transaction(function () use ($b) {
                if (! PadelBooking::whereKey($b->id)->where('status', $b->status)->update(['status' => 'EXPIRED'])) {
                    return false;
                }
                // Jam kuota member / voucher sponsor yang dipotong saat checkout dikembalikan.
                $this->reverseBookingBenefits(PadelBooking::whereKey($b->id)->lockForUpdate()->first());

                return true;
            });
            if (! $expired) {
                continue;
            }

            $currLock = $b->start_time->copy();
            $endLock = $b->end_time->copy();
            while ($currLock->lt($endLock)) {
                $lockKey = "padel_lock:{$b->court_id}:{$b->booking_date->format('Y-m-d')}:" . $currLock->format('Hi');
                Cache::forget($lockKey);
                try {
                    Cache::lock($lockKey)->forceRelease();
                } catch (\Throwable $e) {}
                $currLock->addHour();
            }

            $count++;

            if ($b->order_id) {
                $order = \App\Models\Pos\Order::find($b->order_id);
                if ($order && $order->payment_status !== 'PAID') {
                    $order->update(['payment_status' => 'CANCELLED']);
                    $orderNumbersToCancel[] = $order->order_number;
                }
            }
        }

        // Yang dicatat hanya booking yang SUDAH checkout (menunggu bayar) lalu hangus — keranjang
        // yang ditinggal sebelum checkout (hold 10 menit) terlalu sering & tidak bernilai audit.
        $expiredAfterCheckout = $expiredPendingPayments->pluck('booking_code')->filter()->values();
        if ($expiredAfterCheckout->isNotEmpty()) {
            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: 'booking.expired_unpaid',
                description: 'Sistem menghanguskan '.$expiredAfterCheckout->count().' booking yang tidak dibayar dalam batas waktu',
                meta: [
                    'kode_booking' => $expiredAfterCheckout->take(100)->all(),
                    'order_dibatalkan' => array_values(array_unique($orderNumbersToCancel)),
                    'keranjang_ditinggal_dilepas' => $expiredHolds->count(),
                ],
                asSystem: true,
            );
        }

        // Panggil Midtrans Cancel API di luar DB lock
        if (! empty($orderNumbersToCancel)) {
            $midtrans = app(\App\Services\Payment\MidtransService::class);
            foreach (array_unique($orderNumbersToCancel) as $orderNumber) {
                try {
                    $midtrans->cancelTransaction($orderNumber);
                } catch (\Throwable $e) {
                    // Best-effort
                }
            }
        }

        return $count;
    }
}
