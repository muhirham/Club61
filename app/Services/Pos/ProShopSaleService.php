<?php

namespace App\Services\Pos;

use App\Models\Merch\MerchVariant;
use App\Models\Padel\CourtEquipment;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\TaxAndFeeService;
use App\Services\Merch\MerchStockService;
use App\Services\Padel\BookingAddonService;
use App\Support\PersonName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Kasir Pro Shop tab Jual: perlengkapan padel bersifat Jual (bola dll.) + merchandise dalam satu transaksi, tanpa
 * booking & tanpa akun customer (nama + nomor HP pembeli disimpan di order). Stok dipotong saat transaksi dibuat;
 * Bayar Otomatis yang batal / kedaluwarsa mengembalikannya (PosPendingOrderCanceller).
 *  - Item EQUIPMENT (Buku Transaksi: Add-on Padel) & MERCH (Merchandise, HPP produk disimpan di order_items.unit_cost).
 *  - Pajak & biaya layanan dihitung per kelompok (modul PADEL / MERCH, pengaturan per modul bisa berbeda). Nominal
 *    tetap (FIXED) dikenakan sekali per transaksi, persentase dijumlahkan.
 * Total dihitung ulang di dalam kunci terhadap yang tampil di layar kasir.
 */
class ProShopSaleService
{
    public const SOURCE = 'PRO_SHOP_SALE';

    public const PERMISSION_MERCH = 'sell_merchandise';

    public const MAX_QUANTITY = BookingAddonService::MAX_QUANTITY;

    public const COUNTER = 'PADEL_FRONTDESK';

