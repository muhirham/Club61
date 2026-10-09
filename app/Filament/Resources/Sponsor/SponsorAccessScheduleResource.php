<?php

namespace App\Filament\Resources\Sponsor;

use App\Filament\Resources\Sponsor\Pages\CreateSponsorAccessSchedule;
use App\Filament\Resources\Sponsor\Pages\EditSponsorAccessSchedule;
use App\Filament\Resources\Sponsor\Pages\ListSponsorAccessSchedules;
use App\Models\Sponsor\SponsorAccessSchedule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Jadwal akses lapangan per sponsor corporate — SENGAJA dikontrol di sini (staf venue lewat
 * panel admin), BUKAN self-service oleh PIC. Dipakai untuk gantian jatah jam antar beberapa
 * sponsor yang berbagi kapasitas lapangan yang sama (mis. 3 sponsor, 3 lapangan, tidak semua
 * boleh main di jam rame bersamaan). Lihat PRD Modul 12 dan SponsorScheduleService.
 */
class SponsorAccessScheduleResource extends Resource
{
    use \App\Filament\Resources\Concerns\AuthorizesWithCanMethods;

    protected static ?string $model = SponsorAccessSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Jadwal Akses Sponsor';

    protected static ?string $modelLabel = 'Jadwal Akses Sponsor';

    protected static ?string $pluralModelLabel = 'Jadwal Akses Sponsor';

    protected static string|UnitEnum|null $navigationGroup = 'Sponsor';

    protected static ?int $navigationSort = 2;

