<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Monitoring\Concerns\ReadsHealthSnapshot;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/** Kartu "Storage Club 61": disk VPS + perkiraan penuh, ukuran database & tabel terbesar, ukuran folder file. */
class StorageCard extends Card
{
    use ReadsHealthSnapshot;

    public function render(): Renderable
    {
        $snapshot = $this->snapshot();

        return View::make('livewire.monitoring.storage-card', [
            'snapshot' => $snapshot,
            'storage' => $snapshot['storage'] ?? null,
            'stale' => $this->isStale($snapshot),
        ]);
    }
}
