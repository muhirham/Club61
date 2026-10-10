<?php

namespace App\Services\Padel\Concerns;

use App\Exceptions\SlotConflictException;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Payment;
use App\Models\Pos\Refund;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ManagesRescheduleAndCashier
{
    /**
     * Mengambil daftar slot reschedule yang tersedia dengan durasi terkunci (Anti-Jebakan Durasi)
     * dan validasi ketat anti-tanggal lampau.
     *
     * @throws HttpException
     */
    public function getAvailableRescheduleSlots(
        string $bookingId,
        string $targetCourtId,
        string $targetDate,
        string $timezone = 'Asia/Jakarta'
    ): array {
        $parsedDate = Carbon::parse($targetDate, $timezone)->startOfDay();
        if ($parsedDate->isPast() && ! $parsedDate->isToday()) {
            throw new HttpException(422, 'Tanggal reschedule tidak boleh di masa lampau.');
        }

        $booking = PadelBooking::with('court')->findOrFail($bookingId);
        $durationHours = (int) $booking->start_time->diffInHours($booking->end_time);
        if ($durationHours < 1) {
            $durationHours = 1;
        }

        $court = PadelCourt::findOrFail($targetCourtId);
        if (! $court->is_active) {
            throw new HttpException(422, "Lapangan {$court->name} sedang tidak aktif.");
        }
        $dateStr = $parsedDate->format('Y-m-d');
        $peakHours = app(\App\Services\Padel\PeakHourService::class);

        $activeBookings = PadelBooking::where('court_id', $court->id)
            ->where('booking_date', $dateStr)
            ->whereIn('status', ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN'])
            ->where('id', '!=', $booking->id)
            ->get();

        $openHour = (int) substr($court->open_time ?: '06:00', 0, 2);
        $closeVal = $court->close_time ?: '23:00';
        $closeHour = ($closeVal === '00:00' || $closeVal === '24:00') ? 24 : (int) substr($closeVal, 0, 2);

        // Bulk prefetch Distributed Cache Locks untuk seluruh rentang jam lapangan tujuan (1 query)
        $allSlotCacheKeys = [];
        for ($h = $openHour; $h < $closeHour; $h++) {
            $allSlotCacheKeys[] = "padel_lock:{$court->id}:{$dateStr}:" . sprintf('%02d00', $h);
        }
        $bulkSlotLocks = Cache::many($allSlotCacheKeys);

        $availableSlots = [];
        $benefitContext = $this->rescheduleBenefitContext($booking);
        $nowLocal = Carbon::now($timezone);
        $isToday = $dateStr === $nowLocal->format('Y-m-d');

        // Jam operasional: open_time sampai close_time (batas start adalah closeHour - durasi)
        for ($startHour = $openHour; $startHour <= ($closeHour - $durationHours); $startHour++) {
            // Aturan jam lewat sama dengan grid booking: jam sebelum jam berjalan hari ini tidak ditawarkan.
            if ($isToday && $startHour < (int) $nowLocal->format('H')) {
                continue;
            }

            $slotStart = Carbon::parse("{$dateStr} " . sprintf('%02d:00', $startHour), $timezone);
            $slotEnd = $slotStart->copy()->addHours($durationHours);

            // Contiguous check: pastikan seluruh jam berturut-turut kosong
            $isAvailable = true;
            $currCheck = $slotStart->copy();
            $hasPrime = false;

            while ($currCheck->lt($slotEnd)) {
                $subStart = $currCheck->copy();
                $subEnd = $subStart->copy()->addHour();

                // Cek tabrakan booking aktif
                $hasCollision = $activeBookings->first(function ($b) use ($subStart, $subEnd) {
                    return $b->start_time < $subEnd && $b->end_time > $subStart;
                });

                // Cek distributed cache lock via bulk prefetch
                $cacheKey = "padel_lock:{$court->id}:{$dateStr}:" . $subStart->format('Hi');
                $isCacheLocked = ! empty($bulkSlotLocks[$cacheKey]);

                if ($hasCollision || $isCacheLocked) {
                    $isAvailable = false;
                    break;
                }

                if ($peakHours->isPeak($subStart)) {
                    $hasPrime = true;
                }

                $currCheck->addHour();
            }

            if ($isAvailable) {
                $quote = $this->quoteReschedule($booking, $court, $slotStart, $slotEnd, $benefitContext);

                $startStr = sprintf('%02d:00', $startHour);
                $endStr = sprintf('%02d:00', $startHour + $durationHours);

                $availableSlots[] = [
                    'start_time' => $startStr,
                    'end_time' => $endStr,
                    'duration_hours' => $durationHours,
                    'label' => "{$startStr} - {$endStr} WIB ({$durationHours} Jam)" . ($hasPrime ? ' [Prime Time]' : ' [Reguler]'),
                    'estimated_fee' => $quote['net_new'],
                    'gross_fee' => $quote['gross_new'],
                    'benefit_discount' => $quote['member_discount_new'] + $quote['sponsor_discount_new'],
                    'delta' => $quote['court_delta'],
                    'tax_delta' => $quote['tax'],
                    'admin_fee_delta' => $quote['admin_fee'],
                    'total_delta' => $quote['total_charge'],
                    'forfeited' => $quote['forfeited'],
                    'benefit_dropped_reason' => $quote['benefit_dropped_reason'],
                    'is_prime' => $hasPrime,
                ];
            }
        }

        return [
            'booking_id' => $booking->id,
            'duration_hours' => $durationHours,
            'original_court_fee' => (float) $booking->court_fee,
            'target_court_name' => $court->name,
            'target_date' => $dateStr,
            'membership_time_window' => $benefitContext['time_window'],
            'slots' => $availableSlots,
        ];
    }

    /**
     * SATU-SATUNYA rumus selisih reschedule — dipakai preview di modal DAN eksekusi, jadi angka yang
     * dilihat kasir selalu sama dengan yang ditagih.
     *
     * court_fee yang tersimpan adalah nominal SETELAH benefit (kuota/diskon member, voucher sponsor).
     * Dulu selisih = tarif normal jadwal baru - court_fee, akibatnya booking yang dibayar pakai kuota
     * (court_fee 0) ditagih harga penuh jadwal baru (bayar dua kali) dan booking diskon 20% ditagih
     * ulang 20%-nya. Sekarang benefit ikut pindah dengan PROPORSI yang sama dengan booking aslinya:
     *   - tanpa benefit      -> jadwal baru harga normal
     *   - kuota jam (100%)   -> jadwal baru tetap tertutup kuota (durasi terkunci, jam kuota sama)
     *   - diskon %           -> jadwal baru dapat diskon % yang sama
     *   - voucher sponsor    -> sama, proporsional jam yang ditutup voucher
     *
     * Kebijakan (PM, 30 Sep 2026): pindah ke jadwal lebih murah -> selisih HANGUS, tidak dikembalikan.
     *
     * @param  array{start: string, end: string}|null  $timeWindow  jendela jam paket member (null = bebas)
     */
    public function quoteReschedule(PadelBooking $booking, PadelCourt $court, Carbon $start, Carbon $end, ?array $benefitContext = null): array
    {
        $benefitContext ??= $this->rescheduleBenefitContext($booking);

        // Tarif normal jadwal baru — jam peak/reguler dari pengaturan Master Data (sama dengan grid & checkout).
        $grossNew = app(\App\Services\Padel\PeakHourService::class)->courtFee($court, $start, $end)['fee'];

        $paidCourt = (float) $booking->court_fee;
        $memberDiscount = (float) ($booking->member_discount_court ?? 0);
        $sponsorDiscount = (float) ($booking->sponsor_discount_court ?? 0);
        $grossOld = $paidCourt + $memberDiscount + $sponsorDiscount;

        $memberShare = $grossOld > 0 ? min(1.0, $memberDiscount / $grossOld) : 0.0;
        $sponsorShare = $grossOld > 0 ? min(1.0 - $memberShare, $sponsorDiscount / $grossOld) : 0.0;

        // Benefit hanya ikut pindah kalau MASIH BERLAKU di jadwal baru. Kalau tidak (membership/voucher sudah
        // habis di tanggal itu, atau di luar jam paket), benefitnya gugur: jadwal baru dihitung harga normal dan
        // admin melihat peringatannya dulu (keputusan PM, 1 Okt 2026). Pindah ke tanggal yang masih berlaku =
        // benefit tetap penuh.
        $dropReasons = [];
        if ($memberShare > 0 && ($reason = $this->memberBenefitInvalidReason($benefitContext, $start, $end))) {
            $memberShare = 0.0;
            $dropReasons[] = $reason;
        }
        if ($sponsorShare > 0 && ($reason = $this->sponsorBenefitInvalidReason($benefitContext, $start))) {
            $sponsorShare = 0.0;
            $dropReasons[] = $reason;
        }

        $memberDiscountNew = round($grossNew * $memberShare, 2);
        $sponsorDiscountNew = round($grossNew * $sponsorShare, 2);
        $netNew = max(0.0, round($grossNew - $memberDiscountNew - $sponsorDiscountNew, 2));
        $courtDelta = round($netNew - $paidCourt, 2);

        $tax = 0.0;
        $adminFee = 0.0;
        $totalCharge = 0.0;
        if ($courtDelta > 0) {
            $calc = app(\App\Services\Finance\TaxAndFeeService::class)->calculate(
                subtotal: $courtDelta,
                discountAmount: 0,
                channel: $this->rescheduleFeeChannel($booking),
                module: 'PADEL'
            );
            $tax = (float) $calc['tax_amount'];
            $adminFee = (float) $calc['admin_fee_amount'];
            $totalCharge = (float) $calc['grand_total'];
        }

        return [
            'gross_new' => $grossNew,
            'member_discount_new' => $memberDiscountNew,
            'sponsor_discount_new' => $sponsorDiscountNew,
            'net_new' => $netNew,
            'paid_court' => $paidCourt,
            'court_delta' => $courtDelta,
            'tax' => $tax,
            'admin_fee' => $adminFee,
            'total_charge' => $totalCharge,
            'forfeited' => $courtDelta < 0 ? abs($courtDelta) : 0.0,
            'member_benefit_dropped' => $memberDiscount > 0 && $memberShare <= 0,
            'sponsor_benefit_dropped' => $sponsorDiscount > 0 && $sponsorShare <= 0,
            'benefit_dropped_reason' => $dropReasons ? implode(' ', $dropReasons) : null,
        ];
    }

    /** Biaya selisih mengikuti kanal transaksi aslinya (booking online vs walk-in kasir). */
    protected function rescheduleFeeChannel(PadelBooking $booking): string
    {
        return $booking->order?->order_type === 'WALK_IN' ? 'POS_WALKIN' : 'ONLINE';
    }

    /**
     * Data masa berlaku benefit booking ini (diambil sekali, dipakai untuk semua slot yang dihitung).
     *
     * @return array{time_window: array{start: string, end: string}|null, member_end_date: ?string, member_active: bool, sponsor_expires_at: ?Carbon}
     */
    protected function rescheduleBenefitContext(PadelBooking $booking): array
    {
        $context = ['time_window' => null, 'member_end_date' => null, 'member_active' => true, 'sponsor_expires_at' => null];

        if ($booking->membership_balance_id && (float) $booking->member_discount_court > 0) {
            $balance = \App\Models\Membership\UserMembershipBalance::with('membership')->find($booking->membership_balance_id);
            if ($balance?->time_window_start && $balance->time_window_end) {
                $context['time_window'] = ['start' => (string) $balance->time_window_start, 'end' => (string) $balance->time_window_end];
            }
            $membership = $balance?->membership;
            $context['member_active'] = $membership !== null && $membership->status === 'ACTIVE';
            $context['member_end_date'] = $membership?->end_date?->format('Y-m-d');
        }

        if ($booking->sponsor_member_voucher_id && (float) $booking->sponsor_discount_court > 0) {
            $context['sponsor_expires_at'] = \App\Models\Sponsor\SponsorMemberVoucher::whereKey($booking->sponsor_member_voucher_id)->first()?->expires_at;
        }

        return $context;
    }

    protected function memberBenefitInvalidReason(array $context, Carbon $start, Carbon $end): ?string
    {
        if (! $context['member_active']) {
            return 'Membership customer sudah tidak aktif, benefit member gugur — jadwal baru dihitung harga normal.';
        }

        if ($context['member_end_date'] && $start->format('Y-m-d') > $context['member_end_date']) {
            return 'Membership customer berakhir '.Carbon::parse($context['member_end_date'])->translatedFormat('d M Y')
                .', benefit member gugur di tanggal ini — jadwal baru dihitung harga normal.';
        }

        if ($window = $context['time_window']) {
            // Menit-dalam-hari; jam selesai 00:00 hari berikutnya = 24:00 (dulu 23:00-24:00 lolos dari cek jam paket).
            $toMinutes = fn (string $hms) => ((int) substr($hms, 0, 2)) * 60 + (int) substr($hms, 3, 2);
            $startMin = $start->hour * 60 + $start->minute;
            $endMin = $end->isSameDay($start) ? $end->hour * 60 + $end->minute : 1440 + $end->hour * 60 + $end->minute;
            $windowEnd = $toMinutes($window['end']) === 0 ? 1440 : $toMinutes($window['end']);

            if ($startMin < $toMinutes($window['start']) || $endMin > $windowEnd) {
                return 'Jadwal baru di luar jam berlaku paket membership ('.substr($window['start'], 0, 5).' - '.substr($window['end'], 0, 5)
                    .'), benefit member gugur — jadwal baru dihitung harga normal.';
            }
        }

        return null;
    }

    protected function sponsorBenefitInvalidReason(array $context, Carbon $start): ?string
    {
        $expiresAt = $context['sponsor_expires_at'];
        if ($expiresAt && $start->gte($expiresAt)) {
            return 'Voucher sponsor berakhir '.$expiresAt->translatedFormat('d M Y').', benefit sponsor gugur di tanggal ini — jadwal baru dihitung harga normal.';
        }

        return null;
    }

    /**
     * Eksekusi Admin Override: Pindah Jadwal Padel Booking (Atomik DB::transaction).
     *
     * @throws HttpException
     * @throws SlotConflictException
     */
    public function adminRescheduleBooking(
        string $bookingId,
        string $newCourtId,
        string $newDate,
        string $newStartTimeStr,
        string $reason,
        User $adminUser,
        ?string $paymentMethod = 'QRIS',
        // Default false: uang hanya diterima di POS kasir. Pemanggil yang lupa mengisi flag ini tidak boleh
        // diam-diam menagih selisih di luar layar kasir.
        bool $isDeltaPaid = false,
        string $timezone = 'Asia/Jakarta',
        array $paymentProof = [],
        string $deltaPaymentChannel = 'CASHIER',
    ): array {
        // 100% Cashless: pembayaran tunai tidak diperbolehkan sama sekali untuk pelunasan selisih reschedule.
        if ($paymentMethod && in_array(strtoupper($paymentMethod), ['CASH', 'TUNAI'])) {
            throw new HttpException(422, 'Pembayaran tunai (CASH) tidak diperbolehkan. Venue Club 61 beroperasi 100% Cashless.');
        }

        // Jam mulai datang dari Livewire (bisa direkayasa): hanya jam bulat "HH:00". Dulu "14:30" atau
        // "10:00 +1 day" diterima → booking keluar grid / tanggal berbeda dari booking_date (lolos cek bentrok).
        if (! preg_match('/^([01]\d|2[0-3]):00$/', $newStartTimeStr) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
            throw new HttpException(422, 'Format tanggal/jam reschedule tidak valid. Pilih slot dari daftar.');
        }

        $parsedDate = Carbon::parse($newDate, $timezone)->startOfDay();
        if ($parsedDate->isPast() && ! $parsedDate->isToday()) {
            throw new HttpException(422, 'Tanggal reschedule tidak boleh di masa lampau.');
        }

        return DB::transaction(function () use (
            $bookingId, $newCourtId, $newStartTimeStr, $reason,
            $adminUser, $paymentMethod, $isDeltaPaid, $timezone, $parsedDate, $paymentProof, $deltaPaymentChannel
        ) {
            // Kunci lapangan tujuan SEBELUM booking — urutan yang sama dengan holdBatchSlots (lapangan dulu,
            // baru baris booking), supaya hold dan reschedule di lapangan yang sama antre tanpa deadlock.
            $newCourt = PadelCourt::where('id', $newCourtId)->lockForUpdate()->firstOrFail();
            $booking = PadelBooking::with(['order', 'court'])->where('id', $bookingId)->lockForUpdate()->firstOrFail();

            // Hanya booking yang SUDAH LUNAS. Dulu status LOCKED juga diterima: keranjang customer yang belum
            // dibayar bisa "dipindah" admin lalu keluar sebagai PAID + QR aktif tanpa uang masuk, dan booking
            // yang masih punya tagihan selisih reschedule bisa dipindah lagi sehingga tagihannya menumpuk ganda.
            if ($booking->status !== 'PAID') {
                $hasPendingDelta = $booking->status === 'LOCKED' && (int) $booking->reschedule_count > 0;
                throw new HttpException(400, $hasPendingDelta
                    ? 'Booking ini masih punya tagihan selisih reschedule yang belum lunas. Lunasi dulu (POS Walk-In / customer bayar via invoice) sebelum dipindah lagi.'
                    : "Hanya booking yang sudah lunas yang bisa dipindah jadwalnya. Status saat ini: {$booking->status}.");
            }

            // Modul 21: dikunci — jadwal yang tinggal < 2 jam lagi / sudah dimulai tidak bisa dipindah (dulu bisa,
            // selama status masih PAID, termasuk jam main yang sedang berjalan).
            if (now()->gte(\App\Services\Padel\PadelBookingService::rescheduleDeadline($booking))) {
                throw new HttpException(422, 'Reschedule paling lambat '.\App\Services\Padel\PadelBookingService::RESCHEDULE_CUTOFF_HOURS.' jam sebelum jam main. Jadwal booking ini sudah tidak bisa dipindah.');
            }

            $durationHours = (int) $booking->start_time->diffInHours($booking->end_time);
            if ($durationHours < 1) {
                $durationHours = 1;
            }

            $scheduleBefore = ($booking->court?->name ?? '-').', '.$booking->booking_date->format('d M Y').' '
                .$booking->start_time->format('H:i').'-'.$booking->end_time->format('H:i');
            $oldCourtId = $booking->court_id;
            $oldDateStr = $booking->booking_date->format('Y-m-d');
            $oldStart = $booking->start_time->copy();
            $oldEnd = $booking->end_time->copy();

            // Booking beberapa jam berurutan TIDAK boleh dipecah ke jam berbeda. Data lama walk-in menyimpan tiap
            // jam sebagai booking terpisah dalam satu order — booking yang bersambung langsung (lapangan sama,
            // jam menempel) dengan booking lain di order yang sama tidak boleh dipindah sendirian.
            if ($booking->order_id) {
                $adjacent = PadelBooking::where('order_id', $booking->order_id)
                    ->where('id', '!=', $booking->id)
                    ->where('court_id', $booking->court_id)
                    ->whereIn('status', ['PAID', 'LOCKED', 'CHECKED_IN'])
                    ->where(fn ($q) => $q->where('end_time', $booking->start_time)->orWhere('start_time', $booking->end_time))
                    ->first();
                if ($adjacent) {
                    throw new HttpException(422, "Booking ini satu rangkaian jam berurutan dengan {$adjacent->booking_code} ({$adjacent->start_time->format('H:i')}-{$adjacent->end_time->format('H:i')}). Jadwal yang dipesan berurutan tidak bisa dipecah ke jam berbeda.");
                }
            }

            if (! $newCourt->is_active) {
                throw new HttpException(422, "Lapangan {$newCourt->name} sedang tidak aktif.");
            }
            $dateStr = $parsedDate->format('Y-m-d');

            $newStartDt = Carbon::parse("{$dateStr} {$newStartTimeStr}", $timezone);
            $newEndDt = $newStartDt->copy()->addHours($durationHours);
            if ($newStartDt->format('Y-m-d') !== $dateStr) {
                throw new HttpException(422, 'Jam mulai harus berada di tanggal yang dipilih.');
            }

            // Aturan jam lewat sama dengan grid booking (jam berjalan masih boleh).
            if ($newStartDt->copy()->startOfHour()->lt(Carbon::now($timezone)->startOfHour())) {
                throw new HttpException(422, "Jam {$newStartDt->format('H:i')} sudah lewat. Pilih jam lain.");
            }

            $courtOpen = (int) substr($newCourt->open_time ?: '06:00', 0, 2);
            $courtCloseVal = $newCourt->close_time ?: '23:00';
            $courtClose = ($courtCloseVal === '00:00' || $courtCloseVal === '24:00') ? 24 : (int) substr($courtCloseVal, 0, 2);
            $newEndHour = $newEndDt->isSameDay($newStartDt) ? (int) $newEndDt->format('H') : 24;
            if ((int) $newStartDt->format('H') < $courtOpen || $newEndHour > $courtClose) {
                throw new HttpException(422, "Jadwal baru di luar jam operasional {$newCourt->name} ({$newCourt->open_time} - {$newCourt->close_time} WIB).");
            }

            // Contiguous Check: Pastikan tidak ada tabrakan di jadwal baru
            $hasConflict = PadelBooking::where('court_id', $newCourt->id)
                ->whereDate('booking_date', $dateStr)
                ->whereIn('status', ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN'])
                ->where('id', '!=', $booking->id)
                ->where('start_time', '<', $newEndDt->format('Y-m-d H:i:s'))
                ->where('end_time', '>', $newStartDt->format('Y-m-d H:i:s'))
                ->lockForUpdate()
                ->exists();

            if ($hasConflict) {
                throw new SlotConflictException(
                    "Jadwal baru pada {$newCourt->name} jam {$newStartDt->format('H:i')} - {$newEndDt->format('H:i')} bentrok dengan pemesanan lain.",
                    $newCourt->name,
                    "{$newStartDt->format('H:i')} - {$newEndDt->format('H:i')}"
                );
            }

            // Hitung selisih dengan rumus yang SAMA dengan preview di modal (benefit member/sponsor ikut pindah).
            $order = $this->ensureBookingOrder($booking);
            $quote = $this->quoteReschedule($booking, $newCourt, $newStartDt, $newEndDt);

            // Cek kunci slot di cache dulu (hanya membaca) — bentrok slot dilaporkan sebelum validasi
            // pembayaran. Kunci milik jadwal lama booking ini sendiri tidak dihitung sebagai bentrok.
            for ($cursor = $newStartDt->copy(); $cursor->lt($newEndDt); $cursor->addHour()) {
                $holder = Cache::get("padel_lock:{$newCourt->id}:{$dateStr}:".$cursor->format('Hi'));
                $isOwnOldSlot = $newCourt->id === $oldCourtId && $dateStr === $oldDateStr
                    && $cursor->gte($oldStart) && $cursor->lt($oldEnd);
                if ($holder !== null && $holder !== $booking->id && ! $isOwnOldSlot) {
                    throw new SlotConflictException(
                        "Slot {$newCourt->name} jam {$cursor->format('H:i')} sedang dikunci transaksi lain.",
                        $newCourt->name,
                        $cursor->format('H:i')
                    );
                }
            }

            $mustPay = $quote['total_charge'] > 0;
            $payNow = $mustPay && $isDeltaPaid;
            $proofPayload = [];

            // Semua validasi pembayaran SEBELUM menyentuh kunci slot di cache — cache tidak ikut di-rollback
            // transaksi DB, jadi validasi yang gagal belakangan akan meninggalkan kunci nyangkut.
            if ($payNow) {
                if (! \App\Models\Pos\PosCashierShift::getActiveShift('PADEL_FRONTDESK')) {
                    // Berlaku juga untuk super_admin: uang yang masuk tanpa shift tidak pernah ikut rekap
                    // setoran tutup shift, jadi tidak ada yang bisa mencocokkan apakah uangnya benar ada.
                    throw new HttpException(422, 'Tidak ada shift kasir yang aktif untuk loket [PADEL_FRONTDESK]. Silakan buka shift terlebih dahulu.');
                }
                $proofPayload = \App\Services\Pos\PosPaymentProof::validate((string) $paymentMethod, $paymentProof, $quote['total_charge']);
            }

            // Kunci cache: lepas kunci jadwal lama DULU (jadwal baru boleh beririsan dengan jadwal lama
            // booking ini sendiri), baru ambil kunci jadwal baru secara atomik (anti-TOCTOU).
            for ($cursor = $oldStart->copy(); $cursor->lt($oldEnd); $cursor->addHour()) {
                Cache::forget("padel_lock:{$oldCourtId}:{$oldDateStr}:".$cursor->format('Hi'));
            }

            $acquiredLocks = [];
            for ($cursor = $newStartDt->copy(); $cursor->lt($newEndDt); $cursor->addHour()) {
                $lockKey = "padel_lock:{$newCourt->id}:{$dateStr}:".$cursor->format('Hi');
                if (! Cache::add($lockKey, $booking->id, 86400) && Cache::get($lockKey) !== $booking->id) {
                    foreach ($acquiredLocks as $key) {
                        Cache::forget($key);
                    }
                    throw new SlotConflictException(
                        "Slot {$newCourt->name} jam {$cursor->format('H:i')} sedang dikunci transaksi lain.",
                        $newCourt->name,
                        $cursor->format('H:i')
                    );
                }
                $acquiredLocks[] = $lockKey;
            }

            try {
                $targetStatus = 'PAID';
                $newQrCodeHash = hash_hmac('sha256', $booking->booking_code.$booking->user_id.$newCourt->id.$newStartDt->toISOString(), config('app.key'));
                $deltaPayload = [
                    'type' => 'RESCHEDULE_PRICE_DELTA',
                    'booking_id' => $booking->id,
                    'admin_id' => $adminUser->id,
                    'reason' => $reason,
                    'schedule_before' => $scheduleBefore,
                    'court_delta' => $quote['court_delta'],
                    'tax_delta' => $quote['tax'],
                    'admin_fee_delta' => $quote['admin_fee'],
                    'total_delta' => $quote['total_charge'],
                ];

                $dropBenefits = function () use ($booking, $quote, $adminUser) {
                    // Benefit gugur di jadwal baru (customer bayar harga normal) → jam kuota / jam voucher yang dulu
                    // terpakai booking ini dikembalikan. Tanpa ini customer bayar penuh DAN kehilangan kuotanya.
                    if ($quote['member_benefit_dropped'] && $booking->membership_balance_id && (float) $booking->member_hours_consumed > 0) {
                        app(\App\Services\Membership\MembershipBalanceService::class)->adjustQuota(
                            balanceId: $booking->membership_balance_id,
                            changeType: 'REVERSAL',
                            quantity: (float) $booking->member_hours_consumed,
                            notes: "Benefit gugur saat reschedule booking {$booking->booking_code} (jadwal baru di luar masa/jam berlaku) — dihitung harga normal",
                            relatedType: PadelBooking::class,
                            relatedId: $booking->id,
                            performedBy: $adminUser->id
                        );
                        $booking->member_hours_consumed = 0.00;
                    }
                    if ($quote['sponsor_benefit_dropped'] && $booking->sponsor_member_voucher_id && (float) $booking->sponsor_hours_consumed > 0) {
                        $voucher = \App\Models\Sponsor\SponsorMemberVoucher::whereKey($booking->sponsor_member_voucher_id)->lockForUpdate()->first();
                        if ($voucher) {
                            $voucher->hours_used = max(0, (float) $voucher->hours_used - (float) $booking->sponsor_hours_consumed);
                            $voucher->save();
                        }
                        $booking->sponsor_hours_consumed = 0.00;
                    }
                };

                if ($mustPay) {
                    $dropBenefits();
                    // Kurang bayar: tarif & benefit mengikuti jadwal baru, tagihan selisih masuk ke order yang sama.
                    $booking->court_fee = $quote['net_new'];
                    $booking->member_discount_court = $quote['member_discount_new'];
                    if ($booking->sponsor_discount_court !== null || $quote['sponsor_discount_new'] > 0) {
                        $booking->sponsor_discount_court = $quote['sponsor_discount_new'];
                    }
                    $booking->total_amount = (float) $booking->total_amount + $quote['total_charge'];
                    $booking->reschedule_forfeited_amount = 0;

                    $order->update([
                        'subtotal' => (float) $order->subtotal + $quote['court_delta'],
                        'tax_amount' => (float) $order->tax_amount + $quote['tax'],
                        'service_charge' => (float) $order->service_charge + $quote['admin_fee'],
                        'grand_total' => (float) $order->grand_total + $quote['total_charge'],
                    ]);

                    if ($payNow) {
                        app(\App\Services\Payment\PaymentOrchestratorService::class)->markOrderAsPaid($order, [
                            'payment_gateway' => 'CASHIER_POS',
                            'counter' => 'PADEL_FRONTDESK',
                            'payment_method' => strtoupper((string) $paymentMethod),
                            'amount' => $quote['total_charge'],
                            'transaction_id' => 'SUPP-'.strtoupper(Str::random(12)),
                            'admin_user' => $adminUser,
                            'payload_log' => $deltaPayload + $proofPayload,
                        ]);
                    } else {
                        // Tagihan dikirim ke customer: QR ditahan sampai lunas (bayar via Midtrans di halaman
                        // invoice, atau di kasir lewat tombol Settle).
                        $targetStatus = 'LOCKED';
                        $newQrCodeHash = null;

                        Payment::create([
                            'order_id' => $order->id,
                            'payment_gateway' => 'CASHIER_POS',
                            'transaction_id' => 'SUPP-'.strtoupper(Str::random(12)),
                            'amount' => $quote['total_charge'],
                            'payment_method' => 'MENUNGGU_PEMBAYARAN',
                            'status' => 'PENDING',
                            // Pilihan customer: ONLINE (bayar via Midtrans di invoice) / CASHIER (bayar di POS Walk-in saat datang).
                            'payload_log' => $deltaPayload + ['preferred_channel' => strtoupper($deltaPaymentChannel) === 'ONLINE' ? 'ONLINE' : 'CASHIER'],
                        ]);

                        // Order belum lunas lagi. Dulu tetap PAID → "Cek Midtrans" & laporan menganggapnya lunas.
                        $this->recomputeOrderPaymentStatus($order);
                    }
                } elseif ($quote['forfeited'] > 0) {
                    // Lebih bayar (jadwal baru lebih murah): kebijakan PM — selisih HANGUS, tidak dikembalikan.
                    // court_fee & benefit TETAP nominal yang sudah dibayar supaya pembukuan order tidak berubah;
                    // nominal hangusnya dicatat terpisah agar transparan di invoice & laporan.
                    $booking->reschedule_forfeited_amount = $quote['forfeited'];
                } else {
                    $dropBenefits();
                    $booking->court_fee = $quote['net_new'];
                    $booking->member_discount_court = $quote['member_discount_new'];
                    if ($booking->sponsor_discount_court !== null || $quote['sponsor_discount_new'] > 0) {
                        $booking->sponsor_discount_court = $quote['sponsor_discount_new'];
                    }
                    $booking->reschedule_forfeited_amount = 0;
                }

                // Flat Equipment Invariant: Tabel padel_booking_equipments terikat ke order_id, tidak perlu disentuh.
                $booking->court_id = $newCourt->id;
                $booking->booking_date = $dateStr;
                $booking->start_time = $newStartDt;
                $booking->end_time = $newEndDt;
                $booking->status = $targetStatus;
                $booking->qr_code_hash = $newQrCodeHash;
                $booking->reschedule_count = $booking->reschedule_count + 1;
                $booking->cancel_reason = "Reschedule: {$reason} (Admin: {$adminUser->name})";
                $booking->save();
            } catch (\Throwable $e) {
                foreach ($acquiredLocks as $key) {
                    Cache::forget($key);
                }
                throw $e;
            }

            $scheduleAfter = $newCourt->name.', '.$newStartDt->format('d M Y H:i').'-'.$newEndDt->format('H:i');
            $rupiah = fn (float $v) => \App\Services\Audit\ActivityLogger::rupiah($v);
            $outcome = match (true) {
                $payNow => 'selisih '.$rupiah($quote['total_charge']).' dibayar di frontdesk ('.strtoupper((string) $paymentMethod).')',
                $mustPay => 'tagihan selisih '.$rupiah($quote['total_charge']).(strtoupper($deltaPaymentChannel) === 'ONLINE'
                    ? ' dibayar customer via Midtrans (link di invoice)'
                    : ' dibayar di kasir POS Walk-in saat datang').' — QR ditahan sampai lunas',
                $quote['forfeited'] > 0 => 'jadwal lebih murah, selisih '.$rupiah($quote['forfeited']).' HANGUS',
                default => 'tanpa selisih',
            };

            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: 'booking.rescheduled',
                description: "Memindahkan jadwal booking {$booking->booking_code}: {$scheduleBefore} -> {$scheduleAfter} | {$outcome}",
                subject: $booking,
                meta: array_filter([
                    'kode_booking' => $booking->booking_code,
                    'jadwal_lama' => $scheduleBefore,
                    'jadwal_baru' => $scheduleAfter,
                    'tarif_normal_jadwal_baru' => $quote['gross_new'],
                    'benefit_member_sponsor_ikut_pindah' => $quote['member_discount_new'] + $quote['sponsor_discount_new'] ?: null,
                    'selisih_tarif_lapangan' => $quote['court_delta'],
                    'total_tagihan_selisih' => $quote['total_charge'] ?: null,
                    'selisih_hangus' => $quote['forfeited'] ?: null,
                    'benefit_gugur' => $quote['benefit_dropped_reason'],
                    'status_setelahnya' => $targetStatus,
                    'alasan' => $reason,
                ], fn ($v) => $v !== null && $v !== ''),
                severity: \App\Services\Audit\ActivityLogger::WARNING,
                changes: [
                    'jadwal' => ['old' => $scheduleBefore, 'new' => $scheduleAfter],
                    'court_fee' => ['old' => $quote['paid_court'], 'new' => (float) $booking->court_fee],
                ],
                causer: $adminUser,
            );

            return [
                'success' => true,
                'message' => 'Jadwal booking berhasil dipindahkan.',
                'booking' => $booking->fresh(['court', 'order']),
                'delta' => $quote['court_delta'],
                'total_charge' => $quote['total_charge'],
                'forfeited' => $quote['forfeited'],
                'benefit_dropped_reason' => $quote['benefit_dropped_reason'],
                'is_locked' => $targetStatus === 'LOCKED',
            ];
        });
    }

    /**
     * Pelunasan tagihan selisih (Supplemental Payment) oleh kasir.
     * Mengubah status LOCKED menjadi PAID dan merilis QR tiket baru.
     */
    public function adminSettleSupplementalPayment(string $bookingId, string $paymentMethod, User $adminUser, array $paymentProof = []): array
    {
        return $this->adminSettleCashierPayment($bookingId, $paymentMethod, 0, $adminUser, $paymentProof);
    }

    /**
     * Pelunasan pembayaran oleh kasir/admin di meja frontdesk (Cashier Settle Module).
     * Dapat melunasi booking berstatus PENDING_PAYMENT maupun tagihan sisa LOCKED.
     *
     * Nominal yang dilunasi SELALU sisa tagihan sebenarnya (tagihan PENDING, atau grand_total dikurangi
     * yang sudah dibayar) — dulu kalau nominal tidak diisi, sistem mencatat seluruh grand_total order
     * sebagai uang masuk walau yang ditagih cuma selisih reschedule (omzet tercatat berlipat).
     */
    public function adminSettleCashierPayment(
        string $bookingId,
        string $paymentMethod,
        float $amountReceived,
        User $cashierUser,
        array $paymentProof = [],
    ): array {
        // 100% Cashless: pembayaran tunai tidak diperbolehkan sama sekali untuk pelunasan kasir.
        if (in_array(strtoupper($paymentMethod), ['CASH', 'TUNAI'])) {
            throw new HttpException(422, 'Pembayaran tunai (CASH) tidak diperbolehkan. Venue Club 61 beroperasi 100% Cashless.');
        }

        return DB::transaction(function () use ($bookingId, $paymentMethod, $amountReceived, $cashierUser, $paymentProof) {
            $booking = PadelBooking::with(['order', 'court', 'user'])
                ->where('id', $bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($booking->status, ['PENDING_PAYMENT', 'LOCKED', 'PENDING'])) {
                throw new HttpException(400, "Booking dengan status {$booking->status} tidak dapat dilunasi via kasir.");
            }

            if (! \App\Models\Pos\PosCashierShift::getActiveShift('PADEL_FRONTDESK')) {
                throw new HttpException(422, 'Tidak ada shift kasir yang aktif untuk loket [PADEL_FRONTDESK]. Silakan buka shift terlebih dahulu.');
            }

            $order = $this->ensureBookingOrder($booking);
            // Kunci order SEBELUM membaca pembayaran apa pun — webhook Midtrans juga mengunci baris order ini,
            // jadi pelunasan kasir dan webhook untuk order yang sama tidak bisa saling menimpa.
            $order = \App\Models\Pos\Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Customer pernah membuka pembayaran Midtrans untuk tagihan ini? Tanya Midtrans DULU. Tanpa ini,
            // kasir bisa menerima uang lagi padahal customer sudah membayar online (terjadi: tagihan selisih
            // Rp 309.000 lunas di Midtrans 16:36, lalu di-settle EDC di kasir 16:39 = customer bayar dua kali).
            $reconciler = app(\App\Services\Payment\MidtransReconciliationService::class);
            if ($reconciler->hasOnlinePaymentAttempt($order)) {
                $online = $reconciler->reconcileOrder($order);

                if ($online === \App\Services\Payment\MidtransReconciliationService::PAID) {
                    Cache::forget('kelola_pemesanan_tab_counts');

                    return [
                        'success' => true,
                        'settled_via' => 'MIDTRANS',
                        'message' => 'Customer SUDAH membayar tagihan ini via Midtrans — tiket otomatis aktif. JANGAN terima pembayaran lagi di kasir.',
                        'booking' => $booking->fresh(['court', 'order', 'user']),
                    ];
                }

                if ($online === \App\Services\Payment\MidtransReconciliationService::PENDING) {
                    throw new HttpException(409, 'Customer sedang membayar tagihan ini lewat Midtrans (menunggu pembayaran). Minta customer menyelesaikan atau membatalkan pembayaran online-nya dulu, supaya tidak ditagih dua kali.');
                }

                if ($online === \App\Services\Payment\MidtransReconciliationService::ERROR) {
                    throw new HttpException(503, 'Status pembayaran online customer belum bisa dipastikan (Midtrans tidak merespons). Coba lagi sebentar — jangan terima pembayaran dulu supaya tidak dobel.');
                }
            }

            // Tagihan MILIK booking ini (bukan "PENDING terbaru" order), dibaca dengan locking read = versi terbaru.
            $booking->setRelation('order', $order);
            $pendingPayment = $this->pendingBillForBooking($booking, lock: true);
            $totalPaid = (float) Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->lockForUpdate()->sum('amount');
            $amountDue = $pendingPayment
                ? (float) $pendingPayment->amount
                : max(0.0, (float) ($order->grand_total ?: $booking->total_amount) - $totalPaid);

            if ($amountDue <= 0) {
                throw new HttpException(422, 'Tidak ada tagihan tersisa untuk booking ini.');
            }

            if ($amountReceived > 0 && abs($amountReceived - $amountDue) > 1) {
                throw new HttpException(422, 'Nominal pelunasan harus sama dengan sisa tagihan: Rp '.number_format($amountDue, 0, ',', '.').'.');
            }

            $proofPayload = \App\Services\Pos\PosPaymentProof::validate($paymentMethod, $paymentProof, $amountDue);

            $orchestrator = app(\App\Services\Payment\PaymentOrchestratorService::class);
            $orchestrator->markOrderAsPaid($order, [
                'payment_gateway' => 'CASHIER_POS',
                'counter' => 'PADEL_FRONTDESK',
                'payment_method' => strtoupper($paymentMethod),
                'amount' => $amountDue,
                // Lunasi tagihan yang PERSIS ini; kalau ternyata sudah lunas / hilang, gagal keras (409) supaya
                // kasir tidak menggesek EDC untuk tagihan yang sudah dibayar.
                'transaction_id' => $pendingPayment?->transaction_id,
                'require_pending_payment' => $pendingPayment !== null,
                'payload_log' => [
                    'settled_by' => $cashierUser->id,
                    'settled_by_name' => $cashierUser->name,
                    'settled_at' => now()->toIso8601String(),
                    'channel' => 'FRONTDESK_CASHIER',
                ] + $proofPayload,
            ]);

            // Bersihkan cache kuncian dan counter tab
            Cache::forget('kelola_pemesanan_tab_counts');

            return [
                'success' => true,
                'message' => 'Pelunasan kasir berhasil diverifikasi! E-Tiket QR telah aktif.',
                'booking' => $booking->fresh(['court', 'order', 'user']),
            ];
        });
    }

    /**
     * Modul 21 — pengajuan pembatalan + refund dari Kelola Pemesanan (kasir / resepsionis / admin).
     *
     * Refund = customer minta uangnya kembali, jadi booking langsung batal & slotnya dilepas, kuota member / jam voucher
     * sponsor dikembalikan, dan SELURUH uang yang sudah masuk untuk booking ini (tanpa potongan) diajukan ke Antrian
     * Refund sebagai PENDING — booking berstatus REFUND_PENDING. Uang baru keluar saat disetujui di Antrian Refund
     * (booking → REFUNDED); kalau ditolak, uangnya jadi voucher saldo customer (booking → CANCELLED).
     * Booking tanpa uang masuk (ditanggung kuota member / belum dibayar) langsung CANCELLED tanpa pengajuan.
     */
    public function requestCancelAndRefund(string $bookingId, string $reasonCategory, string $notes, User $requester): array
    {
        return DB::transaction(function () use ($bookingId, $reasonCategory, $notes, $requester) {
            $booking = PadelBooking::with(['order', 'court'])->where('id', $bookingId)->lockForUpdate()->firstOrFail();

            // REFUND_PENDING lama (pengajuan customer dari web, sebelum Modul 21) belum punya catatan refund — boleh
            // diajukan ulang supaya masuk antrian.
            $legacyCustomerRequest = $booking->status === 'REFUND_PENDING'
                && ! Refund::where('padel_booking_id', $booking->id)->where('status', 'PENDING')->exists();
            if (! in_array($booking->status, ['PAID', 'LOCKED'], true) && ! $legacyCustomerRequest) {
                throw new HttpException(400, $booking->status === 'REFUND_PENDING'
                    ? 'Refund booking ini sudah diajukan dan sedang menunggu di Antrian Refund.'
                    : "Booking dengan status {$booking->status} tidak dapat dibatalkan.");
            }

            // Jam main yang sudah dimulai tidak bisa dibatalkan / di-refund lagi (dulu bisa selama status masih PAID).
            // Pengecualian: pengajuan lama customer (minimal H-24) yang belum pernah masuk antrian — tanpa ini booking itu
            // tertahan REFUND_PENDING selamanya setelah jam mainnya lewat.
            if (! $legacyCustomerRequest && $booking->start_time->lte(now())) {
                throw new HttpException(422, 'Jam main booking ini sudah dimulai atau lewat, jadi tidak bisa dibatalkan / di-refund.');
            }

            $currLock = $booking->start_time->copy();
            $endLock = $booking->end_time->copy();
            while ($currLock->lt($endLock)) {
                Cache::forget("padel_lock:{$booking->court_id}:{$booking->booking_date->format('Y-m-d')}:" . $currLock->format('Hi'));
                $currLock->addHour();
            }

            $refundAmount = 0.0;
            $hadOrder = (bool) $booking->order_id;
            $hasAnyPaymentRecord = $hadOrder && Payment::where('order_id', $booking->order_id)->exists();
            // Data legacy: booking PAID dari sebelum ada tabel payments. Hanya untuk kasus ini nominal memakai nilai booking.
            $isLegacyPaid = $booking->status === 'PAID' && (! $hadOrder || ! $hasAnyPaymentRecord) && (float) $booking->total_amount > 0;

            if ($isLegacyPaid || $hasAnyPaymentRecord) {
                $order = $this->ensureBookingOrder($booking);
                $order = \App\Models\Pos\Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $booking->setRelation('order', $order);

                // Bagian BOOKING INI dari uang yang benar-benar masuk (dikurangi refund lain) — tanpa potongan.
                $refundAmount = $isLegacyPaid ? (float) $booking->total_amount : $this->refundableAmountForBooking($booking);
            }

            if ($refundAmount > 0) {
                $origPayment = Payment::where('order_id', $booking->order_id)->where('status', 'SUCCESS')->latest()->first();
                if (! $origPayment) {
                    // Fallback pembukuan KHUSUS data legacy: tabel refunds wajib menunjuk ke satu payment. Ditandai
                    // legacy_backfill supaya tidak terbaca sebagai omzet baru.
                    $origPayment = Payment::create([
                        'order_id' => $booking->order_id,
                        'payment_gateway' => 'TRANSFER_MANUAL',
                        'transaction_id' => 'INIT-' . strtoupper(Str::random(10)),
                        'amount' => (float) $booking->total_amount,
                        'payment_method' => 'TRANSFER_MANUAL',
                        'status' => 'SUCCESS',
                        'payload_log' => ['legacy_backfill' => true, 'note' => 'Booking PAID tanpa catatan pembayaran (data sebelum modul pembayaran).'],
                    ]);
                }

                Refund::create([
                    'order_id' => $booking->order_id,
                    'payment_id' => $origPayment->id,
                    'padel_booking_id' => $booking->id,
                    'requested_by_id' => $requester->id,
                    'refund_amount' => $refundAmount,
                    'reason' => "Pembatalan booking {$booking->booking_code}: [{$reasonCategory}] {$notes}",
                    'status' => 'PENDING',
                ]);
            }

            $this->reverseBookingBenefits($booking);

            $booking->update([
                'status' => $refundAmount > 0 ? 'REFUND_PENDING' : 'CANCELLED',
                'qr_code_hash' => null,
                'cancel_reason' => "[{$reasonCategory}] {$notes} (Diajukan oleh: {$requester->name})",
            ]);

            // Tagihan selisih reschedule yang belum dibayar ikut ditutup (nominalnya keluar dari total order, sesi
            // Snap-nya dibatalkan) — kalau tidak, customer masih bisa membayarnya untuk booking yang sudah batal dan
            // uangnya diam-diam jadi omzet.
            $this->closeRescheduleBills($booking, 'BOOKING_CANCELLED_BY_ADMIN');

            if ($booking->order_id && PadelBooking::where('order_id', $booking->order_id)->whereIn('status', ['PAID', 'LOCKED', 'CHECKED_IN', 'PENDING_PAYMENT', 'PENDING'])->doesntExist()) {
                // Keranjang yang belum dibayar sama sekali: tutup tagihannya supaya pembayaran telat terdeteksi sebagai refund.
                foreach (Payment::where('order_id', $booking->order_id)->where('status', 'PENDING')->get() as $pending) {
                    $log = $this->billPayload($pending);
                    $log['closed_by'] = 'BOOKING_CANCELLED_BY_ADMIN';
                    $pending->update(['status' => 'FAILED', 'payload_log' => $log]);
                }

                // Bagian yang dibayar pakai voucher saldo kembali ke saldonya (bagian tunainya lewat refund di atas).
                $order = \App\Models\Pos\Order::whereKey($booking->order_id)->lockForUpdate()->first();
                if ($order) {
                    app(\App\Services\Finance\VoucherService::class)->restoreCreditForCancelledOrder($order);
                }
            }

            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: $refundAmount > 0 ? 'booking.refund_requested' : 'booking.cancelled',
                description: $refundAmount > 0
                    ? "Membatalkan booking {$booking->booking_code} & mengajukan refund ".\App\Services\Audit\ActivityLogger::rupiah($refundAmount).' ke Antrian Refund'
                    : "Membatalkan booking {$booking->booking_code} (tidak ada uang yang perlu dikembalikan)",
                subject: $booking,
                meta: array_filter([
                    'kode_booking' => $booking->booking_code,
                    'no_order' => $booking->order?->order_number,
                    'lapangan' => $booking->court?->name,
                    'jadwal' => $booking->booking_date->format('d M Y').' '.$booking->start_time->format('H:i').'-'.$booking->end_time->format('H:i'),
                    'nominal_refund' => $refundAmount > 0 ? $refundAmount : null,
                    'kategori_alasan' => $reasonCategory,
                    'catatan' => $notes,
                ], fn ($v) => $v !== null && $v !== ''),
                severity: \App\Services\Audit\ActivityLogger::CRITICAL,
                causer: $requester,
            );

            return [
                'success' => true,
                'refund_amount' => $refundAmount,
                'message' => $refundAmount > 0
                    ? 'Booking dibatalkan. Refund Rp '.number_format($refundAmount, 0, ',', '.').' menunggu persetujuan di Antrian Refund.'
                    : 'Booking dibatalkan. Tidak ada uang yang perlu dikembalikan.',
                'booking' => $booking->fresh(['court', 'order']),
            ];
        });
    }

    /**
     * Tagihan terbuka (PENDING) milik booking ini: tagihan selisih reschedule yang payload booking_id-nya
     * booking ini, atau — kalau tidak ada — tagihan level order (checkout awal, tanpa booking_id).
     * SATU-SATUNYA cara memilih tagihan untuk booking; dulu dipilih "PENDING terbaru" per order sehingga
     * order dengan 2 booking yang sama-sama di-reschedule saling melunasi tagihan yang salah.
     */
    public function pendingBillForBooking(PadelBooking $booking, bool $lock = false): ?Payment
    {
        if (! $booking->order_id) {
            return null;
        }

        $query = Payment::where('order_id', $booking->order_id)->where('status', 'PENDING')->latest()->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $pending = $query->get();

        return $pending->first(fn (Payment $p) => ($this->billPayload($p)['booking_id'] ?? null) === $booking->id)
            ?? $pending->first(fn (Payment $p) => empty($this->billPayload($p)['booking_id']));
    }

    /** Nominal yang masih harus dibayar untuk booking ini (0 kalau tidak ada tagihan terbuka). */
    public function amountDueForBooking(PadelBooking $booking): float
    {
        $bill = $this->pendingBillForBooking($booking);
        if ($bill) {
            return (float) $bill->amount;
        }

        $order = $booking->order;
        if (! $order) {
            return 0.0;
        }
        $paid = (float) Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->sum('amount');

        return max(0.0, round((float) $order->grand_total - $paid, 2));
    }

    /**
     * Tutup semua tagihan selisih reschedule yang belum dibayar milik booking ini (booking dibatalkan / hangus):
     * tagihan → FAILED, nominalnya dikeluarkan dari total order (supaya uang yang telat masuk terdeteksi sebagai
     * kelebihan bayar, bukan omzet), dan sesi Snap Midtrans-nya dibatalkan setelah commit.
     *
     * @return float total nominal tagihan yang ditutup
     */
    protected function closeRescheduleBills(PadelBooking $booking, string $closedBy): float
    {
        if (! $booking->order_id) {
            return 0.0;
        }

        $order = \App\Models\Pos\Order::whereKey($booking->order_id)->lockForUpdate()->first();
        if (! $order) {
            return 0.0;
        }

        $closed = 0.0;
        $gatewayIds = [];
        foreach (Payment::where('order_id', $order->id)->where('status', 'PENDING')->lockForUpdate()->get() as $bill) {
            $log = $this->billPayload($bill);
            if (($log['booking_id'] ?? null) !== $booking->id) {
                continue; // tagihan booking lain / tagihan checkout awal
            }

            $gatewayIds = array_merge($gatewayIds, (array) ($log['midtrans_order_ids'] ?? []));
            $log['closed_by'] = $closedBy;
            $log['closed_at'] = now()->toIso8601String();
            $bill->update(['status' => 'FAILED', 'payload_log' => $log]);
            $closed += (float) $bill->amount;

            if (($log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA' && empty($log['recreated_from_remaining_balance'])) {
                $order->subtotal = max(0, (float) $order->subtotal - (float) ($log['court_delta'] ?? 0));
                $order->tax_amount = max(0, (float) $order->tax_amount - (float) ($log['tax_delta'] ?? 0));
                $order->service_charge = max(0, (float) $order->service_charge - (float) ($log['admin_fee_delta'] ?? 0));
            }
            $order->grand_total = max(0, (float) $order->grand_total - (float) $bill->amount);
        }

        if ($closed > 0) {
            $order->save();
            $this->recomputeOrderPaymentStatus($order);
        }

        $gatewayIds = array_values(array_unique(array_filter($gatewayIds, 'is_string')));
        if ($gatewayIds !== []) {
            // Setelah commit & di luar lock: panggilan HTTP tidak boleh menahan transaksi. Kalau gagal pun aman —
            // pembayaran yang telat masuk ke tagihan FAILED otomatis dibuatkan refund (PaymentOrchestratorService).
            DB::afterCommit(function () use ($gatewayIds) {
                $midtrans = app(\App\Services\Payment\MidtransService::class);
                foreach ($gatewayIds as $id) {
                    $midtrans->cancelTransaction($id);
                }
            });
        }

        return $closed;
    }

    /** PAID kalau uang yang masuk sudah menutup grand_total, PARTIALLY_PAID kalau masih ada sisa. */
    protected function recomputeOrderPaymentStatus(\App\Models\Pos\Order $order): void
    {
        if (in_array($order->payment_status, ['CANCELLED', 'UNPAID'], true)) {
            return;
        }

        $paid = (float) Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->sum('amount');
        $status = $paid >= (float) $order->grand_total - 1 ? 'PAID' : ($paid > 0 ? 'PARTIALLY_PAID' : $order->payment_status);
        if ($status !== $order->payment_status) {
            $order->update(['payment_status' => $status]);
        }
    }

    /**
     * Refund untuk SATU booking = bagian booking ini dari uang yang benar-benar dibayar untuk order-nya, dikurangi refund
     * yang sudah pernah diajukan untuk booking ini, dan tidak pernah melebihi uang order yang belum dikembalikan.
     *
     * Bagian dihitung tetap dari SEMUA booking di order (proporsional nilai lapangan yang sudah dibayar) — termasuk yang
     * sudah dimainkan, hangus, atau dibatalkan. Dulu hanya booking yang masih aktif yang dibagi, sehingga booking terakhir
     * mendapat SELURUH sisa uang order: kalau saudaranya sudah dimainkan atau refund-nya ditolak (jadi voucher), booking
     * terakhir di-refund senilai dua lapangan. Kelebihan bayar (di atas total order) bukan bagian booking — itu refund
     * tersendiri.
     */
    public function refundableAmountForBooking(PadelBooking $booking): float
    {
        $order = $booking->order;
        if (! $order) {
            return 0.0;
        }

        $paid = (float) Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->sum('amount');
        $refunds = Refund::where('order_id', $order->id)->with('voucher:id,refund_id')->get(['id', 'padel_booking_id', 'refund_amount', 'status']);

        // Uang order yang sudah dikembalikan / sedang diajukan / sudah dijadikan voucher saldo.
        $allocated = (float) $refunds
            ->filter(fn (Refund $r) => in_array($r->status, ['PENDING', 'APPROVED', 'PROCESSED'], true) || ($r->status === 'REJECTED' && $r->voucher))
            ->sum('refund_amount');
        $remaining = max(0.0, round($paid - $allocated, 2));
        if ($remaining <= 0) {
            return 0.0;
        }

        $bookings = PadelBooking::where('order_id', $order->id)->get();
        if (! $bookings->contains('id', $booking->id)) {
            $bookings->push($booking);
        }

        $base = (float) $order->grand_total > 0 ? min($paid, (float) $order->grand_total) : $paid;
        $value = fn (PadelBooking $b) => max(0.0, (float) $b->court_fee - $this->unpaidCourtDeltaFor($b));
        $total = $bookings->sum($value);
        $share = $total > 0 ? $base * $value($booking) / $total : $base / max(1, $bookings->count());

        $alreadyRequested = (float) $refunds->where('padel_booking_id', $booking->id)->sum('refund_amount');

        return round(max(0.0, min($share - $alreadyRequested, $remaining)), 2);
    }

    /** Bagian tarif lapangan dari tagihan selisih booking ini yang BELUM dibayar (court_fee sudah menghitungnya). */
    protected function unpaidCourtDeltaFor(PadelBooking $booking): float
    {
        if (! $booking->order_id) {
            return 0.0;
        }

        return (float) Payment::where('order_id', $booking->order_id)->where('status', 'PENDING')->get()
            ->filter(fn (Payment $p) => ($this->billPayload($p)['booking_id'] ?? null) === $booking->id)
            ->sum(fn (Payment $p) => (float) ($this->billPayload($p)['court_delta'] ?? 0));
    }

    protected function billPayload(Payment $payment): array
    {
        return is_array($payment->payload_log) ? $payment->payload_log : [];
    }
}
