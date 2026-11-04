<?php

namespace App\Services\Pos;

use App\Models\Audit\ActivityLog;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Padel\PadelBookingService;
use App\Support\ReceiptPaper;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Rekap Penjualan Harian (X-Report) per loket — hanya MEMBACA data, tidak menutup / mengubah apa pun.
 *
 * Periode = tanggal kalender itu (WIB): shift loket yang SEMPAT BUKA pada tanggal itu — termasuk shift hari sebelumnya
 * yang belum ditutup (lupa tutup shift) — dari jam buka (paling awal 00:00) sampai sekarang / shift terakhir ditutup
 * (paling akhir 23:59). Angka = pembayaran SUCCESS shift-shift itu yang DIBAYAR pada tanggal itu, dikelompokkan dengan
 * PosCashierShift::categorize (kategori yang sama dengan form Tutup Shift & Z-Report), jadi baris rekap bisa langsung
 * dicocokkan dengan struk settlement EDC / mutasi QRIS.
 *
 * Rekap memperlihatkan angka sistem yang di form Tutup Shift sengaja disembunyikan (blind) — karena itu dibatasi izin
 * print_sales_recap dan setiap kali dibuka tercatat di Log Aktivitas (jumlahnya ikut tampil di Z-Report shift itu).
 */
class DailySalesRecapService
{
    public const PERMISSION = 'print_sales_recap';

    public const LOG_EVENT = 'shift.sales_recap_viewed';

    public const COUNTER_LABELS = [
        'PADEL_FRONTDESK' => 'Frontdesk Padel',
        'FNB_COUNTER' => 'Kasir F&B',
    ];

    /** Pembayaran yang bukan uang dari payment gateway (kasir, kuota, transfer manual) — tidak masuk bagian "online". */
    private const NON_ONLINE_GATEWAYS = ['CASHIER_POS', 'MEMBERSHIP_QUOTA', 'TRANSFER_MANUAL'];

    private const TZ = 'Asia/Jakarta';

    private const EDC_SHORT = ['EDC_BCA' => 'BCA', 'EDC_MANDIRI' => 'Mandiri', 'EDC_LAINNYA' => 'Lainnya'];

    private const QRIS_SHORT = [
        'BCA_QRIS' => 'BCA',
        'MANDIRI_QRIS' => 'Mandiri',
        'GOPAY' => 'GoPay',
        'GOPAY_QRIS' => 'GoPay',
        'MIDTRANS_QRIS' => 'Otomatis',
        'DOKU_QRIS' => 'Otomatis DOKU',
        'OVO' => 'OVO',
        'SHOPEEPAY' => 'ShopeePay',
        'DANA' => 'DANA',
        'LIVIN' => 'Livin Mandiri',
        'LAINNYA' => 'Lainnya',
    ];

    private const OTHER_SHORT = [
        'CASH' => 'Tunai',
        'MEMBERSHIP_QUOTA' => 'Kuota Membership',
        'SPONSOR_VOUCHER' => 'Voucher Corporate',
        'MEMBERSHIP_AND_SPONSOR_VOUCHER' => 'Kuota + Voucher Corporate',
        'PROMO_VOUCHER' => 'Kode Promo',
        'VOUCHER' => 'Voucher',
        'TRANSFER_BANK' => 'Transfer Bank',
    ];

