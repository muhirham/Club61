<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Monitoring\Concerns\ReadsHealthSnapshot;
use App\Support\Monitoring\LogAlertRecorder;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * Kartu "Pembayaran Club 61": lunas via webhook vs polling, uang masuk setelah booking batal, notifikasi DOKU bermasalah,
 * tagihan menggantung, refund menunggu (dari snapshot) + peringatan [ALERT] (dari Pulse, mengikuti periode dashboard).
 */
class PaymentsCard extends Card
{
    use ReadsHealthSnapshot;

    public function render(): Renderable
    {
        $snapshot = $this->snapshot();

        [$alerts, $time, $runAt] = $this->remember(
            fn () => $this->aggregate(LogAlertRecorder::TYPE, 'count', 'count', 'desc', 20)
                ->map(fn ($row) => (object) ['message' => $row->key, 'count' => (int) $row->count]),
        );

        return View::make('livewire.monitoring.payments-card', [
            'snapshot' => $snapshot,
            'payments' => $snapshot['payments'] ?? null,
            'stale' => $this->isStale($snapshot),
            'alerts' => $alerts,
            'refundUrl' => $this->adminUrl(\App\Filament\Pages\AntrianRefund::class),
            'logUrl' => $this->adminUrl(\App\Filament\Pages\LogAktivitas::class),
        ]);
    }

    private function adminUrl(string $page): ?string
    {
        try {
            return $page::getUrl(panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }
}
