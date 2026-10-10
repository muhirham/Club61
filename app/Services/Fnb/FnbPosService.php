<?php

namespace App\Services\Fnb;

use App\Models\Fnb\FnbMenu;
use App\Models\Pos\Order;
use App\Models\User;
use App\Services\Finance\TaxAndFeeService;
use App\Services\Payment\PaymentOrchestratorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checkout kasir untuk penjualan menu F&B — sengaja meniru skema pembayaran yang PERSIS sama
 * dengan walk-in booking lapangan (BookOfflineCourt + PadelBookingService::processWalkInCheckout()):
 * tabel Order/OrderItem generik yang sama, penyelesaian pembayaran lewat satu-satunya penulis
 * status pelunasan di aplikasi ini (PaymentOrchestratorService::markOrderAsPaid()), shift kasir
 * yang wajib aktif, dan kebijakan 100% Cashless (CASH ditolak) yang berlaku di seluruh venue.
 */
class FnbPosService
{
    public function __construct(private PaymentOrchestratorService $orchestrator)
    {
    }

    /**
     * @param  array<int, array{menu_id: string, quantity: int, notes?: ?string}>  $items
     * @param  array<string, mixed>  $paymentMeta
     */
    public function checkout(
        array $items,
        string $orderType,
        ?string $tableNumber,
        User $cashier,
        string $paymentMethod,
        array $paymentMeta = [],
        ?string $customerName = null,
    ): array {
        abort_if(empty($items), 422, 'Keranjang masih kosong.');

        $method = strtoupper($paymentMethod);
        abort_if(
            in_array($method, ['CASH', 'TUNAI'], true),
            422,
            'Pembayaran tunai (CASH) tidak diperbolehkan. Venue Club 61 beroperasi 100% Cashless — gunakan QRIS, EDC, atau Transfer.'
        );

        return DB::transaction(function () use ($items, $orderType, $tableNumber, $customerName, $cashier, $method, $paymentMeta) {
            $subtotal = 0.0;
            $orderItemsData = [];

            foreach ($items as $entry) {
                // Row-level lock sebelum baca is_available — pola sama dengan CourtEquipment di
                // checkout padel (anti-race kalau menu dimatikan staf di tab lain persis saat
                // kasir checkout).
                $menu = FnbMenu::query()->lockForUpdate()->find($entry['menu_id'] ?? null);

                if (! $menu || ! $menu->is_available) {
                    // Item tidak tersedia diam-diam dilewati — konsisten dengan perlakuan
                    // CourtEquipment nonaktif di checkout padel (bukan menggagalkan seluruh order).
                    continue;
                }

                $quantity = max(1, (int) ($entry['quantity'] ?? 1));
                // Catatan untuk bar / dapur ("less sugar", "tanpa es") — dicetak di slip pesanan stasiunnya.
                $notes = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) ($entry['notes'] ?? '')), 0, 120)) ?: null;
                $lineSubtotal = (float) $menu->base_price * $quantity;
                $subtotal += $lineSubtotal;

                $orderItemsData[] = [
                    'item_type' => 'FNB',
                    'reference_id' => $menu->id,
                    'item_name' => $menu->name,
                    'quantity' => $quantity,
                    'unit_price' => $menu->base_price,
                    'subtotal' => $lineSubtotal,
                    'notes' => $notes,
                ];
            }

            abort_if(empty($orderItemsData), 422, 'Semua item di keranjang sudah tidak tersedia / habis.');

            $finance = app(TaxAndFeeService::class)->calculate($subtotal, 0, 'POS_WALKIN', 'FNB');

            $orderNumber = 'ORD-FNB-'.strtoupper(Str::random(10));

            // Nomor antrian sederhana untuk dipanggil di konter — reset harian, dihitung dari
            // jumlah order F&B hari ini (pola sama dengan PosCashierShift::generateShiftNumber()).
            $today = now('Asia/Jakarta')->toDateString();
            $queueNumber = Order::query()
                ->whereHas('items', fn ($q) => $q->where('item_type', 'FNB'))
                ->whereDate('created_at', $today)
                ->count() + 1;

            $order = Order::create([
                'order_number' => $orderNumber,
                'queue_number' => $queueNumber,
                'user_id' => null,
                'cashier_id' => $cashier->id,
                'order_type' => $orderType,
                'table_number' => $tableNumber,
                'customer_name' => $customerName,
                'subtotal' => $finance['subtotal'],
                'discount_amount' => 0,
                'tax_amount' => $finance['tax_amount'],
                'service_charge' => $finance['admin_fee_amount'],
                'grand_total' => $finance['grand_total'],
                'payment_status' => 'UNPAID',
            ]);

            foreach ($orderItemsData as $data) {
                $order->items()->create($data);
            }

            // Satu-satunya jalur pelunasan yang sah di seluruh aplikasi — sama persis dengan yang
            // dipakai walk-in padel (shift gating, idempoten, delegasi fulfillment). Begitu lunas,
            // FnbFulfillmentHandler membuat slip pesanan per stasiun (Kitchen, dst.) untuk dicetak
            // di printer stasiunnya.
            // Struktur payload_log sengaja identik dengan walk-in padel (edc_details /
            // qris_details) supaya audit & rekonsiliasi closing shift membaca format yang sama.
            $payloadLog = [
                'source' => 'FNB_POS',
                'cashier_id' => $cashier->id,
                'cashier_name' => $cashier->name,
                'payment_method' => $method,
            ];

            // Bayar Otomatis di layar kasir: order menunggu bayar, popup QR / VA tampil, lunas terkonfirmasi otomatis.
            if ($method === 'QRIS_MIDTRANS') {
                $payloadLog['payment_method'] = 'QRIS';

                return [
                    'order' => $order->fresh(['items', 'payments']),
                    'finance' => $finance,
                    'pending_qris' => app(\App\Services\Pos\PosMidtransQrisService::class)->open($order, 'FNB_COUNTER', $cashier, $payloadLog, (string) ($paymentMeta['pos_online_method'] ?? 'QRIS')),
                ];
            }

            if (in_array($method, ['QRIS', 'QRIS_STATIS'], true)) {
                $payloadLog['qris_details'] = [
                    'provider' => $paymentMeta['qris_provider'] ?? 'BCA_QRIS',
                    'rrn' => $paymentMeta['qris_rrn'] ?? null,
                    'sender_name' => $paymentMeta['qris_sender_name'] ?? null,
                ];
            } elseif ($paymentMeta !== []) {
                $payloadLog['edc_details'] = array_merge($paymentMeta, [
                    'charged_amount' => (float) $finance['grand_total'],
                ]);
            }

            $this->orchestrator->markOrderAsPaid($order, [
                'payment_gateway' => 'CASHIER_POS',
                'counter' => 'FNB_COUNTER',
                'transaction_id' => $orderNumber,
                'payment_method' => $method,
                'amount' => $finance['grand_total'],
                'payload_log' => $payloadLog,
            ]);

            return [
                'order' => $order->fresh(['items', 'payments']),
                'finance' => $finance,
            ];
        });
    }
}
