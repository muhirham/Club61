<?php

namespace App\Filament\Pages;

use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\LedgerReport;
use App\Services\Finance\LedgerWriter;
use App\Services\Pos\PaymentMethodCorrection;
use App\Services\Pos\PosPaymentProof;
use App\Services\Pos\ShiftClosingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Detail satu shift untuk pemegang izin approve_shift_closing: rekonsiliasi (sistem vs yang diketik kasir), daftar
 * transaksi shift dengan koreksi metode bayar (PaymentMethodCorrection), dan setujui / tolak (ShiftClosingService::review).
 */
class DetailShiftKasir extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Detail Shift Kasir';

    protected string $view = 'filament.pages.detail-shift-kasir';

    #[Url]
    public ?string $shift = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('approve_shift_closing');
    }

    public function mount(): void
    {
        abort_unless($this->shift && PosCashierShift::whereKey($this->shift)->exists(), 404);
    }

    public function getRecordProperty(): PosCashierShift
    {
        return PosCashierShift::with(['openedBy:id,name', 'closedBy:id,name', 'reviewedBy:id,name'])->findOrFail($this->shift);
    }

    /** Filament Shield membuat instance setiap halaman (tanpa ?shift=) untuk membaca judulnya — jangan sampai gagal. */
    public function getTitle(): string
    {
        $number = $this->shift ? PosCashierShift::whereKey($this->shift)->value('shift_number') : null;

        return $number ? 'Shift '.$number : (string) static::$title;
    }

    protected function getHeaderActions(): array
    {
        $decide = fn (bool $approve) => Action::make($approve ? 'approve' : 'reject')
            ->label($approve ? 'Setujui' : 'Tolak')
            ->icon($approve ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
            ->color($approve ? 'success' : 'danger')
            ->visible(fn () => $this->record->review_status === PosCashierShift::REVIEW_NEEDS_REVIEW)
            ->authorize(fn () => auth()->user()?->can('approve_shift_closing') ?? false)
            // Setujui hanya setelah semua baris COCOK; selisih nyata = Tolak.
            ->disabled(fn () => $approve && ! ShiftClosingService::fullyMatched($this->record))
            ->tooltip(fn () => $approve && ! ShiftClosingService::fullyMatched($this->record)
                ? 'Masih ada selisih — betulkan dulu lewat Koreksi metode / Koreksi angka struk, atau Tolak.'
                : null)
            ->modalHeading(($approve ? 'Setujui' : 'Tolak').' shift '.$this->record->shift_number)
            ->modalDescription($approve
                ? 'Semua baris sudah COCOK setelah koreksi. Tercatat di Log Aktivitas.'
                : 'Selisih nyata yang tidak bisa dibetulkan (uang kurang / transaksi tidak tercatat) — jadi bahan tindak lanjut ke kasir. Tercatat di Log Aktivitas (kritis).')
            ->schema([
                Textarea::make('notes')->label('Catatan keputusan')->required()->minLength(5)->maxLength(2000)->rows(3),
            ])
            ->action(fn (array $data) => $this->run(
                fn () => app(ShiftClosingService::class)->review($this->record->getKey(), auth()->user(), $approve, $data['notes']),
                $approve ? 'Shift disetujui.' : 'Shift ditolak.',
            ));

        return [
            Action::make('back')->label('Kembali')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(ReviewShiftKasir::getUrl()),
            // Struk Z-Report hilang / rusak → cetak ulang dari data yang tersimpan (bertanda CETAK ULANG, tercatat di log).
            Action::make('reprintZ')
                ->label('Cetak Ulang Z-Report')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn () => $this->record->status === 'CLOSED')
                ->authorize(fn () => auth()->user()?->can('approve_shift_closing') ?? false)
                ->modalWidth('md')
                ->modalHeading('Cetak ulang Z-Report '.$this->record->shift_number)
                ->modalDescription('Isi sesuai data shift saat ini (termasuk koreksi metode & keputusan review). Tercatat di Log Aktivitas.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup')
                ->mountUsing(fn () => ActivityLogger::record(
                    module: 'POS',
                    event: 'shift.z_report_reprinted',
                    description: 'Membuka cetak ulang Z-Report shift '.$this->record->shift_number,
                    subject: $this->record,
                    meta: ['shift' => $this->record->shift_number],
                ))
                ->modalContent(fn () => view('filament.pages.partials.z-report-reprint-modal', [
                    'report' => ShiftClosingService::reportData($this->record),
                    'reprint' => ['at' => now('Asia/Jakarta')->format('d/m/Y H:i'), 'by' => auth()->user()?->name],
                ])),
            $decide(true),
            $decide(false),
        ];
    }

    /**
     * Koreksi angka struk yang salah diketik kasir — dirender per baris di tabel "Sistem vs yang diketik kasir"
     * (argumen row = kunci baris). ShiftClosingService::correctTypedAmount yang memvalidasi semuanya.
     */
    public function correctTypedAction(): Action
    {
        $row = fn (array $arguments) => collect($this->record->settlement_reconciliation ?? [])->firstWhere('key', $arguments['row'] ?? null);

        return Action::make('correctTyped')
            ->label('Koreksi')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->link()
            ->size('xs')
            ->visible(fn () => $this->record->status === 'CLOSED' && $this->record->review_status === PosCashierShift::REVIEW_NEEDS_REVIEW)
            ->authorize(fn () => auth()->user()?->can('approve_shift_closing') ?? false)
            ->modalWidth('md')
            ->modalHeading(fn (array $arguments) => 'Koreksi angka struk · '.($row($arguments)['label'] ?? '-'))
            ->modalDescription(function (array $arguments) use ($row) {
                $r = $row($arguments) ?? [];
                $typed = ($r['typed_by_cashier'] ?? true) ? BukuTransaksi::rupiah($r['settled_amount'] ?? 0) : 'tidak diketik';

                return 'Sistem: '.BukuTransaksi::rupiah($r['system_amount'] ?? 0).' · tercatat sekarang: '.$typed
                    .'. Isi angka yang benar dari struk settlement / Audit Report EDC atau aplikasi QRIS. Ketikan asli kasir tetap tersimpan & tampil; tercatat di Log Aktivitas (kritis).';
            })
            ->schema([
                TextInput::make('amount')->label('Angka yang benar di struk / aplikasi (Rp)')->required()->numeric()->minValue(0)->integer(),
                Textarea::make('reason')->label('Alasan koreksi')->required()->minLength(5)->maxLength(500)->rows(2)
                    ->placeholder('mis. Struk settlement EDC BCA Rp 5.250.000, kasir salah ketik digit'),
            ])
            ->action(fn (array $data, array $arguments) => $this->run(
                fn () => app(ShiftClosingService::class)->correctTypedAmount(
                    $this->record->getKey(), auth()->user(), (string) ($arguments['row'] ?? ''), (string) $data['amount'], (string) $data['reason'],
                ),
                'Angka struk dikoreksi.',
            ));
    }

    public function table(Table $table): Table
    {
        $label = fn (Payment $p) => app(LedgerWriter::class)->methodSnapshot($p);

        return $table
            ->query(Payment::query()->with('order:id,order_number,customer_name,user_id', 'order.user:id,name')
                ->where('pos_shift_id', $this->shift)->where('status', 'SUCCESS'))
            ->defaultSort('paid_at')
            ->paginated([25, 50, 100])
            ->heading('Transaksi di shift ini')
            ->emptyStateHeading('Belum ada transaksi lunas di shift ini')
            ->columns([
                TextColumn::make('paid_at')->label('Waktu')->dateTime('d M, H:i', LedgerReport::TIMEZONE),
                TextColumn::make('order.order_number')->label('No. Order')->fontFamily('mono')->searchable()
                    ->description(fn (Payment $p) => $p->order?->user?->name ?? $p->order?->customer_name),
                TextColumn::make('amount')->label('Nominal')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state) => BukuTransaksi::rupiah($state)),
                TextColumn::make('payment_method')->label('Metode')
                    ->formatStateUsing(fn (Payment $p) => $label($p)['payment_method_label'])
                    ->description(function (Payment $p) use ($label) {
                        $last = collect($p->payload_log['method_corrections'] ?? [])->last();

                        return trim(($label($p)['payment_reference'] ?? '').($last
                            ? ' · Dikoreksi '.($last['by_name'] ?? '-').' '.Carbon::parse($last['at'])->setTimezone(LedgerReport::TIMEZONE)->format('d M H:i')
                            : ''), ' ·');
                    }),
            ])
            ->recordActions([
                Action::make('correct')
                    ->label('Koreksi metode')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (Payment $p) => PaymentMethodCorrection::blockedReason($p, $this->record) === null)
                    ->authorize(fn () => auth()->user()?->can('approve_shift_closing') ?? false)
                    ->modalHeading(fn (Payment $p) => 'Koreksi metode bayar '.BukuTransaksi::rupiah($p->amount))
                    ->modalDescription('Nominal tidak berubah. Isi metode & bukti yang benar sesuai struk / slip. Riwayat Transaksi tetap satu baris dengan tanda koreksi; tercatat di Log Aktivitas (kritis).')
                    ->schema([
                        Select::make('method')->label('Metode yang benar')->options(PosPaymentProof::activeMethods())->required()->native(false)->live(),
                        Select::make('card_type')->label('Jenis kartu')->options(['DEBIT' => 'Kartu Debit', 'CREDIT' => 'Kartu Kredit'])->default('DEBIT')
                            ->visible(fn (Get $get) => str_starts_with((string) $get('method'), 'EDC_'))->native(false),
                        TextInput::make('card_last_4')->label('4 digit terakhir kartu')->maxLength(4)
                            ->visible(fn (Get $get) => str_starts_with((string) $get('method'), 'EDC_')),
                        TextInput::make('approval_code')->label('Approval code (slip EDC)')->maxLength(12)
                            ->visible(fn (Get $get) => str_starts_with((string) $get('method'), 'EDC_')),
                        TextInput::make('trace_number')->label('Trace number (slip EDC)')->maxLength(12)
                            ->visible(fn (Get $get) => str_starts_with((string) $get('method'), 'EDC_')),
                        Select::make('qris_provider')->label('Penyedia QRIS')->options(PosPaymentProof::QRIS_PROVIDERS)->default('BCA_QRIS')->native(false)
                            ->visible(fn (Get $get) => $get('method') === 'QRIS'),
                        TextInput::make('qris_rrn')->label('RRN QRIS')->maxLength(32)
                            ->visible(fn (Get $get) => $get('method') === 'QRIS'),
                        TextInput::make('transfer_bank')->label('Bank')->maxLength(40)
                            ->visible(fn (Get $get) => $get('method') === 'TRANSFER_BANK'),
                        TextInput::make('transfer_reference')->label('Nomor referensi transfer')->maxLength(40)
                            ->visible(fn (Get $get) => $get('method') === 'TRANSFER_BANK'),
                        Textarea::make('reason')->label('Alasan koreksi')->required()->minLength(5)->maxLength(500)->rows(2),
                    ])
                    ->action(fn (Payment $record, array $data) => $this->run(
                        fn () => app(PaymentMethodCorrection::class)->apply(
                            $record, auth()->user(), (string) $data['method'],
                            array_diff_key($data, array_flip(['method', 'reason'])), (string) $data['reason'],
                        ),
                        'Metode bayar dikoreksi.',
                    )),
            ]);
    }

    private function run(\Closure $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->title($success)->success()->send();
        } catch (HttpException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
