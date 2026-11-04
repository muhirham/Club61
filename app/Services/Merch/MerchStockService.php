<?php

namespace App\Services\Merch;

use App\Models\Merch\MerchProduct;
use App\Models\Merch\MerchStockMovement;
use App\Models\Merch\MerchVariant;
use App\Models\Pos\Order;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\LedgerReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Satu-satunya penulis stok varian merchandise & Kartu Stok (pola sama dengan FnbStockService).
 *  - Varian baru mulai dari stok 0; stok bertambah lewat Barang Masuk supaya setiap unit punya jejak.
 *  - Penjualan Kasir Pro Shop memotong stok saat transaksi dibuat (sama dengan bola) — tidak boleh minus;
 *    Bayar Otomatis yang batal / kedaluwarsa mengembalikannya (PosPendingOrderCanceller).
 *  - Penyesuaian mencatat selisih dengan alasan; HPP saat itu disimpan untuk nilai kerugian stok.
 * Stok diubah lewat query builder + baris Kartu Stok; aksi staf juga tercatat di Log Aktivitas.
 */
class MerchStockService
{
    public const MAX_QTY = 100000;

    public const MAX_RECEIPT_LINES = 200;

    public const MAX_PURCHASE_TOTAL = 10000000000;

    /**
     * Barang Masuk (satu nota supplier, banyak varian): semua baris masuk atau tidak sama sekali.
     *
     * @param  array<int, array{variant_id: string, qty: int|string, total: int|float|string|null}>  $lines
     * @return string nomor penerimaan
     */
    public function receive(array $lines, ?string $supplier, ?string $invoiceNumber, ?string $notes, User $by): string
    {
        abort_if($lines === [], 422, 'Isi minimal satu baris barang yang diterima.');
        abort_if(count($lines) > self::MAX_RECEIPT_LINES, 422, 'Maksimal '.self::MAX_RECEIPT_LINES.' baris per penerimaan.');

        $variantIds = [];
        foreach ($lines as $i => $line) {
            $row = $i + 1;
            abort_if(empty($line['variant_id']), 422, "Baris {$row}: varian wajib dipilih.");
            abort_if(in_array($line['variant_id'], $variantIds, true), 422, "Baris {$row}: varian yang sama tercantum lebih dari satu kali. Gabungkan jumlahnya dalam satu baris.");
            abort_unless(is_numeric($line['qty'] ?? null) && (int) $line['qty'] == $line['qty'] && (int) $line['qty'] >= 1, 422, "Baris {$row}: jumlah diterima harus bilangan bulat minimal 1.");
            abort_if((int) $line['qty'] > self::MAX_QTY, 422, "Baris {$row}: jumlah diterima maksimal ".number_format(self::MAX_QTY, 0, ',', '.').'.');
            $total = $line['total'] ?? null;
            abort_unless($total === null || $total === '' || (is_numeric($total) && (float) $total >= 0), 422, "Baris {$row}: total harga beli harus angka 0 atau lebih.");
            $variantIds[] = $line['variant_id'];
        }
        $grandTotal = round(collect($lines)->sum(fn ($l) => (float) ($l['total'] ?? 0)), 2);
        abort_if($grandTotal > self::MAX_PURCHASE_TOTAL, 422, 'Total satu penerimaan terlalu besar. Pisahkan menjadi beberapa penerimaan.');

        return DB::transaction(function () use ($lines, $variantIds, $grandTotal, $supplier, $invoiceNumber, $notes, $by) {
            // Kunci berurutan (id) supaya dua transaksi bersamaan tidak saling menunggu (deadlock).
            $variants = MerchVariant::with('product')->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $reference = 'BM-'.now(LedgerReport::TIMEZONE)->format('ymd').'-'.strtoupper(Str::random(5));
            $supplier = $this->clean($supplier, 100);
            $invoiceNumber = $this->clean($invoiceNumber, 60);
            $notes = $this->clean($notes, 500);

            $summary = [];
            foreach ($lines as $i => $line) {
                $variant = $variants->get($line['variant_id']);
                abort_unless($variant && $variant->product, 422, 'Baris '.($i + 1).': varian tidak ditemukan.');
                $this->move($variant, (int) $line['qty'], MerchStockMovement::RESTOCK, [
                    'reference_number' => $reference,
                    'supplier' => $supplier,
                    'invoice_number' => $invoiceNumber,
                    'purchase_total' => round((float) ($line['total'] ?? 0), 2),
                    'notes' => $notes,
                ], $by);
                $summary[$variant->product->name.' · '.$variant->label()] = '+'.(int) $line['qty'];
            }

            ActivityLogger::record('MERCH', 'merch_stock.received',
                'Mencatat Barang Masuk merchandise '.$reference.': '.count($lines).' varian, '.ActivityLogger::rupiah($grandTotal),
                meta: ['supplier' => $supplier, 'no_nota' => $invoiceNumber, 'barang' => $summary],
                subjectLabel: $reference,
            );

            return $reference;
        });
    }