    // Model SponsorAccessSchedule tidak punya Gate::policy() apa pun terdaftar — tanpa override
    // ini, Filament fallback ke Gate::before-only (default ALLOW ke semua staf yang login, apapun
    // rolenya) karena tidak ada policy yang bisa dicek sama sekali. Override langsung di sini
    // supaya cuma staf dengan permission eksplisit yang bisa lihat/ubah jadwal akses sponsor.
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('view_sponsor_access_schedules');
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('view_sponsor_access_schedules');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_access_schedules');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_access_schedules');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_access_schedules');
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_access_schedules');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Apa Itu Jadwal Akses Sponsor?')
                ->description(
                    "Fitur ini buat MENGATUR JAM BOLEH MAIN karyawan dari 1 perusahaan sponsor, supaya lapangan tidak diborong terus-menerus oleh 1 perusahaan saja di jam-jam ramai.\n\n".
                    "Contoh nyata: Club 61 cuma punya 3 lapangan, tapi ada 3 perusahaan sponsor berbeda. Kalau semua karyawan dari 3 perusahaan itu bebas booking kapan saja, bisa saja 1 perusahaan (misal PT Maju Jaya) memborong ketiga lapangan terus setiap jam ramai, dan perusahaan lain jadi tidak kebagian.\n\n".
                    "Dengan aturan di bawah ini, Anda bisa bilang ke sistem: \"PT Maju Jaya cuma boleh main jam 08:00–16:00, Senin sampai Jumat, dan maksimal pakai 1 lapangan di waktu yang sama.\" Kalau karyawan PT Maju Jaya coba booking di luar jam/hari itu, atau mau pakai lapangan ke-2 di jam yang sama, sistem OTOMATIS MENOLAK booking-nya."
                )
                ->schema([])
                ->columnSpanFull(),

            Section::make('1. Pilih Perusahaan Sponsor')
                ->description('Aturan yang Anda buat di halaman ini HANYA berlaku untuk 1 perusahaan yang dipilih di sini — bukan untuk semua sponsor sekaligus.')
                ->schema([
                    Select::make('sponsor_organization_id')
                        ->label('Perusahaan Sponsor')
                        ->helperText('Cari nama perusahaan atau nama PIC-nya.')
                        ->relationship('organization', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->columnSpanFull(),

            Section::make('2. Kapan Aturan Ini Mulai & Berakhir')
                ->description('Aturan ini HANYA aktif di antara 2 tanggal ini. Di luar rentang tanggal ini, karyawan sponsor bebas booking kapan saja tanpa batasan jam/hari di bawah.')
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('valid_from')
                            ->label('Mulai Berlaku Tanggal')
                            ->helperText('Contoh: 1 Oktober 2026 — aturan mulai aktif dari tanggal ini.')
                            ->required(),

                        DatePicker::make('valid_until')
                            ->label('Berakhir Tanggal')
                            ->helperText('Contoh: 31 Oktober 2026 — setelah tanggal ini aturan otomatis berhenti. Mau berlaku terus-menerus? Isi tanggal yang jauh di masa depan (misal beberapa tahun lagi).')
                            ->required()
                            ->rules(['after_or_equal:valid_from']),
                    ]),
                ])
                ->columnSpanFull(),

            Section::make('3. Hari & Jam Berapa Saja yang Boleh Main')
                ->description('Ini bagian INTI-nya: di luar hari/jam yang diizinkan di sini, booking karyawan sponsor ini akan ditolak sistem.')
                ->schema([
                    Select::make('days_of_week')
                        ->label('Hari yang Diizinkan')
                        ->helperText('Klik hari-hari yang BOLEH main. Contoh: kalau cuma pilih Senin–Jumat, berarti Sabtu & Minggu sponsor ini bebas main jam berapa saja (aturan jam di bawah tidak berlaku di weekend). KOSONGKAN kalau aturan jam berlaku SETIAP HARI termasuk weekend.')
                        ->multiple()
                        ->options([
                            1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
                            5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
                        ])
                        ->dehydrateStateUsing(fn (?array $state) => filled($state) ? array_values(array_map('intval', $state)) : null),

                    Grid::make(2)->schema([
                        TimePicker::make('time_start')
                            ->label('Boleh Main Mulai Jam')
                            ->helperText('Contoh: 08:00 — karyawan sponsor ini baru bisa booking mulai jam 8 pagi.')
                            ->seconds(false)
                            ->required(),

                        TimePicker::make('time_end')
                            ->label('Boleh Main Sampai Jam')
                            ->helperText('Contoh: 16:00 — booking di atas jam 4 sore otomatis ditolak sistem.')
                            ->seconds(false)
                            ->required()
                            ->rules(['after:time_start']),
                    ]),
                ])
                ->columnSpanFull(),

            Section::make('4. Batasi Jumlah Lapangan Sekaligus (Opsional)')
                ->description('Bagian ini OPSIONAL — boleh dikosongkan kalau tidak perlu dibatasi.')
                ->schema([
                    TextInput::make('max_concurrent_courts')
                        ->label('Maksimal Lapangan Dipakai Bersamaan')
                        ->placeholder('Kosongkan = tanpa batas')
                        ->helperText(
                            'Ini buat jaga-jaga supaya 1 sponsor tidak "memborong" semua lapangan sekaligus di jam yang sama. '.
                            'Contoh: Club 61 punya 3 lapangan, lalu diisi angka 1 — artinya sponsor ini MAKSIMAL cuma boleh pakai 1 lapangan di waktu bersamaan. '.
                            'Kalau ada 2 karyawan dari sponsor yang sama coba booking di jam yang sama tapi 1 lapangan sudah terpakai, booking karyawan ke-2 akan ditolak dan harus cari jam lain. '.
                            'Kosongkan kalau sponsor ini boleh pakai berapa pun lapangan yang tersedia sekaligus (tanpa batas).'
                        )
                        ->numeric()
                        ->minValue(1),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Sponsor')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('valid_from')
                    ->label('Dari')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('valid_until')
                    ->label('Sampai')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('days_of_week')
                    ->label('Hari')
                    ->formatStateUsing(function (?array $state) {
                        if (empty($state)) {
                            return 'Semua Hari';
                        }
                        $labels = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

                        return collect($state)->map(fn ($d) => $labels[(int) $d] ?? $d)->implode(', ');
                    }),

                TextColumn::make('time_start')
                    ->label('Jam Mulai'),

                TextColumn::make('time_end')
                    ->label('Jam Selesai'),

                TextColumn::make('max_concurrent_courts')
                    ->label('Maks Lapangan')
                    ->placeholder('Tanpa batas'),

                TextColumn::make('createdBy.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('-'),
            ])
            ->defaultSort('valid_from', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSponsorAccessSchedules::route('/'),
            'create' => CreateSponsorAccessSchedule::route('/create'),
            'edit' => EditSponsorAccessSchedule::route('/{record}/edit'),
        ];
    }
}
