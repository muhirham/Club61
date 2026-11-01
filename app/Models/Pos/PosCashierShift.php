<?php

namespace App\Models\Pos;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PosCashierShift extends Model
{
    use HasUlids;

    protected $table = 'pos_cashier_shifts';

    protected $fillable = [
        'shift_number',
        'counter',
        'status',
        'opened_by_id',
        'closed_by_id',
        'opened_at',
        'closed_at',
        'starting_cash',
        'expected_cash',
        'actual_cash',
        'cash_difference',
        'total_cash_sales',
        'total_edc_bca_sales',
        'total_edc_mandiri_sales',
        'total_qris_sales',
        'total_other_sales',
        'total_sales',
        'total_transactions',
        'opening_notes',
        'closing_notes',
        'settlement_reconciliation',
        'settlement_difference',
        'closing_inputs',
        'review_status',
        'reviewed_by_id',
        'reviewed_at',
        'review_notes',
    ];

    /** Hasil tutup shift (null = shift lama sebelum rekonsiliasi blind / masih OPEN). */
    public const REVIEW_MATCHED = 'MATCHED';

    /** Ada selisih (toleransi Rp0) → menunggu keputusan pemegang izin approve_shift_closing. */
    public const REVIEW_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const REVIEW_APPROVED = 'APPROVED';

    public const REVIEW_REJECTED = 'REJECTED';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'starting_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'total_cash_sales' => 'decimal:2',
            'total_edc_bca_sales' => 'decimal:2',
            'total_edc_mandiri_sales' => 'decimal:2',
            'total_qris_sales' => 'decimal:2',
            'total_other_sales' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'total_transactions' => 'integer',
            'settlement_reconciliation' => 'array',
            'settlement_difference' => 'decimal:2',
            'closing_inputs' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    private const EDC_TERMINAL_LABELS = [
        'EDC_BCA' => 'Mesin EDC BCA',
        'EDC_MANDIRI' => 'Mesin EDC Mandiri',
        'EDC_LAINNYA' => 'Mesin EDC Lainnya',
    ];

    private const QRIS_PROVIDER_LABELS = [
        'BCA_QRIS' => 'QRIS BCA Frontdesk',
        'MANDIRI_QRIS' => 'QRIS Bank Mandiri',
        'GOPAY' => 'GoPay / Midtrans QRIS',
        'GOPAY_QRIS' => 'GoPay / Midtrans QRIS',
        // QR dinamis dari layar kasir — uangnya masuk saldo Midtrans, dicek di dashboard Midtrans.
        'MIDTRANS_QRIS' => 'QRIS Otomatis (Kasir)',
        // Sama, lewat DOKU — uangnya masuk saldo DOKU, dicek di dashboard DOKU.
        'DOKU_QRIS' => 'QRIS Otomatis DOKU (Kasir)',
        'OVO' => 'OVO',
        'SHOPEEPAY' => 'ShopeePay',
        'DANA' => 'DANA',
        'LIVIN' => 'Livin Mandiri',
        'LAINNYA' => 'QRIS Lainnya / Bank Lain',
    ];

    /**
     * Rincian penjualan sukses shift ini per kategori settlement — satu baris per
     * kombinasi yang punya laporan settlement sendiri di dunia nyata: tiap mesin EDC
     * dipisah debit/kredit (struk settlement EDC memang memisahkan keduanya), tiap
     * penyedia QRIS dipisah (mutasi QRIS dicek per dashboard acquirer). Kunci baris
     * hanya huruf/angka/underscore supaya aman dipakai sebagai path wire:model.
     *
     * @return array<int, array{key: string, label: string, source: string, count: int, system_amount: float}>
     */
    public function settlementBreakdown(): array
    {
        $rows = [];

        foreach ($this->payments()->where('status', 'SUCCESS')->get() as $payment) {
            ['key' => $key, 'label' => $label, 'source' => $source] = self::categorize($payment);
            $rows[$key] ??= ['key' => $key, 'label' => $label, 'source' => $source, 'count' => 0, 'system_amount' => 0.0];
            $rows[$key]['count']++;
            $rows[$key]['system_amount'] += (float) $payment->amount;
        }

        ksort($rows);

        return array_values($rows);
    }