    /**
     * @return array<string, mixed>|null null = tidak ada shift loket ini yang buka pada tanggal itu (atau tanggalnya belum tiba)
     */
    public function build(string $counter, string $date, User $printedBy): ?array
    {
        $dayStart = Carbon::parse($date, self::TZ)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();
        if ($dayStart->gt(now())) {
            return null;
        }
        $start = $dayStart->copy()->setTimezone(config('app.timezone'));
        $end = $dayEnd->copy()->setTimezone(config('app.timezone'));

        $shifts = PosCashierShift::query()
            ->where('counter', $counter)
            ->where('opened_at', '<=', $end)
            ->where(fn ($q) => $q->where('status', 'OPEN')->orWhere('closed_at', '>=', $start))
            ->orderBy('opened_at')
            ->get();

        if ($shifts->isEmpty()) {
            return null;
        }

        $openCount = $shifts->where('status', 'OPEN')->count();
        $from = Carbon::parse($shifts->first()->opened_at);
        $from = $from->lt($dayStart) ? $dayStart->copy() : $from;
        $until = $openCount > 0 ? now() : Carbon::parse($shifts->max('closed_at'));
        $until = $until->gt($dayEnd) ? $dayEnd->copy() : $until;

        $payments = Payment::query()
            ->with('order:id,order_type')
            ->whereIn('pos_shift_id', $shifts->pluck('id'))
            ->where('status', 'SUCCESS')
            ->whereBetween('paid_at', [$start, $end])
            ->get();

        // Shift dari hari sebelumnya yang masih / baru ditutup — dicetak supaya kasir tahu shift-nya belum ditutup.
        $carriedOver = $shifts->filter(fn (PosCashierShift $s) => Carbon::parse($s->opened_at)->lt($dayStart))
            ->map(fn (PosCashierShift $s) => [
                'since' => Carbon::parse($s->opened_at)->setTimezone(self::TZ)->format('d/m H:i'),
                'still_open' => $s->status === 'OPEN',
            ])->values()->all();

        // Shift yang masih buka sejak sebelum hari ini: total seluruh shift (sejak dibuka s/d sekarang) untuk dicocokkan
        // saat Tutup Shift — kelompok metodenya sama dengan form Tutup Shift. Shift yang dibuka hari ini sudah sama
        // dengan rekap harian, jadi tidak diulang.
        $todayStart = now(self::TZ)->startOfDay();
        $runningShifts = $shifts
            ->filter(fn (PosCashierShift $s) => $s->status === 'OPEN' && Carbon::parse($s->opened_at)->lt($todayStart))
            ->map(function (PosCashierShift $s) {
                $shiftPayments = Payment::query()->where('pos_shift_id', $s->id)->where('status', 'SUCCESS')->get();

                return [
                    'shift_number' => $s->shift_number,
                    'since' => Carbon::parse($s->opened_at)->setTimezone(self::TZ)->format('d/m H:i'),
                    'transactions' => $shiftPayments->count(),
                    'total' => (float) $shiftPayments->sum('amount'),
                    'methods' => $this->cashierMethods($shiftPayments),
                ];
            })->values()->all();

        $now = now()->setTimezone(self::TZ);

        return [
            'counter' => $counter,
            'counter_label' => self::COUNTER_LABELS[$counter] ?? $counter,
            'date' => $dayStart->toDateString(),
            'date_label' => $dayStart->locale('id')->translatedFormat('l').', '.$dayStart->format('d/m/Y'),
            'opened_at' => Carbon::parse($from)->setTimezone(self::TZ)->format('H:i'),
            'until' => $until->copy()->setTimezone(self::TZ)->format('H:i'),
            'still_open' => $openCount > 0,
            'shift_count' => $shifts->count(),
            'open_shift_count' => $openCount,
            'shift_ids' => $shifts->pluck('id')->all(),
            'carried_over' => $carriedOver,
            'printed_at' => $now->format('d/m/Y H:i'),
            'printed_by' => self::fit((string) $printedBy->name, 20),
            'transactions' => $payments->count(),
            'total' => (float) $payments->sum('amount'),
            'methods' => $this->cashierMethods($payments),
            // Loket frontdesk dipakai POS Walk-In, Jual Membership & Kasir Pro Shop (satu shift) — dipecah per jenis.
            'types' => $counter === 'PADEL_FRONTDESK' ? self::byTransactionType($payments) : [],
            'running_shifts' => $runningShifts,
            // Bayar online customer hanya untuk booking & membership — loket padel saja.
            'online' => $counter === 'PADEL_FRONTDESK' ? $this->online($from, $until) : null,
        ];
    }

