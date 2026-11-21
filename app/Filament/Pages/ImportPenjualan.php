<?php

namespace App\Filament\Pages;

use App\Models\Finance\SalesImportBatch;
use App\Services\Import\SalesImportCancelBlocked;
use App\Services\Import\SalesImportFailed;
use App\Services\Import\SalesImportService;
use App\Services\Import\SalesImportTemplate;
use App\Services\Padel\PadelBookingService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Import penjualan soft opening (booking padel walk-in & F&B) dari Excel. Terpisah dari layar kasir: tidak membuka
 * shift, tidak lewat Midtrans, tidak mencetak slip dapur. Alur: unduh template → upload → pratinjau & cek error per
 * baris → Import (semua atau tidak sama sekali). Khusus super_admin (izin import_sales_data).
 */
class ImportPenjualan extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import Penjualan';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Import Penjualan';

    protected string $view = 'filament.pages.import-penjualan';

    /** File .xlsx yang sedang diperiksa (upload sementara Livewire). */
    public $file = null;

    /** Hasil pemeriksaan untuk ditampilkan (hanya teks & angka). */
    public ?array $preview = null;

    public ?array $result = null;

    /** Admin sudah mengecek peringatan (mis. No. HP milik akun dengan nama lain) dan tetap mau import. */
    public bool $confirmWarnings = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('View:ImportPenjualan') && $user->can('import_sales_data'));
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        $this->authorizeImport();

        $path = tempnam(sys_get_temp_dir(), 'club61-import').'.xlsx';
        app(SalesImportTemplate::class)->build($path);

        return response()->download($path, 'Template-Import-Penjualan-Club61.xlsx')->deleteFileAfterSend();
    }

    /** File baru dipilih → langsung diperiksa. */
    public function updatedFile(): void
    {
        $this->result = null;
        $this->preview = null;
        $this->confirmWarnings = false;

        $this->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx'],
        ], [
            'file.mimes' => 'File harus Excel .xlsx (pakai template).',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ]);

        $this->analyze();
    }

    public function analyze(): void
    {
        $this->authorizeImport();
        if (! $this->file) {
            return;
        }

        app(PadelBookingService::class)->releaseExpiredLocks();
        $this->preview = $this->forDisplay(app(SalesImportService::class)->analyze($this->file->getRealPath()));
    }

    public function import(): void
    {
        $this->authorizeImport();
        if (! $this->file) {
            return;
        }

        if ((($this->preview['warning_count'] ?? 0) > 0) && ! $this->confirmWarnings) {
            Notification::make()->title('Cek peringatan dulu')->body('Centang "Sudah saya cek" di bagian peringatan sebelum import.')->warning()->send();

            return;
        }

        try {
            $result = app(SalesImportService::class)->commit($this->file->getRealPath(), auth()->user(), $this->file->getClientOriginalName());
        } catch (SalesImportFailed $e) {
            // Data berubah sejak pratinjau (mis. slot keburu dibooking) → tampilkan error terbaru, tidak ada yang masuk.
            $this->analyze();
            Notification::make()->title('Import dibatalkan')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->result = $result['summary'] + ['batch' => $result['batch']];
        $this->preview = null;
        $this->file = null;
        $this->confirmWarnings = false;

        Notification::make()
            ->title('Import berhasil')
            ->body("{$result['summary']['padel_transactions']} transaksi padel & {$result['summary']['fnb_transactions']} transaksi F&B masuk ke Riwayat Transaksi.")
            ->success()
            ->send();
    }

    public function resetImport(): void
    {
        $this->file = null;
        $this->preview = null;
        $this->result = null;
        $this->confirmWarnings = false;
        $this->resetValidation();
    }

    /** Ringkas hasil analisis jadi teks & angka (properti Livewire tidak boleh berisi objek model / Carbon). */
    protected function forDisplay(array $analysis): array
    {
        $date = fn ($carbon) => $carbon?->copy()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i');

        return [
            'summary' => $analysis['summary'],
            'errors' => array_slice($analysis['errors'], 0, 300),
            'error_count' => count($analysis['errors']),
            'warnings' => array_slice($analysis['warnings'], 0, 300),
            'warning_count' => count($analysis['warnings']),
            'skipped' => $analysis['skipped'],
            'padel' => array_map(fn ($tx) => [
                'ref' => $tx['ref'],
                'row' => $tx['row'],
                'paid_at' => $date($tx['paid_at']),
                'customer' => $tx['name'],
                'phone' => $tx['phone'],
                'customer_exists' => $tx['customer_exists'],
                'cashier' => $tx['cashier']?->name,
                'bookings' => array_map(fn ($b) => [
                    'label' => "{$b['court_name']} · ".\Carbon\Carbon::parse($b['play_date'])->format('d/m/Y')." {$b['start']}–{$b['end']}",
                    'status' => $b['status'],
                ], $tx['bookings']),
                'total' => $tx['total'],
            ], $analysis['padel']),
            'fnb' => array_map(fn ($tx) => [
                'ref' => $tx['ref'],
                'row' => $tx['row'],
                'paid_at' => $date($tx['paid_at']),
                'type' => $tx['order_type'] === 'DINE_IN' ? 'Dine-In'.($tx['table'] ? ' · Meja '.$tx['table'] : '') : 'Bawa Pulang',
                'customer' => $tx['customer'],
                'cashier' => $tx['cashier']?->name,
                'items' => implode(', ', array_map(fn ($i) => "{$i['qty']}x {$i['menu_name']}", $tx['items'])),
                'total' => $tx['total'],
            ], $analysis['fnb']),
        ];
    }

    // ==================================================================== RIWAYAT IMPORT

    public function getBatchesProperty()
    {
        return SalesImportBatch::with(['importedBy:id,name', 'cancelledBy:id,name'])->latest()->limit(30)->get();
    }

    public function downloadBatchFile(string $batchId): ?StreamedResponse
    {
        $this->authorizeImport();
        $batch = SalesImportBatch::findOrFail($batchId);
        if (! $batch->file_path || ! Storage::disk('local')->exists($batch->file_path)) {
            Notification::make()->title('File tidak tersimpan')->body('Import ini dibuat sebelum file asli disimpan otomatis.')->warning()->send();

            return null;
        }

        return Storage::disk('local')->download($batch->file_path, $batch->file_name ?: $batch->batch_code.'.xlsx');
    }

    public function cancelBatch(string $batchId): void
    {
        $this->authorizeImport();
        $batch = SalesImportBatch::findOrFail($batchId);

        try {
            $done = app(SalesImportService::class)->cancelBatch($batch, auth()->user());
        } catch (SalesImportCancelBlocked $e) {
            Notification::make()->title('Import tidak bisa dibatalkan')->body(implode("\n", array_slice($e->reasons, 0, 8)))->danger()->persistent()->send();

            return;
        }

        $this->result = null;
        Notification::make()
            ->title('Import dibatalkan')
            ->body("{$done['orders']} transaksi ({$done['bookings']} booking, Rp ".number_format($done['total'], 0, ',', '.').') dihapus'.($done['customers_removed'] ? ", {$done['customers_removed']} akun customer baru ikut dihapus" : '').'. Slot jadwalnya sudah kosong lagi.')
            ->success()
            ->send();
    }

    protected function authorizeImport(): void
    {
        abort_unless(static::canAccess(), 403, 'Akses ditolak: hanya super admin yang bisa meng-import data penjualan.');
    }
}
