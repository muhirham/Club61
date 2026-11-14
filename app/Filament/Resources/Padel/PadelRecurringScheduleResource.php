<?php

namespace App\Filament\Resources\Padel;

use App\Filament\Resources\Concerns\AuthorizesWithCanMethods;
use App\Filament\Resources\Padel\Pages\ManagePadelRecurringSchedules;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelRecurringOccurrence;
use App\Models\Padel\PadelRecurringSchedule;
use App\Services\Padel\RecurringScheduleService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Jadwal Tetap Mingguan — booking rutin corporate / member (mis. IOH tiap Kamis 19:00–22:00 di 3 lapangan) yang dibuat
 * otomatis dan dipotong dari kuota jam membership. Batas reschedule mengikuti pengaturan global (tombol Batas Reschedule di Jadwal Tetap Mingguan).
 */
class PadelRecurringScheduleResource extends Resource
{
    use AuthorizesWithCanMethods;

    protected static ?string $model = PadelRecurringSchedule::class;

    protected static ?string $slug = 'jadwal-tetap';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Booking Mingguan';

    protected static ?string $modelLabel = 'Jadwal Tetap';

    protected static ?string $pluralModelLabel = 'Booking Mingguan';

    protected static string|UnitEnum|null $navigationGroup = 'Operasional Harian';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('manage_recurring_bookings');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny() && ! ($record instanceof PadelRecurringSchedule && $record->isStopped());
    }

    /** Hapus bawaan Filament tidak dipakai: booking & kuota jam harus ikut dibereskan → aksi "Hapus jadwal" (RecurringScheduleService::remove). */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $hours = fn (int $from, int $to) => collect(range($from, $to))
            ->mapWithKeys(fn (int $h) => [sprintf('%02d:00', $h) => sprintf('%02d:00', $h)])->all();

        return $schema->components([
            Section::make('Pemilik kuota & jadwal')->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('name')->label('Nama jadwal')->required()->maxLength(100)
                        ->placeholder('Contoh: IOH — Kamis malam'),
                    Select::make('user_membership_id')->label('Kuota jam / Tagihan Bulanan dari membership')
                        ->options(fn (?PadelRecurringSchedule $record) => app(RecurringScheduleService::class)->membershipOptions($record?->user_membership_id))
                        ->searchable()->required()->native(false)->live()
                        ->disabled(fn (?PadelRecurringSchedule $record) => $record?->occurrences()->exists() ?? false)
                        ->helperText('Corporate atau member individu yang punya kuota jam Padel atau Tagihan Bulanan. Booking dibuat atas nama pemilik membership (PIC untuk corporate Tagihan Bulanan); booking Tagihan Bulanan langsung terkonfirmasi dan masuk rekap jam main yang ditagihkan Finance.'),
                    Select::make('day_of_week')->label('Hari')->options(PadelRecurringSchedule::DAYS)->required()->native(false),
                    Select::make('court_ids')->label('Lapangan')->multiple()->required()->native(false)
                        ->options(fn () => PadelCourt::where('is_active', true)->ordered()->pluck('name', 'id')->all()),
                    Select::make('start_time')->label('Jam mulai')->options($hours(6, 23))->required()->native(false),
                    Select::make('end_time')->label('Jam selesai')->options($hours(7, 24))->required()->native(false),
                    DatePicker::make('starts_on')->label('Mulai tanggal')->required()->native(false)->displayFormat('d M Y')->live(),
                ]),
            ]),
            // Lama jadwal = sampai kapan jadwal ini berjalan tiap minggu (disimpan sebagai ends_on oleh periodToEndsOn()).
            Section::make('Lama jadwal')->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('period_mode')->label('Jadwal berjalan')->required()->native(false)->live()
                        ->options([
                            self::PERIOD_MEMBERSHIP => 'Sampai membership habis',
                            self::PERIOD_WEEKS => 'Selama beberapa minggu',
                            self::PERIOD_DATE => 'Sampai tanggal tertentu',
                        ])
                        ->default(self::PERIOD_MEMBERSHIP),
                    TextInput::make('period_weeks')->label('Jumlah minggu')->integer()->minValue(1)->maxValue(104)->suffix('minggu')->live(onBlur: true)
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('period_mode') === self::PERIOD_WEEKS)
                        ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('period_mode') === self::PERIOD_WEEKS)
                        ->helperText(fn (\Filament\Schemas\Components\Utilities\Get $get) => ($end = self::weeksEnd($get('starts_on'), $get('period_weeks')))
                            ? 'Berakhir '.$end->locale('id')->translatedFormat('l, d M Y').'.' : 'Dihitung dari tanggal mulai.'),
                    DatePicker::make('ends_on')->label('Sampai tanggal')->native(false)->displayFormat('d M Y')
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('period_mode') === self::PERIOD_DATE)
                        ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('period_mode') === self::PERIOD_DATE),
                ]),
            ])->description('Tidak boleh lewat dari masa aktif membership. Setelah tanggal berakhir tidak ada booking baru, dan daftar "Lewati tanggal" juga hanya sampai tanggal itu.'),
            Section::make('Aturan')->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('weeks_ahead')->label('Kunci slot lebih dulu')->integer()->minValue(1)
                        ->maxValue(PadelRecurringSchedule::MAX_WEEKS_AHEAD)->required()->default(5)->suffix('minggu')
                        ->helperText('Bukan lama jadwal — hanya seberapa jauh ke depan booking dibuat & slot dikunci. Minggu berikutnya dibuat otomatis tiap malam.'),
                ]),
                Textarea::make('notes')->label('Catatan (opsional)')->rows(2)->maxLength(1000)->columnSpanFull(),
            ])->description('Customer bisa booking sampai 30 hari ke depan, jadi kunci 5 minggu = slot jadwal tetap sudah terkunci duluan. Mengubah hari / jam / durasi / lapangan ikut memindahkan booking yang sudah dibuat (aturan reschedule: yang sudah mepet tetap main di jadwal lama; lapangan yang dihapus dibatalkan & jamnya kembali). Batas reschedule mengikuti pengaturan global (tombol Batas Reschedule di kanan atas halaman ini).'),
        ]);
    }

    public const PERIOD_MEMBERSHIP = 'MEMBERSHIP';

    public const PERIOD_WEEKS = 'WEEKS';

    public const PERIOD_DATE = 'DATE';

    /** "Selama N minggu" dari tanggal mulai → tanggal terakhir (N kali hari jadwal, karena tiap 7 hari berisi satu hari jadwal). */
    public static function weeksEnd(mixed $startsOn, mixed $weeks): ?Carbon
    {
        if (! filled($startsOn) || ! is_numeric($weeks) || (int) $weeks < 1) {
            return null;
        }

        try {
            return Carbon::parse((string) $startsOn)->startOfDay()->addWeeks((int) $weeks)->subDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Pilihan "Lama jadwal" → ends_on (kosong = sampai membership habis, diisi saat validasi di RecurringScheduleService::normalize). */
    public static function periodToEndsOn(array $data): array
    {
        $data['ends_on'] = match ($data['period_mode'] ?? self::PERIOD_DATE) {
            self::PERIOD_MEMBERSHIP => null,
            self::PERIOD_WEEKS => self::weeksEnd($data['starts_on'] ?? null, $data['period_weeks'] ?? null)?->toDateString()
                ?? throw new HttpException(422, 'Isi jumlah minggu jadwal (minimal 1).'),
            default => $data['ends_on'] ?? null,
        };

        return $data;
    }

    /** Isi "Lama jadwal" saat edit: tanggal berakhir = tanggal habis membership (atau kosong) → "Sampai membership habis". */
    public static function periodState(PadelRecurringSchedule $schedule): array
    {
        $membershipEnd = $schedule->membership?->end_date?->toDateString();
        $endsOn = $schedule->ends_on?->toDateString();

        return $endsOn === null || $endsOn === $membershipEnd
            ? ['period_mode' => self::PERIOD_MEMBERSHIP, 'ends_on' => null]
            : ['period_mode' => self::PERIOD_DATE, 'ends_on' => $endsOn];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['membership.user', 'membership.sponsorOrganization', 'membership.balances']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Jadwal')->weight('bold')->searchable()
                    ->description(fn (PadelRecurringSchedule $r) => $r->ownerLabel()),
                TextColumn::make('day_of_week')->label('Hari & jam')
                    ->formatStateUsing(fn (PadelRecurringSchedule $r) => $r->scheduleLabel())
                    ->description(fn (PadelRecurringSchedule $r) => self::courtNames($r).' · '.$r->hoursPerWeek().' jam/minggu'),
                TextColumn::make('quota')->label('Sisa kuota')
                    ->state(function (PadelRecurringSchedule $r) {
                        $left = app(RecurringScheduleService::class)->weeksLeft($r);

                        return match (true) {
                            $left !== null => self::num($left['remaining']).' jam',
                            $r->monthlyBillingBalance() !== null => 'Tagihan Bulanan',
                            default => 'Tidak ada kuota jam',
                        };
                    })
                    ->description(fn (PadelRecurringSchedule $r) => ! $r->padelBalance() && $r->monthlyBillingBalance()
                        ? 'Rekap jam main ditagihkan Finance per bulan'
                        : self::forecastLabel($r))
                    ->wrap(),
                TextColumn::make('starts_on')->label('Periode')->date('d M Y')
                    ->description(function (PadelRecurringSchedule $r) {
                        $lastBooked = PadelBooking::where('recurring_schedule_id', $r->id)->whereNotIn('status', ['CANCELLED', 'REFUNDED'])->max('booking_date');

                        return ($r->ends_on ? 's/d '.$r->ends_on->locale('id')->translatedFormat('d M Y') : 'tanpa tanggal akhir')
                            .($lastBooked ? ' · booking dibuat s/d '.Carbon::parse($lastBooked)->locale('id')->translatedFormat('d M Y') : '');
                    })
                    ->wrap(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        PadelRecurringSchedule::STATUS_ACTIVE => 'Aktif',
                        PadelRecurringSchedule::STATUS_STOPPED => 'Dihentikan',
                        default => 'Dijeda',
                    })
                    ->color(fn (string $state) => match ($state) {
                        PadelRecurringSchedule::STATUS_ACTIVE => 'success',
                        PadelRecurringSchedule::STATUS_STOPPED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('history')->label('Riwayat')->icon('heroicon-o-list-bullet')->color('gray')
                    ->modalHeading(fn (PadelRecurringSchedule $r) => 'Riwayat '.$r->name)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')->modalWidth('3xl')
                    ->modalContent(fn (PadelRecurringSchedule $r) => view('filament.resources.padel.recurring-occurrences', [
                        'schedule' => $r,
                        'rows' => $r->occurrences()->with(['court', 'booking'])->orderByDesc('occurrence_date')->limit(60)->get()
                            ->groupBy(fn (PadelRecurringOccurrence $o) => $o->occurrence_date->toDateString()),
                    ])),
                ActionGroup::make([
                    Action::make('generate')->label('Buat booking sekarang')->icon('heroicon-o-bolt')
                        ->visible(fn (PadelRecurringSchedule $r) => $r->isActive())
                        ->requiresConfirmation()
                        ->modalDescription(fn (PadelRecurringSchedule $r) => 'Booking minggu yang belum dibuat (sampai '.$r->weeks_ahead.' minggu ke depan) dibuat sekarang dan dipotong dari kuota jam. Sistem juga menjalankan ini otomatis tiap malam.')
                        ->action(fn (PadelRecurringSchedule $record) => self::notifyGenerated(app(RecurringScheduleService::class)->generate($record, auth()->user()))),
                    Action::make('skip')->label('Lewati tanggal')->icon('heroicon-o-forward')->color('warning')
                        ->visible(fn (PadelRecurringSchedule $r) => ! $r->isStopped())
                        ->modalDescription('Semua lapangan jadwal ini di tanggal itu dilewati. Booking yang sudah dibuat dibatalkan dan jam kuotanya dikembalikan.')
                        ->schema(fn (PadelRecurringSchedule $record) => [
                            Select::make('date')->label('Tanggal')->required()->native(false)
                                ->options(self::upcomingDateOptions($record)),
                            Textarea::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(255)->rows(2)
                                ->placeholder('Contoh: Grand Opening'),
                        ])
                        ->action(fn (PadelRecurringSchedule $record, array $data) => self::attempt(function () use ($record, $data) {
                            $cancelled = app(RecurringScheduleService::class)->skip($record, (string) $data['date'], (string) $data['reason'], auth()->user());

                            return $cancelled
                                ? "Tanggal dilewati — {$cancelled} booking dibatalkan, kuota jam dikembalikan."
                                : 'Tanggal dilewati. Belum ada booking di tanggal ini, jadi kuota jam tidak berubah.';
                        })),
                    Action::make('unskip')->label('Batal lewati')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                        ->visible(fn (PadelRecurringSchedule $r) => ! $r->isStopped() && $r->occurrences()->where('status', PadelRecurringOccurrence::STATUS_SKIPPED)
                            ->whereDate('occurrence_date', '>=', now()->toDateString())->exists())
                        ->modalDescription('Tanggal ini dibuatkan booking lagi saat "Buat booking sekarang" / proses otomatis malam berikutnya (kalau slotnya masih kosong).')
                        ->schema(fn (PadelRecurringSchedule $record) => [
                            Select::make('date')->label('Tanggal yang dilewati')->required()->native(false)
                                ->options($record->occurrences()->where('status', PadelRecurringOccurrence::STATUS_SKIPPED)
                                    ->whereDate('occurrence_date', '>=', now()->toDateString())->orderBy('occurrence_date')->get()
                                    ->mapWithKeys(fn (PadelRecurringOccurrence $o) => [$o->occurrence_date->toDateString() => $o->occurrence_date->locale('id')->translatedFormat('l, d M Y').($o->note ? ' — '.$o->note : '')])
                                    ->all()),
                        ])
                        ->action(fn (PadelRecurringSchedule $record, array $data) => self::attempt(function () use ($record, $data) {
                            app(RecurringScheduleService::class)->unskip($record, (string) $data['date'], auth()->user());

                            return 'Tanggal tidak jadi dilewati. Booking dibuat lagi (dan kuota jam dipotong) saat "Buat booking sekarang" atau proses otomatis malam, bila slotnya masih kosong.';
                        })),
                    Action::make('toggle')
                        ->visible(fn (PadelRecurringSchedule $r) => ! $r->isStopped())
                        ->label(fn (PadelRecurringSchedule $r) => $r->isActive() ? 'Jeda' : 'Aktifkan')
                        ->icon(fn (PadelRecurringSchedule $r) => $r->isActive() ? 'heroicon-o-pause' : 'heroicon-o-play')
                        ->requiresConfirmation()
                        ->modalDescription(fn (PadelRecurringSchedule $r) => $r->isActive()
                            ? 'Booking baru berhenti dibuat. Booking yang sudah ada tidak berubah (lewati tanggalnya kalau perlu dibatalkan).'
                            : 'Booking minggu berikutnya dibuat lagi otomatis.')
                        ->action(function (PadelRecurringSchedule $record) {
                            $record->update(['status' => $record->isActive() ? PadelRecurringSchedule::STATUS_PAUSED : PadelRecurringSchedule::STATUS_ACTIVE]);
                            Notification::make()->title($record->isActive() ? 'Jadwal diaktifkan.' : 'Jadwal dijeda.')->success()->send();
                        }),
                    EditAction::make()->modalWidth('3xl')
                        ->mutateRecordDataUsing(fn (array $data, PadelRecurringSchedule $record) => self::periodState($record) + RecurringScheduleService::formState($record))
                        ->mutateDataUsing(fn (array $data, PadelRecurringSchedule $record, EditAction $action) => self::normalizeOrHalt($data, $action, $record))
                        ->using(function (PadelRecurringSchedule $record, array $data) {
                            self::notifyUpdated(app(RecurringScheduleService::class)->updateSchedule($record, $data, auth()->user()));

                            return $record;
                        })
                        ->successNotification(null),
                    Action::make('remove')->label('Hapus jadwal')->icon('heroicon-o-trash')->color('danger')
                        ->visible(fn (PadelRecurringSchedule $r) => ! $r->isStopped())
                        ->authorize(fn () => self::canViewAny())
                        ->requiresConfirmation()
                        ->modalHeading(fn (PadelRecurringSchedule $r) => 'Hapus jadwal '.$r->name.'?')
                        ->modalDescription(fn (PadelRecurringSchedule $r) => self::removalDescription($r))
                        ->modalSubmitActionLabel('Hapus jadwal')
                        ->action(fn (PadelRecurringSchedule $record) => self::notifyRemoved($record)),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePadelRecurringSchedules::route('/')];
    }

    /** Dipakai CreateAction & EditAction: periksa isian di server; gagal → notifikasi, modal tetap terbuka. */
    public static function normalizeOrHalt(array $data, Action $action, ?PadelRecurringSchedule $record = null): array
    {
        try {
            return app(RecurringScheduleService::class)->normalize(self::periodToEndsOn($data), $record);
        } catch (HttpException $e) {
            Notification::make()->title('Belum bisa disimpan')->body($e->getMessage())->danger()->send();
            $action->halt();
        }
    }

    /** Ringkasan setelah jadwal diedit: booking dipindah / tetap / dibatalkan + minggu baru yang dibuat. */
    public static function notifyUpdated(array $summary): void
    {
        $gen = $summary['generated'];
        $lines = array_filter([
            $summary['moved'] ? $summary['moved'].' booking dipindah ke jadwal baru' : null,
            $summary['cancelled'] ? $summary['cancelled'].' booking dibatalkan (lapangan dihapus), jam kuota kembali' : null,
            $gen['booked'] ? $gen['booked'].' booking baru dibuat' : null,
            $summary['kept'] ? 'Tetap di jadwal lama: '.implode(' · ', array_slice($summary['kept'], 0, 4)).(count($summary['kept']) > 4 ? ' …' : '') : null,
            $gen['failed'] ? 'Gagal dibuat: '.implode(' · ', array_slice($gen['failed'], 0, 3)) : null,
        ]);
        $notification = Notification::make()->title('Jadwal disimpan')->body($lines ? implode('. ', $lines).'.' : 'Tidak ada booking yang perlu disesuaikan.');
        ($summary['kept'] || $gen['failed']) ? $notification->warning()->persistent() : $notification->success();
        $notification->send();
    }

    public static function notifyGenerated(array $result): void
    {
        if ($result['busy']) {
            Notification::make()->title('Sedang diproses')->body('Jadwal ini sedang dibuat oleh proses lain. Coba lagi sebentar.')->warning()->send();

            return;
        }

        $notification = Notification::make()->title($result['booked'].' booking dibuat');
        if ($result['failed'] !== []) {
            $notification->body('Gagal: '.implode(' · ', array_slice($result['failed'], 0, 5)).(count($result['failed']) > 5 ? ' …' : ''))->warning()->persistent();
        } else {
            $notification->body($result['booked'] ? null : 'Semua minggu dalam jangkauan sudah dibuat / dilewati.')->success();
        }
        $notification->send();
    }

    /** Isi dialog konfirmasi "Hapus jadwal": apa yang dibatalkan, berapa jam kembali, apa yang tetap. */
    private static function removalDescription(PadelRecurringSchedule $schedule): string
    {
        $preview = app(RecurringScheduleService::class)->removalPreview($schedule);
        $lines = [
            $preview['cancel'] > 0
                ? "{$preview['cancel']} booking yang jam mainnya belum mulai dibatalkan, ".self::num($preview['hours']).' jam kembali utuh ke kuota dan lapangannya kosong lagi.'
                : 'Tidak ada booking mendatang yang perlu dibatalkan.',
            $preview['played'] > 0
                ? "{$preview['played']} sesi yang jamnya sudah lewat dianggap sudah dimainkan: tetap tercatat dan jamnya terpakai. Jadwal ditandai Dihentikan supaya riwayatnya tetap bisa dilihat."
                : 'Jadwal dihapus dari daftar.',
        ];

        return implode(' ', $lines);
    }

    private static function notifyRemoved(PadelRecurringSchedule $schedule): void
    {
        try {
            $result = app(RecurringScheduleService::class)->remove($schedule, auth()->user());
        } catch (HttpException $e) {
            Notification::make()->title('Gagal menghapus jadwal')->body($e->getMessage().' Tidak ada yang diubah.')->danger()->send();

            return;
        }

        if ($result['busy']) {
            Notification::make()->title('Sedang diproses')->body('Jadwal ini sedang dibuatkan booking oleh proses lain. Coba lagi sebentar.')->warning()->send();

            return;
        }

        Notification::make()
            ->title($result['deleted'] ? 'Jadwal dihapus' : 'Jadwal dihentikan')
            ->body(implode(' ', array_filter([
                $result['cancelled'] ? $result['cancelled'].' booking dibatalkan, '.self::num($result['hours_returned']).' jam kembali ke kuota.' : null,
                $result['played'] ? $result['played'].' sesi yang sudah lewat tetap tercatat.' : null,
            ])) ?: null)
            ->success()
            ->send();
    }

    private static function attempt(callable $callback): void
    {
        try {
            Notification::make()->title($callback())->success()->send();
        } catch (HttpException $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Tanggal main 12 minggu ke depan (hari jadwal ini) untuk "Lewati tanggal". Tanggal yang semua lapangannya sudah
     * dilewati tidak ditampilkan lagi (muncul kembali setelah "Batal lewati"), begitu juga hari ini bila jam mainnya
     * sudah dimulai (skip() menolaknya).
     */
    public static function upcomingDateOptions(PadelRecurringSchedule $schedule): array
    {
        $courts = count((array) $schedule->court_ids);
        $skipped = $schedule->occurrences()->where('status', PadelRecurringOccurrence::STATUS_SKIPPED)
            ->whereDate('occurrence_date', '>=', now()->toDateString())
            ->get(['occurrence_date', 'court_id'])
            ->groupBy(fn (PadelRecurringOccurrence $o) => $o->occurrence_date->toDateString())
            ->filter(fn ($rows) => $rows->pluck('court_id')->intersect((array) $schedule->court_ids)->unique()->count() >= $courts)
            ->keys()->all();

        // Jam kuota yang sudah terpotong per tanggal (booking yang sudah dibuat) → dikembalikan bila tanggal itu dilewati.
        $bookedHours = \App\Models\Padel\PadelBooking::where('recurring_schedule_id', $schedule->id)
            ->whereDate('booking_date', '>=', now()->toDateString())
            ->whereNotIn('status', ['CANCELLED', 'EXPIRED', 'REFUNDED'])
            ->get(['booking_date', 'member_hours_consumed'])
            ->groupBy(fn ($b) => $b->booking_date->toDateString())
            ->map(fn ($rows) => (float) $rows->sum('member_hours_consumed'));

        $options = [];
        $date = $schedule->firstDateOnOrAfter(now()->startOfDay()->max($schedule->starts_on->copy()->startOfDay()));
        for ($i = 0; $i < 12; $i++, $date = $date->copy()->addWeek()) {
            if ($schedule->ends_on && $date->gt($schedule->ends_on)) {
                break;
            }
            if (in_array($date->toDateString(), $skipped, true) || Carbon::parse($date->toDateString().' '.$schedule->start_time)->lte(now())) {
                continue;
            }
            $hours = $bookedHours->get($date->toDateString());
            $options[$date->toDateString()] = $date->locale('id')->translatedFormat('l, d M Y').($hours !== null
                ? ' — sudah dibooking, '.self::num($hours).' jam kuota dikembalikan'
                : ' — belum dibooking, kuota belum terpotong');
        }

        return $options;
    }

    /**
     * Keterangan di bawah sisa kuota: cukup sampai akhir periode, atau sampai tanggal berapa kuota menutup jadwal ini
     * (RecurringScheduleService::quotaForecast) — supaya kuota bisa ditambah sebelum minggunya gagal dibuat.
     */
    private static function forecastLabel(PadelRecurringSchedule $schedule): ?\Illuminate\Support\HtmlString
    {
        $service = app(RecurringScheduleService::class);
        $forecast = $service->quotaForecast($schedule);
        if ($forecast === null) {
            $left = $service->weeksLeft($schedule);

            return $left === null ? null : new \Illuminate\Support\HtmlString(e('≈ '.$left['weeks'].' minggu lagi'.($left['leftover'] > 0 ? ' + '.self::num($left['leftover']).' jam' : '')));
        }

        $day = fn (Carbon $d) => $d->locale('id')->translatedFormat('D, d M Y');
        if ($forecast['shortfall'] <= 0) {
            return new \Illuminate\Support\HtmlString('<span style="color:#047857;">'.e('Kuota cukup sampai akhir periode'
                .($forecast['period_end'] ? ' ('.$day($forecast['period_end']).')' : '')).'</span>');
        }

        $parts = ['Kuota kurang '.self::num($forecast['shortfall']).' jam'.($forecast['period_end'] ? ' untuk sampai '.$day($forecast['period_end']) : '').'.'];
        if ($forecast['covered_until']) {
            $parts[] = 'Cukup sampai '.$day($forecast['covered_until']).'.';
        }
        if ($forecast['partial']) {
            $parts[] = $day($forecast['partial']['date']).': hanya '.$forecast['partial']['courts'].' dari '.$forecast['partial']['of'].' lapangan.';
        }
        if ($forecast['first_missing']) {
            $parts[] = $day($forecast['first_missing']).' dst. tidak akan dibuat.';
        }

        return new \Illuminate\Support\HtmlString('<span style="color:#B42318; font-weight:600;">'.e(implode(' ', $parts)).'</span>');
    }

    private static function courtNames(PadelRecurringSchedule $schedule): string
    {
        static $courts = null;
        $courts ??= PadelCourt::pluck('name', 'id');

        return collect((array) $schedule->court_ids)->map(fn ($id) => $courts[$id] ?? '?')->implode(', ');
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