    /** Baris Tutup Shift untuk transfer Pelunasan Tagihan Coaching (Modul 31). */
    public const TRANSFER_COACHING_KEY = 'TRANSFER_COACHING';

    /**
     * Kategori settlement satu pembayaran (dipakai settlementBreakdown & tutup shift blind) — EDC per mesin + debit/kredit,
     * QRIS per penyedia, Bayar Otomatis non-QRIS per metode, sisanya OTHER_<metode>.
     *
     * @return array{key: string, label: string, source: string}
     */
    public static function categorize(Payment $payment): array
    {
        $log = $payment->payload_log ?? [];
        $method = strtoupper((string) $payment->payment_method);

        $posOnline = isset($log['pos_qris']) ? strtoupper((string) ($log['pos_qris']['method'] ?? 'QRIS')) : null;

        if ($posOnline !== null && $posOnline !== 'QRIS') {
            // Bayar Otomatis non-QRIS dari kasir (mis. VA): dicek di dashboard pembayaran online, bukan mutasi bank.
            $key = 'AUTO_'.preg_replace('/[^A-Z0-9_]/', '_', $posOnline);
            $label = \App\Services\Pos\PosMidtransQrisService::labelFor($log);
            $source = 'Dashboard pembayaran online';
        } elseif (in_array($method, ['QRIS', 'QRIS_STATIS'], true)) {
            $provider = strtoupper((string) ($log['qris_details']['provider'] ?? $log['qris_provider'] ?? 'LAINNYA'));
            $key = 'QRIS_'.$provider;
            $label = 'QRIS — '.(self::QRIS_PROVIDER_LABELS[$provider] ?? $provider);
            $source = in_array($provider, ['MIDTRANS_QRIS', 'DOKU_QRIS'], true) ? 'Dashboard pembayaran online' : 'Mutasi / dashboard QRIS';
        } elseif (in_array($method, ['DEBIT_CARD', 'CREDIT_CARD', 'DEBIT', 'CREDIT', 'EDC_BCA', 'EDC_MANDIRI'], true)) {
            $edc = $log['edc_details'] ?? $log;
            $terminal = strtoupper((string) ($edc['terminal'] ?? (in_array($method, ['EDC_BCA', 'EDC_MANDIRI'], true) ? $method : 'EDC_LAINNYA')));
            $cardType = strtoupper((string) ($edc['card_type'] ?? (str_contains($method, 'CREDIT') ? 'CREDIT' : 'DEBIT')));
            $key = $terminal.'_'.$cardType;
            $label = ($cardType === 'CREDIT' ? 'Kartu Kredit' : 'Kartu Debit').' — '.(self::EDC_TERMINAL_LABELS[$terminal] ?? $terminal);
            $source = 'Struk settlement '.(self::EDC_TERMINAL_LABELS[$terminal] ?? $terminal);
        } elseif ($method === 'TRANSFER_BANK' && ! empty($log['coaching_settlement_id'])) {
            // Modul 31: transfer academy langsung ke rekening Club 61 (tidak lewat mesin di meja kasir) — dicek admin pada
            // mutasi rekening dengan bukti terlampir, tidak diketik kasir saat tutup shift.
            $key = self::TRANSFER_COACHING_KEY;
            $label = 'Transfer Bank — Pelunasan Tagihan Coaching';
            $source = 'Mutasi rekening Club 61 (bukti transfer di Tagihan Coaching)';
        } else {
            $key = 'OTHER_'.preg_replace('/[^A-Z0-9_]/', '_', $method);
            $label = 'Lainnya — '.$method;
            $source = 'Bukti transaksi';
        }

        return ['key' => $key, 'label' => $label, 'source' => $source];
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'pos_shift_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'pos_shift_id');
    }

