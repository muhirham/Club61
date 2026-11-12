<?php

namespace App\Filament\Pages;

use App\Models\Padel\PadelBooking;
use App\Services\Coaching\CoachingBookingService;
use App\Services\Finance\LedgerReport;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Jadwal Coaching (Modul 24 Opsi B): sesi coaching per tanggal untuk resepsionis — siapkan lapangan / keranjang bola dan
 * kirim pengingat WhatsApp ke coach (pesan siap kirim, resepsionis yang menekan tombolnya), serta Ganti Coach bila coach
 * berhalangan / diganti di lapangan.
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
     * Tanggal sebagai "Y-m-d". Dulu nilai default DatePicker ikut membawa jam ("2026-10-23 14:50:25") dan di MySQL
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

    /** Toolbar tanggal: 'day' = satu tanggal main ($date), 'upcoming' = hari ini & seterusnya. */
    public string $range = 'day';

    /** Tanggal main (Y-m-d) saat $range = 'day'. Dibuka ulang → selalu hari ini. */
    public string $date = '';

    public function mount(): void
    {
        $this->date = self::filterDate(null);
    }

    public function updatedDate(): void
    {
        $this->date = self::filterDate($this->date);
        $this->range = 'day';
        $this->resetPage();
    }

    public function showDay(int $offset): void
    {
        $this->date = now(LedgerReport::TIMEZONE)->addDays($offset)->toDateString();
        $this->range = 'day';
        $this->resetPage();
    }

    public function showUpcoming(): void
    {
        $this->range = 'upcoming';
        $this->resetPage();
    }

    /** Sesi sesuai toolbar tanggal (dipakai tabel & jumlah sesi). */
    public function scheduleQuery(): Builder
    {
        return PadelBooking::query()
            ->whereNotNull('coaching_booking_id')
            ->whereIn('status', array_keys(self::STATUS_LABELS))
            ->when($this->range === 'upcoming',
                fn (Builder $q) => $q->whereDate('booking_date', '>=', self::filterDate(null)),
                fn (Builder $q) => $q->whereDate('booking_date', self::filterDate($this->date)));
    }

    public function sessionCount(): int
    {
        return $this->scheduleQuery()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->scheduleQuery()
                ->with(['court:id,name', 'user:id,name,phone', 'coachingBooking.coaches.academy:id,name']))
            ->defaultSort('start_time')
            ->paginated([25, 50, 100])
            ->emptyStateHeading(fn () => $this->range === 'upcoming' ? 'Belum ada sesi coaching mendatang' : 'Belum ada sesi coaching di tanggal ini')
            ->columns([
                // Lapangan digabung ke kolom jam supaya tombol aksi muat di layar tablet tanpa geser ke samping.
                TextColumn::make('start_time')->label('Jam & Lapangan')->weight('bold')
                    ->formatStateUsing(fn (PadelBooking $b) => $b->start_time->format('H:i').' – '.$b->end_time->format('H:i'))
                    ->description(fn (PadelBooking $b) => ($b->court?->name ?? '-').' · '.$b->start_time->locale('id')->translatedFormat('D, d M')),
                TextColumn::make('user.name')->label('Atas nama')
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
                    ->state(fn (PadelBooking $b) => $b->coachingBooking?->sortedCoaches()
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
                // Coach berhalangan / diganti di lapangan: jadwal, pengingat WhatsApp & rekap per coach ikut coach pengganti.
                Action::make('changeCoach')
                    ->label('Ganti Coach')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (PadelBooking $b) => $b->coachingBooking !== null
                        && in_array($b->status, CoachingBookingService::CHANGEABLE_STATUSES, true))
                    ->authorize(fn () => auth()->user()?->can('book_coaching') ?? false)
                    ->modalHeading('Ganti coach sesi coaching')
                    ->modalDescription('Coach pengganti harus berasal dari academy yang sama dan tidak memiliki sesi lain pada jam ini. Perubahan berlaku untuk seluruh jam pada sesi ini; harga dan pembayaran tidak berubah.')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->fillForm(fn (PadelBooking $record) => [
                        'coaches' => self::sessionCoaches($record)->mapWithKeys(fn ($c) => [(string) $c->id => (string) $c->id])->all(),
                    ])
                    ->schema(fn (PadelBooking $record) => [
                        ...self::sessionCoaches($record)->map(fn ($c) => Select::make('coaches.'.$c->id)
                            ->label(($c->pivot->role === 'HEAD' ? 'Coach utama' : 'Coach tambahan').' — saat ini: '.$c->name)
                            ->options(CoachingBookingService::replacementOptions($c)->mapWithKeys(fn ($o) => [(string) $o->id => $o->optionLabel()])->all())
                            ->required()
                            ->native(false)
                            ->searchable())->all(),
                        Textarea::make('reason')->label('Alasan (opsional)')->placeholder('Contoh: coach berhalangan hadir')->rows(2)->maxLength(500),
                    ])
                    ->action(function (PadelBooking $record, array $data) {
                        try {
                            app(CoachingBookingService::class)->changeCoaches($record->coachingBooking, (array) ($data['coaches'] ?? []), $data['reason'] ?? null, auth()->user());
                            Notification::make()->title('Coach berhasil diganti.')
                                ->body('Silakan kirim pengingat ke coach pengganti melalui tombol Ingatkan Coach.')->success()->send();
                        } catch (HttpException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    /** Coach sesi ini, coach utama lebih dulu. */
    private static function sessionCoaches(PadelBooking $booking): Collection
    {
        return $booking->coachingBooking?->sortedCoaches() ?? collect();
    }
}
