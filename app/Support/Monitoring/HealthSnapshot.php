<?php

namespace App\Support\Monitoring;

use App\Models\Audit\ActivityLog;
use App\Models\Payment\DokuNotification;
use App\Models\Pos\Payment;
use App\Models\Pos\Refund;
use App\Services\Padel\BookingTimeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Data kartu Club 61 di dashboard Monitoring Sistem (Pulse): Storage, Layanan, Pembayaran.
 *
 * Dihitung tugas terjadwal `monitoring:snapshot` (tiap 5 menit) lalu disimpan sebagai file JSON di disk lokal privat —
 * halaman dashboard hanya membaca file itu, jadi membuka dashboard tidak menghitung ukuran folder / database. Tidak
 * memakai tabel Pulse, sehingga logika ini bisa dipakai ulang kalau dashboard nanti diganti versi buatan sendiri.
 * Hanya MEMBACA data aplikasi; tidak ada yang diubah.
 */
final class HealthSnapshot
{
    public const FILE = 'monitoring/snapshot.json';

    public const HISTORY_FILE = 'monitoring/disk-history.json';

    /** Snapshot lebih tua dari ini = tugas terjadwal (scheduler) kemungkinan berhenti. */
    public const STALE_MINUTES = 15;

    public const TIMEZONE = 'Asia/Jakarta';

    private const HISTORY_DAYS = 60;

    private const GATEWAYS = ['MIDTRANS', 'DOKU'];

    /** Nama folder yang dikenal (lainnya ditampilkan apa adanya). */
    private const FOLDER_LABELS = [
        'logs' => 'Log aplikasi',
        'public/company-profile' => 'Konten website',
        'public/fnb' => 'Foto menu F&B',
        'public/merch' => 'Foto merchandise',
        'public/coaches' => 'Foto coach',
        'public/signature' => 'Konten Digital Signage (TV)',
        'corporate-transfer' => 'Bukti transfer corporate',
        'guides' => 'Panduan karyawan',
        'sales-imports' => 'File import penjualan',
        'livewire-tmp' => 'Upload sementara',
        'monitoring' => 'Data monitoring',
    ];