    /**
     * Rekap dibuka → Log Aktivitas, menempel ke shift loket yang sedang buka (kalau ada) supaya Z-Report shift itu bisa
     * menyebut berapa kali rekap dilihat sebelum tutup shift.
     */
    public function recordView(array $recap, User $by): void
    {
        $openShift = PosCashierShift::getActiveShift($recap['counter']);

        ActivityLogger::record(
            module: 'POS',
            event: self::LOG_EVENT,
            description: 'Membuka Rekap Penjualan Harian (X-Report) '.$recap['counter_label'].' '.$recap['date_label'],
            subject: $openShift,
            meta: [
                'loket' => $recap['counter_label'],
                'tanggal' => $recap['date'],
                'total' => ActivityLogger::rupiah($recap['total']),
                'transaksi' => $recap['transactions'],
            ],
            causer: $by,
        );
    }

    /** Berapa kali rekap harian dibuka selama shift ini berjalan (baris info di Z-Report). */
    /** Urutan & label (≤ 28 karakter kertas struk) jenis transaksi loket frontdesk. */
    public const TRANSACTION_TYPES = [
        'BOOKING' => 'Booking Padel',
        'RESCHEDULE' => 'Selisih Reschedule',
        'COACHING' => 'Tagihan Coaching',
        'MEMBERSHIP' => 'Membership',
        'PRO_SHOP_SALE' => 'Penjualan Pro Shop',
        'ADDON' => 'Tambahan Alat',
        'FINE' => 'Denda Alat',
    ];

    /** Jenis transaksi satu pembayaran loket frontdesk (POS Walk-In / Jual Membership / Kasir Pro Shop). Order harus dimuat. */
    public static function transactionType(Payment $payment): string
    {
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];
        $source = $log['source'] ?? null;

        return match (true) {
            ! empty($log['coaching_settlement_id']) => 'COACHING',
            ($log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA' => 'RESCHEDULE',
            $source === ProShopSaleService::SOURCE => 'PRO_SHOP_SALE',
            $source === 'BOOKING_ADDON' || ! empty($log['booking_addon']) => 'ADDON',
            $source === \App\Services\Padel\EquipmentLoanService::SOURCE_FINE => 'FINE',
            $source === 'MEMBERSHIP_POS' || $payment->order?->order_type === 'MEMBERSHIP' => 'MEMBERSHIP',
            default => 'BOOKING',
        };
    }

    /**
     * Rekap per jenis transaksi (urut TRANSACTION_TYPES, yang kosong tidak ditampilkan).
     *
     * @return list<array{label: string, count: int, amount: float}>
     */
    public static function byTransactionType(\Illuminate\Support\Collection $payments): array
    {
        $rows = [];
        foreach ($payments as $payment) {
            $key = self::transactionType($payment);
            $rows[$key] ??= ['label' => self::TRANSACTION_TYPES[$key], 'count' => 0, 'amount' => 0.0];
            $rows[$key]['count']++;
            $rows[$key]['amount'] += (float) $payment->amount;
        }

        return array_values(array_filter(array_map(fn (string $key) => $rows[$key] ?? null, array_keys(self::TRANSACTION_TYPES))));
    }

    /** Rekap per jenis untuk Z-Report satu shift (loket frontdesk saja; loket lain = kosong). */
    public static function shiftTransactionTypes(PosCashierShift $shift): array
    {
        if ($shift->counter !== 'PADEL_FRONTDESK') {
            return [];
        }

        return self::byTransactionType(Payment::with('order:id,order_type')->where('pos_shift_id', $shift->getKey())->where('status', 'SUCCESS')->get());
    }

