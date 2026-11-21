<?php

namespace App\Services\Finance;

use App\Models\Finance\LedgerEntry;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Modul 17 Fase 3 — angka dashboard Analytics & Keuangan dari buku besar ledger_entries (sumber yang sama dengan
 * Buku Transaksi, jadi F&B ikut terhitung dan angkanya selalu cocok). Semua agregasi di SQL.
 */
class LedgerAnalytics
{
    public const DIMENSIONS = ['category', 'source', 'method'];

    /** Rentang lebih panjang dari ini ditampilkan per bulan, bukan per hari. */
    public const MAX_DAILY_POINTS = 62;

    /**
     * Rincian per kategori / sumber / metode bayar. Uang masuk & refund dipisah; "bersih" = setelah refund.
     *
     * @return list<array{key: string, label: string, method_code: ?string, money_in: float, refunds: float, money_net: float, net: float, tax: float, service: float, transactions: int}>
     */
    public static function breakdown(Builder $base, string $dimension): array
    {
        $expression = match ($dimension) {
            'category' => 'category',
            'source' => 'source',
            // Per penyedia/bank (label snapshot: "EDC Mandiri Debit", "BCA Virtual Account"), bukan cuma kode. QRIS per jenis
            // (manual / otomatis kasir / online): labelnya bisa sama antar-jenis, dan tautannya harus membuka jenis yang sama.
            'method' => 'COALESCE('.LedgerReport::qrisVariantSql().", payment_method_label, payment_method, '-')",
        };

        $rows = (clone $base)->toBase()
            ->selectRaw("{$expression} as dim,
                MAX(payment_method) as method_code,
                COALESCE(SUM(CASE WHEN entry_type <> 'REFUND' THEN total_amount ELSE 0 END), 0) as money_in,
                COALESCE(SUM(CASE WHEN entry_type = 'REFUND' THEN total_amount ELSE 0 END), 0) as refunds,
                COALESCE(SUM(total_amount), 0) as money_net,
                COALESCE(SUM(net_amount), 0) as net,
                COALESCE(SUM(tax_amount), 0) as tax,
                COALESCE(SUM(service_amount), 0) as service,
                COUNT(DISTINCT CASE WHEN entry_type <> 'REFUND' THEN payment_id END) as transactions")
            // Kelompokkan lewat alias: MySQL (ONLY_FULL_GROUP_BY) tidak selalu menganggap ekspresi CASE QRIS di SELECT sama
            // dengan salinannya di GROUP BY (error 1055); alias "dim" dipahami MySQL & SQLite.
            ->groupBy('dim')
            ->orderByDesc('money_net')
            ->get();

        return $rows->map(fn ($r) => [
            'key' => (string) $r->dim,
            'label' => match ($dimension) {
                'category' => LedgerEntry::categoryLabel($r->dim),
                'source' => LedgerEntry::sourceLabel($r->dim),
                'method' => LedgerReport::QRIS_VARIANTS[$r->dim] ?? (string) $r->dim,
            },
            'method_code' => array_key_exists((string) $r->dim, LedgerReport::QRIS_VARIANTS) ? (string) $r->dim : $r->method_code,
            'money_in' => (float) $r->money_in,
            'refunds' => (float) $r->refunds,
            'money_net' => (float) $r->money_net,
            'net' => (float) $r->net,
            'tax' => (float) $r->tax,
            'service' => (float) $r->service,
            'transactions' => (int) $r->transactions,
        ])->all();
    }

    /**
     * Tren uang masuk & refund per hari WIB (per bulan kalau rentangnya panjang). Hari tanpa transaksi = 0.
     *
     * @return array{unit: 'day'|'month', points: list<array{key: string, label: string, money_in: float, refunds: float, money_net: float}>}
     */
    public static function trend(Builder $base, ?string $from, ?string $until, int $maxDailyPoints = self::MAX_DAILY_POINTS): array
    {
        $today = Carbon::now(LedgerReport::TIMEZONE)->startOfDay();
        $first = (clone $base)->min('occurred_at');
        $start = $from ? Carbon::parse($from, LedgerReport::TIMEZONE) : ($first ? Carbon::parse($first)->setTimezone(LedgerReport::TIMEZONE)->startOfDay() : $today->copy());
        $end = $until ? Carbon::parse($until, LedgerReport::TIMEZONE) : $today->copy();
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $unit = $start->diffInDays($end) + 1 > $maxDailyPoints ? 'month' : 'day';
        $bucket = self::localBucketExpression($unit);

        $sums = (clone $base)->toBase()
            ->selectRaw("{$bucket} as bucket,
                COALESCE(SUM(CASE WHEN entry_type <> 'REFUND' THEN total_amount ELSE 0 END), 0) as money_in,
                COALESCE(SUM(CASE WHEN entry_type = 'REFUND' THEN total_amount ELSE 0 END), 0) as refunds")
            ->groupByRaw($bucket)
            ->get()
            ->keyBy(fn ($r) => (string) $r->bucket);

        $period = $unit === 'day'
            ? CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay())
            : CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end->copy()->startOfMonth());

        $points = [];
        foreach ($period as $date) {
            $key = $unit === 'day' ? $date->format('Y-m-d') : $date->format('Y-m');
            $in = (float) ($sums[$key]->money_in ?? 0);
            $out = (float) ($sums[$key]->refunds ?? 0);
            $points[] = [
                'key' => $key,
                'label' => $unit === 'day' ? $date->translatedFormat('d M') : $date->translatedFormat('M Y'),
                'money_in' => $in,
                'refunds' => $out,
                'money_net' => $in + $out,
            ];
        }

        return ['unit' => $unit, 'points' => $points];
    }

    /**
     * Tanggal / bulan WIB dari occurred_at (disimpan di zona waktu aplikasi) sebagai ekspresi SQL — server
     * production memakai Asia/Jakarta, test memakai UTC; WIB tidak punya DST jadi selisihnya tetap.
     */
    private static function localBucketExpression(string $unit): string
    {
        $offset = Carbon::now(LedgerReport::TIMEZONE)->utcOffset() - Carbon::now(config('app.timezone'))->utcOffset();

        if (DB::connection()->getDriverName() === 'sqlite') {
            $shifted = $offset === 0 ? 'occurred_at' : sprintf("datetime(occurred_at, '%+d minutes')", $offset);

            return $unit === 'day' ? "date({$shifted})" : "strftime('%Y-%m', {$shifted})";
        }

        $shifted = $offset === 0 ? 'occurred_at' : sprintf('DATE_ADD(occurred_at, INTERVAL %d MINUTE)', $offset);

        return $unit === 'day' ? "DATE({$shifted})" : "DATE_FORMAT({$shifted}, '%Y-%m')";
    }
}
