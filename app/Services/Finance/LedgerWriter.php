<?php

namespace App\Services\Finance;

use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\Refund;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Services\Payment\PaymentOrchestratorService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Satu-satunya penulis Buku Transaksi (Modul 17 §3.2). Dipanggil di DALAM transaksi database pelunasan / refund:
 * kalau pelunasan di-rollback, baris buku ikut hilang; kalau penulisan buku gagal, pelunasan ikut gagal.
 *
 * Aturan pembagian (PRD §3.4): penjualan kotor per kategori dari order_items, diskon order dibagi proporsional
 * terhadap penjualan kotor, pajak & biaya layanan proporsional terhadap penjualan bersih. Pelunasan selisih
 * reschedule dibagi dari payload_log tagihannya sendiri (court_delta / tax_delta / admin_fee_delta). Semua hitungan
 * dalam sen (integer); nominal dibulatkan ke rupiah, sisa pembulatan ke baris dengan penjualan bersih terbesar.
 * Invarian: jumlah total_amount satu pembayaran = payments.amount persis.
 */
class LedgerWriter
{
    public const ITEM_CATEGORIES = [
        'PADEL' => 'SEWA_LAPANGAN',
        'EQUIPMENT' => 'ADDON_PADEL',
        'EQUIPMENT_FINE' => 'DENDA_ALAT',
        'MEMBERSHIP' => 'MEMBERSHIP',
        'FNB' => 'FNB',
        'WELLNESS' => 'WELLNESS',
        'GYM' => 'GYM',
        'MERCH' => 'MERCH',
        'SALON' => 'SALON',
        // Modul 34: pembayaran Tagihan Bulanan perusahaan (rekap jam main sebulan).
        'MONTHLY_BILL' => 'SEWA_LAPANGAN',
    ];

    /** Status pembayaran yang uangnya benar-benar masuk. */
    public const MONEY_IN_STATUSES = ['SUCCESS', PaymentOrchestratorService::DUPLICATE_STATUS];

    /**
     * Pembayaran yang dicatat di buku: uang sungguhan. MOCK = simulator; legacy_backfill = catatan pengganti untuk
     * booking PAID dari sebelum ada tabel payments (dibuat saat refund, bukan uang baru — lihat adminCancelBooking).
     */
    public static function isCountable(Payment $payment): bool
    {
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];

