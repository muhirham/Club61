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
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
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
                        // Daftar awal hanya 50 user: PIC tersimpan di luar daftar itu tetap tampil namanya, bukan ID.
                        ->getOptionLabelUsing(fn ($value) => User::find($value)?->name)
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

            // Disimpan sebagai daftar aksi yang DIMATIKAN (kosong = semua boleh), jadi perusahaan lama & aksi baru di
            // masa depan otomatis tetap boleh. Formulir menampilkan kebalikannya: yang dicentang = diizinkan.
            Section::make('Hak Akses PIC')
                ->description('Atur aksi yang boleh dilakukan PIC di halaman Tim Corporate. Aksi yang tidak dicentang disembunyikan dari PIC dan ditolak oleh sistem. Melihat dashboard, daftar karyawan, dan jadwal tetap selalu diizinkan.')
                ->schema([
                    CheckboxList::make('pic_restricted_actions')
                        ->label('PIC diizinkan untuk')
                        ->options(SponsorOrganization::PIC_ACTIONS)
                        ->formatStateUsing(fn ($state): array => array_values(array_diff(array_keys(SponsorOrganization::PIC_ACTIONS), (array) ($state ?? []))))
                        ->dehydrateStateUsing(fn ($state): ?array => array_values(array_diff(array_keys(SponsorOrganization::PIC_ACTIONS), (array) ($state ?? []))) ?: null)
                        ->bulkToggleable(),
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
                self::recordTransferAction(),
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

    /**
     * Catat transfer perusahaan langsung ke rekening Club 61 (tanpa shift kasir). Satu tombol untuk dua jenis:
     *  - Pembayaran Paket: paket membership yang sudah aktif (kategori Membership; kartu, kuota & jadwal tidak diubah);
     *  - Tagihan Bulanan (Modul 34): per periode, nominal sesuai invoice Finance (kategori Sewa Lapangan).
     * Dipakai di tabel Kelola Sponsor dan halaman Edit Sponsor.
     */
    public static function recordTransferAction(): Action
    {
        $card = fn (?SponsorOrganization $record) => $record ? \App\Services\Membership\MonthlyBilling::balanceOf($record->userMembership) : null;
        $kindOf = fn (Get $get) => $get('kind') ?: self::TRANSFER_PACKAGE;

        return Action::make('recordTransfer')
            ->label('Catat Transfer')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (SponsorOrganization $record): bool => (bool) auth()->user()?->can(\App\Services\Sponsor\CorporateTransfer::PERMISSION)
                && $record->user_membership_id !== null)
            ->modalHeading(fn (SponsorOrganization $record) => 'Catat Transfer — '.$record->name)
            ->modalDescription('Pembayaran yang ditransfer perusahaan langsung ke rekening Club 61. Data kartu, kuota, karyawan, dan jadwal tidak berubah.')
            ->modalSubmitActionLabel('Catat Pembayaran')
            ->schema(fn (SponsorOrganization $record) => [
                Radio::make('kind')
                    ->label('Jenis Pembayaran')
                    ->options([
                        self::TRANSFER_MONTHLY => 'Tagihan Bulanan',
                        self::TRANSFER_PACKAGE => 'Pembayaran Paket Membership',
                    ])
                    ->default(self::TRANSFER_MONTHLY)
                    ->live()
                    ->required()
                    ->visible(fn () => $card($record) !== null),
                Select::make('month')
                    ->label('Periode Tagihan')
                    ->options(fn () => ($balance = $card($record))
                        ? collect(\App\Services\Membership\MonthlyBillingPayment::periodOptions($balance))
                            ->except(\App\Services\Membership\MonthlyBilling::paidPeriods($balance->id))->all()
                        : [])
                    ->live()
                    ->native(false)
                    ->required()
                    ->visible(fn (Get $get) => $card($record) !== null && $kindOf($get) === self::TRANSFER_MONTHLY),
                Text::make(function (Get $get) use ($card, $record) {
                    $balance = $card($record);
                    $month = (string) $get('month');
                    if (! $balance || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
                        return 'Pilih periode untuk melihat ringkasan jam main.';
                    }
                    $t = \App\Services\Membership\MonthlyBilling::recap($balance, $month)['totals'];
                    $h = fn ($v) => \App\Services\Membership\MonthlyBilling::hoursLabel((float) $v);

                    return "Jam main periode ini: {$h($t['hours'])} jam ({$t['bookings']} booking) — reguler {$h($t['regular_hours'])} jam, peak {$h($t['peak_hours'])} jam.";
                })->visible(fn (Get $get) => $card($record) !== null && $kindOf($get) === self::TRANSFER_MONTHLY),
                Text::make(fn () => 'Paket: '.($record->userMembership?->plan?->name ?? '-').' ('.($record->userMembership?->membership_code ?? '-').').')
                    ->visible(fn (Get $get) => $kindOf($get) === self::TRANSFER_PACKAGE),
                TextInput::make('amount')
                    ->label('Nominal Diterima')
                    ->helperText(fn (Get $get) => $kindOf($get) === self::TRANSFER_MONTHLY
                        ? 'Sesuai invoice yang diterbitkan Finance.'
                        : 'Sesuai kontrak / invoice paket.')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required(),
                ...\App\Services\Sponsor\CorporateTransfer::formFields(),
            ])
            ->action(function (SponsorOrganization $record, array $data) use ($card): void {
                abort_unless(auth()->user()?->can(\App\Services\Sponsor\CorporateTransfer::PERMISSION), 403);
                $monthly = $card($record) !== null && ($data['kind'] ?? self::TRANSFER_PACKAGE) === self::TRANSFER_MONTHLY;

                try {
                    $monthly
                        ? app(\App\Services\Membership\MonthlyBillingPayment::class)
                            ->record($card($record), (string) ($data['month'] ?? ''), (float) ($data['amount'] ?? 0), $data, auth()->user())
                        : \App\Services\Sponsor\CorporateTransfer::recordPackagePayment($record, (float) ($data['amount'] ?? 0), $data, auth()->user());
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    \App\Services\Sponsor\CorporateTransfer::discardProof($data['proof_path'] ?? null);
                    \Filament\Notifications\Notification::make()->title('Pembayaran Tidak Tercatat')->body($e->getMessage())->danger()->send();

                    return;
                }

                \Filament\Notifications\Notification::make()->title('Pembayaran Transfer Tercatat')
                    ->body($record->name.($monthly ? ' — Tagihan Bulanan '.\App\Services\Membership\MonthlyBilling::monthLabel(\Carbon\Carbon::parse($data['month'].'-01')) : ' — Pembayaran Paket')
                        .' sebesar Rp '.number_format((float) $data['amount'], 0, ',', '.').'.')
                    ->success()->send();
            });
    }

    public const TRANSFER_MONTHLY = 'MONTHLY';

    public const TRANSFER_PACKAGE = 'PACKAGE';

    public static function getPages(): array
    {
        return [
            'index' => ListSponsorOrganizations::route('/'),
            'create' => CreateSponsorOrganization::route('/create'),
            'edit' => EditSponsorOrganization::route('/{record}/edit'),
        ];
    }
}