    /** Hitung semua bagian lalu simpan. */
    public static function capture(): array
    {
        $snapshot = (new self)->collect();
        Storage::disk('local')->put(self::FILE, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $snapshot;
    }

    public static function load(): ?array
    {
        try {
            $raw = Storage::disk('local')->get(self::FILE);
        } catch (\Throwable) {
            return null;
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public function collect(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'storage' => $this->section(fn () => $this->storage()),
            'services' => $this->section(fn () => $this->services()),
            'payments' => $this->section(fn () => $this->payments()),
        ];
    }

    /** Satu bagian gagal tidak boleh menghapus bagian lain. */
    private function section(\Closure $compute): array
    {
        try {
            return $compute();
        } catch (\Throwable $e) {
            return ['error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 300)];
        }
    }

    // ── Storage ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function storage(): array
    {
        $total = (float) (@disk_total_space(base_path()) ?: 0);
        $free = (float) (@disk_free_space(base_path()) ?: 0);
        $used = max(0, $total - $free);

        $history = $total > 0 ? $this->updateHistory($used, $total) : [];

        return [
            'disk' => [
                'total' => $total,
                'used' => $used,
                'free' => $free,
                'percent' => $total > 0 ? round($used / $total * 100, 1) : null,
                'days_until_full' => $this->daysUntilFull($history, $free),
            ],
            'history' => $history,
            'database' => $this->databaseSize(),
            'folders' => $this->folderSizes(),
        ];
    }

    /** @return list<array{date: string, used: float, total: float}> */
    private function updateHistory(float $used, float $total): array
    {
        $disk = Storage::disk('local');
        $points = [];
        try {
            $points = json_decode((string) $disk->get(self::HISTORY_FILE), true) ?: [];
        } catch (\Throwable) {
        }

        $points[now(self::TIMEZONE)->toDateString()] = ['used' => $used, 'total' => $total];
        ksort($points);
        $points = array_slice($points, -self::HISTORY_DAYS, null, true);
        $disk->put(self::HISTORY_FILE, json_encode($points));

        return array_map(fn ($date, $p) => ['date' => $date, 'used' => (float) $p['used'], 'total' => (float) $p['total']], array_keys($points), $points);
    }

    /** Perkiraan hari sampai disk penuh dari kenaikan rata-rata harian (butuh minimal 3 hari data). */
    private function daysUntilFull(array $history, float $free): ?int
    {
        if (count($history) < 3) {
            return null;
        }
        $first = $history[0];
        $last = $history[count($history) - 1];
        $days = max(1, Carbon::parse($first['date'])->diffInDays(Carbon::parse($last['date'])));
        $growthPerDay = ($last['used'] - $first['used']) / $days;

        return $growthPerDay > 0 ? (int) floor($free / $growthPerDay) : null;
    }

    private function databaseSize(): array
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Tanpa GROUP BY; ukuran dari information_schema (perkiraan MySQL, cukup untuk memantau tren).
            $rows = collect(DB::select(
                'SELECT table_name AS name, COALESCE(data_length, 0) + COALESCE(index_length, 0) AS size, table_rows AS row_count
                 FROM information_schema.tables WHERE table_schema = DATABASE()'
            ))->map(fn ($r) => ['name' => (string) $r->name, 'size' => (float) $r->size, 'rows' => (int) $r->row_count])
                ->sortByDesc('size')->values();

            return ['driver' => $driver, 'total' => (float) $rows->sum('size'), 'tables' => $rows->take(10)->all()];
        }

        if ($driver === 'sqlite') {
            $path = (string) config('database.connections.sqlite.database');

            return ['driver' => $driver, 'total' => is_file($path) ? (float) filesize($path) : null, 'tables' => []];
        }

        return ['driver' => $driver, 'total' => null, 'tables' => []];
    }

    /** @return list<array{key: string, label: string, size: float, files: int}> */
    private function folderSizes(): array
    {
        $targets = [];
        foreach (glob(storage_path('app/public/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $targets['public/'.basename($dir)] = $dir;
        }
        foreach (glob(storage_path('app/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== 'public') {
                $targets[basename($dir)] = $dir;
            }
        }
        $targets['logs'] = storage_path('logs');

        $folders = [];
        foreach ($targets as $key => $dir) {
            [$size, $files] = $this->directorySize($dir);
            $folders[] = ['key' => $key, 'label' => self::FOLDER_LABELS[$key] ?? $key, 'size' => $size, 'files' => $files];
        }
        usort($folders, fn ($a, $b) => $b['size'] <=> $a['size']);

        return $folders;
    }

    /** @return array{0: float, 1: int} */
    private function directorySize(string $dir): array
    {
        if (! is_dir($dir)) {
            return [0.0, 0];
        }
        $size = 0.0;
        $files = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += (float) $file->getSize();
                    $files++;
                }
            }
        } catch (\Throwable) {
        }

        return [$size, $files];
    }

    // ── Layanan ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function services(): array
    {
        return [
            'queue' => $this->queueStats(),
            'reverb' => $this->reverbStatus(),
            'environment' => (string) app()->environment(),
            'production' => app()->environment('production'),
            'app_debug' => (bool) config('app.debug'),
            'doku_debug_inbound' => (bool) config('services.doku.snap.debug_inbound'),
            'php_version' => PHP_VERSION,
            'php_cli_memory_limit' => (string) ini_get('memory_limit'),
            'laravel_version' => app()->version(),
            'uptime_seconds' => $this->uptimeSeconds(),
        ];
    }

    private function queueStats(): array
    {
        $connection = (string) config('queue.default');
        $stats = ['connection' => $connection, 'pending' => null, 'oldest_pending_at' => null, 'failed_total' => null, 'failed_24h' => null];

        $jobsTable = (string) config('queue.connections.database.table', 'jobs');
        if ($connection === 'database' && Schema::hasTable($jobsTable)) {
            $stats['pending'] = DB::table($jobsTable)->count();
            $oldest = DB::table($jobsTable)->min('created_at');
            $stats['oldest_pending_at'] = $oldest ? Carbon::createFromTimestamp((int) $oldest)->toIso8601String() : null;
        }

        $failedTable = (string) config('queue.failed.table', 'failed_jobs');
        if (Schema::hasTable($failedTable)) {
            $stats['failed_total'] = DB::table($failedTable)->count();
            $stats['failed_24h'] = DB::table($failedTable)->where('failed_at', '>=', now()->subDay())->count();
        }

        return $stats;
    }

    private function reverbStatus(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['used' => false, 'up' => null];
        }
        $host = (string) config('reverb.servers.reverb.host', '127.0.0.1');
        $host = in_array($host, ['0.0.0.0', '::', ''], true) ? '127.0.0.1' : $host;
        $port = (int) config('reverb.servers.reverb.port', 8080);

        $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
        $up = $socket !== false;
        if ($up) {
            fclose($socket);
        }

        return ['used' => true, 'up' => $up, 'address' => "{$host}:{$port}"];
    }