        return strtoupper((string) $payment->payment_gateway) !== 'MOCK' && empty($log['legacy_backfill']);
    }

    /**
     * Catat uang masuk satu pembayaran. Idempoten: pembayaran yang sudah tercatat dilewati.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function recordPayment(Payment $payment, string $entryType = LedgerEntry::TYPE_PAYMENT, ?CarbonInterface $occurredAt = null): Collection
    {
        if (! in_array($payment->status, self::MONEY_IN_STATUSES, true) || ! self::isCountable($payment)) {
            return collect();
        }

        if (LedgerEntry::where('payment_id', $payment->id)->whereIn('entry_type', [LedgerEntry::TYPE_PAYMENT, LedgerEntry::TYPE_OVERPAYMENT])->exists()) {
            return collect();
        }

        $order = Order::withTrashed()->with(['items', 'user'])->findOrFail($payment->order_id);
        $amount = self::cents($payment->amount);
        $basis = $this->paymentBasis($order, $payment);
        $parts = $this->allocate($basis, $amount);

        $benefit = $basis['kind'] === 'ORDER' ? $this->benefitCents($order, $payment) : 0;
        $benefitCategory = isset($parts['SEWA_LAPANGAN']) ? 'SEWA_LAPANGAN' : array_key_first($parts);
        $common = $this->snapshot($order, $payment) + [
            'occurred_at' => $occurredAt ?? $payment->paid_at ?? now(),
            'entry_type' => $entryType,
            'source' => $basis['source'],
            'payment_id' => $payment->id,
        ];

        $meta = $this->orderMeta($order, $payment, $basis);
        $rows = collect();
        foreach ($parts as $category => $part) {
            $rows->push(LedgerEntry::create($common + [
                'category' => $category,
                'dedupe_key' => "P:{$payment->id}:{$category}",
                'gross_amount' => self::rupiah($part['gross']),
                'discount_amount' => self::rupiah($part['discount']),
                'net_amount' => self::rupiah($part['net']),
                'service_amount' => self::rupiah($part['service']),
                'tax_amount' => self::rupiah($part['tax']),
                'total_amount' => self::rupiah($part['total']),
                'benefit_amount' => self::rupiah($category === $benefitCategory ? $benefit : 0),
                'meta' => $meta + ['items' => $basis['item_names'][$category] ?? null],
            ]));
        }

        $this->assertBalanced($rows, $amount, "pembayaran {$payment->transaction_id}");

        return $rows;
    }

    /**
     * Catat uang keluar satu refund PROCESSED (baris negatif), dibagi dengan proporsi baris pembayaran yang direfund —
     * refund raket mengurangi kategori add-on, bukan lapangan. Idempoten per refund.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function recordRefund(Refund $refund, ?CarbonInterface $occurredAt = null): Collection
    {
        if ($refund->status !== 'PROCESSED' || LedgerEntry::where('refund_id', $refund->id)->exists()) {
            return collect();
        }

        $payment = Payment::find($refund->payment_id);
        if ($payment && strtoupper((string) $payment->payment_gateway) === 'MOCK') {
            return collect();
        }

        $paymentRows = collect();
        if ($payment) {
            // Pembayaran yang belum tercatat (data sebelum buku ada & belum di-backfill) dicatat dulu supaya refund
            // punya pasangan dan proporsi kategorinya benar.
            $this->recordPayment($payment);
            $paymentRows = LedgerEntry::where('payment_id', $payment->id)
                ->whereIn('entry_type', [LedgerEntry::TYPE_PAYMENT, LedgerEntry::TYPE_OVERPAYMENT])
                ->orderBy('dedupe_key')
                ->get();
        }

        $order = Order::withTrashed()->with(['items', 'user'])->findOrFail($refund->order_id);
        $amount = self::cents($refund->refund_amount);

        if ($paymentRows->isNotEmpty() && $paymentRows->sum(fn ($r) => abs(self::cents($r->total_amount))) > 0) {
            $source = $paymentRows->first()->source;
            $templates = $paymentRows->mapWithKeys(fn (LedgerEntry $r) => [$r->category => [
                'gross' => self::cents($r->gross_amount), 'discount' => self::cents($r->discount_amount),
                'net' => self::cents($r->net_amount), 'service' => self::cents($r->service_amount),
                'tax' => self::cents($r->tax_amount), 'total' => self::cents($r->total_amount),
            ]])->all();
        } else {
            // Pembayaran legacy / tanpa baris buku: seluruh refund dianggap penjualan bersih kategori order.
            $category = $this->fallbackCategory($order);
            $source = $this->orderSource($order, $payment);
            $templates = [$category => ['gross' => $amount, 'discount' => 0, 'net' => $amount, 'service' => 0, 'tax' => 0, 'total' => $amount]];
        }

        $shares = self::split($amount, array_map(fn ($t) => $t['total'], $templates));
        $common = ($payment ? $this->snapshot($order, $payment) : $this->orderSnapshot($order)) + [
            'occurred_at' => $occurredAt ?? $refund->processed_at ?? now(),
            'entry_type' => LedgerEntry::TYPE_REFUND,
            'source' => $source,
            'payment_id' => $refund->payment_id,
            'refund_id' => $refund->id,
        ];

        $rows = collect();
        foreach ($templates as $category => $t) {
            $share = $shares[$category];
            $ratio = $t['total'] !== 0 ? $share / $t['total'] : 0;
            $tax = self::roundToRupiah($t['tax'] * $ratio);
            $service = self::roundToRupiah($t['service'] * $ratio);
            $net = $share - $tax - $service;
            $discount = self::roundToRupiah($t['discount'] * $ratio);

            $rows->push(LedgerEntry::create($common + [
                'category' => $category,
                'dedupe_key' => "R:{$refund->id}:{$category}",
                'gross_amount' => -self::rupiah($net + $discount),
                'discount_amount' => -self::rupiah($discount),
                'net_amount' => -self::rupiah($net),
                'service_amount' => -self::rupiah($service),
                'tax_amount' => -self::rupiah($tax),
                'total_amount' => -self::rupiah($share),
                'benefit_amount' => 0,
                'meta' => array_filter([
                    'reason' => mb_substr((string) $refund->reason, 0, 500),
                    'refund_of' => $payment?->transaction_id,
                    'refund_method' => $refund->refund_method,
                    'refund_reference' => $refund->refund_reference,
                    'processed_by' => $refund->processed_by_id,
                ]),
            ]));
        }

        $this->assertBalanced($rows, -$amount, "refund {$refund->id}");

        return $rows;
    }

    /**
     * Komposisi yang dibayar oleh satu pembayaran (dalam sen).
     *
     * @return array{kind: string, source: string, lines: array<string, int>, discount: int, tax: int, service: int, item_names: array<string, array>}
     */
    private function paymentBasis(Order $order, Payment $payment): array
    {
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];

        if (($log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA') {
            $source = $this->isCounterPayment($order, $payment) ? 'RESCHEDULE_DELTA_POS' : 'RESCHEDULE_DELTA_ONLINE';

            if (array_key_exists('court_delta', $log)) {
                return [
                    'kind' => 'RESCHEDULE_DELTA',
                    'source' => $source,
                    'lines' => [($this->coachingBookingIds($order) !== [] ? 'COACHING' : 'SEWA_LAPANGAN') => max(0, self::cents($log['court_delta']))],
                    'discount' => 0,
                    'tax' => max(0, self::cents($log['tax_delta'] ?? 0)),
                    'service' => max(0, self::cents($log['admin_fee_delta'] ?? 0)),
                    'item_names' => [],
                ];
            }

            // Tagihan sisa yang dibuat ulang tanpa rincian: proporsi mengikuti order.
            return ['kind' => 'RESCHEDULE_DELTA', 'source' => $source] + $this->orderComposition($order, $payment);
        }

        return ['kind' => 'ORDER', 'source' => $this->orderSource($order, $payment)] + $this->orderComposition($order, $payment);
    }

    /**
     * Komposisi order SAAT TRANSAKSI: total order sekarang ikut menghitung tagihan selisih reschedule (dinaikkan saat
     * tagihan dibuat), jadi seluruh rincian selisih dikurangkan — sama dengan struk penjualan kasir.
     */
    private function orderComposition(Order $order, Payment $payment): array
    {
        $deltaBills = Payment::where('order_id', $order->id)
            ->where('id', '!=', $payment->id)
            ->whereIn('status', ['SUCCESS', 'PENDING'])
            ->get()
            ->filter(function (Payment $p) {
                $log = is_array($p->payload_log) ? $p->payload_log : [];

                return ($log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA' && empty($log['recreated_from_remaining_balance']);
            });
        $sumDelta = fn (string $key) => (int) $deltaBills->sum(fn (Payment $p) => self::cents($p->payload_log[$key] ?? 0));

        $weights = [];
        $names = [];
        $coachingBookingIds = $this->coachingBookingIds($order);
        foreach ($order->items as $item) {
            $category = self::ITEM_CATEGORIES[$item->item_type] ?? 'LAINNYA';
            // Slot coaching tetap item PADEL (alur bayar/refund sama) tapi dibukukan sebagai sewa lapangan coaching.
            if ($item->item_type === 'PADEL' && in_array((string) $item->reference_id, $coachingBookingIds, true)) {
                $category = 'COACHING';
            }
            $weights[$category] = ($weights[$category] ?? 0) + max(0, self::cents($item->subtotal));
            if (count($names[$category] ?? []) < 15) {
                $names[$category][] = $item->item_name.' x'.$item->quantity;
            }
        }

        $gross = max(0, self::cents($order->subtotal) - $sumDelta('court_delta'));
        if (array_sum($weights) <= 0) {
            $weights = [$this->fallbackCategory($order) => 1];
        }

        return [
            'lines' => self::split($gross, $weights),
            'discount' => max(0, self::cents($order->discount_amount)),
            'tax' => max(0, self::cents($order->tax_amount) - $sumDelta('tax_delta')),
            'service' => max(0, self::cents($order->service_charge) - $sumDelta('admin_fee_delta')),
            'item_names' => $names,
        ];
    }

    /**
     * Bagi nominal pembayaran ke kategori sesuai basis.
     *
     * @return array<string, array{gross: int, discount: int, net: int, service: int, tax: int, total: int}>
     */
    private function allocate(array $basis, int $amount): array
    {
        $lines = array_filter($basis['lines'], fn ($v) => $v > 0) ?: [array_key_first($basis['lines']) ?? 'LAINNYA' => 0];
        $gross = array_sum($lines);
        $discount = min($basis['discount'], $gross);
        $net = $gross - $discount;
        $base = $net + $basis['tax'] + $basis['service'];

        if ($base > 0) {
            $ratio = $amount / $base;
            $tTax = self::roundToRupiah($basis['tax'] * $ratio);
            $tService = self::roundToRupiah($basis['service'] * $ratio);
            $tDiscount = self::roundToRupiah($discount * $ratio);
        } elseif ($amount === 0) {
            // Ditanggung penuh voucher (kotor = diskon): komposisi apa adanya, bersih 0.
            $tTax = 0;
            $tService = 0;
            $tDiscount = $discount;
        } else {
            $tTax = 0;
            $tService = 0;
            $tDiscount = 0;
        }

        $tNet = $amount - $tTax - $tService;
        if ($tNet < 0) {
            $tTax = max(0, $tTax + $tNet);
            $tService = $amount - $tTax;
            $tNet = 0;
        }

        $discounts = [];
        foreach ($lines as $category => $lineGross) {
            $discounts[$category] = $gross > 0 ? $discount * $lineGross / $gross : 0;
        }
        $netWeights = array_map(fn ($c) => max(0, $lines[$c] - $discounts[$c]), array_combine(array_keys($lines), array_keys($lines)));
        if (array_sum($netWeights) <= 0) {
            $netWeights = $lines;
        }

        $nets = self::split($tNet, $netWeights);
        $taxes = self::split($tTax, $netWeights);
        $services = self::split($tService, $netWeights);
        $discountParts = self::split($tDiscount, $lines);

        $parts = [];
        foreach (array_keys($lines) as $category) {
            $parts[$category] = [
                'gross' => $nets[$category] + $discountParts[$category],
                'discount' => $discountParts[$category],
                'net' => $nets[$category],
                'service' => $services[$category],
                'tax' => $taxes[$category],
                'total' => $nets[$category] + $services[$category] + $taxes[$category],
            ];
        }

        return $parts;
    }

    /**
     * Bagi $total (sen) sesuai bobot: tiap bagian dibulatkan ke rupiah, sisa pembulatan ke bobot terbesar.
     *
     * @param  array<string, int|float>  $weights
     * @return array<string, int>
     */
    public static function split(int $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = array_sum(array_map(fn ($w) => max(0, $w), $weights));
        $largest = array_search(max($weights), $weights, true);
        $parts = [];
        foreach ($weights as $key => $weight) {
            $parts[$key] = $sum > 0 ? self::roundToRupiah($total * max(0, $weight) / $sum) : 0;
        }
        $parts[$largest] += $total - array_sum($parts);

        return $parts;
    }

    /** @return array<int, string> id booking lapangan order ini yang merupakan sesi coaching (Modul 24 Opsi B). */
    private function coachingBookingIds(Order $order): array
    {
        return PadelBooking::where('order_id', $order->id)->whereNotNull('coaching_booking_id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Pembayaran di loket (shift kasir / gateway kasir), bukan online. */
    private function isCounterPayment(Order $order, Payment $payment): bool
    {
        return $payment->pos_shift_id !== null || strtoupper((string) $payment->payment_gateway) === 'CASHIER_POS';
    }

    private function orderSource(Order $order, ?Payment $payment): string
    {
        $types = $order->items->pluck('item_type')->unique();
        $atCounter = ($payment && $this->isCounterPayment($order, $payment)) || $order->cashier_id !== null;

        return match (true) {
            // Transfer perusahaan langsung ke rekening Club 61 (dicatat di Kelola Sponsor, tanpa shift kasir).
            $payment && strtoupper((string) $payment->payment_gateway) === \App\Services\Sponsor\CorporateTransfer::GATEWAY => 'CORPORATE_TRANSFER',
            $types->contains('MEMBERSHIP') => $atCounter ? 'POS_MEMBERSHIP' : 'ONLINE_MEMBERSHIP',
            $types->contains('FNB') => 'POS_FNB',
            $order->order_type === 'WALK_IN' => 'POS_WALKIN_PADEL',
            $order->order_type === 'ONLINE_BOOKING' => 'ONLINE_PADEL',
            PadelBooking::where('order_id', $order->id)->exists() => $atCounter ? 'POS_WALKIN_PADEL' : 'ONLINE_PADEL',
            default => 'LAINNYA',
        };
    }

    private function fallbackCategory(Order $order): string
    {
        return match (true) {
            in_array($order->order_type, ['WALK_IN', 'ONLINE_BOOKING'], true) => 'SEWA_LAPANGAN',
            $order->order_type === 'MEMBERSHIP' => 'MEMBERSHIP',
            in_array($order->order_type, ['DINE_IN', 'TAKE_AWAY'], true) => 'FNB',
            PadelBooking::where('order_id', $order->id)->exists() => 'SEWA_LAPANGAN',
            default => 'LAINNYA',
        };
    }

    /**
     * Nilai kuota member / voucher sponsor yang menanggung harga lapangan (informasi, non-tunai). Hanya pada
     * pembayaran pertama order supaya tidak terhitung dua kali saat order dibayar beberapa kali.
     */
    private function benefitCents(Order $order, Payment $payment): int
    {
        $alreadyRecorded = LedgerEntry::where('order_id', $order->id)
            ->where('payment_id', '!=', $payment->id)
            ->whereIn('entry_type', [LedgerEntry::TYPE_PAYMENT, LedgerEntry::TYPE_OVERPAYMENT])
            ->where('benefit_amount', '>', 0)
            ->exists();
        if ($alreadyRecorded) {
            return 0;
        }

        return (int) PadelBooking::where('order_id', $order->id)->get(['member_discount_court', 'sponsor_discount_court'])
            ->sum(fn ($b) => self::cents($b->member_discount_court) + self::cents($b->sponsor_discount_court));
    }

    private function orderSnapshot(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'customer_id' => $order->user_id,
            'customer_name' => mb_substr((string) ($order->user?->name ?? $order->customer_name ?? ''), 0, 150) ?: null,
        ];
    }

    private function snapshot(Order $order, Payment $payment): array
    {
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];
        $atCounter = $this->isCounterPayment($order, $payment);

        $cashierId = $atCounter ? ($log['settled_by'] ?? $log['cashier_id'] ?? $order->cashier_id) : null;
        $cashierName = $atCounter ? ($log['settled_by_name'] ?? $log['cashier_name'] ?? ($cashierId ? User::whereKey($cashierId)->value('name') : null)) : null;
        // Transfer perusahaan: "kasir" = staf yang mencatat transfernya di Kelola Sponsor.
        if (! $atCounter && is_array($log['corporate_transfer'] ?? null)) {
            $cashierId = $log['corporate_transfer']['recorded_by'] ?? null;
            $cashierName = $log['corporate_transfer']['recorded_by_name'] ?? null;
        }

        return $this->orderSnapshot($order) + [
            'cashier_id' => is_string($cashierId) ? $cashierId : null,
            'cashier_name' => $cashierName ? mb_substr((string) $cashierName, 0, 150) : null,
            'pos_shift_id' => $payment->pos_shift_id,
            'payment_gateway' => $payment->payment_gateway,
        ] + $this->methodSnapshot($payment);
    }

    /**
     * Metode, label & bukti bayar seperti yang ditulis ke buku — dipakai juga koreksi metode bayar oleh admin
     * (PaymentMethodCorrection) supaya hasil koreksi berformat sama dengan baris normal.
     *
     * @return array{payment_method: ?string, payment_method_label: string, payment_reference: ?string}
     */
    public function methodSnapshot(Payment $payment): array
    {
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];

        try {
            $label = app(PadelBookingService::class)->formatPaymentMethodLabel($payment->payment_method, $log);
        } catch (\Throwable) {
            $label = $payment->payment_method;
        }

        return [
            'payment_method' => $payment->payment_method,
            'payment_method_label' => mb_substr((string) $label, 0, 120),
            'payment_reference' => mb_substr((string) $this->paymentReference($payment, $log), 0, 120) ?: null,
        ];
    }

    /** Bukti bayar: RRN QRIS / approval code EDC / nomor VA / order_id Midtrans. */
    private function paymentReference(Payment $payment, array $log): ?string
    {
        $edc = is_array($log['edc_details'] ?? null) ? $log['edc_details'] : [];
        $qris = is_array($log['qris_details'] ?? null) ? $log['qris_details'] : [];

        return match (true) {
            ! empty($qris['rrn']) => 'RRN '.$qris['rrn'],
            ! empty($edc['approval_code']) => 'APPR '.$edc['approval_code'].(! empty($edc['trace_number']) ? ' · TRACE '.$edc['trace_number'] : ''),
            ! empty($log['va_numbers'][0]['va_number']) => 'VA '.$log['va_numbers'][0]['va_number'],
            // DOKU (Modul 27): ringkasan pembayaran dari DokuReconciliationService::summary.
            ! empty($log['doku_payment']['va_number']) && is_scalar($log['doku_payment']['va_number']) => 'VA '.$log['doku_payment']['va_number'],
            ! empty($log['transaction_id']) && is_string($log['transaction_id']) => $log['transaction_id'],
            default => $payment->transaction_id,
        };
    }

    private function orderMeta(Order $order, Payment $payment, array $basis): array
    {
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];
        $bookingCodes = PadelBooking::where('order_id', $order->id)->limit(20)->pluck('booking_code')->all();

        return array_filter([
            'transaction_id' => $payment->transaction_id,
            'order_type' => $order->order_type,
            'booking_codes' => $bookingCodes ?: null,
            'delta_booking_id' => $basis['kind'] === 'RESCHEDULE_DELTA' ? ($log['booking_id'] ?? null) : null,
            'received_after_bill_closed' => ! empty($log['received_after_bill_closed']) ?: null,
            'duplicate_of_payment_id' => $log['duplicate_of_payment_id'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function assertBalanced(Collection $rows, int $expectedCents, string $what): void
    {
        $actual = (int) $rows->sum(fn (LedgerEntry $r) => self::cents($r->total_amount));
        if ($actual !== $expectedCents) {
            throw new RuntimeException("Buku transaksi tidak seimbang untuk {$what}: tercatat {$actual} sen, seharusnya {$expectedCents} sen.");
        }
    }

    public static function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private static function rupiah(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private static function roundToRupiah(float $cents): int
    {
        return (int) (round($cents / 100) * 100);
    }
}
