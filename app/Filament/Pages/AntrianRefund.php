<?php

namespace App\Filament\Pages;

use App\Models\Pos\Refund;
use App\Services\Finance\LedgerReport;
use App\Services\Finance\RefundQueueService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Modul 17 FR-06 — Antrian Refund: refund PENDING (kelebihan bayar, pembayaran ganda, uang masuk untuk tagihan yang
 * sudah ditutup) dulu tidak bisa diproses dari mana pun. Hanya untuk pemegang izin process_refund_queue.
 * Modul 21: pengajuan pembatalan + refund dari Kelola Pemesanan (kasir / resepsionis / admin) juga disetujui di sini;
 * yang ditolak otomatis jadi voucher saldo customer.
 */
class AntrianRefund extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Pengembalian Dana';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Pengembalian Dana';

    protected string $view = 'filament.pages.antrian-refund';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('process_refund_queue');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Refund::where('status', 'PENDING')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Refund::query()->with(['order.user:id,name', 'payment', 'processedBy:id,name', 'requestedBy:id,name', 'booking:id,booking_code', 'voucher:id,refund_id,code,balance']))
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50])
            ->emptyStateHeading('Tidak ada refund pada filter ini')
            ->columns([
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y, H:i', LedgerReport::TIMEZONE)->sortable(),
                TextColumn::make('order.order_number')
                    ->label('No. Order')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (Refund $r) => trim(($r->order?->user?->name ?? $r->order?->customer_name ?? '').($r->booking ? ' · '.$r->booking->booking_code : ''), ' ·')),
                TextColumn::make('refund_amount')->label('Nominal')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state) => BukuTransaksi::rupiah($state)),
                TextColumn::make('reason')->label('Alasan')->wrap()->limit(140)
                    ->description(fn (Refund $r) => $r->requestedBy ? 'Diajukan oleh '.$r->requestedBy->name : 'Otomatis dari sistem'),
                TextColumn::make('payment.payment_method')
                    ->label('Dibayar via')
                    ->description(fn (Refund $r) => $r->payment?->transaction_id)
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'PENDING' => 'warning',
                        'PROCESSED' => 'success',
                        'REJECTED' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Refund $r) => $r->status === 'PENDING' ? null : trim(($r->refund_method ?? '').' '.($r->refund_reference ? '· '.$r->refund_reference : '').' '.($r->voucher ? 'Jadi voucher '.$r->voucher->code : '').' '.($r->processedBy ? '· '.$r->processedBy->name : ''))),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(['PENDING' => 'Menunggu', 'PROCESSED' => 'Diproses', 'REJECTED' => 'Ditolak'])
                    ->default('PENDING'),
            ])
            ->recordActions([
                Action::make('process')
                    ->label('Proses')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Refund $r) => $r->status === 'PENDING')
                    ->authorize(fn () => auth()->user()?->can('process_refund_queue') ?? false)
                    // Pengajuan sendiri diputuskan orang lain (kecuali super_admin) — server tetap menolak juga.
                    ->disabled(fn (Refund $r) => ! RefundQueueService::canDecide($r, auth()->user()))
                    ->tooltip(fn (Refund $r) => RefundQueueService::canDecide($r, auth()->user()) ? null : 'Anda yang mengajukan — harus diputuskan admin lain atau super admin')
                    ->modalHeading(fn (Refund $r) => 'Proses refund '.BukuTransaksi::rupiah($r->refund_amount))
                    ->modalDescription('Pastikan uang SUDAH dikembalikan ke customer sebelum menyimpan. Tercatat di Riwayat Transaksi sebagai uang keluar & di Log Aktivitas (kritis).')
                    ->modalSubmitActionLabel('Uang sudah dikembalikan')
                    ->schema([
                        Select::make('refund_method')->label('Dikembalikan lewat')->options(RefundQueueService::METHODS)->required()->native(false),
                        TextInput::make('refund_reference')->label('Nomor referensi')->placeholder('No. transfer / void EDC / refund Midtrans')->required()->minLength(3)->maxLength(120),
                        Textarea::make('admin_notes')->label('Catatan (opsional)')->rows(2)->maxLength(1000),
                    ])
                    ->action(function (Refund $record, array $data) {
                        $this->runQueueAction(fn () => app(RefundQueueService::class)->process(
                            $record, auth()->user(), $data['refund_method'], $data['refund_reference'], $data['admin_notes'] ?? null
                        ), 'Refund diproses dan dicatat di Riwayat Transaksi.');
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Refund $r) => $r->status === 'PENDING')
                    ->authorize(fn () => auth()->user()?->can('process_refund_queue') ?? false)
                    // Pengajuan sendiri diputuskan orang lain (kecuali super_admin) — server tetap menolak juga.
                    ->disabled(fn (Refund $r) => ! RefundQueueService::canDecide($r, auth()->user()))
                    ->tooltip(fn (Refund $r) => RefundQueueService::canDecide($r, auth()->user()) ? null : 'Anda yang mengajukan — harus diputuskan admin lain atau super admin')
                    ->modalHeading(fn (Refund $r) => 'Tolak refund '.BukuTransaksi::rupiah($r->refund_amount))
                    ->modalDescription('Tidak ada uang keluar & Riwayat Transaksi tidak berubah. Booking tetap batal. Uangnya dijadikan voucher saldo atas nama customer (bisa dipakai untuk booking berikutnya). Alasan wajib diisi dan tercatat di Log Aktivitas (kritis).')
                    ->modalSubmitActionLabel('Tolak refund')
                    ->schema([
                        Textarea::make('reason')->label('Alasan penolakan')->required()->minLength(5)->maxLength(1000)->rows(3),
                        Toggle::make('issue_voucher')
                            ->label('Jadikan voucher saldo untuk customer')
                            ->helperText('Matikan hanya kalau uangnya memang bukan milik customer (mis. sudah dikembalikan di luar sistem / data ganda).')
                            ->default(true),
                    ])
                    ->action(function (Refund $record, array $data) {
                        $issue = (bool) ($data['issue_voucher'] ?? true);
                        $this->runQueueAction(
                            fn () => app(RefundQueueService::class)->reject($record, auth()->user(), $data['reason'], $issue),
                            'Refund ditolak.',
                            fn (Refund $r) => $r->voucher ? 'Voucher saldo '.$r->voucher->code.' senilai '.BukuTransaksi::rupiah($r->voucher->balance).' diterbitkan untuk customer.' : null,
                        );
                    }),
            ]);
    }

    private function runQueueAction(\Closure $callback, string $success, ?\Closure $body = null): void
    {
        try {
            $result = $callback();
            Notification::make()->title($success)->body($body ? $body($result) : null)->success()->send();
        } catch (HttpException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
