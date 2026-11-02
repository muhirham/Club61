<?php

namespace App\Services\Coaching;

use App\Models\Coaching\CoachingAcademy;
use App\Models\Coaching\CoachingBooking;
use App\Models\Coaching\CoachingSettlement;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Pos\Order;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Padel\PadelBookingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Modul 31 — Tagihan Coaching. Academy "Tagihan Berkala": booking coaching di kasir tidak dibayar saat itu, tetapi tercatat
 * pada tagihan academy dan dilunasi sekaligus di kasir sesuai periode (uang tetap hanya diterima di POS).
 *
 * Booking tagihan memakai status yang sudah ada (PENDING_PAYMENT, order UNPAID) supaya cek bentrok slot, jadwal, dan
 * batal tanpa refund tetap berjalan seperti booking lain. Bedanya hanya di sini (satu tempat):
 *  - tidak dilepas pembersih booking kedaluwarsa & tidak bisa dilepas customer dari keranjang,
 *  - boleh check-in walau belum lunas,
 *  - tidak bisa dibayar online — dilunasi di kasir,
 *  - dibatalkan lewat cancelTab() dengan batas waktu yang sama dengan reschedule; lewat batas / tidak datang = tetap ditagih,
 *  - dipindah jadwal dengan aturan reschedule global; tagihannya mengikuti tarif jadwal baru (repriceForReschedule).
 */
class CoachingBillingService
{
    /** Status booking yang tetap dihitung sebagai sesi tagihan (sudah / akan dipakai). */
    public const BILLABLE_STATUSES = ['PENDING_PAYMENT', 'CHECKED_IN', 'COMPLETED'];

    /** Metode Pelunasan Tagihan Coaching (kode PosPaymentProof). */
    public const SETTLEMENT_METHODS = ['QRIS', 'EDC_BCA', 'TRANSFER_BANK'];

    /** Bukti transfer pelunasan: disk privat, hanya bisa dibuka pemegang izin. */
    public const PROOF_DISK = 'local';

    public static function isTabBooking(PadelBooking $booking): bool
    {
        if (! $booking->coaching_booking_id) {
            return false;
        }

        return CoachingBooking::whereKey($booking->coaching_booking_id)->where('billing', CoachingAcademy::BILLING_TAB)->exists();
    }