    private function uptimeSeconds(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');

        return is_string($raw) && $raw !== '' ? (int) explode(' ', $raw)[0] : null;
    }

    // ── Pembayaran ───────────────────────────────────────────────────────────────────────────────────────────────

    private function payments(): array
    {
        $since24 = now()->subDay();
        $since7 = now()->subDays(7);

        // Lunas lewat webhook (payment.paid) vs lewat polling / cek status (payment.paid_via_reconcile), gateway saja.
        $paid = ActivityLog::query()
            ->whereIn('event', ['payment.paid', 'payment.paid_via_reconcile'])
            ->where('created_at', '>=', $since7)
            ->get(['event', 'created_at', 'meta'])
            ->filter(fn (ActivityLog $log) => in_array(strtoupper((string) ($log->meta['gateway'] ?? '')), self::GATEWAYS, true));

        $count = fn (string $event, Carbon $since) => $paid->filter(fn ($l) => $l->event === $event && $l->created_at->gte($since))->count();
        $lastWebhook = [];
        foreach (self::GATEWAYS as $gateway) {
            $last = $paid->filter(fn ($l) => $l->event === 'payment.paid' && strtoupper((string) ($l->meta['gateway'] ?? '')) === $gateway)
                ->max('created_at');
            $lastWebhook[$gateway] = $last ? Carbon::parse($last)->toIso8601String() : null;
        }

        $late = ActivityLog::query()
            ->where('event', 'payment.late_settlement_refund')
            ->where('created_at', '>=', $since7)
            ->latest('created_at')
            ->limit(10)
            ->get(['created_at', 'meta']);

        return [
            'settled' => [
                'webhook_24h' => $count('payment.paid', $since24),
                'polling_24h' => $count('payment.paid_via_reconcile', $since24),
                'webhook_7d' => $count('payment.paid', $since7),
                'polling_7d' => $count('payment.paid_via_reconcile', $since7),
                'last_webhook' => $lastWebhook,
            ],
            'late_settlements' => [
                'count_7d' => ActivityLog::where('event', 'payment.late_settlement_refund')->where('created_at', '>=', $since7)->count(),
                'count_24h' => ActivityLog::where('event', 'payment.late_settlement_refund')->where('created_at', '>=', $since24)->count(),
                'latest' => $late->map(fn (ActivityLog $l) => [
                    'at' => $l->created_at?->toIso8601String(),
                    'order' => $l->meta['no_order'] ?? null,
                    'customer' => $l->meta['pelanggan'] ?? null,
                    'amount' => isset($l->meta['nominal_dibayar']) ? (float) $l->meta['nominal_dibayar'] : null,
                    'gateway' => $l->meta['gateway'] ?? null,
                ])->all(),
            ],
            'doku' => $this->dokuNotifications($since24, $since7),
            'hanging' => $this->hangingBills(),
            'refunds' => [
                'pending' => Refund::where('status', 'PENDING')->count(),
                'pending_gateway' => Refund::where('status', 'PENDING')
                    ->whereHas('payment', fn ($q) => $q->whereIn('payment_gateway', self::GATEWAYS))->count(),
            ],
        ];
    }

