<?php

namespace App\Livewire\Monitoring\Concerns;

use App\Support\Monitoring\HealthSnapshot;
use Carbon\Carbon;

/** Dipakai kartu Club 61 di dashboard Pulse: CSS kartu + data snapshot `monitoring:snapshot`. */
trait ReadsHealthSnapshot
{
    protected function css()
    {
        return resource_path('css/monitoring-cards.css');
    }

    /** Periode dashboard dalam bahasa Indonesia (periodForHumans() bawaan Pulse berbahasa Inggris: "hour"). */
    public function periodLabel(): string
    {
        return match ($this->period) {
            '6_hours' => '6 jam',
            '24_hours' => '24 jam',
            '7_days' => '7 hari',
            default => '1 jam',
        };
    }

    protected function snapshot(): ?array
    {
        return HealthSnapshot::load();
    }

    /** Snapshot tidak ada / terlalu lama = tugas terjadwal kemungkinan tidak berjalan. */
    protected function isStale(?array $snapshot): bool
    {
        $at = $snapshot['generated_at'] ?? null;

        return ! $at || Carbon::parse($at)->lt(now()->subMinutes(HealthSnapshot::STALE_MINUTES));
    }
}
