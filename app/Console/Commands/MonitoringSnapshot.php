<?php

namespace App\Console\Commands;

use App\Support\Monitoring\HealthSnapshot;
use Illuminate\Console\Command;

/**
 * Hitung data kartu Club 61 di dashboard Monitoring Sistem (storage, layanan, pembayaran) dan simpan ke
 * storage/app/monitoring/snapshot.json. Dijadwalkan tiap 5 menit; waktu snapshot sekaligus jadi tanda scheduler hidup.
 * Hanya membaca data aplikasi.
 */
class MonitoringSnapshot extends Command
{
    protected $signature = 'monitoring:snapshot';

    protected $description = 'Hitung data dashboard Monitoring Sistem (storage, layanan, pembayaran)';

    public function handle(): int
    {
        $snapshot = HealthSnapshot::capture();

        foreach (['storage', 'services', 'payments'] as $section) {
            if (isset($snapshot[$section]['error'])) {
                $this->warn("Bagian {$section} gagal: {$snapshot[$section]['error']}");
            }
        }

        $disk = $snapshot['storage']['disk'] ?? null;
        $this->info('Snapshot tersimpan'.($disk ? ' — disk terpakai '.($disk['percent'] ?? '-').'%' : '').'.');

        return self::SUCCESS;
    }
}
