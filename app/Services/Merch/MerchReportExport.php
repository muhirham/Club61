<?php

namespace App\Services\Merch;

use App\Models\Merch\MerchStockMovement;
use App\Models\Merch\MerchVariant;
use App\Services\Finance\LedgerReport;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export Laporan Merchandise (Excel & PDF). Angka dari MerchReportService yang sama dengan layar, jadi file = layar.
 * Teks dinetralkan dari formula injection (Excel).
 */
class MerchReportExport
{
    public function __construct(private MerchReportService $report) {}

    /** Excel 4 sheet: Ringkasan, Per Varian, Kartu Stok, Stok Saat Ini. */
    public function excel(?string $from, ?string $until, string $periodLabel, string $path): string
    {
        $s = $this->report->summary($from, $until);
        $writer = new Writer();
        $writer->openToFile($path);
        $bold = (new Style())->setFontBold();
        $header = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('662721');
        $money = (new Style())->setFormat('#,##0;-#,##0');
        $row = fn (array $values, ?Style $style = null) => new Row(array_map(
            fn ($v) => Cell::fromValue(is_string($v) ? LedgerReport::cellSafe($v) : $v, $style ?? (is_float($v) ? $money : null)),
            $values,
        ));

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Ringkasan');
        $sheet->setColumnWidth(52, 1);
        $sheet->setColumnWidth(20, 2);
        $writer->addRow($row(['Laporan Merchandise — Club 61'], $bold));
        $writer->addRow($row(['Periode', $periodLabel]));
        $writer->addRow($row(['Dicetak', now(LedgerReport::TIMEZONE)->format('d/m/Y H:i').' WIB']));
        $writer->addRow($row([]));
        $writer->addRow($row(['LAPORAN LABA', 'Rp'], $header));
        foreach ([
            ['Penjualan (bersih, tanpa pajak & biaya layanan)', $s['sales']],
            ['HPP terjual', -$s['hpp']],
            ['Laba Kotor', $s['gross_profit']],
            ['Kerugian stok (rusak, hilang, selisih)', -$s['stock_loss']],
            ['Laba setelah kerugian stok', $s['net_profit']],
        ] as [$label, $value]) {
            $writer->addRow($row([$label, (float) $value]));
        }
        $writer->addRow($row([]));
        $writer->addRow($row(['INFORMASI', ''], $header));
        $writer->addRow($row(['Uang masuk penjualan (termasuk pajak & biaya layanan)', (float) $s['money_in']]));
        $writer->addRow($row(['Pembelian barang (Barang Masuk)', (float) $s['purchases']]));
        $writer->addRow($row(['Unit terjual', $s['qty_sold']]));
        if ($s['missing_cost_qty'] > 0) {
            $writer->addRow($row([]));
            $writer->addRow($row(["Catatan: {$s['missing_cost_qty']} unit terjual belum memiliki HPP (dihitung Rp0)."]));
        }

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Per Varian');
        foreach ([44, 12, 18, 18, 18, 12] as $i => $w) {
            $sheet->setColumnWidth($w, $i + 1);
        }
        $writer->addRow($row(['Produk · Varian', 'Terjual', 'Penjualan', 'HPP', 'Laba Kotor', 'Margin %'], $header));
        foreach ($s['items'] as $item) {
            $writer->addRow($row([(string) $item['name'], $item['qty'], (float) $item['sales'], (float) $item['hpp'], (float) $item['profit'],
                $item['sales'] > 0 ? round($item['profit'] / $item['sales'] * 100) : 0]));
        }

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Kartu Stok');
        foreach ([18, 40, 18, 12, 12, 60, 22] as $i => $w) {
            $sheet->setColumnWidth($w, $i + 1);
        }
        $writer->addRow($row(['Waktu (WIB)', 'Produk · Varian', 'Jenis', 'Perubahan', 'Stok Akhir', 'Keterangan', 'Oleh'], $header));
        $this->report->movementsBetween($from, $until)->with(['creator:id,name', 'order:id,order_number'])
            ->orderBy('created_at')
            ->each(fn (MerchStockMovement $m) => $writer->addRow($row([
                $m->created_at?->timezone(LedgerReport::TIMEZONE)->format('Y-m-d H:i'), $m->product_name.' · '.$m->variant_label,
                MerchStockMovement::TYPES[$m->type] ?? $m->type, $m->qty_change, $m->stock_after,
                implode(' · ', array_filter([
                    $m->order ? 'Order '.$m->order->order_number : null,
                    $m->reference_number ? 'No. '.$m->reference_number : null,
                    $m->supplier ? 'Supplier: '.$m->supplier : null,
                    $m->invoice_number ? 'Nota: '.$m->invoice_number : null,
                    $m->purchase_total !== null ? 'Harga beli '.number_format((float) $m->purchase_total, 0, ',', '.') : null,
                    $m->reason ? (MerchStockMovement::REASONS[$m->reason] ?? $m->reason) : null,
                    $m->notes,
                ])),
                $m->creator?->name ?? 'Sistem',
            ])));

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Stok Saat Ini');
        foreach ([44, 20, 10, 14, 16, 18, 14] as $i => $w) {
            $sheet->setColumnWidth($w, $i + 1);
        }
        $writer->addRow($row(['Produk · Varian', 'SKU', 'Stok', 'Stok Minimum', 'HPP', 'Nilai Stok', 'Status'], $header));
        MerchVariant::with('product:id,name,hpp,is_active')->get()
            ->sortBy(fn (MerchVariant $v) => mb_strtolower(($v->product?->name ?? '').' '.$v->label()))
            ->each(fn (MerchVariant $v) => $writer->addRow($row([
                ($v->product?->name ?? '-').' · '.$v->label(), (string) $v->sku, $v->stock_quantity, $v->min_stock ?? '',
                (float) ($v->product?->hpp ?? 0), (float) (max(0, $v->stock_quantity) * (float) ($v->product?->hpp ?? 0)),
                ! $v->is_active || ! $v->product?->is_active ? 'Nonaktif' : ($v->stock_quantity <= 0 ? 'Habis' : ($v->isLowStock() ? 'Menipis' : 'Aman')),
            ])));

        $writer->close();

        return $path;
    }

    /** PDF ringkas: laba, rekap per varian, stok menipis. */
    public function pdf(?string $from, ?string $until, string $periodLabel, ?string $printedBy): string
    {
        $s = $this->report->summary($from, $until);

        return Pdf::loadView('exports.laporan-merchandise-pdf', [
            's' => $s,
            'alerts' => $this->report->stockAlerts(),
            'periodLabel' => $periodLabel,
            'printedBy' => $printedBy,
            'printedAt' => now(LedgerReport::TIMEZONE)->format('d/m/Y H:i').' WIB',
        ])->setPaper('a4', 'portrait')->output();
    }
}
