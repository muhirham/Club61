<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\BukuTransaksi;
use App\Http\Controllers\Controller;
use App\Models\Finance\LedgerEntry;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\LedgerReport;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Modul 17 FR-05 — Export Buku Transaksi sesuai filter aktif. Route GET biasa (bukan aksi Livewire) dengan batas baris;
 * URL-nya dikecualikan dari mode SPA panel (lihat AdminPanelProvider) supaya file diunduh, bukan dirender jadi halaman.
 *  - Excel (XLSX): dua lembar — "Transaksi" (satu baris per pembayaran / refund) & "Rincian Kategori" (per baris buku).
 *  - PDF: server hanya mengirim DATA laporan (JSON, ditulis bertahap per 1.000 baris); file PDF-nya dirakit di browser
 *    oleh resources/js/ledger-pdf.js (tinypdf). Dulu dompdf di server: 400 baris saja butuh ±200 MB → error 500 di
 *    batas memory_limit 128 MB. Sekarang semua baris ikut tanpa membebani server.
 * Nomor HP & email customer TIDAK ikut (PRD §10 no. 5). Teks dinetralkan dari formula injection (Excel).
 */
class LedgerExportController extends Controller
{
    public const TRANSACTION_HEADER = ['Waktu (WIB)', 'Jenis', 'No. Order', 'Sumber', 'Kategori', 'Customer', 'Kasir', 'Metode Bayar',
        'Bukti / Referensi', 'Harga Item (Kotor)', 'Diskon', 'Penjualan Bersih', 'Biaya Layanan', 'Pajak', 'Total Dibayar', 'Benefit (non-tunai)', 'Status'];

    public const DETAIL_HEADER = ['Waktu (WIB)', 'Jenis', 'No. Order', 'Sumber', 'Kategori', 'Customer', 'Kasir', 'Metode Bayar',
        'Harga Item (Kotor)', 'Diskon', 'Penjualan Bersih', 'Biaya Layanan', 'Pajak', 'Total Dibayar', 'Benefit (non-tunai)', 'ID Pembayaran', 'ID Refund'];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless(
            $user && $user->can('View:BukuTransaksi') && $user->can('export_ledger'),
            403,
            'Akses ditolak: Anda tidak memiliki izin [export_ledger] untuk export Riwayat Transaksi.'
        );

        // validate() hanya mengembalikan parameter yang ada di whitelist — sisanya dibuang.
        $filters = $request->validate(LedgerReport::filterRules());
        $format = $filters['format'] ?? 'xlsx';
        $limit = max(1, (int) config('ledger.export_max_rows', 50000));

