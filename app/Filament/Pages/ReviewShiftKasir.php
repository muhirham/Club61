<?php

namespace App\Filament\Pages;

use App\Models\Pos\PosCashierShift;
use App\Services\Finance\LedgerReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Review Shift Kasir (tutup shift Fase 1): shift yang ditutup dengan selisih (toleransi Rp0) menunggu keputusan pemegang
 * izin approve_shift_closing, plus shift yang masih buka (koreksi salah klik saat itu juga). Detail, koreksi metode bayar
 * & setujui / tolak ada di DetailShiftKasir.
 */
class ReviewShiftKasir extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Rekap Shift Kasir';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Rekap Shift Kasir';

    protected string $view = 'filament.pages.review-shift-kasir';

    public const STATUS_LABELS = [
        PosCashierShift::REVIEW_NEEDS_REVIEW => 'Perlu Review',
        PosCashierShift::REVIEW_MATCHED => 'Cocok',
        PosCashierShift::REVIEW_APPROVED => 'Disetujui',
        PosCashierShift::REVIEW_REJECTED => 'Ditolak',
    ];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('approve_shift_closing');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = PosCashierShift::where('review_status', PosCashierShift::REVIEW_NEEDS_REVIEW)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function counterLabel(?string $counter): string
    {
        return match ($counter) {
            'PADEL_FRONTDESK' => 'Frontdesk Padel',
            'FNB_COUNTER' => 'Kasir F&B',
            default => (string) $counter,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            // Shift yang masih buka ikut tampil: kasir salah klik → lapor admin → admin koreksi saat itu juga.
            ->query(PosCashierShift::query()->with(['openedBy:id,name', 'closedBy:id,name', 'reviewedBy:id,name'])->whereIn('status', ['OPEN', 'CLOSED']))
            ->defaultSort('opened_at', 'desc')
            ->paginated([25, 50])
            ->emptyStateHeading('Tidak ada shift pada filter ini')
            ->columns([
                TextColumn::make('shift_number')->label('Shift')->fontFamily('mono')->weight('bold')->searchable()
                    ->description(fn (PosCashierShift $s) => self::counterLabel($s->counter)),
                TextColumn::make('closed_at')->label('Ditutup')->dateTime('d M Y, H:i', LedgerReport::TIMEZONE)->sortable()
                    ->placeholder('Masih buka')
                    ->description(fn (PosCashierShift $s) => $s->status === 'OPEN'
                        ? 'dibuka '.$s->opened_at?->setTimezone(LedgerReport::TIMEZONE)->format('d M H:i').' oleh '.($s->openedBy?->name ?? '-')
                        : 'oleh '.($s->closedBy?->name ?? '-')),
                // Shift masih buka: total_sales baru diisi saat tutup shift → tampilkan total berjalan (sama dengan halaman detail).
                TextColumn::make('total_sales')->label('Penjualan')->alignEnd()
                    ->getStateUsing(fn (PosCashierShift $s) => $s->status === 'OPEN' ? $s->calculateSummary()['total_sales'] : $s->total_sales)
                    ->description(fn (PosCashierShift $s) => $s->status === 'OPEN' ? 'berjalan' : null)
                    ->formatStateUsing(fn ($state) => BukuTransaksi::rupiah($state)),
                TextColumn::make('settlement_difference')->label('Selisih')->alignEnd()->weight('bold')->placeholder('-')
                    ->color(fn ($state) => abs((float) $state) < 0.005 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state) => abs((float) $state) < 0.005 ? 'Rp 0' : ((float) $state > 0 ? '+' : '-').BukuTransaksi::rupiah(abs((float) $state))),
                TextColumn::make('review_status')->label('Status')->badge()
                    ->getStateUsing(fn (PosCashierShift $s) => $s->status === 'OPEN' ? 'OPEN' : $s->review_status)
                    ->formatStateUsing(fn (?string $state) => $state === 'OPEN' ? 'Sedang buka' : (self::STATUS_LABELS[$state] ?? 'Sebelum rekonsiliasi blind'))
                    ->color(fn (?string $state) => match ($state) {
                        'OPEN' => 'info',
                        PosCashierShift::REVIEW_NEEDS_REVIEW => 'warning',
                        PosCashierShift::REVIEW_MATCHED, PosCashierShift::REVIEW_APPROVED => 'success',
                        PosCashierShift::REVIEW_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (PosCashierShift $s) => $s->reviewedBy ? 'oleh '.$s->reviewedBy->name : null),
            ])
            ->filters([
                SelectFilter::make('review_status')->label('Status')
                    ->options(['OPEN' => 'Sedang buka'] + self::STATUS_LABELS)
                    ->default(PosCashierShift::REVIEW_NEEDS_REVIEW)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'OPEN' => $query->where('status', 'OPEN'),
                        default => $query->where('status', 'CLOSED')->where('review_status', $data['value']),
                    }),
                SelectFilter::make('counter')->label('Loket')->options(['PADEL_FRONTDESK' => 'Frontdesk Padel', 'FNB_COUNTER' => 'Kasir F&B']),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Buka')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (PosCashierShift $s) => DetailShiftKasir::getUrl(['shift' => $s->getKey()])),
            ]);
    }
}