    public function adjust(MerchVariant $variant, int $qtyChange, string $reason, string $notes, User $by): MerchStockMovement
    {
        abort_if($qtyChange === 0, 422, 'Jumlah penyesuaian tidak boleh 0.');
        abort_if(abs($qtyChange) > self::MAX_QTY, 422, 'Jumlah penyesuaian maksimal '.number_format(self::MAX_QTY, 0, ',', '.').'.');
        abort_unless(array_key_exists($reason, MerchStockMovement::REASONS), 422, 'Alasan penyesuaian tidak dikenal.');
        $notes = $this->clean($notes, 500);
        abort_if($notes === null || mb_strlen($notes) < 5, 422, 'Keterangan penyesuaian wajib diisi (minimal 5 karakter).');

        return DB::transaction(function () use ($variant, $qtyChange, $reason, $notes, $by) {
            $variant = MerchVariant::with('product')->lockForUpdate()->findOrFail($variant->id);
            abort_if($variant->stock_quantity + $qtyChange < 0, 422, "Stok {$variant->product?->name} · {$variant->label()} tinggal {$variant->stock_quantity} — pengurangan tidak boleh melebihi stok.");
            $movement = $this->move($variant, $qtyChange, MerchStockMovement::ADJUSTMENT, ['reason' => $reason, 'notes' => $notes], $by);

            ActivityLogger::record('MERCH', 'merch_stock.adjusted',
                "Penyesuaian stok {$variant->product?->name} · {$variant->label()}: ".sprintf('%+d', $qtyChange).' ('.MerchStockMovement::REASONS[$reason].", stok menjadi {$movement->stock_after})",
                subject: $variant->product,
                meta: ['alasan' => MerchStockMovement::REASONS[$reason], 'keterangan' => $notes, 'stok_setelah' => $movement->stock_after],
                severity: ActivityLogger::WARNING,
            );

            return $movement;
        });
    }

    /**
     * Penjualan Kasir Pro Shop: potong stok varian yang SUDAH dikunci pemanggil (di dalam transaksi checkout).
     *
     * @param  array<string, int>  $quantities  [variant_id => qty]
     * @param  \Illuminate\Support\Collection<string, MerchVariant>  $variants  terkunci, dengan relasi product
     */
    public function deductForSale(Order $order, array $quantities, $variants, User $by): void
    {
        foreach ($quantities as $variantId => $qty) {
            $this->move($variants->get($variantId), -$qty, MerchStockMovement::SALE, ['order_id' => $order->id], $by);
        }
    }

    /** Bayar Otomatis batal / kedaluwarsa: kembalikan stok penjualan order ini sekali saja. */
    public function restoreCancelledSale(Order $order): void
    {
        if (MerchStockMovement::where('order_id', $order->id)->where('type', MerchStockMovement::SALE_CANCEL)->exists()) {
            return;
        }
        $sold = MerchStockMovement::where('order_id', $order->id)->where('type', MerchStockMovement::SALE)->get()
            ->groupBy('variant_id')->map(fn ($rows) => -(int) $rows->sum('qty_change'));
        if ($sold->isEmpty()) {
            return;
        }

        $variants = MerchVariant::with('product')->whereIn('id', $sold->keys()->filter())->orderBy('id')->lockForUpdate()->get();
        foreach ($variants as $variant) {
            $this->move($variant, (int) $sold->get($variant->id), MerchStockMovement::SALE_CANCEL, [
                'order_id' => $order->id,
                'notes' => 'Bayar Otomatis batal / kedaluwarsa — stok dikembalikan.',
            ], null);
        }
    }

    /** Ubah stok (atomik) + tulis satu baris Kartu Stok. Varian sudah dikunci pemanggil. */
    private function move(MerchVariant $variant, int $qtyChange, string $type, array $attributes, ?User $by): MerchStockMovement
    {
        MerchVariant::whereKey($variant->id)->increment('stock_quantity', $qtyChange);
        $stockAfter = (int) MerchVariant::whereKey($variant->id)->value('stock_quantity');
        $product = $variant->product ?? MerchProduct::find($variant->product_id);

        return MerchStockMovement::create(array_merge([
            'variant_id' => $variant->id,
            'product_name' => mb_substr((string) ($product?->name ?? '-'), 0, 100),
            'variant_label' => mb_substr($variant->label(), 0, 50),
            'type' => $type,
            'qty_change' => $qtyChange,
            'stock_after' => $stockAfter,
            'unit_cost' => (float) ($product?->hpp ?? 0),
            'created_by' => $by?->id,
            'created_at' => now(),
        ], $attributes));
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $value), 0, $max));

        return $value === '' ? null : $value;
    }
}