        $transactions = LedgerReport::orderNewestFirst(LedgerReport::grouped(LedgerReport::applyFilters(LedgerEntry::query(), $filters)))->limit($limit);
        $details = LedgerReport::applyFilters(LedgerEntry::query(), $filters)->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit);

        [$from, $until] = LedgerReport::resolveRange($filters['periode'] ?? null, $filters['dari'] ?? null, $filters['sampai'] ?? null);
        $periodLabel = BukuTransaksi::periodLabel($filters['periode'] ?? null, $from, $until);
        ActivityLogger::record(
            module: 'FINANCE',
            event: 'ledger.exported',
            description: 'Export Riwayat Transaksi ke '.($format === 'pdf' ? 'PDF' : 'Excel').' ('.$periodLabel.')',
            meta: array_filter([
                'format' => $format,
                'dari' => $from,
                'sampai' => $until,
                'batas_baris' => $limit,
                'filter' => Arr::except($filters, ['search', 'format', 'periode', 'dari', 'sampai']) ?: null,
                'pencarian' => $filters['search'] ?? null,
            ], fn ($value) => $value !== null),
            severity: ActivityLogger::WARNING,
        );

        $stamp = now(LedgerReport::TIMEZONE)->format('Ymd-His');

        if ($format === 'pdf') {
            return $this->pdfData($filters, $limit, $periodLabel, $from, $until, $stamp, $user->name);
        }
        $path = tempnam(sys_get_temp_dir(), 'ledger').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        // Nominal tetap angka (bisa dijumlah di Excel), ditampilkan dengan pemisah ribuan.
        $money = (new Style)->setFormat('#,##0;-#,##0');

        $writer->getCurrentSheet()->setName('Transaksi');
        $writer->addRow(Row::fromValues(self::TRANSACTION_HEADER, $bold));
        foreach ($transactions->cursor() as $row) {
            $writer->addRow(self::xlsxRow(self::transactionRow($row), $money));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Rincian Kategori');
        $writer->addRow(Row::fromValues(self::DETAIL_HEADER, $bold));
        foreach ($details->cursor() as $row) {
            $writer->addRow(self::xlsxRow(self::detailRow($row), $money));
        }
        $writer->close();

        return response()->download($path, "riwayat-transaksi-{$stamp}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Data laporan PDF (dirakit di browser oleh resources/js/ledger-pdf.js): ringkasan, rekap per kategori, lalu SEMUA
     * transaksi. Baris diambil & ditulis per 1.000 (status refund satu query per kelompok), jadi memori server tetap
     * kecil berapa pun jumlah barisnya. Nomor HP & email customer tidak ikut.
     */
    private function pdfData(array $filters, int $limit, string $periodLabel, ?string $from, ?string $until, string $stamp, ?string $printedBy): StreamedResponse
    {
        $base = LedgerReport::applyFilters(LedgerEntry::query(), $filters);
        // Jumlah transaksi (satu per pembayaran / refund) — untuk menandai PDF yang terpotong batas baris.
        $total = (int) (clone $base)->toBase()->selectRaw('COUNT(DISTINCT COALESCE(refund_id, payment_id)) as c')->value('c');
        $categories = (clone $base)->toBase()
            ->selectRaw("category, SUM(net_amount) as net, SUM(service_amount) as service, SUM(tax_amount) as tax, SUM(total_amount) as total, COUNT(DISTINCT CASE WHEN entry_type <> 'REFUND' THEN payment_id END) as payments")
            ->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($c) => [
                'name' => LedgerEntry::categoryLabel($c->category),
                'payments' => (int) $c->payments,
                'net' => (float) $c->net,
                'service' => (float) $c->service,
                'tax' => (float) $c->tax,
                'total' => (float) $c->total,
            ])->all();
        $company = rescue(fn () => \App\Models\Setting\CompanyProfileSetting::current(), null, false);

        $meta = [
            'file_name' => "riwayat-transaksi-{$stamp}.pdf",
            'period' => $periodLabel,
            'printed_by' => (string) ($printedBy ?? '-'),
            'printed_at' => now(LedgerReport::TIMEZONE)->format('d/m/Y H:i').' WIB',
            'address' => (string) ($company?->address_line ?? ''),
            'logo_url' => is_file(public_path('images/club61-logo.png')) ? asset('images/club61-logo.png') : null,
            'summary' => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, LedgerReport::summary($base, $from, $until)),
            'categories' => $categories,
            'total' => $total,
            'limit' => $limit,
        ];
        $transactions = LedgerReport::orderNewestFirst(LedgerReport::grouped(LedgerReport::applyFilters(LedgerEntry::query(), $filters)));

        return response()->stream(function () use ($meta, $transactions, $limit) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
            echo substr(json_encode($meta, $flags), 0, -1).',"rows":[';

            $written = 0;
            for ($page = 1; $written < $limit; $page++) {
                $rows = (clone $transactions)->forPage($page, self::PDF_CHUNK)->get();
                if ($rows->isEmpty()) {
                    break;
                }
                LedgerReport::primeRefunds($rows->pluck('payment_id')->all());
                LedgerReport::primeEdc($rows);
                foreach ($rows as $row) {
                    if ($written >= $limit) {
                        break;
                    }
                    echo ($written > 0 ? ',' : '').json_encode(self::pdfRow($row), $flags);
                    $written++;
                }
                LedgerReport::flushMemo();
                flush();
                if ($rows->count() < self::PDF_CHUNK) {
                    break;
                }
            }

            echo ']}';
        }, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public const PDF_CHUNK = 1000;

    /**
     * Satu baris daftar transaksi PDF (urutan kolom = tabel di ledger-pdf.js).
     *
     * @return array<int, string|float>
     */
    public static function pdfRow(LedgerEntry $row): array
    {
        return [
            $row->occurred_at?->timezone(LedgerReport::TIMEZONE)->format('d/m/y H:i') ?? '',
            (string) $row->order_number,
            LedgerEntry::sourceLabel($row->source),
            LedgerReport::categoriesLabel($row->categories),
            (string) ($row->customer_name ?? '-'),
            LedgerReport::cashierLabel($row),
            LedgerReport::methodLabel($row) ?: '-', // kartu EDC: debit/kredit + mesin (QRIS tetap label buku)
            (float) $row->net_amount,
            (float) $row->service_amount,
            (float) $row->tax_amount,
            (float) $row->total_amount,
            LedgerReport::statusLabel($row),
        ];
    }

    /** @param  array<int, string|float>  $values */
    private static function xlsxRow(array $values, Style $money): Row
    {
        return new Row(array_map(fn ($value) => Cell::fromValue($value, is_float($value) ? $money : null), $values));
    }

    /** @return array<int, string|float> */
    public static function transactionRow(LedgerEntry $row): array
    {
        return [
            $row->occurred_at?->timezone(LedgerReport::TIMEZONE)->format('Y-m-d H:i:s') ?? '',
            LedgerEntry::ENTRY_TYPES[$row->entry_type] ?? $row->entry_type,
            LedgerReport::cellSafe($row->order_number),
            LedgerEntry::sourceLabel($row->source),
            LedgerReport::categoriesLabel($row->categories),
            LedgerReport::cellSafe($row->customer_name),
            LedgerReport::cellSafe(LedgerReport::cashierLabel($row)),
            LedgerReport::cellSafe(LedgerReport::methodLabel($row)),
            LedgerReport::cellSafe(LedgerReport::referenceLabel($row)),
            (float) $row->gross_amount,
            (float) $row->discount_amount,
            (float) $row->net_amount,
            (float) $row->service_amount,
            (float) $row->tax_amount,
            (float) $row->total_amount,
            (float) $row->benefit_amount,
            LedgerReport::statusLabel($row),
        ];
    }

    /** @return array<int, string|float> */
    public static function detailRow(LedgerEntry $row): array
    {
        return [
            $row->occurred_at?->timezone(LedgerReport::TIMEZONE)->format('Y-m-d H:i:s') ?? '',
            LedgerEntry::ENTRY_TYPES[$row->entry_type] ?? $row->entry_type,
            LedgerReport::cellSafe($row->order_number),
            LedgerEntry::sourceLabel($row->source),
            LedgerEntry::categoryLabel($row->category),
            LedgerReport::cellSafe($row->customer_name),
            LedgerReport::cellSafe(LedgerReport::cashierLabel($row)),
            LedgerReport::cellSafe(LedgerReport::methodLabel($row)),
            (float) $row->gross_amount,
            (float) $row->discount_amount,
            (float) $row->net_amount,
            (float) $row->service_amount,
            (float) $row->tax_amount,
            (float) $row->total_amount,
            (float) $row->benefit_amount,
            (string) $row->payment_id,
            (string) $row->refund_id,
        ];
    }
}