    /** Query booking padel: buang booking tagihan coaching (untuk pembersih otomatis & pelepasan keranjang). */
    public static function withoutTab(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('coaching_booking_id')
            ->orWhereDoesntHave('coachingBooking', fn (Builder $c) => $c->where('billing', CoachingAcademy::BILLING_TAB)));
    }

    /** Sesi tagihan yang belum lunas (opsional: satu academy). */
    public function unpaidSessions(?string $academyId = null): Builder
    {
        return PadelBooking::query()
            ->whereIn('status', self::BILLABLE_STATUSES)
            ->whereHas('coachingBooking', fn (Builder $c) => $c->where('billing', CoachingAcademy::BILLING_TAB)
                ->when($academyId, fn (Builder $q) => $q->where('academy_id', $academyId)))
            ->whereHas('order', fn (Builder $o) => $o->where('payment_status', 'UNPAID'));
    }

    /**
     * Sesi belum lunas yang sudah lewat jatuh tempo: periode harian / mingguan / 2 mingguan = due_at lewat; per shift =
     * shift kasir tempat booking dibuat sudah ditutup.
     *
     * @return array{sessions: int, amount: float}
     */
    public function overdue(CoachingAcademy $academy): array
    {
        $now = now();
        $bookings = $this->unpaidSessions($academy->id)
            ->whereHas('coachingBooking', fn (Builder $c) => $c->where(fn (Builder $q) => $q
                ->where('due_at', '<', $now)
                ->orWhere(fn (Builder $s) => $s->where('billing_cycle', CoachingAcademy::CYCLE_SHIFT)
                    ->whereHas('cashierShift', fn (Builder $shift) => $shift->where('status', '!=', 'OPEN')))))
            ->get(['id', 'order_id']);

        return [
            'sessions' => $bookings->count(),
            'amount' => (float) Order::whereIn('id', $bookings->pluck('order_id')->unique())->sum('grand_total'),
        ];
    }

    /** Sakelar "blok booking baru" aktif dan masih ada tagihan lewat jatuh tempo → ditolak di server. */
    public function assertCanBook(CoachingAcademy $academy): void
    {
        if (! $academy->block_when_overdue) {
            return;
        }

        $overdue = $this->overdue($academy);
        if ($overdue['sessions'] > 0) {
            throw new HttpException(422, "{$academy->name} memiliki tagihan yang melewati jatuh tempo ({$overdue['sessions']} sesi, Rp "
                .number_format($overdue['amount'], 0, ',', '.').'). Booking baru dapat dibuat setelah tagihan dilunasi melalui Pelunasan Tagihan Coaching.');
        }
    }

    /**
     * Jatuh tempo sesi menurut periode academy, dihitung dari jam main (jam dinding venue, sama dengan start_time booking
     * & batas reschedule). Per shift → null (jatuh tempo = saat shift ditutup). 2 mingguan dihitung dari tanggal mulai
     * siklus academy (tanpa tanggal mulai: Senin minggu academy dibuat).
     */
    public function dueAt(CoachingAcademy $academy, CarbonInterface $startTime): ?Carbon
    {
        $play = Carbon::parse($startTime);

        return match ($academy->billing_cycle) {
            CoachingAcademy::CYCLE_DAILY => $play->copy()->endOfDay(),
            CoachingAcademy::CYCLE_WEEKLY => $play->copy()->endOfWeek(CarbonInterface::SUNDAY),
            CoachingAcademy::CYCLE_BIWEEKLY => $this->biweeklyEnd($academy, $play),
            default => null,
        };
    }

    private function biweeklyEnd(CoachingAcademy $academy, Carbon $play): Carbon
    {
        $anchor = $academy->billing_anchor_date
            ? Carbon::parse($academy->billing_anchor_date->toDateString())
            : Carbon::parse(($academy->created_at ?? $play)->toDateString())->startOfWeek(CarbonInterface::MONDAY);
        $cycles = (int) floor($anchor->diffInDays($play->copy()->startOfDay(), false) / 14);

        return $anchor->copy()->addDays($cycles * 14 + 13)->endOfDay();
    }

    /**
     * Reschedule sesi tagihan (belum dibayar): tidak ada selisih yang ditagih atau hangus — tarif sesi diganti tarif
     * jadwal baru, item order ikut, lalu total order dihitung ulang (pajak & biaya layanan sekali per transaksi, sama
     * dengan saat dicatat). Booking belum disimpan di sini; pemanggil (adminRescheduleBooking) yang menyimpan.
     */
    public function repriceForReschedule(PadelBooking $booking, Order $order, float $newCourtFee, string $courtName): void
    {
        $delta = round($newCourtFee - (float) $booking->court_fee, 2);
        $booking->court_fee = $newCourtFee;
        $booking->total_amount = round((float) $booking->total_amount + $delta, 2);

        $order->items()->where('item_type', 'PADEL')->where('reference_id', $booking->id)->update([
            'item_name' => 'Sewa Lapangan Coaching · '.$courtName,
            'unit_price' => $newCourtFee,
            'subtotal' => $newCourtFee,
        ]);

        $calc = app(\App\Services\Finance\TaxAndFeeService::class)->calculate(
            subtotal: (float) $order->subtotal + $delta,
            discountAmount: (float) $order->discount_amount,
            channel: 'POS_WALKIN',
            module: 'PADEL'
        );
        $order->update([
            'subtotal' => $calc['subtotal'],
            'tax_amount' => $calc['tax_amount'],
            'service_charge' => $calc['admin_fee_amount'],
            'grand_total' => $calc['grand_total'],
        ]);
    }

    /**
     * Tambah Alat ke sesi tagihan (BookingAddonService): alat masuk order tagihan yang belum dilunasi sebagai item
     * EQUIPMENT, pajak & biaya layanan dihitung ulang sekali per transaksi. Order sudah dikunci & dicek UNPAID oleh pemanggil.
     *
     * @param  list<array{equipment_id: string, name: string, quantity: int, unit_price: float, subtotal: float}>  $items
     */
    public function addEquipmentToTab(Order $order, float $itemsSubtotal, array $items): void
    {
        foreach ($items as $item) {
            $order->items()->create([
                'item_type' => 'EQUIPMENT',
                'reference_id' => $item['equipment_id'],
                'item_name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['subtotal'],
            ]);
        }

        $calc = self::tabTotalsWith($order, $itemsSubtotal);
        $order->update([
            'subtotal' => $calc['subtotal'],
            'tax_amount' => $calc['tax_amount'],
            'service_charge' => $calc['admin_fee_amount'],
            'grand_total' => $calc['grand_total'],
        ]);
    }

    /** Total order tagihan bila ditambah $extraSubtotal (rumus yang sama dengan repriceForReschedule). */
    public static function tabTotalsWith(Order $order, float $extraSubtotal): array
    {
        return app(\App\Services\Finance\TaxAndFeeService::class)->calculate(
            subtotal: (float) $order->subtotal + $extraSubtotal,
            discountAmount: (float) $order->discount_amount,
            channel: 'POS_WALKIN',
            module: 'PADEL'
        );
    }

    /**
     * Jatuh tempo dihitung ulang dari jam main paling awal setelah reschedule, dengan periode yang tersimpan saat sesi
     * dicatat. Per shift tetap mengikuti shift tempat sesi dicatat (tanpa due_at).
     */
    public function refreshDueAt(?string $coachingBookingId): void
    {
        $record = $coachingBookingId ? CoachingBooking::with('academy')->find($coachingBookingId) : null;
        if (! $record || ! $record->academy || $record->billing_cycle === CoachingAcademy::CYCLE_SHIFT) {
            return;
        }

        $firstStart = PadelBooking::where('coaching_booking_id', $record->id)->whereIn('status', self::BILLABLE_STATUSES)->min('start_time');
        if (! $firstStart) {
            return;
        }

        $academy = clone $record->academy;
        $academy->billing_cycle = $record->billing_cycle;
        $record->update(['due_at' => $this->dueAt($academy, Carbon::parse($firstStart))]);
    }

    /** Data tagihan yang disimpan di booking coaching saat dibuat (snapshot cara bayar academy). */
    public function stamp(CoachingBooking $record, CoachingAcademy $academy, CarbonInterface $firstStart, ?PosCashierShift $shift): void
    {
        $record->update([
            'academy_id' => $academy->id,
            'billing' => CoachingAcademy::BILLING_TAB,
            'billing_cycle' => $academy->billing_cycle,
            'cashier_shift_id' => $shift?->id,
            'due_at' => $this->dueAt($academy, $firstStart),
        ]);
    }

    /**
     * Daftar tagihan belum lunas satu academy, per transaksi booking (satu order bisa berisi beberapa jam / lapangan).
     *
     * @return \Illuminate\Support\Collection<int, array{order_id: string, code: string, date: string, schedule: string, court: string, coach: string, amount: float, due: ?string, overdue: bool}>
     */
    public function openBills(CoachingAcademy $academy): \Illuminate\Support\Collection
    {
        $sessions = $this->unpaidSessions($academy->id)
            ->with(['court:id,name', 'order:id,grand_total', 'coachingBooking.coaches', 'coachingBooking.cashierShift:id,status'])
            ->orderBy('start_time')->get();

        return $sessions->groupBy('order_id')->map(function ($group) {
            $first = $group->first();
            $record = $first->coachingBooking;
            $overdue = $record->due_at ? $record->due_at->lt(now())
                : ($record->billing_cycle === CoachingAcademy::CYCLE_SHIFT && $record->cashierShift && $record->cashierShift->status !== 'OPEN');

            return [
                'order_id' => (string) $first->order_id,
                'code' => $first->booking_code,
                'date' => $first->start_time->translatedFormat('D, d M Y'),
                'schedule' => $group->map(fn (PadelBooking $b) => $b->start_time->format('H:i').'–'.$b->end_time->format('H:i'))->implode(', '),
                'court' => $group->pluck('court.name')->filter()->unique()->implode(', '),
                'coach' => $record->coachSummary(),
                'amount' => (float) $first->order->grand_total,
                'due' => $record->due_at?->translatedFormat('d M Y'),
                'overdue' => (bool) $overdue,
            ];
        })->values();
    }

    /**
     * Pelunasan Tagihan Coaching di kasir POS Walk-In: beberapa transaksi booking academy dibayar sekaligus dengan satu
     * bukti (QRIS / EDC BCA / Transfer Bank + foto bukti). Tiap order dilunasi lewat PaymentOrchestratorService (satu
     * pembayaran per order, bukti sama) supaya Buku Transaksi, laporan, refund & Tutup Shift memakai jalur yang sama.
     *
     * @param  list<string>  $orderIds
     * @param  array<string, mixed>  $proofInput  isian bukti PosPaymentProof::validate()
     * @param  float|null  $expectedTotal  total yang tampil di layar kasir — tagihan berubah (mis. sesi dipindah) → ditolak
     */
    public function settle(CoachingAcademy $academy, array $orderIds, string $method, array $proofInput, \Illuminate\Http\UploadedFile|\Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null $transferProof, User $cashier, ?float $expectedTotal = null): CoachingSettlement
    {
        if (! $cashier->can('settle_coaching_bill')) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [settle_coaching_bill] untuk menerima Pelunasan Tagihan Coaching.');
        }
        $method = strtoupper(trim($method));
        if (! in_array($method, self::SETTLEMENT_METHODS, true)) {
            throw new HttpException(422, 'Metode pelunasan tidak dikenali. Pilih QRIS, Mesin EDC BCA, atau Transfer Bank.');
        }
        $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds))));
        if ($orderIds === []) {
            throw new HttpException(422, 'Pilih minimal satu sesi yang akan dilunasi.');
        }
        if ($method === 'TRANSFER_BANK' && ! $transferProof) {
            throw new HttpException(422, 'Unggah foto bukti transfer untuk pelunasan melalui Transfer Bank.');
        }
        $shift = PosCashierShift::getActiveShift('PADEL_FRONTDESK');
        if (! $shift) {
            throw new HttpException(422, 'Tidak ada shift kasir yang aktif untuk loket [PADEL_FRONTDESK]. Buka shift terlebih dahulu.');
        }

        $proofPath = $transferProof ? \App\Services\Media\SecureImageUploader::store($transferProof, 'coaching/transfer', self::PROOF_DISK) : null;

        try {
            return DB::transaction(function () use ($academy, $orderIds, $method, $proofInput, $proofPath, $shift, $cashier, $expectedTotal) {
                $orders = Order::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
                $billable = $this->unpaidSessions($academy->id)->whereIn('order_id', $orderIds)->pluck('order_id')->unique();
                if ($orders->count() !== count($orderIds) || $billable->count() !== count($orderIds)
                    || $orders->contains(fn (Order $o) => $o->payment_status !== 'UNPAID')) {
                    throw new HttpException(409, 'Sebagian sesi sudah dilunasi, dibatalkan, atau bukan tagihan '.$academy->name.'. Muat ulang daftar tagihan.');
                }

                $total = round((float) $orders->sum('grand_total'), 2);
                if ($expectedTotal !== null && abs($total - $expectedTotal) > 1) {
                    throw new HttpException(409, 'Total tagihan berubah dari Rp '.number_format($expectedTotal, 0, ',', '.').' menjadi Rp '.number_format($total, 0, ',', '.').'. Periksa lagi rincian di layar sebelum menerima pembayaran.');
                }
                $proofPayload = \App\Services\Pos\PosPaymentProof::validate($method, $proofInput, $total);

                $settlement = CoachingSettlement::create([
                    'settlement_number' => 'TC-'.now()->format('ymd').'-'.strtoupper(\Illuminate\Support\Str::random(5)),
                    'academy_id' => $academy->id,
                    'academy_name' => $academy->name,
                    'payment_method' => $method,
                    'total_amount' => $total,
                    'session_count' => PadelBooking::whereIn('order_id', $orderIds)->count(),
                    'reference' => $proofPayload['qris_details']['rrn'] ?? $proofPayload['edc_details']['approval_code'] ?? $proofPayload['transfer_details']['reference'] ?? null,
                    'sender_name' => $proofPayload['qris_details']['sender_name'] ?? $proofPayload['transfer_details']['sender_name'] ?? null,
                    'proof_path' => $proofPath,
                    'cashier_shift_id' => $shift->id,
                    'created_by' => $cashier->id,
                ]);

                $orchestrator = app(\App\Services\Payment\PaymentOrchestratorService::class);
                foreach ($orders->values() as $i => $order) {
                    $orchestrator->markOrderAsPaid($order, [
                        'payment_gateway' => 'CASHIER_POS',
                        'counter' => 'PADEL_FRONTDESK',
                        'payment_method' => $method,
                        'amount' => (float) $order->grand_total,
                        'transaction_id' => $settlement->settlement_number.'-'.($i + 1),
                        'cashier_id' => $cashier->id,
                        'payload_log' => [
                            'settled_by' => $cashier->id,
                            'settled_by_name' => $cashier->name,
                            'settled_at' => now()->toIso8601String(),
                            'channel' => 'FRONTDESK_CASHIER',
                            'coaching_settlement_id' => $settlement->id,
                            'coaching_settlement_number' => $settlement->settlement_number,
                        ] + $proofPayload,
                    ]);
                }
                CoachingBooking::whereIn('order_id', $orderIds)->update(['settlement_id' => $settlement->id]);

                ActivityLogger::record('COACHING', 'coaching_tab.settled',
                    "Menerima Pelunasan Tagihan Coaching {$settlement->settlement_number} {$academy->name}: {$settlement->session_count} sesi, Rp ".number_format($total, 0, ',', '.'),
                    subject: $settlement,
                    meta: ['metode' => \App\Services\Pos\PosPaymentProof::METHODS[$method], 'referensi' => $settlement->reference, 'transaksi' => count($orderIds)],
                    subjectLabel: $settlement->settlement_number,
                );

                return $settlement;
            });
        } catch (\Throwable $e) {
            if ($proofPath) {
                \Illuminate\Support\Facades\Storage::disk(self::PROOF_DISK)->delete($proofPath);
            }

            throw $e;
        }
    }

    /**
     * Batalkan satu transaksi booking tagihan (semua jamnya) sebelum batas waktu reschedule: keluar dari tagihan, tanpa
     * refund (belum ada uang). Lewat batas / sesi sudah dimulai / sudah check-in → ditolak, tetap ditagih.
     */
    public function cancelTab(PadelBooking $booking, string $reason, User $by): void
    {
        if (! $by->can('book_coaching')) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [book_coaching] untuk membatalkan sesi coaching.');
        }
        $reason = trim(mb_substr($reason, 0, 255));
        if (mb_strlen($reason) < 5) {
            throw new HttpException(422, 'Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }

        $bookingService = app(PadelBookingService::class);
        $released = [];

        DB::transaction(function () use ($booking, $reason, $bookingService, &$released) {
            $order = Order::whereKey($booking->order_id)->lockForUpdate()->first();
            $sessions = PadelBooking::where('order_id', $booking->order_id)->lockForUpdate()->get();

            if (! $order || ! self::isTabBooking($booking)) {
                throw new HttpException(422, 'Booking ini bukan sesi tagihan coaching.');
            }
            if ($order->payment_status !== 'UNPAID') {
                throw new HttpException(422, 'Tagihan sesi ini sudah dilunasi atau dibatalkan. Gunakan Batalkan & Ajukan Refund.');
            }
            if ($sessions->contains(fn (PadelBooking $b) => $b->status !== 'PENDING_PAYMENT')) {
                throw new HttpException(422, 'Sesi sudah check-in atau selesai sehingga tetap tercatat pada tagihan.');
            }
            $deadline = $sessions->map(fn (PadelBooking $b) => PadelBookingService::rescheduleDeadline($b))->min();
            if (now()->gte($deadline)) {
                throw new HttpException(422, 'Batas pembatalan sudah terlewati ('.PadelBookingService::rescheduleCutoffLabel($sessions->first())
                    .' sebelum jam main). Sesi tetap tercatat pada tagihan academy.');
            }

            $order->update(['payment_status' => 'CANCELLED']);
            foreach ($sessions as $session) {
                $bookingService->reverseBookingBenefits($session);
                $session->update(['status' => 'CANCELLED']);
                $released[] = $session;
            }
            // Bola (habis pakai) dipotong saat booking → stoknya dikembalikan.
            PadelBookingEquipment::with('equipment')->where('order_id', $order->id)->whereNotNull('stock_deducted_at')->get()
                ->each(function (PadelBookingEquipment $row) {
                    $row->equipment?->increment('stock_quantity', (int) $row->quantity);
                    $row->update(['stock_deducted_at' => null]);
                });

            $first = $sessions->sortBy('start_time')->first();
            ActivityLogger::record('COACHING', 'coaching_tab.cancelled',
                "Membatalkan sesi tagihan berkala coaching {$first->booking_code} (".$sessions->count().' slot, Rp '.number_format((float) $order->grand_total, 0, ',', '.').')',
                subject: $first,
                meta: ['order' => $order->order_number, 'alasan' => $reason, 'jadwal' => $first->start_time->format('d/m/Y H:i')],
                severity: ActivityLogger::WARNING,
                subjectLabel: $first->booking_code,
            );
        });

        foreach ($released as $session) {
            $bookingService->releaseSlotCacheLocks($session);
        }
    }
}