    private function dokuNotifications(Carbon $since24, Carbon $since7): ?array
    {
        if (! Schema::hasTable('doku_notifications')) {
            return null;
        }

        $byResult = DokuNotification::query()
            ->where('received_at', '>=', $since24)
            ->selectRaw('result, COUNT(*) AS total')
            ->groupBy('result')
            ->pluck('total', 'result')
            ->map(fn ($n) => (int) $n)
            ->all();

        // SUCCESS yang ditolak karena API status DOKU menyatakan lain (DokuNotificationProcessor::contradicted).
        $contradicted = DokuNotification::query()
            ->where('result', DokuNotification::IGNORED)
            ->where('result_note', 'like', 'API status:%')
            ->where('received_at', '>=', $since7);

        return [
            'by_result_24h' => $byResult,
            'exhausted_7d' => DokuNotification::where('result', DokuNotification::EXHAUSTED)->where('received_at', '>=', $since7)->count(),
            'contradicted_7d' => (clone $contradicted)->count(),
            'contradicted_latest' => (clone $contradicted)->latest('received_at')->limit(5)
                ->get(['invoice_number', 'amount', 'received_at', 'result_note'])
                ->map(fn (DokuNotification $n) => [
                    'invoice' => $n->invoice_number,
                    'amount' => $n->amount !== null ? (float) $n->amount : null,
                    'at' => $n->received_at?->toIso8601String(),
                    'note' => $n->result_note,
                ])->all(),
            'last_received_at' => ($last = DokuNotification::max('received_at')) ? Carbon::parse($last)->toIso8601String() : null,
        ];
    }

    /** Tagihan gateway yang sudah lewat batas bayar (+ jeda 3 menit) tapi masih PENDING — 24 jam terakhir. */
    private function hangingBills(): array
    {
        $minutes = app(BookingTimeService::class)->paymentWindowMinutes() + BookingTimeService::GRACE_MINUTES;

        $query = Payment::query()
            ->where('status', 'PENDING')
            ->whereIn('payment_gateway', self::GATEWAYS)
            ->where('created_at', '>=', now()->subDay())
            ->where('created_at', '<=', now()->subMinutes($minutes));

        return [
            'after_minutes' => $minutes,
            'count' => (clone $query)->count(),
            'latest' => (clone $query)->with('order:id,order_number')->latest()->limit(5)
                ->get(['id', 'order_id', 'payment_gateway', 'amount', 'created_at'])
                ->map(fn (Payment $p) => [
                    'order' => $p->order?->order_number,
                    'gateway' => $p->payment_gateway,
                    'amount' => (float) $p->amount,
                    'at' => $p->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    // ── Format tampilan ──────────────────────────────────────────────────────────────────────────────────────────

    public static function bytes(?float $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i >= 3 ? 1 : 0, ',', '.').' '.$units[$i];
    }

    public static function time(?string $iso, string $format = 'd M H:i'): string
    {
        return $iso ? Carbon::parse($iso)->timezone(self::TIMEZONE)->translatedFormat($format) : '-';
    }

    public static function ago(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->locale('id')->diffForHumans() : '-';
    }

    public static function rupiah(?float $amount): string
    {
        return $amount === null ? '-' : 'Rp '.number_format($amount, 0, ',', '.');
    }
}
