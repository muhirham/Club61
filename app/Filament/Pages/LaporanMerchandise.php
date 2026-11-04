<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HandlesFnbErrors;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\LedgerReport;
use App\Services\Merch\MerchReportExport;
use App\Services\Merch\MerchReportService;
use BackedEnum;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Laporan Merchandise: penjualan (Buku Transaksi kategori MERCH), HPP, laba kotor, kerugian stok, rekap per varian
 * & stok menipis. Unduh Excel / PDF sesuai periode (izin export_reports, tercatat di Log Aktivitas). Pola sama dengan
 * Laporan Keuangan F&B.
 */
class LaporanMerchandise extends Page
{
    use HandlesFnbErrors;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Laporan Merchandise';

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Laporan Merchandise';

    protected string $view = 'filament.pages.laporan-merchandise';

    public string $preset = 'bulan_ini';

    public ?string $from = null;

    public ?string $until = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('View:LaporanMerchandise');
    }

    public static function canExport(): bool
    {
        return self::canAccess() && (bool) auth()->user()?->can('export_reports');
    }

    public function exportExcel(): BinaryFileResponse
    {
        [$from, $until, $label] = $this->exportRange('Excel');
        $path = app(MerchReportExport::class)->excel($from, $until, $label, $this->tempXlsxPath('merch-report'));

        return response()->download($path, $this->fileName('xlsx'))->deleteFileAfterSend();
    }

    public function exportPdf(): StreamedResponse
    {
        [$from, $until, $label] = $this->exportRange('PDF');
        $pdf = app(MerchReportExport::class)->pdf($from, $until, $label, auth()->user()?->name);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf;
        }, $this->fileName('pdf'), ['Content-Type' => 'application/pdf']);
    }

    /** @return array{0: ?string, 1: ?string, 2: string} */
    private function exportRange(string $format): array
    {
        $this->authorizeAction('View:LaporanMerchandise');
        $this->authorizeAction('export_reports');
        [$from, $until] = LedgerReport::resolveRange($this->preset, $this->from, $this->until);
        $label = BukuTransaksi::periodLabel($this->preset, $from, $until);

        ActivityLogger::record('MERCH', 'merch_report.exported', "Export Laporan Merchandise ke {$format} ({$label})",
            meta: array_filter(['format' => $format, 'dari' => $from, 'sampai' => $until]));

        return [$from, $until, $label];
    }

    private function fileName(string $extension): string
    {
        return 'laporan-merchandise-'.now(LedgerReport::TIMEZONE)->format('Ymd-His').'.'.$extension;
    }

    protected function getViewData(): array
    {
        $report = app(MerchReportService::class);
        [$from, $until] = LedgerReport::resolveRange($this->preset, $this->from, $this->until);

        return [
            'periodLabel' => BukuTransaksi::periodLabel($this->preset, $from, $until),
            's' => $report->summary($from, $until),
            'alerts' => $report->stockAlerts(),
        ];
    }
}
