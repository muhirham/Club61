<?php

namespace App\Filament\Pages;

use App\Models\Padel\PadelBooking;
use App\Services\Finance\LedgerReport;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Jadwal Coaching (Modul 24 Opsi B): sesi coaching per tanggal untuk resepsionis — siapkan lapangan / keranjang bola dan
 * kirim pengingat WhatsApp ke coach (pesan siap kirim, resepsionis yang menekan tombolnya).
 */
class JadwalCoaching extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Jadwal Coaching';

    protected static string|UnitEnum|null $navigationGroup = 'Coaching';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Jadwal Coaching';

    protected string $view = 'filament.pages.jadwal-coaching';

    public const STATUS_LABELS = [
        'PENDING_PAYMENT' => 'Menunggu bayar',
        'PENDING' => 'Menunggu bayar',
        'PAID' => 'Confirmed',
        'CHECKED_IN' => 'Sudah check-in',
        'COMPLETED' => 'Selesai',
        // Jam sudah lewat tanpa check-in (ditandai otomatis) — tetap tampil supaya jadwal hari itu lengkap.
        'EXPIRED' => 'Tidak check-in',
    ];

    /**
     * Tanggal filter sebagai "Y-m-d". Nilai default DatePicker ikut membawa jam ("2026-10-23 14:50:25") dan di MySQL
     * DATE(booking_date) = '… 14:50:25' tidak pernah cocok — dulu tabel kosong saat halaman baru dibuka. Nilai yang
     * bukan tanggal valid (kiriman browser) → hari ini.
     */
    public static function filterDate(?string $value): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return now(LedgerReport::TIMEZONE)->toDateString();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PadelBooking::query()
                ->whereNotNull('coaching_booking_id')
                ->whereIn('status', array_keys(self::STATUS_LABELS))
                ->with(['court:id,name', 'user:id,name,phone', 'coachingBooking.coaches.academy:id,name']))
            ->defaultSort('start_time')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Belum ada sesi coaching di tanggal ini')
            ->columns([
                TextColumn::make('start_time')->label('Jam')->weight('bold')
                    ->formatStateUsing(fn (PadelBooking $b) => $b->start_time->format('H:i').' – '.$b->end_time->format('H:i'))
                    ->description(fn (PadelBooking $b) => $b->start_time->translatedFormat('D, d M')),
                TextColumn::make('court.name')->label('Lapangan'),
                TextColumn::make('user.name')->label('Atas nama')->searchable()
                    ->description(fn (PadelBooking $b) => $b->user?->phone),
                TextColumn::make('status')->label('Status')->badge()
                    // Sesi tagihan berkala academy (Modul 31) belum dibayar tetapi sudah terkonfirmasi.
                    ->formatStateUsing(fn (string $state, PadelBooking $b) => $state === 'PENDING_PAYMENT' && $b->coachingBooking?->isTab()
                        ? 'Tagihan Berkala' : (self::STATUS_LABELS[$state] ?? $state))
                    ->color(fn (string $state, PadelBooking $b) => match (true) {
                        in_array($state, ['PAID', 'CHECKED_IN', 'COMPLETED'], true) => 'success',
                        $state === 'EXPIRED' => 'danger',
                        $state === 'PENDING_PAYMENT' && $b->coachingBooking?->isTab() => 'info',
                        default => 'warning',
                    }),
                TextColumn::make('coaches')->label('Coach')
                    ->state(fn (PadelBooking $b) => $b->coachingBooking?->coaches
                        ->sortBy(fn ($c) => $c->pivot->role === 'HEAD' ? 0 : 1)
                        ->map(fn ($c) => $c->name.($c->pivot->role === 'HEAD' ? '' : ' (+)'))
                        ->all() ?? [])
                    ->listWithLineBreaks()
                    ->description(fn (PadelBooking $b) => $b->coachingBooking?->academy_name),
                TextColumn::make('details')->label('Murid & catatan')->wrap()
                    ->state(fn (PadelBooking $b) => trim(implode(' · ', array_filter([
                        ($students = (array) ($b->coachingBooking?->students ?? [])) ? 'Murid: '.implode(', ', $students) : null,
                        $b->coachingBooking?->notes,
                    ]))) ?: '-'),
            ])
            ->filters([
                Filter::make('tanggal')
                    ->schema([DatePicker::make('date')->label('Tanggal')->default(now(LedgerReport::TIMEZONE)->toDateString())->native(false)])
                    ->query(fn (Builder $query, array $data) => $query->whereDate('booking_date', self::filterDate($data['date'] ?? null)))
                    ->indicateUsing(fn (array $data) => 'Tanggal: '.Carbon::parse(self::filterDate($data['date'] ?? null))->translatedFormat('d M Y')),
            ])
            ->recordActions([
                Action::make('remind')
                    ->label('Ingatkan Coach')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->modalHeading('Kirim pengingat ke coach')
                    ->modalDescription('Buka WhatsApp dengan pesan siap kirim, lalu tekan kirim di WhatsApp.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (PadelBooking $record) => view('filament.pages.partials.coaching-reminder-links', ['booking' => $record])),
            ]);
    }
}
