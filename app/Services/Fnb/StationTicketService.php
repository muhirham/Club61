<?php

namespace App\Services\Fnb;

use App\Models\Fnb\FnbMenu;
use App\Models\Pos\KitchenTicket;
use App\Models\Pos\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slip pesanan per stasiun F&B (Kitchen, Bar, ...). Dibuat SEKALI begitu order F&B lunas (FnbFulfillmentHandler — lunas
 * di kasir maupun lewat Midtrans), lalu tablet kasir mengirimnya ke printer LAN stasiun masing-masing dan melaporkan
 * hasilnya. Menu tanpa stasiun (atau stasiunnya nonaktif) dibuat di kasir dan tidak mendapat slip.
 */
class StationTicketService
{
    /** @return Collection<int, KitchenTicket> */
    public function createForOrder(Order $order): Collection
    {
        if ($order->payment_status !== 'PAID') {
            return collect();
        }

        return DB::transaction(function () use ($order) {
            // Kunci order: fulfillment jalan di tiap pembayaran sukses, dua jalur lunas bersamaan tidak boleh membuat slip ganda.
            Order::whereKey($order->id)->lockForUpdate()->first();

            $existing = KitchenTicket::where('order_id', $order->id)->get();
            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $items = $order->items()->where('item_type', 'FNB')->get();
            $menus = FnbMenu::with('station')->whereIn('id', $items->pluck('reference_id')->filter()->all())->get()->keyBy('id');

            return $items
                ->map(fn ($item) => ['item' => $item, 'station' => $menus[$item->reference_id]->station ?? null])
                ->filter(fn ($row) => $row['station'] && $row['station']->is_active)
                ->groupBy(fn ($row) => $row['station']->id)
                ->sortBy(fn ($rows) => [$rows->first()['station']->sort_order, $rows->first()['station']->name])
                ->map(fn ($rows) => KitchenTicket::create([
                    'order_id' => $order->id,
                    'station_id' => $rows->first()['station']->id,
                    'station_name' => $rows->first()['station']->name,
                    'items' => $rows->map(fn ($row) => [
                        'name' => $row['item']->item_name,
                        'quantity' => (int) $row['item']->quantity,
                        'notes' => $row['item']->notes,
                    ])->values()->all(),
                    'status' => 'QUEUED',
                    'created_at' => now(),
                ]))
                ->values();
        });
    }

    /**
     * Slip yang dikirim tablet kasir ke printer stasiun: { ticket_id, station_name, host, port, html }.
     *
     * @return array<int, array<string, mixed>>
     */
    public function printJobs(Order $order, bool $onlyUnprinted = false): array
    {
        $order->loadMissing('cashier');

        return KitchenTicket::with('station')
            ->where('order_id', $order->id)
            ->when($onlyUnprinted, fn ($q) => $q->whereNull('printed_at'))
            ->orderBy('created_at')
            ->get()
            ->map(fn (KitchenTicket $ticket) => [
                'ticket_id' => $ticket->id,
                'station_name' => $ticket->station_name,
                'host' => $ticket->station?->hasPrinter() ? trim($ticket->station->printer_host) : null,
                'port' => (int) ($ticket->station?->printer_port ?: 9100),
                'html' => view('pos.receipts.fnb-station-slip', ['slip' => $this->slipData($order, $ticket)])->render(),
            ])
            ->values()
            ->all();
    }

    public function recordPrintResult(KitchenTicket $ticket, bool $ok, ?string $error = null): KitchenTicket
    {
        $ticket->update([
            'print_attempts' => $ticket->print_attempts + 1,
            'printed_at' => $ok ? now() : $ticket->printed_at,
            'last_print_error' => $ok ? null : mb_substr(trim((string) $error) ?: 'Gagal mengirim ke printer stasiun.', 0, 255),
        ]);

        return $ticket;
    }

    /**
     * Status slip per stasiun untuk modal struk kasir.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusFor(Order $order): array
    {
        return KitchenTicket::where('order_id', $order->id)->orderBy('created_at')->get()
            ->map(fn (KitchenTicket $ticket) => [
                'ticket_id' => $ticket->id,
                'station_name' => $ticket->station_name,
                'printed_at' => $ticket->printed_at?->setTimezone('Asia/Jakarta')->format('H:i'),
                'error' => $ticket->last_print_error,
                'attempts' => $ticket->print_attempts,
            ])
            ->values()
            ->all();
    }

    protected function slipData(Order $order, KitchenTicket $ticket): array
    {
        return [
            'station_name' => $ticket->station_name,
            'items' => $ticket->items ?? [],
            'queue_number' => $order->queue_number,
            'order_type' => $order->order_type,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'order_number' => $order->order_number,
            'created_at' => $order->created_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'),
            'cashier_name' => $order->cashier?->name ?? '-',
        ];
    }
}