    /**
     * @param  array<string, int|string>  $equipmentQty  [equipment_id => qty]
     * @param  array<string, int|string>  $merchQty  [variant_id => qty]
     * @param  Collection<string, CourtEquipment>|null  $equipments  baris yang sudah dikunci (checkout)
     * @param  Collection<string, MerchVariant>|null  $variants  baris yang sudah dikunci, dengan relasi product (checkout)
     */
    public function quote(array $equipmentQty, array $merchQty, ?Collection $equipments = null, ?Collection $variants = null): array
    {
        $wantedEq = self::wanted($equipmentQty);
        $wantedMerch = self::wanted($merchQty);
        $equipments ??= CourtEquipment::whereIn('id', array_keys($wantedEq))->where('is_active', true)->get()->keyBy('id');
        $variants ??= MerchVariant::with('product')->whereIn('id', array_keys($wantedMerch))->get()->keyBy('id');

        $items = [];
        foreach ($wantedEq as $id => $qty) {
            $eq = $equipments->get($id);
            if (! $eq || ! $eq->is_active || ! $eq->isSale()) {
                continue;
            }
            $items[] = [
                'kind' => 'EQUIPMENT',
                'equipment_id' => $eq->id,
                'name' => $eq->name,
                'quantity' => $qty,
                'unit_price' => (float) $eq->rental_price,
                'subtotal' => round((float) $eq->rental_price * $qty, 2),
            ];
        }
        foreach ($wantedMerch as $id => $qty) {
            $variant = $variants->get($id);
            if (! $variant || ! self::sellable($variant)) {
                continue;
            }
            $items[] = [
                'kind' => 'MERCH',
                'variant_id' => $variant->id,
                'name' => self::itemName($variant),
                'quantity' => $qty,
                'unit_price' => $variant->price(),
                'unit_cost' => (float) $variant->product->hpp,
                'subtotal' => round($variant->price() * $qty, 2),
            ];
        }

        $groupSubtotals = array_filter([
            'PADEL' => round(array_sum(array_map(fn ($i) => $i['kind'] === 'EQUIPMENT' ? $i['subtotal'] : 0, $items)), 2),
            'MERCH' => round(array_sum(array_map(fn ($i) => $i['kind'] === 'MERCH' ? $i['subtotal'] : 0, $items)), 2),
        ], fn ($subtotal) => $subtotal > 0);

        $taxes = [];
        $fees = [];
        foreach ($groupSubtotals as $module => $subtotal) {
            $calc = app(TaxAndFeeService::class)->calculate(subtotal: $subtotal, discountAmount: 0, channel: 'POS_WALKIN', module: $module);
            $taxes[] = (float) $calc['tax_amount'];
            $fees[] = (float) $calc['admin_fee_amount'];
        }
        $settings = ClubFinanceSetting::getSettings();
        $tax = $taxes === [] ? 0.0 : ($settings->tax_type === 'FIXED' ? max($taxes) : array_sum($taxes));
        $fee = $fees === [] ? 0.0 : ($settings->admin_fee_type === 'PERCENTAGE' ? array_sum($fees) : max($fees));
        $subtotal = round(array_sum(array_column($items, 'subtotal')), 2);

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'tax_amount' => (float) $tax,
            'service_charge' => (float) $fee,
            'grand_total' => round($subtotal + $tax + $fee, 2),
        ];
    }

    /**
     * @param  array<string, int|string>  $equipmentQty  [equipment_id => qty]
     * @param  array<string, int|string>  $merchQty  [variant_id => qty]
     * @return array{order: Order, payment: ?Payment, pending_qris: ?array, quote: array}
     */
    public function checkout(array $equipmentQty, array $merchQty, float $expectedTotal, string $method, array $proof, string $buyerName, string $buyerPhone, User $cashier, ?string $onlineMethod = null): array
    {
        $wantedEq = self::wanted($equipmentQty);
        $wantedMerch = self::wanted($merchQty);
        if ($wantedEq === [] && $wantedMerch === []) {
            throw new HttpException(422, 'Pilih minimal satu barang yang dijual.');
        }
        if ($wantedEq !== [] && ! $cashier->can(BookingAddonService::PERMISSION)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [add_booking_equipment] untuk menjual perlengkapan padel.');
        }
        if ($wantedMerch !== [] && ! $cashier->can(self::PERMISSION_MERCH)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin ['.self::PERMISSION_MERCH.'] untuk menjual merchandise.');
        }

        $buyerName = trim(preg_replace('/\s+/u', ' ', $buyerName));
        $buyerPhone = preg_replace('/[^0-9]/', '', $buyerPhone);
        if ($buyerName === '' || mb_strlen($buyerName) > 100 || ! PersonName::isValid($buyerName)) {
            throw new HttpException(422, $buyerName === '' ? 'Nama pembeli wajib diisi.' : PersonName::MESSAGE);
        }
        if (strlen($buyerPhone) < 8 || strlen($buyerPhone) > 15) {
            throw new HttpException(422, 'Nomor WhatsApp / telepon pembeli harus 8–15 digit.');
        }
        $autoPay = strtoupper($method) === 'QRIS_MIDTRANS';

        return DB::transaction(function () use ($wantedEq, $wantedMerch, $expectedTotal, $method, $proof, $buyerName, $buyerPhone, $cashier, $onlineMethod, $autoPay) {
            // Kunci berurutan (id) supaya dua penjualan bersamaan tidak saling menunggu (deadlock).
            $equipments = CourtEquipment::whereIn('id', collect(array_keys($wantedEq))->sort()->values())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variants = MerchVariant::with('product')->whereIn('id', collect(array_keys($wantedMerch))->sort()->values())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Barang yang sudah nonaktif / alat Sewa yang ikut terkirim tidak boleh diam-diam dilewati.
            foreach (array_keys($wantedEq) as $id) {
                $eq = $equipments->get($id);
                if (! $eq || ! $eq->is_active) {
                    throw new HttpException(422, 'Ada perlengkapan yang sudah tidak aktif. Muat ulang halaman lalu pilih lagi.');
                }
                if (! $eq->isSale()) {
                    throw new HttpException(422, "{$eq->name} adalah alat Sewa — hanya bisa ditambahkan ke booking, tidak bisa dijual tanpa booking.");
                }
            }
            foreach (array_keys($wantedMerch) as $id) {
                $variant = $variants->get($id);
                if (! $variant || ! self::sellable($variant)) {
                    throw new HttpException(422, 'Ada merchandise yang sudah tidak dijual. Muat ulang halaman lalu pilih lagi.');
                }
            }

            $quote = $this->quote($wantedEq, $wantedMerch, $equipments, $variants);
            if (abs($quote['grand_total'] - $expectedTotal) > 1) {
                throw new HttpException(409, 'Total berubah dari Rp '.number_format($expectedTotal, 0, ',', '.').' menjadi Rp '
                    .number_format($quote['grand_total'], 0, ',', '.').'. Periksa lagi rincian di layar sebelum menagih customer.');
            }
            if (! PosCashierShift::getActiveShift(self::COUNTER)) {
                throw new HttpException(422, 'Tidak ada shift kasir yang aktif untuk loket ['.self::COUNTER.']. Buka shift terlebih dahulu.');
            }
            if ($quote['grand_total'] <= 0) {
                throw new HttpException(422, 'Total Rp 0 — tidak ada yang perlu dibayar.');
            }
            foreach ($quote['items'] as $item) {
                $row = $item['kind'] === 'MERCH' ? $variants->get($item['variant_id']) : $equipments->get($item['equipment_id']);
                if ((int) $row->stock_quantity < $item['quantity']) {
                    throw new HttpException(422, "Stok {$item['name']} tinggal {$row->stock_quantity}, tidak cukup untuk {$item['quantity']}.");
                }
            }
            $proofPayload = $autoPay ? [] : PosPaymentProof::validate($method, $proof, $quote['grand_total']);

            $order = Order::create([
                'order_number' => 'ORD-SHP-'.strtoupper(Str::random(10)),
                'user_id' => null,
                'customer_name' => $buyerName,
                'customer_phone' => $buyerPhone,
                'cashier_id' => $cashier->id,
                'order_type' => 'WALK_IN',
                'subtotal' => $quote['subtotal'],
                'discount_amount' => 0,
                'tax_amount' => $quote['tax_amount'],
                'service_charge' => $quote['service_charge'],
                'grand_total' => $quote['grand_total'],
                'payment_status' => 'UNPAID',
            ]);
            $merchSold = [];
            foreach ($quote['items'] as $item) {
                $order->items()->create([
                    'item_type' => $item['kind'],
                    'reference_id' => $item['kind'] === 'MERCH' ? $item['variant_id'] : $item['equipment_id'],
                    'item_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'] ?? null,
                    'subtotal' => $item['subtotal'],
                ]);
                if ($item['kind'] === 'MERCH') {
                    $merchSold[$item['variant_id']] = $item['quantity'];
                } else {
                    $equipments->get($item['equipment_id'])->decrement('stock_quantity', $item['quantity']);
                }
            }
            app(MerchStockService::class)->deductForSale($order, $merchSold, $variants, $cashier);

            $equipmentRows = array_values(array_filter($quote['items'], fn ($i) => $i['kind'] === 'EQUIPMENT'));
            $payloadLog = [
                'cashier_id' => $cashier->id,
                'cashier_name' => $cashier->name,
                'source' => self::SOURCE,
                'channel' => 'FRONTDESK_CASHIER',
                // Dipakai PosPendingOrderCanceller untuk mengembalikan stok alat bila Bayar Otomatis batal / kedaluwarsa
                // (stok merchandise dikembalikan dari Kartu Stok).
                'equipment_sale' => array_map(fn ($i) => ['equipment_id' => $i['equipment_id'], 'quantity' => $i['quantity']], $equipmentRows),
            ];
            $payment = null;
            $pending = null;
            if ($autoPay) {
                $pending = app(PosAutoPayment::class)->open($order, self::COUNTER, $cashier, $payloadLog + ['payment_method' => 'QRIS'], $onlineMethod ?: 'QRIS');
            } else {
                app(\App\Services\Payment\PaymentOrchestratorService::class)->markOrderAsPaid($order, [
                    'payment_gateway' => 'CASHIER_POS',
                    'counter' => self::COUNTER,
                    'transaction_id' => $order->order_number,
                    'payment_method' => strtoupper($method),
                    'amount' => $quote['grand_total'],
                    'cashier_id' => $cashier->id,
                    'payload_log' => $payloadLog + $proofPayload,
                ]);
                $payment = Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->latest()->first();
            }

            $list = collect($quote['items'])->map(fn ($i) => "{$i['name']} ×{$i['quantity']}")->implode(', ');
            ActivityLogger::record(
                module: $merchSold !== [] ? 'MERCH' : 'PADEL',
                event: 'pos.pro_shop_sale',
                description: "Penjualan Kasir Pro Shop ke {$buyerName}: {$list} (Rp ".number_format($quote['grand_total'], 0, ',', '.').") — transaksi {$order->order_number}".($autoPay ? ' (menunggu Bayar Otomatis)' : ''),
                subject: $order,
                meta: [
                    'pembeli' => $buyerName,
                    'barang' => $list,
                    'total' => ActivityLogger::rupiah($quote['grand_total']),
                    'cara_bayar' => $autoPay ? 'Bayar Otomatis (menunggu pembayaran)' : (PosPaymentProof::METHODS[strtoupper($method)] ?? $method),
                    'order' => $order->order_number,
                ],
                causer: $cashier,
            );

            return ['order' => $order->fresh(['items']), 'payment' => $payment, 'pending_qris' => $pending, 'quote' => $quote];
        });
    }

    /** Varian bisa dijual: varian & produknya aktif, harganya tidak minus (Rp0 boleh). */
    public static function sellable(MerchVariant $variant): bool
    {
        return $variant->is_active && $variant->product && $variant->product->is_active && $variant->price() >= 0;
    }

    /** Nama di struk / Buku Transaksi: "Kaos Club 61 · Hitam / L" (varian standar = nama produk saja). */
    public static function itemName(MerchVariant $variant): string
    {
        $name = (string) $variant->product?->name;

        return mb_substr($variant->isStandard() ? $name : $name.' · '.$variant->label(), 0, 150);
    }

    /** @return array<string, int> jumlah > 0, maksimal MAX_QUANTITY per baris */
    private static function wanted(array $quantities): array
    {
        $out = [];
        foreach ($quantities as $id => $qty) {
            $qty = (int) $qty;
            if (is_string($id) && $id !== '' && $qty > 0) {
                $out[$id] = min($qty, self::MAX_QUANTITY);
            }
        }

        return $out;
    }
}