    public static function getActiveShift(string $counter = 'PADEL_FRONTDESK'): ?self
    {
        return static::where('counter', $counter)
            ->where('status', 'OPEN')
            ->latest('opened_at')
            ->first();
    }

    public static function generateShiftNumber(string $counter = 'PADEL_FRONTDESK'): string
    {
        $prefixCode = match ($counter) {
            'PADEL_FRONTDESK' => 'PADEL',
            'FNB_COUNTER' => 'FNB',
            default => strtoupper(substr(str_replace('_', '', $counter), 0, 5)),
        };

        $date = Carbon::now('Asia/Jakarta')->format('Ymd');
        $prefix = "SFT-{$prefixCode}-{$date}-";

        $countToday = static::where('counter', $counter)
            ->whereDate('opened_at', Carbon::now('Asia/Jakarta')->toDateString())
            ->count();

        $sequence = str_pad((string) ($countToday + 1), 4, '0', STR_PAD_LEFT);

        while (static::where('shift_number', $prefix . $sequence)->exists()) {
            $countToday++;
            $sequence = str_pad((string) ($countToday + 1), 4, '0', STR_PAD_LEFT);
        }

        return $prefix . $sequence;
    }

    /**
     * Pembayaran "Bayar Otomatis" (QR / VA di layar kasir) yang masih menunggu customer. Shift tidak boleh ditutup selama
     * masih ada: kalau dibayar setelah shift ditutup, uangnya masuk ke shift yang sudah direkap (setoran tidak cocok).
     * Pembayaran online customer tidak pernah punya pos_shift_id, jadi cukup PENDING di shift ini.
     */
    public function pendingAutoPaymentCount(): int
    {
        return $this->payments()->where('status', 'PENDING')->count();
    }

    public const PENDING_AUTO_PAYMENT_MESSAGE = 'Masih ada pembayaran Bayar Otomatis (QR / VA) yang menunggu customer. Selesaikan atau batalkan dulu sebelum menutup shift.';

    public function calculateSummary(): array
    {
        $payments = $this->payments()->where('status', 'SUCCESS')->get();

        $cashSales = (float) $payments->where('payment_method', 'CASH')->sum('amount');
        $debitSales = (float) $payments->whereIn('payment_method', ['DEBIT_CARD', 'DEBIT'])->sum('amount');
        $creditSales = (float) $payments->whereIn('payment_method', ['CREDIT_CARD', 'CREDIT'])->sum('amount');
        $edcBcaSales = (float) $payments->where('payment_method', 'EDC_BCA')->sum('amount');
        $edcMandiriSales = (float) $payments->where('payment_method', 'EDC_MANDIRI')->sum('amount');
        $qrisSales = (float) $payments->whereIn('payment_method', ['QRIS', 'QRIS_STATIS'])->sum('amount');
        $otherSales = (float) $payments->whereNotIn('payment_method', ['CASH', 'DEBIT_CARD', 'DEBIT', 'CREDIT_CARD', 'CREDIT', 'EDC_BCA', 'EDC_MANDIRI', 'QRIS', 'QRIS_STATIS'])->sum('amount');
        $totalSales = $cashSales + $debitSales + $creditSales + $edcBcaSales + $edcMandiriSales + $qrisSales + $otherSales;
        $totalTransactions = $payments->count();
        $expectedCash = (float) $this->starting_cash + $cashSales;

        return [
            'total_cash_sales' => $cashSales,
            'total_debit_sales' => $debitSales,
            'total_credit_sales' => $creditSales,
            'total_edc_bca_sales' => $edcBcaSales,
            'total_edc_mandiri_sales' => $edcMandiriSales,
            'total_qris_sales' => $qrisSales,
            'total_other_sales' => $otherSales,
            'total_sales' => $totalSales,
            'total_transactions' => $totalTransactions,
            'expected_cash' => $expectedCash,
        ];
    }
}
