<?php

namespace App\Filament\Resources\Membership;

use App\Filament\Resources\Membership\Pages\CreateMembershipPlan;
use App\Filament\Resources\Membership\Pages\EditMembershipPlan;
use App\Filament\Resources\Membership\Pages\ListMembershipPlans;
use App\Models\Membership\MembershipFacility;
use App\Models\Membership\MembershipPlan;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Membership\MembershipFacilityService;
use App\Services\Membership\MonthlyBilling;
use App\Services\Membership\PadelAccessRule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

class MembershipPlanResource extends Resource
{
    use \App\Filament\Resources\Concerns\AuthorizesWithCanMethods;

    protected static ?string $model = MembershipPlan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Paket Membership';

    protected static ?string $modelLabel = 'Paket Membership';

    protected static ?string $pluralModelLabel = 'Paket Membership';

    protected static string|UnitEnum|null $navigationGroup = 'Customer & Membership';

    protected static ?int $navigationSort = 2;

    // Model MembershipPlan tidak punya Gate::policy() apa pun terdaftar — tanpa override ini,
    // Filament fallback ke Gate::before-only (default ALLOW ke semua staf yang login, apapun
    // rolenya). Override langsung di sini supaya cuma staf dengan permission eksplisit yang bisa
    // mengubah harga/benefit paket membership.
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('view_membership_plans');
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('view_membership_plans');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('manage_membership_plans');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('manage_membership_plans');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('manage_membership_plans');
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->can('manage_membership_plans');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Utama Paket')
                    ->description('Tentukan identitas paket, kode sistem, masa berlaku, dan harga jual keanggotaan.')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 3,
                        ])->schema([
                            TextInput::make('code')
                                ->label('Kode Paket')
                                ->placeholder('Contoh: MBR-SILVER')
                                ->required()
                                ->maxLength(30)
                                ->unique(ignoreRecord: true),

                            TextInput::make('name')
                                ->label('Nama Paket')
                                ->placeholder('Contoh: Silver Padel Addict')
                                ->required()
                                ->maxLength(150),

                            Select::make('ownership_type')
                                ->label('Tipe Kepemilikan')
                                ->options([
                                    'INDIVIDUAL' => 'Individual (Perorangan)',
                                    'ORGANIZATIONAL' => 'Organizational (Perusahaan / Sponsor)',
                                ])
                                ->default('INDIVIDUAL')
                                ->required()
                                // Modul 34: Tagihan Bulanan dibayar perusahaan & dicatat di Kelola Sponsor → hanya paket Organisasi.
                                ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if ($value !== 'ORGANIZATIONAL' && collect((array) $get('benefits'))->contains(fn ($b) => ! empty($b['monthly_billing_enabled']))) {
                                        $fail('Tagihan Bulanan hanya untuk paket Organizational (Perusahaan / Sponsor).');
                                    }
                                }),

                            TextInput::make('duration_days')
                                ->label('Masa Aktif (Hari)')
                                ->numeric()
                                ->default(30)
                                ->suffix('Hari')
                                ->required(),

                            TextInput::make('price')
                                ->label('Harga (Rp)')
                                ->numeric()
                                ->prefix('Rp')
                                ->required(),

                            Toggle::make('is_active')
                                ->label('Status Aktif')
                                ->helperText('Paket dapat dibeli oleh customer jika aktif')
                                ->default(true),

                            Select::make('sales_channel')
                                ->label('Saluran Penjualan')
                                ->options(MembershipPlan::SALES_CHANNELS)
                                ->default(MembershipPlan::SALES_ALL)
                                ->selectablePlaceholder(false)
                                ->required()
                                // Modul 34: kartu Tagihan Bulanan = booking tanpa bayar di muka → hanya diberikan staf, tidak dijual online.
                                ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if ($value !== MembershipPlan::SALES_POS_ONLY && collect((array) $get('benefits'))->contains(fn ($b) => ! empty($b['monthly_billing_enabled']))) {
                                        $fail('Paket dengan Tagihan Bulanan hanya dapat dijual melalui Front Desk / Admin. Pilih "Khusus Front Desk / Admin".');
                                    }
                                })
                                ->helperText('Pilih "Khusus Front Desk / Admin" untuk paket kontrak (mis. corporate): paket tidak ditampilkan dan tidak dapat dibeli melalui web maupun aplikasi. Membership yang sudah terjual tidak terpengaruh.'),
                        ]),

                        Textarea::make('description')
                            ->label('Detail Paket untuk Customer')
                            ->placeholder("Contoh:\nBerlaku weekday pukul 06:00–18:00\nTermasuk weekend\nBisa dipakai di semua lapangan")
                            ->helperText('Satu baris = satu poin (tekan Enter untuk poin baru). Tampil sebagai daftar ✓ di halaman membership.')
                            ->rows(5)
                            ->autosize()
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Repeater::make('perks')
                            ->label('Privilege Tambahan (opsional)')
                            ->helperText('Tampil di kartu "Privilege" halaman membership. Kosongkan kalau tidak ada — dulu kartu ini berisi teks contoh.')
                            ->simple(TextInput::make('perk')->required()->maxLength(150)->placeholder('Contoh: Free parkir VIP'))
                            ->addActionLabel('Tambah Privilege')
                            ->defaultItems(0)
                            ->maxItems(10)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Benefit per Fasilitas')
                    ->description('Fasilitas diambil dari menu Fasilitas Membership. Pilihan kuota menyesuaikan cara pemakaian fasilitasnya.')
                    ->schema([
                        Repeater::make('benefits')
                            ->relationship('benefits')
                            // Tagihan Bulanan (Modul 34) disimpan di extra_benefits.monthly_billing hanya bila dicentang.
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::normalizeMonthlyBilling($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::normalizeMonthlyBilling($data))
                            ->itemLabel(fn (array $state): ?string => isset($state['facility'])
                                ? 'Benefit: '.app(MembershipFacilityService::class)->name($state['facility'])
                                : 'Benefit Fasilitas')
                            ->addActionLabel('Tambah Fasilitas / Benefit')
                            ->collapsible()
                            ->collapsed(false)
                            ->schema([
                                Select::make('facility')
                                    ->label('Fasilitas')
                                    ->options(fn (Get $get) => app(MembershipFacilityService::class)->options(array_filter([$get('facility')])))
                                    ->required()
                                    ->live()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                                Select::make('quota_type')
                                    ->label('Tipe Kuota')
                                    ->options(fn (Get $get) => self::quotaTypes($get))
                                    ->in(fn (Get $get) => array_keys(self::quotaTypes($get)))
                                    ->required()
                                    ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                        if (self::isMonthly($get) && $value !== 'NONE') {
                                            $fail('Paket Tagihan Bulanan tidak memakai kuota jam. Pilih tipe kuota "Tanpa Kuota".');
                                        }
                                    })
                                    ->live(),

                                TextInput::make('quota_value')
                                    ->label(fn (Get $get) => $get('quota_type') === 'HOURS' ? 'Jumlah Jam' : 'Jumlah Sesi')
                                    ->numeric()
                                    ->minValue(0.5)
                                    ->maxValue(9999)
                                    ->required(fn (Get $get) => $get('quota_type') === 'HOURS')
                                    ->helperText(fn (Get $get) => $get('quota_type') === 'VISITS' ? 'Kosongkan untuk akses unlimited' : null)
                                    ->visible(fn (Get $get) => in_array($get('quota_type'), ['HOURS', 'VISITS'], true))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => in_array($get('quota_type'), ['HOURS', 'VISITS'], true) && $state !== '' ? $state : null),

                                TextInput::make('discount_percent')
                                    ->label('Diskon Biaya Lapangan / Sesi (%)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(0)
                                    ->suffix('%')
                                    ->visible(fn (Get $get) => ! self::isInfo($get) && ! self::isMonthly($get))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isInfo($get) || self::isMonthly($get) ? 0 : (float) ($state ?: 0)),

                                TextInput::make('booking_priority_days')
                                    ->label('Prioritas Booking (Hari Lebih Awal)')
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(60)
                                    ->default(0)
                                    ->visible(fn (Get $get) => in_array(self::mode($get), [MembershipFacility::MODE_PADEL_BOOKING, MembershipFacility::MODE_WELLNESS_BOOKING], true))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => in_array(self::mode($get), [MembershipFacility::MODE_PADEL_BOOKING, MembershipFacility::MODE_WELLNESS_BOOKING], true) ? (int) ($state ?: 0) : 0),

                                // Aturan akses jam padel (Modul 28) — satu pilihan "Waktu Berlaku"; yang disimpan tetap kolom
                                // access_days / time_window / off_peak_only / peak_only, dicek lewat PadelAccessRule di checkout,
                                // kasir, voucher corporate, reschedule & Jadwal Tetap. Fasilitas lain tetap memakai jam akses biasa.
                                Select::make('access_mode')
                                    ->label('Waktu Berlaku')
                                    ->options(self::ACCESS_MODES)
                                    ->default('ANY')
                                    ->selectablePlaceholder(false)
                                    ->live()
                                    ->dehydrated(false)
                                    ->afterStateHydrated(fn (Select $component, Get $get) => $component->state(self::accessMode($get)))
                                    ->helperText('Jam peak dan tanggal merah mengikuti pengaturan pada Master Data.')
                                    ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                        if (self::isPadel($get) && ! array_filter(PadelAccessRule::usableHours(self::formRule($get), ...self::courtHours()))) {
                                            $fail('Aturan ini membuat paket tidak dapat digunakan pada jam mana pun. Ubah Waktu Berlaku atau hari dan jamnya.');
                                        }
                                    })
                                    ->visible(fn (Get $get) => self::isPadel($get)),

                                Placeholder::make('access_summary')
                                    ->label('Paket Dapat Digunakan')
                                    ->content(fn (Get $get) => self::accessSummary($get))
                                    ->visible(fn (Get $get) => self::isPadel($get))
                                    ->columnSpan(['default' => 1, 'md' => 2]),

                                CheckboxList::make('access_days')
                                    ->label('Hari Berlaku')
                                    ->options(PadelAccessRule::DAYS)
                                    ->columns(7)
                                    ->bulkToggleable()
                                    ->live()
                                    ->required(fn (Get $get) => self::isCustomPadel($get))
                                    ->validationMessages(['required' => 'Pilih minimal satu hari.'])
                                    ->visible(fn (Get $get) => self::isCustomPadel($get))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isCustomPadel($get) && filled($state)
                                        ? array_values(array_map('intval', (array) $state)) : null)
                                    ->columnSpanFull(),

                                // Format 24 jam tanpa detik (input bawaan browser menampilkan AM/PM & detik). Untuk padel hanya
                                // pada "Atur manual"; gym & wellness tetap seperti sebelumnya.
                                TimePicker::make('time_window_start')
                                    ->label('Jam Akses Mulai (Opsional)')
                                    ->native(false)
                                    ->seconds(false)
                                    ->displayFormat('H:i')
                                    ->live()
                                    ->helperText('Batas jam penggunaan paket, misalnya 06:00–12:00. Kosongkan apabila tidak diperlukan.')
                                    ->visible(fn (Get $get) => self::usesTimeWindow($get))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::usesTimeWindow($get) ? $state : null),

                                TimePicker::make('time_window_end')
                                    ->label('Jam Akses Selesai (Opsional)')
                                    ->native(false)
                                    ->seconds(false)
                                    ->displayFormat('H:i')
                                    ->live()
                                    ->helperText('Isi 00:00 untuk batas hingga tengah malam.')
                                    ->visible(fn (Get $get) => self::usesTimeWindow($get))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::usesTimeWindow($get) ? $state : null),

                                Hidden::make('off_peak_only')
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isPadel($get) && $get('access_mode') === 'OFF_PEAK'),

                                Hidden::make('peak_only')
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isPadel($get) && $get('access_mode') === 'PEAK'),

                                // Modul 34 — Tagihan Bulanan: booking PIC langsung terkonfirmasi Rp0 dan direkap per bulan; harga per
                                // jam ditentukan & ditagihkan Finance di luar sistem.
                                Toggle::make('monthly_billing_enabled')
                                    ->label('Tagihan Bulanan')
                                    ->helperText('Khusus paket Organisasi. Booking PIC langsung terkonfirmasi tanpa pembayaran dan direkap per bulan; harga per jam ditentukan dan ditagihkan oleh Finance. Paket ini tidak memakai kuota jam maupun diskon, dan jam tidak dapat dibagikan ke karyawan.')
                                    ->live()
                                    ->dehydrated(false)
                                    ->afterStateHydrated(fn (Toggle $component, Get $get) => $component->state(MonthlyBilling::settings((array) $get('extra_benefits')) !== null))
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        if ($state) {
                                            $set('quota_type', 'NONE');
                                        }
                                    })
                                    ->visible(fn (Get $get) => self::isPadel($get))
                                    ->columnSpanFull(),

                                Hidden::make('extra_benefits.'.MonthlyBilling::KEY.'.enabled')
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isMonthly($get) ? true : null),

                                TextInput::make('extra_benefits.note')
                                    ->label('Catatan untuk Customer (opsional)')
                                    ->placeholder('Contoh: Termasuk handuk & loker')
                                    ->helperText('Mengganti deskripsi bawaan fasilitas di kartu benefit paket ini.')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 3,
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function mode(Get $get): ?string
    {
        return app(MembershipFacilityService::class)->mode($get('facility'));
    }

    private static function isInfo(Get $get): bool
    {
        return self::mode($get) === MembershipFacility::MODE_INFO;
    }

    private static function isPadel(Get $get): bool
    {
        return self::mode($get) === MembershipFacility::MODE_PADEL_BOOKING;
    }

    private static function isMonthly(Get $get): bool
    {
        return self::isPadel($get) && (bool) $get('monthly_billing_enabled');
    }

    /**
     * Simpan penanda Tagihan Bulanan hanya untuk fasilitas Padel yang dicentang. Tidak dicentang / fasilitas lain → kunci
     * monthly_billing dihapus (catatan customer tetap).
     */
    private static function normalizeMonthlyBilling(array $data): array
    {
        $extra = (array) ($data['extra_benefits'] ?? []);
        $isPadel = app(MembershipFacilityService::class)->mode($data['facility'] ?? null) === MembershipFacility::MODE_PADEL_BOOKING;
        $settings = $isPadel ? MonthlyBilling::settings($extra) : null;
        unset($extra[MonthlyBilling::KEY]);

        if ($settings) {
            $extra[MonthlyBilling::KEY] = ['enabled' => true];
            $data['quota_type'] = 'NONE';
            $data['quota_value'] = null;
            $data['discount_percent'] = 0;
        }
        $data['extra_benefits'] = $extra;

        return $data;
    }

    private const ACCESS_MODES = [
        'ANY' => 'Kapan saja',
        'OFF_PEAK' => 'Hanya di luar jam peak',
        'PEAK' => 'Hanya di jam peak',
        'CUSTOM' => 'Atur manual (hari & jam tertentu)',
    ];

    private static function isCustomPadel(Get $get): bool
    {
        return self::isPadel($get) && $get('access_mode') === 'CUSTOM';
    }

    /** Jam akses dipakai gym / wellness seperti biasa; untuk padel hanya pada "Atur manual". */
    private static function usesTimeWindow(Get $get): bool
    {
        return ! self::isInfo($get) && (! self::isPadel($get) || $get('access_mode') === 'CUSTOM');
    }

    /** Pilihan Waktu Berlaku yang cocok dengan aturan tersimpan (paket dengan hari / jam akses → "Atur manual"). */
    private static function accessMode(Get $get): string
    {
        return match (true) {
            (bool) $get('peak_only') => 'PEAK',
            (bool) $get('off_peak_only') => 'OFF_PEAK',
            filled($get('access_days')) || ($get('time_window_start') && $get('time_window_end')) => 'CUSTOM',
            default => 'ANY',
        };
    }

    /** Aturan sesuai isian form saat ini (belum disimpan) — untuk ringkasan & cek "nol jam". */
    private static function formRule(Get $get): \App\Models\Membership\MembershipPlanBenefit
    {
        $mode = $get('access_mode') ?? 'ANY';

        return (new \App\Models\Membership\MembershipPlanBenefit)->forceFill([
            'access_days' => $mode === 'CUSTOM' ? array_map('intval', (array) $get('access_days')) : null,
            'time_window_start' => $mode === 'CUSTOM' ? $get('time_window_start') : null,
            'time_window_end' => $mode === 'CUSTOM' ? $get('time_window_end') : null,
            'off_peak_only' => $mode === 'OFF_PEAK',
            'peak_only' => $mode === 'PEAK',
        ]);
    }

    /** @return array{0: int, 1: int} jam buka paling awal & tutup paling akhir lapangan aktif (00:00 = 24) */
    private static function courtHours(): array
    {
        $courts = \App\Models\Padel\PadelCourt::where('is_active', true)->get(['open_time', 'close_time']);
        if ($courts->isEmpty()) {
            return [6, 24];
        }
        $close = fn (?string $t) => in_array(substr((string) ($t ?: '23:00'), 0, 5), ['00:00', '24:00'], true) ? 24 : (int) substr((string) ($t ?: '23:00'), 0, 2);

        return [(int) $courts->min(fn ($c) => (int) substr((string) ($c->open_time ?: '06:00'), 0, 2)), (int) $courts->max(fn ($c) => $close($c->close_time))];
    }

    private static function accessSummary(Get $get): \Illuminate\Support\HtmlString
    {
        $usable = PadelAccessRule::usableHours(self::formRule($get), ...self::courtHours());
        if (! array_filter($usable)) {
            return new \Illuminate\Support\HtmlString('<span style="color:#B42318; font-weight:600;">Tidak ada jam yang dapat digunakan. Ubah Waktu Berlaku atau hari dan jamnya.</span>');
        }

        $rows = collect(PadelAccessRule::summary($usable))->map(fn (array $row) => '<div style="display:flex; gap:0.75rem;">'
            .'<span style="min-width:6.5rem; font-weight:600;">'.e($row[0]).'</span>'
            .'<span style="'.($row[1] === 'Tidak berlaku' ? 'color:#8A7A72;' : '').'">'.e($row[1]).'</span></div>')->implode('');

        return new \Illuminate\Support\HtmlString('<div style="font-size:0.85rem; line-height:1.6;">'.$rows
            .'<div style="margin-top:0.35rem; font-size:0.75rem; color:#8A7A72;">Sesuai jam peak pada Master Data saat ini. Tanggal merah mengikuti aturan hari Minggu.</div></div>');
    }

    /** @return array<string, string> */
    private static function quotaTypes(Get $get): array
    {
        $types = app(MembershipFacilityService::class)->quotaTypesFor(self::mode($get));

        // Tagihan Bulanan tidak memakai kuota jam → hanya "Tanpa Kuota" yang dapat dipilih selama dicentang.
        return self::isMonthly($get) && isset($types['NONE']) ? ['NONE' => 'Tanpa Kuota (Tagihan Bulanan)'] : $types;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Nama Paket')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ownership_type')
                    ->label('Tipe')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ORGANIZATIONAL' ? 'info' : 'gray'),

                TextColumn::make('duration_days')
                    ->label('Masa Aktif')
                    ->suffix(' Hari')
                    ->sortable(),

                TextColumn::make('price')
                    ->label('Harga')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                TextColumn::make('benefits.facility')
                    ->label('Cakupan Fasilitas')
                    ->badge()
                    ->separator(', '),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                TextColumn::make('sales_channel')
                    ->label('Saluran Penjualan')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => MembershipPlan::SALES_CHANNELS[$state ?? MembershipPlan::SALES_ALL] ?? $state)
                    ->color(fn (?string $state) => $state === MembershipPlan::SALES_POS_ONLY ? 'warning' : 'gray'),
            ])
            // in_use = masih ada kartu aktif / menunggu bayar → tombol Terapkan hanya muncul untuk paket yang sudah terjual.
            ->modifyQueryUsing(fn ($query) => $query->withExists([
                'userMemberships as in_use' => fn ($q) => $q->whereIn('status', MembershipPlan::IN_USE_STATUSES),
            ]))
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::applyRulesAction(),
                    self::applyQuotaAction(),
                    self::guardedDeleteAction(),
                ])->tooltip('Aksi lainnya'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records) {
                            $inUse = $records->filter(fn (MembershipPlan $plan) => $plan->isInUse());
                            if ($inUse->isNotEmpty()) {
                                self::notifyPlanInUse($inUse->pluck('name')->implode(', '));
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Kartu yang sudah terjual memegang salinan aturan akses saat dibeli; tombol ini (izin apply_membership_plan_rules,
     * bawaan hanya super admin) menyamakannya dengan aturan paket terbaru. Kuota & masa aktif tidak disentuh.
     */
    public static function applyRulesAction(): Action
    {
        return Action::make('applyRules')
            ->label('Terapkan Aturan')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (MembershipPlan $record) => (bool) auth()->user()?->can('apply_membership_plan_rules') && self::isSold($record))
            ->authorize(fn () => (bool) auth()->user()?->can('apply_membership_plan_rules'))
            ->requiresConfirmation()
            ->modalHeading(fn (MembershipPlan $record) => 'Terapkan aturan '.$record->name.' ke membership aktif?')
            ->modalDescription(fn (MembershipPlan $record) => 'Aturan akses paket saat ini (jam akses, hari berlaku, dan jam peak) serta pengaturan Tagihan Bulanan akan diterapkan pada '
                .app(MembershipBalanceService::class)->membershipsFollowingPlan($record).' membership yang aktif atau menunggu pembayaran. '
                .'Kuota, diskon, masa aktif, serta booking yang sudah dibuat tidak berubah.')
            ->modalSubmitActionLabel('Ya, terapkan')
            ->action(function (MembershipPlan $record) {
                $result = app(MembershipBalanceService::class)->applyPlanAccessRules($record, auth()->user());
                $skipped = $result['monthly_skipped'] ?? [];
                Notification::make()->title('Aturan paket diterapkan')
                    ->body("{$result['memberships']} membership kini mengikuti aturan terbaru paket {$record->name}."
                        .($skipped !== [] ? ' Tagihan Bulanan tidak diubah pada '.count($skipped).' kartu: '.implode('; ', array_slice($skipped, 0, 5)).'.' : ''))
                    ->{$skipped !== [] ? 'warning' : 'success'}()
                    ->persistent($skipped !== [])
                    ->send();
            });
    }

    /**
     * Kuota & masa aktif kartu yang sudah terjual mengikuti paket saat dibeli; tombol ini (izin apply_membership_plan_quota,
     * bawaan hanya super admin) menyamakannya dengan paket terbaru setelah admin melihat pratinjau per kartu.
     */
    public static function applyQuotaAction(): Action
    {
        return Action::make('applyQuota')
            ->label('Terapkan Kuota & Masa Aktif')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->visible(fn (MembershipPlan $record) => (bool) auth()->user()?->can('apply_membership_plan_quota') && self::isSold($record))
            ->authorize(fn () => (bool) auth()->user()?->can('apply_membership_plan_quota'))
            ->modalHeading(fn (MembershipPlan $record) => 'Terapkan kuota & masa aktif '.$record->name.' ke kartu yang sudah terjual')
            ->modalDescription('Periksa perubahan per kartu di bawah. Kuota yang sudah terpakai tetap dihitung; sisa kuota tidak pernah menjadi minus. Diskon dan aturan akses tidak berubah (gunakan Terapkan Aturan).')
            ->modalContent(fn (MembershipPlan $record) => view('filament.resources.membership.plan-quota-preview', [
                'rows' => app(\App\Services\Membership\MembershipPlanQuotaService::class)->preview($record),
            ]))
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Ya, terapkan')
            // Tidak ada kartu yang berubah → hanya tombol tutup.
            ->modalSubmitAction(fn (Action $action, MembershipPlan $record) => collect(app(\App\Services\Membership\MembershipPlanQuotaService::class)->preview($record))
                ->contains('changes', true) ? $action : false)
            ->action(function (MembershipPlan $record) {
                try {
                    $result = app(\App\Services\Membership\MembershipPlanQuotaService::class)->apply($record, auth()->user());
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    if ($e->getStatusCode() !== 409) {
                        throw $e;
                    }
                    Notification::make()->title('Belum diterapkan')->body($e->getMessage())->warning()->persistent()->send();

                    return;
                }
                if ($result['memberships'] === 0) {
                    Notification::make()->title('Tidak ada perubahan')->body("Semua kartu {$record->name} sudah sesuai dengan kuota & masa aktif paket.")->info()->send();

                    return;
                }
                Notification::make()->title('Kuota & masa aktif diterapkan')
                    ->body("{$result['memberships']} kartu diperbarui ({$result['quotas']} kuota, {$result['end_dates']} tanggal berakhir"
                        .($result['schedules'] > 0 ? ", {$result['schedules']} batas akhir Booking Mingguan" : '').'). Rincian tercatat di Audit Log Mutasi Kuota & Log Aktivitas.')
                    ->success()->send();
            });
    }

    /**
     * Hapus paket ditolak selama masih ada kartu aktif / menunggu bayar yang memakainya (dulu bisa → Home member 500,
     * kasir error, aktivasi gagal). Dipakai di tabel & halaman edit; model juga menolak sebagai pengaman terakhir.
     */
    public static function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, MembershipPlan $record) {
                if ($record->isInUse()) {
                    self::notifyPlanInUse($record->name);
                    $action->cancel();
                }
            });
    }

    /** Paket sudah terjual (ada kartu aktif / menunggu bayar) — pakai in_use dari query tabel bila ada. */
    private static function isSold(MembershipPlan $record): bool
    {
        return (bool) ($record->in_use ?? $record->isInUse());
    }

    private static function notifyPlanInUse(string $names): void
    {
        Notification::make()
            ->title('Paket masih dipakai member')
            ->body("{$names} masih dipakai kartu member yang aktif / menunggu pembayaran, jadi tidak bisa dihapus. Nonaktifkan paketnya (matikan Aktif) supaya tidak dijual lagi.")
            ->danger()
            ->persistent()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipPlans::route('/'),
            'create' => CreateMembershipPlan::route('/create'),
            'edit' => EditMembershipPlan::route('/{record}/edit'),
        ];
    }
}