    public static function viewsDuringShift(PosCashierShift $shift): int
    {
        return ActivityLog::query()
            ->where('event', self::LOG_EVENT)
            ->where('subject_type', class_basename($shift))
            ->where('subject_id', $shift->getKey())
            ->count();
    }

    /**
     * Pembayaran kasir per kategori PosCashierShift::categorize (sama dengan form Tutup Shift & Z-Report).
     *
     * @param  \Illuminate\Support\Collection<int, Payment>  $payments
     * @return list<array{label: string, count: int, amount: float}>
     */
    private function cashierMethods(\Illuminate\Support\Collection $payments): array
    {
        $methods = [];
        foreach ($payments as $payment) {
            $category = PosCashierShift::categorize($payment);
            $methods[$category['key']] ??= ['label' => $this->counterLabel($category['key'], $payment), 'count' => 0, 'amount' => 0.0];
            $methods[$category['key']]['count']++;
            $methods[$category['key']]['amount'] += (float) $payment->amount;
        }
        ksort($methods);

        return array_values($methods);
    }

    /** Pembayaran online customer (website / aplikasi) yang lunas dalam periode rekap — info, tidak lewat EDC. */
    private function online(CarbonInterface $from, CarbonInterface $until): array
    {
        $payments = Payment::query()
            ->whereNull('pos_shift_id')
            ->where('status', 'SUCCESS')
            ->whereNotIn('payment_gateway', self::NON_ONLINE_GATEWAYS)
            ->where('amount', '>', 0)
            ->whereBetween('paid_at', [$from, $until])
            ->get();

        $methods = [];
        $labeler = app(PadelBookingService::class);
        foreach ($payments as $payment) {
            $label = self::fit($labeler->formatPaymentMethodLabel($payment->payment_method, is_array($payment->payload_log) ? $payment->payload_log : null));
            $methods[$label] ??= ['label' => $label, 'count' => 0, 'amount' => 0.0];
            $methods[$label]['count']++;
            $methods[$label]['amount'] += (float) $payment->amount;
        }
        ksort($methods);

        return [
            'transactions' => $payments->count(),
            'total' => (float) $payments->sum('amount'),
            'methods' => array_values($methods),
        ];
    }

    /**
     * Nama metode pendek supaya satu baris struk (lebar kertas PC = ReceiptPaper::PC_COLUMNS karakter) tidak pernah
     * turun ke baris berikutnya. Pengelompokannya tetap kunci categorize(), hanya labelnya yang dipendekkan.
     */
    private function counterLabel(string $key, Payment $payment): string
    {
        if (preg_match('/^(EDC_[A-Z]+)_(DEBIT|CREDIT)$/', $key, $m)) {
            return self::fit(($m[2] === 'CREDIT' ? 'Kredit' : 'Debit').' EDC '.(self::EDC_SHORT[$m[1]] ?? Str::title(str_replace('_', ' ', $m[1]))));
        }

        if (str_starts_with($key, 'QRIS_')) {
            $provider = substr($key, 5);

            return self::fit('QRIS '.(self::QRIS_SHORT[$provider] ?? Str::title(str_replace('_', ' ', $provider))));
        }

        if (str_starts_with($key, 'OTHER_')) {
            $method = strtoupper((string) $payment->payment_method);

            return self::fit(self::OTHER_SHORT[$method] ?? Str::title(str_replace('_', ' ', strtolower($method))));
        }

        // AUTO_* (Bayar Otomatis non-QRIS, mis. VA dari layar kasir): label categorize() sudah pendek.
        return self::fit(PosCashierShift::categorize($payment)['label']);
    }

    /** Potong ke lebar struk (tanpa memotong di tengah kata kalau bisa). */
    public static function fit(string $text, int $width = ReceiptPaper::PC_COLUMNS): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $width) {
            return $text;
        }

        $cut = mb_substr($text, 0, $width);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space >= $width - 8 ? mb_substr($cut, 0, $space) : $cut);
    }
}
