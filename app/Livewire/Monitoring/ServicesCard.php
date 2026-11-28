<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Monitoring\Concerns\ReadsHealthSnapshot;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/** Kartu "Layanan Club 61": tugas otomatis, antrian, realtime (Reverb), setelan berbahaya di production, info PHP. */
class ServicesCard extends Card
{
    use ReadsHealthSnapshot;

    public function render(): Renderable
    {
        $snapshot = $this->snapshot();

        return View::make('livewire.monitoring.services-card', [
            'snapshot' => $snapshot,
            'services' => $snapshot['services'] ?? null,
            'stale' => $this->isStale($snapshot),
            // Dibaca saat halaman dirender (PHP-FPM / web) — berbeda dengan batas memori tugas terjadwal (CLI).
            'webMemoryLimit' => (string) ini_get('memory_limit'),
        ]);
    }
}
