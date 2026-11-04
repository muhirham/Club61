<?php

namespace App\Services\Merch;

use App\Models\Finance\LedgerEntry;
use App\Models\Merch\MerchStockMovement;
use App\Models\Merch\MerchVariant;
use App\Models\Pos\OrderItem;
use App\Services\Finance\LedgerReport;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Angka Laporan Merchandise. Hari kalender WIB (sama dengan Buku Transaksi & Laporan F&B).
 *  - Penjualan = Buku Transaksi kategori MERCH (bersih, tanpa pajak & biaya layanan; refund ikut mengurangi).
 *  - HPP: snapshot order_items.unit_cost dari transaksi yang pembayarannya masuk di periode itu.
 *  - Kerugian stok: pengurangan Penyesuaian (kecuali Salah Input) × HPP saat itu.
 *  - Pembelian barang: total harga beli Barang Masuk (informasi arus kas, tidak dipotong lagi di laba — sudah lewat HPP).
 */
class MerchReportService
{
    /** @return array<string, mixed> */
    public function summary(?string $from, ?string $until): array
    {
        $ledger = LedgerReport::applyDateRange(LedgerEntry::query()->where('category', 'MERCH'), $from, $until);
        $sales = (float) (clone $ledger)->sum('net_amount');
        $moneyIn = (float) (clone $ledger)->sum('total_amount');

        $items = $this->itemBreakdown($ledger);
        $hpp = (float) $items->sum('hpp');

        $movements = $this->movementsBetween($from, $until);
        $stockLoss = (float) (clone $movements)
            ->where('type', MerchStockMovement::ADJUSTMENT)
            ->where('qty_change', '<', 0)
            ->where(fn (Builder $q) => $q->whereNull('reason')->orWhereNotIn('reason', MerchStockMovement::NOT_A_LOSS))
            ->selectRaw('COALESCE(SUM(-qty_change * unit_cost), 0) as total')->value('total');
        $purchases = (float) (clone $movements)->where('type', MerchStockMovement::RESTOCK)->sum('purchase_total');

        $grossProfit = $sales - $hpp;

        return [
            'sales' => $sales,
            'hpp' => $hpp,
            'gross_profit' => $grossProfit,
            'stock_loss' => $stockLoss,
            'net_profit' => $grossProfit - $stockLoss,
            'money_in' => $moneyIn,
            'purchases' => $purchases,
            'qty_sold' => (int) $items->sum('qty'),
            'missing_cost_qty' => (int) $items->sum('missing_cost_qty'),
            'items' => $items,
        ];
    }

    /** Penjualan, HPP & laba kotor per varian dari transaksi yang dibayar di periode itu (HPP Rp0 = belum memiliki HPP). */
    public function itemBreakdown(Builder $ledger): Collection
    {
        $orderIds = (clone $ledger)->where('entry_type', LedgerEntry::TYPE_PAYMENT)->select('order_id');

        return OrderItem::query()
            ->where('item_type', 'MERCH')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('reference_id, MAX(item_name) as name, SUM(quantity) as qty, SUM(subtotal) as sales,
                SUM(quantity * COALESCE(unit_cost, 0)) as hpp, SUM(CASE WHEN unit_cost IS NULL OR unit_cost = 0 THEN quantity ELSE 0 END) as missing_cost_qty')
            ->groupBy('reference_id')
            ->get()
            ->map(fn ($r) => [
                'variant_id' => $r->reference_id,
                'name' => $r->name,
                'qty' => (int) $r->qty,
                'sales' => (float) $r->sales,
                'hpp' => (float) $r->hpp,
                'profit' => (float) $r->sales - (float) $r->hpp,
                'missing_cost_qty' => (int) $r->missing_cost_qty,
            ])
            ->sortByDesc('sales')
            ->values();
    }

    /** Varian aktif (produk aktif) yang stoknya habis atau di bawah / sama dengan stok minimum. */
    public function stockAlerts(): Collection
    {
        return MerchVariant::with('product:id,name,is_active')
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
            ->where(fn (Builder $q) => $q->where('stock_quantity', '<=', 0)->orWhereColumn('stock_quantity', '<=', 'min_stock'))
            ->orderBy('stock_quantity')
            ->get();
    }

    public function movementsBetween(?string $from, ?string $until): Builder
    {
        $tz = config('app.timezone');

        return MerchStockMovement::query()
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($from, LedgerReport::TIMEZONE)->startOfDay()->setTimezone($tz)))
            ->when($until, fn (Builder $q) => $q->where('created_at', '<', Carbon::parse($until, LedgerReport::TIMEZONE)->addDay()->startOfDay()->setTimezone($tz)));
    }
}
