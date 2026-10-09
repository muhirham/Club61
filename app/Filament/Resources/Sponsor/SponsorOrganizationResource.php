<?php

namespace App\Filament\Resources\Sponsor;

use App\Filament\Resources\Sponsor\Pages\CreateSponsorOrganization;
use App\Filament\Resources\Sponsor\Pages\EditSponsorOrganization;
use App\Filament\Resources\Sponsor\Pages\ListSponsorOrganizations;
use App\Models\Membership\UserMembership;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Onboarding & pengelolaan akun sponsor/corporate (PRD Modul 12) — panel STAF. Biasanya
 * baris di sini sudah OTOMATIS terbentuk begitu ada pembelian membership ORGANIZATIONAL
 * (lihat MembershipFulfillmentHandler + SponsorOrganizationService::ensureOrganizationForMembership),
 * dari kanal manapun (POS Jual Membership walk-in atau online). Halaman ini dipakai staf untuk:
 * (1) memperbaiki nama perusahaan yang masih placeholder hasil auto-create, (2) menautkan
 * ulang/ganti PIC, (3) membuat manual untuk kasus edge (mis. migrasi data lama).
 */
class SponsorOrganizationResource extends Resource
{
    use \App\Filament\Resources\Concerns\AuthorizesWithCanMethods;

    protected static ?string $model = SponsorOrganization::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Kelola Sponsor';

    protected static ?string $modelLabel = 'Sponsor';

    protected static ?string $pluralModelLabel = 'Kelola Sponsor';

    protected static string|UnitEnum|null $navigationGroup = 'Sponsor';

    protected static ?int $navigationSort = 1;

    // Sengaja OVERRIDE authorization di Resource ini secara langsung (bukan lewat Model Policy)
    // — model SponsorOrganization sudah punya Gate::policy() sendiri (SponsorOrganizationPolicy)
    // yang khusus buat portal customer PIC (ngecek sponsor_admin_user_id === user login), TIDAK
    // cocok dipakai buat panel staf ini. Kalau resource ini dibiarkan tanpa override, Filament
    // fallback ke Gate::before-only (lihat vendor/filament/.../get_authorization_response()),
    // yang berarti viewAny/create/delete otomatis KEBUKA buat SEMUA staf yang login, apapun
    // rolenya — bug nyata yang sempat kejadian sebelum override ini ditambahkan.
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('view_sponsor_organizations');
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('view_sponsor_organizations');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_organizations');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_organizations');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_organizations');
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->can('manage_sponsor_organizations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Detail Akun Sponsor/Corporate')
                ->description('Pembeli paket membership ORGANIZATIONAL otomatis menjadi PIC (Person In Charge) tim ini. Gunakan form ini untuk memperbaiki nama perusahaan atau menautkan/mengganti PIC secara manual. Kuota jam kontrak (mis. "200 jam") diatur lewat Master Data > Paket Membership > Matriks Entitlement Fasilitas (facility PADEL, tipe kuota Jam Bermain), BUKAN di sini.')
                ->schema([
                    TextInput::make('name')
                        ->label('Nama Perusahaan / Sponsor')
                        ->required()
                        ->maxLength(150),

                    Select::make('sponsor_admin_user_id')
                        ->label('PIC (Person In Charge)')
                        ->options(fn () => User::query()->orderBy('name')->limit(50)->pluck('name', 'id'))
                        ->searchable(['name', 'phone', 'email'])
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->limit(20)
                            ->pluck('name', 'id'))
                        ->required(),

                    Select::make('user_membership_id')
                        ->label('Kartu Membership Organisasional')
                        ->helperText('Hanya menampilkan membership ORGANIZATIONAL yang belum ditautkan ke sponsor manapun.')
                        ->options(fn ($record) => UserMembership::query()
                            ->where('owner_type', 'ORGANIZATIONAL')
                            ->whereDoesntHave('sponsorOrganization', fn ($q) => $record ? $q->where('id', '!=', $record->id) : $q)
                            ->with(['plan', 'sponsorOrganization' => fn ($q) => $q->onlyTrashed()])
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($m) => [$m->id => $m->membership_code.' — '.($m->plan->name ?? '-')
                                .($m->sponsorOrganization ? ' (sponsor lama "'.$m->sponsorOrganization->name.'" akan dipulihkan)' : '')]))
                        ->searchable()
                        ->required()
                        ->disabledOn('edit'),

                    Select::make('status')
                        ->label('Status')
                        ->options(['ACTIVE' => 'Aktif', 'SUSPENDED' => 'Ditangguhkan'])
                        ->default('ACTIVE')
                        ->required(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Perusahaan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('sponsorAdmin.name')
                    ->label('PIC')
                    ->searchable(),

                TextColumn::make('sponsorAdmin.phone')
                    ->label('No HP PIC'),

                TextColumn::make('userMembership.plan.name')
                    ->label('Paket'),

                TextColumn::make('quota_display')
                    ->label('Kuota Jam (dari Paket)')
                    ->state(function (SponsorOrganization $record): string {
                        $remaining = $record->remainingQuota();
                        $total = $record->totalQuota();

                        return $total !== null
                            ? number_format($remaining, 1).' / '.number_format($total, 1).' jam'
                            : 'Tidak terbatas';
                    })
                    ->tooltip('Diatur lewat Master Data > Paket Membership > Matriks Entitlement Fasilitas (facility PADEL).'),

                TextColumn::make('members_count')
                    ->label('Anggota')
                    ->counts('members'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ACTIVE' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('previewPicDashboard')
                    ->label('Lihat Dashboard PIC')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (): bool => \App\Filament\Pages\SponsorDashboard::canAccess())
                    ->url(fn (SponsorOrganization $record): string => \App\Filament\Pages\SponsorDashboard::getUrl(['organization' => $record->id])),
                // Tombol tabel Filament mengotorisasi lewat Gate policy model — dan policy
                // SponsorOrganization itu khusus PIC portal customer (sponsor_admin_user_id ===
                // user login), jadi staf non-super_admin selalu ditolak. Paksa pakai izin staf.
                EditAction::make()
                    ->authorize(fn (SponsorOrganization $record): bool => static::canEdit($record)),
                DeleteAction::make()
                    ->authorize(fn (SponsorOrganization $record): bool => static::canDelete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => static::canDeleteAny()),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSponsorOrganizations::route('/'),
            'create' => CreateSponsorOrganization::route('/create'),
            'edit' => EditSponsorOrganization::route('/{record}/edit'),
        ];
    }
}
