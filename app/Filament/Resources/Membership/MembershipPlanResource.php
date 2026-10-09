<?php

namespace App\Filament\Resources\Membership;

use App\Filament\Resources\Membership\Pages\CreateMembershipPlan;
use App\Filament\Resources\Membership\Pages\EditMembershipPlan;
use App\Filament\Resources\Membership\Pages\ListMembershipPlans;
use App\Models\Membership\MembershipFacility;
use App\Models\Membership\MembershipPlan;
use App\Services\Membership\MembershipFacilityService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
                                ->required(),

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
                        ]),

                        Textarea::make('description')
                            ->label('Deskripsi Paket untuk Customer')
                            ->placeholder('Contoh: Paket untuk pemain rutin 2–3x seminggu, sudah termasuk akses gym.')
                            ->rows(2)
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
                                    ->visible(fn (Get $get) => ! self::isInfo($get))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isInfo($get) ? 0 : (float) ($state ?: 0)),

                                TextInput::make('booking_priority_days')
                                    ->label('Prioritas Booking (Hari Lebih Awal)')
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(60)
                                    ->default(0)
                                    ->visible(fn (Get $get) => in_array(self::mode($get), [MembershipFacility::MODE_PADEL_BOOKING, MembershipFacility::MODE_WELLNESS_BOOKING], true))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => in_array(self::mode($get), [MembershipFacility::MODE_PADEL_BOOKING, MembershipFacility::MODE_WELLNESS_BOOKING], true) ? (int) ($state ?: 0) : 0),

                                TimePicker::make('time_window_start')
                                    ->label('Jam Akses Mulai (Opsional)')
                                    ->visible(fn (Get $get) => ! self::isInfo($get))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isInfo($get) ? null : $state),

                                TimePicker::make('time_window_end')
                                    ->label('Jam Akses Selesai (Opsional)')
                                    ->visible(fn (Get $get) => ! self::isInfo($get))
                                    ->dehydratedWhenHidden()
                                    ->dehydrateStateUsing(fn ($state, Get $get) => self::isInfo($get) ? null : $state),

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

    /** @return array<string, string> */
    private static function quotaTypes(Get $get): array
    {
        return app(MembershipFacilityService::class)->quotaTypesFor(self::mode($get));
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
            ])
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
            'index' => ListMembershipPlans::route('/'),
            'create' => CreateMembershipPlan::route('/create'),
            'edit' => EditMembershipPlan::route('/{record}/edit'),
        ];
    }
}
