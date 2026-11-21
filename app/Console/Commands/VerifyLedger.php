<?php

namespace App\Console\Commands;

use App\Models\Finance\LedgerEntry;
use App\Models\Pos\Payment;
use App\Models\Pos\Refund;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\LedgerWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Modul 17 FR-08: pemeriksaan konsistensi harian. Per hari (waktu Jakarta) jumlah uang masuk di buku harus sama dengan
 * jumlah pembayaran SUCCESS/DUPLICATE, dan jumlah refund di buku sama dengan refund PROCESSED. Selisih → Log Aktivitas
 * KRITIS + exit code gagal.
 */
class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify
        {--date= : Hari terakhir yang diperiksa (Y-m-d, waktu Jakarta; default kemarin)}
        {--days=1 : Jumlah hari ke belakang yang diperiksa}';

    protected $description = 'Cocokkan Buku Transaksi dengan pembayaran & refund per hari';

    public function handle(): int
    {
        $lastDay = $this->option('date')
            ? Carbon::parse($this->option('date'), 'Asia/Jakarta')->startOfDay()
            : Carbon::now('Asia/Jakarta')->subDay()->startOfDay();
        $days = max(1, (int) $this->option('days'));

        $rows = [];
        $mismatches = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $lastDay->copy()->subDays($i);
            $result = $this->checkDay($day);
            $rows[] = [
                $day->toDateString(),
                number_format($result['payments'], 2, ',', '.'),
                number_format($result['ledger_in'], 2, ',', '.'),
                number_format($result['refunds'], 2, ',', '.'),
                number_format($result['ledger_out'], 2, ',', '.'),
                $result['unrecorded'],
                $result['ok'] ? 'OK' : 'SELISIH',
            ];
            if (! $result['ok']) {
                $mismatches[] = $result;
            }
        }

        $this->table(['Tanggal', 'Pembayaran', 'Buku (masuk)', 'Refund', 'Buku (refund)', 'Belum tercatat', 'Status'], $rows);

        foreach ($mismatches as $m) {
            ActivityLogger::record(
                module: 'FINANCE',
                event: 'ledger.verify_mismatch',
                description: "SELISIH Riwayat Transaksi tanggal {$m['date']}: pembayaran ".ActivityLogger::rupiah($m['payments'])
                    .' vs buku '.ActivityLogger::rupiah($m['ledger_in']).', refund '.ActivityLogger::rupiah($m['refunds'])
                    .' vs buku '.ActivityLogger::rupiah($m['ledger_out']),
                meta: [
                    'tanggal' => $m['date'],
                    'pembayaran' => $m['payments'],
                    'buku_masuk' => $m['ledger_in'],
                    'refund' => $m['refunds'],
                    'buku_refund' => $m['ledger_out'],
                    'pembayaran_belum_tercatat' => $m['unrecorded_ids'],
                ],
                severity: ActivityLogger::CRITICAL,
                asSystem: true,
            );
        }

        if ($mismatches !== []) {
            $this->error(count($mismatches).' hari tidak cocok. Jalankan `php artisan ledger:backfill` untuk transaksi yang belum tercatat.');

            return self::FAILURE;
        }

        $this->info('Riwayat Transaksi cocok dengan pembayaran & refund.');

        return self::SUCCESS;
    }

    private function checkDay(Carbon $dayJakarta): array
    {
        $tz = config('app.timezone');
        $start = $dayJakarta->copy()->setTimezone($tz);
        $end = $dayJakarta->copy()->addDay()->setTimezone($tz);

        $payments = Payment::query()
            ->whereIn('status', LedgerWriter::MONEY_IN_STATUSES)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->get(['id', 'amount', 'payment_gateway', 'payload_log'])
            ->filter(fn (Payment $p) => LedgerWriter::isCountable($p));

        $recordedIds = LedgerEntry::whereIn('payment_id', $payments->pluck('id'))
            ->whereIn('entry_type', [LedgerEntry::TYPE_PAYMENT, LedgerEntry::TYPE_OVERPAYMENT])
            ->distinct()->pluck('payment_id')->all();
        $unrecorded = $payments->reject(fn (Payment $p) => in_array($p->id, $recordedIds, true));

        $ledgerIn = (float) LedgerEntry::whereIn('entry_type', [LedgerEntry::TYPE_PAYMENT, LedgerEntry::TYPE_OVERPAYMENT])
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->sum('total_amount');

        $refunds = (float) Refund::query()
            ->where('status', 'PROCESSED')
            ->where('processed_at', '>=', $start)->where('processed_at', '<', $end)
            ->where(fn ($q) => $q->whereDoesntHave('payment')->orWhereHas('payment', fn ($p) => $p->where('payment_gateway', '!=', 'MOCK')))
            ->sum('refund_amount');

        $ledgerOut = -(float) LedgerEntry::where('entry_type', LedgerEntry::TYPE_REFUND)
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->sum('total_amount');

        $paymentsTotal = (float) $payments->sum(fn (Payment $p) => (float) $p->amount);

        return [
            'date' => $dayJakarta->toDateString(),
            'payments' => round($paymentsTotal, 2),
            'ledger_in' => round($ledgerIn, 2),
            'refunds' => round($refunds, 2),
            'ledger_out' => round($ledgerOut, 2),
            'unrecorded' => $unrecorded->count(),
            'unrecorded_ids' => $unrecorded->pluck('id')->take(50)->values()->all(),
            'ok' => abs($paymentsTotal - $ledgerIn) < 0.01 && abs($refunds - $ledgerOut) < 0.01 && $unrecorded->isEmpty(),
        ];
    }
}
