<?php

namespace App\Filament\Pages;

use App\Models\Membership\UserMembershipBalance;
use App\Models\Pos\Payment;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\LedgerReport;
use App\Services\Membership\MonthlyBilling;
use App\Services\Membership\MonthlyBillingRecapExport;
use App\Services\Sponsor\CorporateTransfer;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Modul 34 — Tagihan Bulanan (izin View:TagihanBulanan): rekap jam main per perusahaan per bulan untuk Finance (unduh
 * Excel / PDF, tanpa harga) dan riwayat transfer perusahaan yang dicatat dari Kelola Sponsor (bukti transfer, batalkan
 * pencatatan yang salah input). Tidak ada penerimaan uang di halaman ini.
 */
class TagihanBulanan extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationLabel = 'Tagihan Bulanan';

    protected static string|UnitEnum|null $navigationGroup = 'Customer & Membership';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Tagihan Bulanan';

    protected string $view = 'filament.pages.tagihan-bulanan';

    public ?string $balanceId = null;

    public ?string $month = null;

    public ?string $voidPaymentId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->balanceId = array_key_first(self::cardOptions());
        $this->month = now(LedgerReport::TIMEZONE)->format('Y-m');
    }

    /** @return array<string, string> [balance_id => "Perusahaan — kode kartu"] semua kartu Tagihan Bulanan */
    public static function cardOptions(): array
    {
        return MonthlyBilling::cards()->get()
            ->mapWithKeys(fn (UserMembershipBalance $b) => [$b->id => MonthlyBilling::cardLabel($b)])
            ->all();
    }

    /** @return array<string, string> ["2026-12" => "Desember 2026"] 12 bulan ke belakang s.d. 2 bulan ke depan */
    public static function monthOptions(): array
    {
        $start = now(LedgerReport::TIMEZONE)->startOfMonth()->addMonthsNoOverflow(2);

        return collect(range(0, 14))->mapWithKeys(fn (int $i) => [
            $start->copy()->subMonthsNoOverflow($i)->format('Y-m') => MonthlyBilling::monthLabel($start->copy()->subMonthsNoOverflow($i)),
        ])->all();
    }

    /** Kartu & bulan terpilih yang sah (isian Livewire bisa direkayasa). */
    private function selection(): ?array
    {
        $balance = $this->balanceId ? MonthlyBilling::cards()->whereKey($this->balanceId)->first() : null;
        $month = (string) $this->month;

        return $balance && isset(self::monthOptions()[$month]) ? [$balance, $month] : null;
    }

    public function exportExcel(): ?BinaryFileResponse
    {
        $selection = $this->exportSelection('Excel');
        if (! $selection) {
            return null;
        }
        [$balance, $month] = $selection;
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rekap-tagihan-bulanan-'.Str::uuid().'.xlsx';
        app(MonthlyBillingRecapExport::class)->excel($balance, $month, $path);

        return response()->download($path, $this->fileName($balance, $month, 'xlsx'))->deleteFileAfterSend();
    }

    public function exportPdf(): ?StreamedResponse
    {
        $selection = $this->exportSelection('PDF');
        if (! $selection) {
            return null;
        }
        [$balance, $month] = $selection;
        $pdf = app(MonthlyBillingRecapExport::class)->pdf($balance, $month, auth()->user()?->name);

        return response()->streamDownload(fn () => print ($pdf), $this->fileName($balance, $month, 'pdf'), ['Content-Type' => 'application/pdf']);
    }

    /** @return array{0: UserMembershipBalance, 1: string}|null */
    private function exportSelection(string $format): ?array
    {
        abort_unless(static::canAccess(), 403);
        $selection = $this->selection();
        if (! $selection) {
            Notification::make()->title('Pilih Perusahaan dan Periode')->warning()->send();

            return null;
        }
        [$balance, $month] = $selection;
        ActivityLogger::record('MEMBERSHIP', 'monthly_bill.recap_exported',
            "Mengunduh Rekap Jam Main Tagihan Bulanan {$this->companyName($balance)} periode ".MonthlyBilling::monthLabel(Carbon::parse($month.'-01'))." ({$format})",
            subject: $balance,
            meta: ['format' => $format, 'periode' => $month],
        );

        return $selection;
    }

    private function companyName(UserMembershipBalance $balance): string
    {
        return $balance->membership ? MonthlyBilling::billedName($balance->membership) : '-';
    }

    private function fileName(UserMembershipBalance $balance, string $month, string $ext): string
    {
        return 'Rekap-Tagihan-Bulanan-'.(Str::slug($this->companyName($balance)) ?: 'perusahaan').'-'.$month.'.'.$ext;
    }

    /** Bukti transfer (disk privat) — hanya pemegang izin halaman ini. */
    public function downloadProof(string $paymentId): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $payment = Str::isUlid($paymentId) ? CorporateTransfer::payments()->whereKey($paymentId)->first() : null;
        $path = $payment ? CorporateTransfer::validProofPath(CorporateTransfer::of($payment)['proof_path'] ?? null) : null;
        if (! $path || ! Storage::disk(CorporateTransfer::PROOF_DISK)->exists($path)) {
            Notification::make()->title('Bukti Tidak Ditemukan')->danger()->send();

            return null;
        }

        return Storage::disk(CorporateTransfer::PROOF_DISK)->download($path, 'bukti-transfer-'.$payment->transaction_id.'.'.pathinfo($path, PATHINFO_EXTENSION));
    }

    public function getCanVoidProperty(): bool
    {
        return (bool) auth()->user()?->can(CorporateTransfer::PERMISSION);
    }

    public function openVoid(string $paymentId): void
    {
        abort_unless(static::canAccess() && $this->canVoid, 403);
        $payment = Str::isUlid($paymentId) ? CorporateTransfer::payments()->where('status', 'SUCCESS')->whereKey($paymentId)->first() : null;
        $this->voidPaymentId = $payment && CorporateTransfer::isVoidable((array) $payment->payload_log) ? $payment->id : null;
        $this->voidReason = '';
    }

    public function confirmVoid(): void
    {
        abort_unless(static::canAccess(), 403);
        $payment = $this->voidPaymentId ? Payment::find($this->voidPaymentId) : null;

        try {
            if (! $payment) {
                throw new HttpException(404, 'Pembayaran tidak ditemukan.');
            }
            CorporateTransfer::void($payment, $this->voidReason, auth()->user());
            Notification::make()->title('Pencatatan Dibatalkan')
                ->body('Pembayaran dikeluarkan dari laporan dan dapat dicatat ulang melalui Kelola Sponsor → Catat Transfer.')
                ->success()->send();
            $this->voidPaymentId = null;
        } catch (HttpException $e) {
            Notification::make()->title('Tidak Dapat Dibatalkan')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        $selection = $this->selection();
        $data = $selection ? app(MonthlyBillingRecapExport::class)->data(...$selection) : null;

        return [
            'cards' => self::cardOptions(),
            'months' => self::monthOptions(),
            'recap' => $data,
            'transfers' => CorporateTransfer::history(),
        ];
    }
}
