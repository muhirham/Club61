<?php

namespace App\Services\Finance;

use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Refund;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Query Buku Transaksi (Modul 17 Fase 2) — SATU sumber untuk tabel halaman, kartu ringkasan, dan export, supaya
 * angka yang dilihat = angka yang diunduh. Semua tanggal dalam hari kalender WIB; occurred_at disimpan di zona waktu
 * aplikasi, jadi batas hari WIB dikonversi ke zona aplikasi (pola sama dengan LogAktivitas).
 */
class LedgerReport
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const PRESETS = [
        'hari_ini' => 'Hari ini',
        'kemarin' => 'Kemarin',
        '7_hari' => '7 hari terakhir',
        'bulan_ini' => 'Bulan ini',
        'bulan_lalu' => 'Bulan lalu',
        'kustom' => 'Pilih tanggal sendiri',
        'semua' => 'Semua tanggal',
    ];

    public const DEFAULT_PRESET = 'bulan_ini';

    /** Filter status transaksi. */
    public const STATUSES = [
        'PAYMENT' => 'Pembayaran',
        'OVERPAYMENT' => 'Kelebihan bayar',
        'REFUND' => 'Refund (uang keluar)',
        'REFUNDED' => 'Pembayaran yang pernah direfund',
    ];

    public const MONEY_COLUMNS = ['gross_amount', 'discount_amount', 'net_amount', 'service_amount', 'tax_amount', 'total_amount', 'benefit_amount'];

    /** @return array{0: ?string, 1: ?string} tanggal Y-m-d (WIB) awal & akhir, null = tanpa batas */
    public static function resolveRange(?string $preset, ?string $from = null, ?string $until = null): array
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        return match ($preset ?: self::DEFAULT_PRESET) {
            'hari_ini' => [$today->toDateString(), $today->toDateString()],
            'kemarin' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
            '7_hari' => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
            'bulan_ini' => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'bulan_lalu' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'semua' => [null, null],
            default => [self::dateOnly($from), self::dateOnly($until)],
        };
    }

    public static function applyDateRange(Builder $query, ?string $from, ?string $until): Builder
    {
        $tz = config('app.timezone');

        return $query
            ->when($from, fn (Builder $q, $date) => $q->where('occurred_at', '>=', Carbon::parse($date, self::TIMEZONE)->startOfDay()->setTimezone($tz)))
            ->when($until, fn (Builder $q, $date) => $q->where('occurred_at', '<', Carbon::parse($date, self::TIMEZONE)->addDay()->startOfDay()->setTimezone($tz)));
    }

    /** Transaksi yang memuat salah satu kategori ini (seluruh baris transaksinya ikut, bukan hanya baris kategorinya). */
    public static function applyCategories(Builder $query, array $categories): Builder
    {
        if ($categories === []) {
            return $query;
        }

        return $query->whereIn('payment_id', LedgerEntry::query()->select('payment_id')->whereNotNull('payment_id')->whereIn('category', $categories));
    }

    public static function applyStatuses(Builder $query, array $statuses): Builder
    {
        if ($statuses === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($statuses) {
            $types = array_values(array_intersect($statuses, [LedgerEntry::TYPE_PAYMENT, LedgerEntry::TYPE_OVERPAYMENT, LedgerEntry::TYPE_REFUND]));
            if ($types !== []) {
                $q->orWhereIn('entry_type', $types);
            }
            if (in_array('REFUNDED', $statuses, true)) {
                $q->orWhere(fn (Builder $w) => $w->where('entry_type', '!=', LedgerEntry::TYPE_REFUND)
                    ->whereIn('payment_id', LedgerEntry::query()->select('payment_id')->where('entry_type', LedgerEntry::TYPE_REFUND)->whereNotNull('payment_id')));
            }
        });
    }

    /** Pencarian: no. order / kode booking / nama customer / no. HP customer / bukti bayar / nama kasir. */
    public static function applySearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }
        $like = '%'.$search.'%';

        return $query->where(function (Builder $q) use ($like) {
            foreach (['order_number', 'customer_name', 'payment_reference', 'cashier_name', 'meta'] as $column) {
                $q->orWhere($column, 'like', $like);
            }
            $q->orWhereIn('customer_id', \App\Models\User::query()->select('id')->where('phone', 'like', $like));
        });
    }

    /**
     * Terapkan seluruh filter (sudah tervalidasi lewat filterRules(), atau dari state filter tabel yang dinormalisasi).
     *
     * @param  array<string, mixed>  $filters
     */
    public static function applyFilters(Builder $query, array $filters): Builder
    {
        [$from, $until] = self::resolveRange($filters['periode'] ?? null, $filters['dari'] ?? null, $filters['sampai'] ?? null);
        self::applyDateRange($query, $from, $until);

        $query
            ->when($filters['source'] ?? null, fn (Builder $q, $values) => $q->whereIn('source', (array) $values))
            ->when($filters['method'] ?? null, fn (Builder $q, $values) => self::applyMethods($q, (array) $values))
            ->when($filters['cashier_id'] ?? null, fn (Builder $q, $value) => $q->where('cashier_id', $value))
            ->when($filters['pos_shift_id'] ?? null, fn (Builder $q, $value) => $q->where('pos_shift_id', $value));

        self::applyCategories($query, (array) ($filters['category'] ?? []));
        self::applyStatuses($query, (array) ($filters['status'] ?? []));

        return self::applySearch($query, $filters['search'] ?? null);
    }

    /**
     * Satu baris per pembayaran / refund (baris kategori digabung). Kolom snapshot sama untuk semua baris satu
     * transaksi, jadi MAX() aman (dan lolos ONLY_FULL_GROUP_BY MySQL).
     */
    public static function grouped(Builder $query): Builder
    {
        $sums = collect(self::MONEY_COLUMNS)->map(fn ($c) => "SUM({$c}) as {$c}")->implode(', ');
        $snapshot = collect(['occurred_at', 'order_id', 'order_number', 'source', 'customer_id', 'customer_name', 'cashier_id', 'cashier_name',
            'pos_shift_id', 'payment_gateway', 'payment_method', 'payment_method_label', 'payment_reference',
            // Tanda koreksi metode bayar oleh admin (semua baris satu pembayaran dikoreksi bersamaan).
            'method_corrected_at', 'method_corrected_by_name', 'original_payment_method_label'])
            ->map(fn ($c) => "MAX({$c}) as {$c}")->implode(', ');

        return $query
            ->selectRaw("MIN(id) as id, payment_id, refund_id, entry_type, {$snapshot}, {$sums}, GROUP_CONCAT(category) as categories")
            ->groupBy('payment_id', 'refund_id', 'entry_type');
    }

    public static function orderNewestFirst(Builder $query, string $direction = 'desc'): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query->orderByRaw("MAX(occurred_at) {$direction}")->orderByRaw("MIN(id) {$direction}");
    }

    /**
     * Angka kartu ringkasan — satu query agregat (bukan perulangan PHP).
     *
     * @return array<string, float|int>
     */
    public static function summary(Builder $filteredBase, ?string $from = null, ?string $until = null): array
    {
        // Kartu utama dihitung SETELAH refund (baris refund bernilai negatif ikut dijumlah), supaya
        // Penjualan Bersih + Biaya Layanan + Pajak = Total Uang Masuk (Bersih). Dulu penjualan bersih dihitung sebelum
        // refund sementara totalnya setelah refund — angkanya tidak bisa dicocokkan dengan menjumlah tabel.
        $in = "entry_type <> 'REFUND'";
        $row = (clone $filteredBase)->toBase()->selectRaw(
            "COALESCE(SUM(net_amount), 0) as net,
             COALESCE(SUM(service_amount), 0) as service,
             COALESCE(SUM(tax_amount), 0) as tax,
             COALESCE(SUM(gross_amount), 0) as gross,
             COALESCE(SUM(discount_amount), 0) as discount,
             COALESCE(SUM(CASE WHEN {$in} THEN net_amount ELSE 0 END), 0) as net_before_refund,
             COALESCE(SUM(CASE WHEN {$in} THEN total_amount ELSE 0 END), 0) as money_in,
             COALESCE(SUM(CASE WHEN entry_type = 'REFUND' THEN total_amount ELSE 0 END), 0) as refunds,
             COALESCE(SUM(CASE WHEN entry_type = 'OVERPAYMENT' THEN total_amount ELSE 0 END), 0) as overpayments,
             COALESCE(SUM(total_amount), 0) as money_net,
             COALESCE(SUM(benefit_amount), 0) as benefit,
             COUNT(DISTINCT CASE WHEN {$in} THEN payment_id END) as payments_count,
             COUNT(DISTINCT refund_id) as refunds_count"
        )->first();

        $pending = Refund::query()->where('status', 'PENDING');
        $forfeited = PadelBooking::query()->where('reschedule_forfeited_amount', '>', 0);
        if ($from || $until) {
            $tz = config('app.timezone');
            $forfeited
                ->when($from, fn ($q) => $q->where('updated_at', '>=', Carbon::parse($from, self::TIMEZONE)->startOfDay()->setTimezone($tz)))
                ->when($until, fn ($q) => $q->where('updated_at', '<', Carbon::parse($until, self::TIMEZONE)->addDay()->startOfDay()->setTimezone($tz)));
        }

        return [
            'net' => (float) $row->net,
            'service' => (float) $row->service,
            'tax' => (float) $row->tax,
            'gross' => (float) $row->gross,
            'discount' => (float) $row->discount,
            'net_before_refund' => (float) $row->net_before_refund,
            'money_in' => (float) $row->money_in,
            'refunds' => (float) $row->refunds,
            'overpayments' => (float) $row->overpayments,
            'money_net' => (float) $row->money_net,
            'benefit' => (float) $row->benefit,
            'payments_count' => (int) $row->payments_count,
            'refunds_count' => (int) $row->refunds_count,
            'forfeited' => (float) $forfeited->sum('reschedule_forfeited_amount'),
            'pending_refund_count' => (clone $pending)->count(),
            'pending_refund_amount' => (float) (clone $pending)->sum('refund_amount'),
        ];
    }

    /** @var array<string, array{processed: float, pending: float}> */
    private static array $refundMemo = [];

    /** Status satu baris transaksi (hasil grouped()). */
    public static function statusLabel(LedgerEntry $row): string
    {
        if ($row->entry_type === LedgerEntry::TYPE_REFUND) {
            return 'Refund';
        }

        $refunds = self::refundsFor($row->payment_id);
        $total = (float) $row->total_amount;
        $refunded = match (true) {
            $refunds['processed'] <= 0 => '',
            $refunds['processed'] >= $total - 1 => 'Direfund',
            default => 'Direfund sebagian',
        };

        if ($row->entry_type === LedgerEntry::TYPE_OVERPAYMENT) {
            return 'Kelebihan bayar'.($refunded !== '' ? ' · '.strtolower($refunded) : ($refunds['pending'] > 0 ? ' · refund menunggu' : ''));
        }

        return $refunded !== '' ? $refunded : ($refunds['pending'] > 0 ? 'Lunas · refund menunggu' : 'Lunas');
    }

    public static function statusColor(string $label): string
    {
        return match (true) {
            str_starts_with($label, 'Refund') => 'danger',
            str_starts_with($label, 'Kelebihan') => 'warning',
            str_starts_with($label, 'Direfund') => 'gray',
            str_contains($label, 'menunggu') => 'warning',
            default => 'success',
        };
    }

    /** @return array{processed: float, pending: float} */
    public static function refundsFor(?string $paymentId): array
    {
        if (! $paymentId) {
            return ['processed' => 0.0, 'pending' => 0.0];
        }

        return self::$refundMemo[$paymentId] ??= [
            'processed' => (float) Refund::where('payment_id', $paymentId)->where('status', 'PROCESSED')->sum('refund_amount'),
            'pending' => (float) Refund::where('payment_id', $paymentId)->where('status', 'PENDING')->sum('refund_amount'),
        ];
    }

    public static function flushMemo(): void
    {
        self::$refundMemo = [];
        self::$edcMemo = [];
    }

    /**
     * Isi memo refundsFor() untuk banyak pembayaran sekaligus — satu query, bukan dua query per baris (export ribuan
     * baris dulu menjalankan ribuan query hanya untuk kolom Status).
     *
     * @param  array<int, string|null>  $paymentIds
     */
    public static function primeRefunds(array $paymentIds): void
    {
        $ids = array_values(array_unique(array_filter($paymentIds)));
        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            self::$refundMemo[$id] = ['processed' => 0.0, 'pending' => 0.0];
        }
        Refund::query()->toBase()
            ->selectRaw('payment_id, status, SUM(refund_amount) as total')
            ->whereIn('payment_id', $ids)
            ->whereIn('status', ['PROCESSED', 'PENDING'])
            ->groupBy('payment_id', 'status')
            ->get()
            ->each(function ($row) {
                self::$refundMemo[$row->payment_id][strtolower($row->status)] = (float) $row->total;
            });
    }

    /**
     * Kasir yang melunasi. Pembayaran online = "Online"; pembayaran di loket tanpa nama kasir (data lama sebelum
     * nama kasir dicatat) = "-", bukan "Online".
     */
    public static function cashierLabel(LedgerEntry $row): string
    {
        if ($row->cashier_name) {
            return $row->cashier_name;
        }

        $atCounter = $row->pos_shift_id !== null || strtoupper((string) $row->payment_gateway) === 'CASHIER_POS'
            || in_array($row->source, ['POS_WALKIN_PADEL', 'RESCHEDULE_DELTA_POS', 'POS_MEMBERSHIP', 'POS_FNB'], true);

        return $atCounter ? '-' : 'Online';
    }

    public static function categoriesLabel(?string $csv): string
    {
        return collect(explode(',', (string) $csv))->filter()->unique()
            ->map(fn ($c) => LedgerEntry::categoryLabel($c))->implode(' + ');
    }

    /** Opsi filter metode bayar (kode → label yang tercatat), di-cache 10 menit. */
    /**
     * QRIS manual (QR statis + RRN di kasir), QRIS otomatis dari layar kasir, dan QRIS online customer sama-sama
     * berkode `QRIS`. Dibedakan dari kolom buku yang sudah ada sejak awal (gateway & shift kasir), jadi baris lama
     * ikut terpilah tanpa mengubah data. Kode `QRIS` polos tetap berarti semua QRIS (tautan lama).
     */
    public const QRIS_MANUAL = 'QRIS-MANUAL';

    public const QRIS_KASIR = 'QRIS-KASIR';

    public const QRIS_ONLINE = 'QRIS-ONLINE';

    public const QRIS_VARIANTS = [
        self::QRIS_MANUAL => 'QRIS Static',
        self::QRIS_KASIR => 'QR Online (Kasir)',
        self::QRIS_ONLINE => 'QRIS (Online Customer)',
    ];

    /** Gateway pembayaran di loket (bukti diketik kasir); selain ini = payment gateway (DOKU / Midtrans). */
    private const COUNTER_GATEWAY = 'CASHIER_POS';

    /** Kode metode kartu lewat mesin EDC di kasir. */
    private const EDC_METHODS = ['DEBIT_CARD', 'CREDIT_CARD', 'DEBIT', 'CREDIT', 'EDC_BCA', 'EDC_MANDIRI', 'EDC_LAINNYA'];

    /** Bank penerbit & jaringan kartu (nilai dropdown form EDC kasir). */
    private const CARD_ISSUERS = ['BCA' => 'BCA', 'MANDIRI' => 'Mandiri', 'BNI' => 'BNI', 'BRI' => 'BRI', 'CIMB' => 'CIMB Niaga',
        'PERMATA' => 'Permata', 'DANAMON' => 'Danamon', 'BSI' => 'BSI', 'LAINNYA' => 'Bank lain'];

    private const CARD_NETWORKS = ['GPN' => 'GPN', 'MASTERCARD' => 'Mastercard', 'VISA' => 'Visa', 'JCB' => 'JCB', 'AMEX' => 'Amex', 'LAINNYA' => 'Jaringan lain'];

    /** payment_id => rincian kartu EDC (null = tidak ada); diisi primeEdc() / per baris, dikosongkan flushMemo(). */
    private static array $edcMemo = [];

    /**
     * Nama metode bayar yang ditampilkan. Kartu EDC: debit/kredit + mesinnya (dari bukti EDC yang disimpan kasir, jadi
     * transaksi lama ikut). $qrisNames (tabel Buku Transaksi): QRIS kasir = "QRIS Static" / "QR Online (DOKU|Midtrans)".
     * Hanya tampilan — label yang tersimpan di buku tidak diubah.
     */
    public static function methodLabel(LedgerEntry $row, bool $qrisNames = false): string
    {
        $stored = (string) ($row->payment_method_label ?? $row->payment_method ?? '');

        if ($edc = self::edcDetails($row)) {
            $terminal = $edc['terminal'] ?? (in_array($row->payment_method, ['EDC_BCA', 'EDC_MANDIRI', 'EDC_LAINNYA'], true) ? $row->payment_method : null);
            $machine = $terminal ? (\App\Models\Pos\PosCashierShift::EDC_TERMINAL_LABELS[$terminal] ?? $terminal) : null;

            return ($edc['card_type'] === 'CREDIT' ? 'Kartu Kredit' : 'Kartu Debit').($machine ? ' — '.$machine : ' (EDC)');
        }

        if ($qrisNames) {
            return match (self::qrisVariant($row)) {
                self::QRIS_MANUAL => 'QRIS Static',
                self::QRIS_KASIR => 'QR Online ('.self::gatewayName($row->payment_gateway).')',
                default => $stored,
            };
        }

        return $stored;
    }

    /** Bukti / referensi yang ditampilkan. Kartu EDC: bank · jaringan · 4 digit · APPR · TRACE. */
    public static function referenceLabel(LedgerEntry $row): ?string
    {
        if ($edc = self::edcDetails($row)) {
            $parts = array_filter([
                $edc['issuer'] ? (self::CARD_ISSUERS[$edc['issuer']] ?? $edc['issuer']) : null,
                $edc['network'] ? (self::CARD_NETWORKS[$edc['network']] ?? $edc['network']) : null,
                $edc['last4'] ? '••••'.$edc['last4'] : null,
                $edc['approval'] ? 'APPR '.$edc['approval'] : null,
                $edc['trace'] ? 'TRACE '.$edc['trace'] : null,
            ]);
            if ($parts !== []) {
                return implode(' · ', $parts);
            }
        }

        return $row->payment_reference;
    }

    /** Jenis QRIS satu baris (sama dengan qrisVariantSql), null kalau bukan QRIS. */
    public static function qrisVariant(LedgerEntry $row): ?string
    {
        if ($row->payment_method !== 'QRIS') {
            return null;
        }
        if ($row->payment_gateway === null || $row->payment_gateway === self::COUNTER_GATEWAY) {
            return self::QRIS_MANUAL;
        }

        return $row->pos_shift_id !== null ? self::QRIS_KASIR : self::QRIS_ONLINE;
    }

    /**
     * Ambil rincian kartu EDC untuk banyak baris sekaligus (satu query) — tabel per halaman & PDF per kelompok.
     *
     * @param  iterable<LedgerEntry>  $rows
     */
    public static function primeEdc(iterable $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row instanceof LedgerEntry && $row->payment_id && in_array($row->payment_method, self::EDC_METHODS, true)
                && ! array_key_exists($row->payment_id, self::$edcMemo)) {
                $ids[$row->payment_id] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        foreach (array_keys($ids) as $id) {
            self::$edcMemo[$id] = null;
        }
        \App\Models\Pos\Payment::query()->whereIn('id', array_keys($ids))->get(['id', 'payload_log'])
            ->each(function ($payment) {
                self::$edcMemo[$payment->id] = self::edcFromPayload($payment->payload_log);
            });
    }

    /** @return array{terminal: ?string, card_type: string, issuer: ?string, network: ?string, last4: ?string, approval: ?string, trace: ?string}|null */
    private static function edcDetails(LedgerEntry $row): ?array
    {
        if (! $row->payment_id || ! in_array($row->payment_method, self::EDC_METHODS, true)) {
            return null;
        }
        if (! array_key_exists($row->payment_id, self::$edcMemo)) {
            if (count(self::$edcMemo) > 5000) {
                self::$edcMemo = []; // export Excel ribuan baris: memo tidak dibiarkan membesar
            }
            self::$edcMemo[$row->payment_id] = self::edcFromPayload(\App\Models\Pos\Payment::whereKey($row->payment_id)->first(['id', 'payload_log'])?->payload_log);
        }

        return self::$edcMemo[$row->payment_id];
    }

    private static function edcFromPayload(mixed $log): ?array
    {
        $log = is_string($log) ? json_decode($log, true) : $log;
        $edc = is_array($log) && is_array($log['edc_details'] ?? null) ? $log['edc_details'] : null;
        if (! $edc) {
            return null;
        }
        // Isian kasir (bank / jaringan tidak divalidasi ke daftar) dipotong supaya kolom tetap rapi.
        $text = fn (string $key) => is_scalar($edc[$key] ?? null) && trim((string) $edc[$key]) !== '' ? mb_substr(trim((string) $edc[$key]), 0, 30) : null;

        return [
            'terminal' => ($t = $text('terminal')) ? strtoupper($t) : null,
            'card_type' => strtoupper((string) $text('card_type')) === 'CREDIT' ? 'CREDIT' : 'DEBIT',
            'issuer' => ($i = $text('card_issuer')) ? strtoupper($i) : null,
            'network' => ($n = $text('card_network')) ? strtoupper($n) : null,
            'last4' => $text('card_last_4'),
            'approval' => $text('approval_code'),
            'trace' => $text('trace_number'),
        ];
    }

    private static function gatewayName(?string $gateway): string
    {
        return match (strtoupper((string) $gateway)) {
            'DOKU' => 'DOKU',
            'MIDTRANS' => 'Midtrans',
            default => ucfirst(strtolower((string) $gateway)) ?: '-',
        };
    }

    /** Ekspresi SQL jenis QRIS satu baris buku (NULL kalau bukan QRIS) — dipakai rincian Analytics & pilihan filter. */
    public static function qrisVariantSql(): string
    {
        return "CASE WHEN payment_method <> 'QRIS' THEN NULL"
            ." WHEN payment_gateway IS NULL OR payment_gateway = '".self::COUNTER_GATEWAY."' THEN '".self::QRIS_MANUAL."'"
            ." WHEN pos_shift_id IS NOT NULL THEN '".self::QRIS_KASIR."'"
            ." ELSE '".self::QRIS_ONLINE."' END";
    }

    /** Filter Metode Bayar: kode metode biasa + jenis QRIS (lihat QRIS_VARIANTS). */
    public static function applyMethods(Builder $query, array $values): Builder
    {
        $values = array_values(array_filter(array_map('strval', $values), 'filled'));
        if ($values === []) {
            return $query;
        }
        $codes = array_values(array_diff($values, array_keys(self::QRIS_VARIANTS)));
        $variants = array_values(array_intersect($values, array_keys(self::QRIS_VARIANTS)));
        $counter = fn (Builder $q) => $q->whereNull('payment_gateway')->orWhere('payment_gateway', self::COUNTER_GATEWAY);
        $gateway = fn (Builder $q) => $q->whereNotNull('payment_gateway')->where('payment_gateway', '<>', self::COUNTER_GATEWAY);

        return $query->where(function (Builder $q) use ($codes, $variants, $counter, $gateway) {
            if ($codes !== []) {
                $q->orWhereIn('payment_method', $codes);
            }
            foreach ($variants as $variant) {
                $q->orWhere(fn (Builder $v) => match ($variant) {
                    self::QRIS_MANUAL => $v->where('payment_method', 'QRIS')->where($counter),
                    self::QRIS_KASIR => $v->where('payment_method', 'QRIS')->where($gateway)->whereNotNull('pos_shift_id'),
                    default => $v->where('payment_method', 'QRIS')->where($gateway)->whereNull('pos_shift_id'),
                });
            }
        });
    }

    public static function methodOptions(): array
    {
        $query = function () {
            $options = LedgerEntry::query()->toBase()
                ->selectRaw('payment_method, MAX(payment_method_label) as label')
                ->whereNotNull('payment_method')->groupBy('payment_method')->orderBy('payment_method')->limit(100)->get()
                ->mapWithKeys(fn ($r) => [$r->payment_method => $r->label ? "{$r->label} ({$r->payment_method})" : $r->payment_method])->all();

            // QRIS dipecah per jenis (dulu satu pilihan berlabel salah satu jenis saja, mis. "QRIS Otomatis (Kasir)").
            if (array_key_exists('QRIS', $options)) {
                $present = LedgerEntry::query()->toBase()->where('payment_method', 'QRIS')
                    ->selectRaw(self::qrisVariantSql().' as variant')->distinct()->pluck('variant')->all();
                unset($options['QRIS']);
                foreach (self::QRIS_VARIANTS as $key => $label) {
                    if (in_array($key, $present, true)) {
                        $options[$key] = $label;
                    }
                }
            }

            return $options;
        };

        try {
            return Cache::remember('ledger:method_options:v3', 600, $query);
        } catch (Throwable) {
            return $query();
        }
    }

    public static function cashierOptions(): array
    {
        $query = fn () => LedgerEntry::query()->toBase()
            ->selectRaw('cashier_id, MAX(cashier_name) as name')
            ->whereNotNull('cashier_id')->groupBy('cashier_id')->orderBy('name')->limit(200)->get()
            ->mapWithKeys(fn ($r) => [$r->cashier_id => $r->name ?: $r->cashier_id])->all();

        try {
            return Cache::remember('ledger:cashier_options', 600, $query);
        } catch (Throwable) {
            return $query();
        }
    }

    /** Whitelist parameter route export (nilai enum dicek terhadap daftar yang dikenal). */
    public static function filterRules(): array
    {
        return [
            'periode' => ['nullable', Rule::in(array_keys(self::PRESETS))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'array', 'max:'.count(LedgerEntry::SOURCES)],
            'source.*' => ['string', Rule::in(array_keys(LedgerEntry::SOURCES))],
            'category' => ['nullable', 'array', 'max:'.count(LedgerEntry::CATEGORIES)],
            'category.*' => ['string', Rule::in(array_keys(LedgerEntry::CATEGORIES))],
            'method' => ['nullable', 'array', 'max:50'],
            'method.*' => ['string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'cashier_id' => ['nullable', 'string', 'ulid'],
            'pos_shift_id' => ['nullable', 'string', 'ulid'],
            'status' => ['nullable', 'array', 'max:'.count(self::STATUSES)],
            'status.*' => ['string', Rule::in(array_keys(self::STATUSES))],
            'search' => ['nullable', 'string', 'max:200'],
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ];
    }

    /**
     * Cegah CSV/Formula injection: sel yang diawali = + - @ (atau tab/CR) dieksekusi sebagai rumus oleh Excel.
     * Nama customer & catatan refund berasal dari input user.
     */
    public static function cellSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private static function dateOnly(?string $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->toDateString() : null;
    }
}
