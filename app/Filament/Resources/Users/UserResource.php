<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Kelola Pengguna';

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Kelola Pengguna';

    protected static string|\UnitEnum|null $navigationGroup = 'Karyawan & Akses';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\TextInput::make('name')
                    ->label('Nama Lengkap')
                    ->required()
                    ->maxLength(100),

                \Filament\Forms\Components\TextInput::make('email')
                    ->label('Alamat Email')
                    ->email()
                    ->required()
                    ->maxLength(150)
                    ->unique(ignoreRecord: true),

                \Filament\Forms\Components\TextInput::make('phone')
                    ->label('Nomor WhatsApp')
                    ->tel()
                    ->maxLength(20),

                \Filament\Forms\Components\TextInput::make('password')
                    ->label('Kata Sandi')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => \Illuminate\Support\Facades\Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),

                \Filament\Forms\Components\Select::make('roles')
                    ->label('Peran (Roles)')
                    ->relationship(
                        name: 'roles',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => auth()->user()?->hasRole('super_admin')
                            ? $query
                            : $query->where('name', '!=', 'super_admin')
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->rule(function () {
                        return function (string $attribute, $value, \Closure $fail) {
                            if (! auth()->user()?->hasRole('super_admin')) {
                                $superAdminRole = \App\Models\Role::findByName('super_admin', 'web');
                                $submitted = (array) $value;
                                if ($superAdminRole && (in_array($superAdminRole->id, $submitted) || in_array((string) $superAdminRole->id, $submitted, true) || in_array('super_admin', $submitted, true))) {
                                    $fail('Hanya Super Administrator yang berwenang memberikan peran Super Admin.');
                                }
                            }
                        };
                    }),

                \Filament\Forms\Components\Toggle::make('is_active')
                    ->label('Akun Aktif')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        // Relasi roles disimpan Filament lewat sync() biasa (tanpa event Spatie) — snapshot sebelum
        // & sesudah simpan supaya pergantian role tercatat di Log Aktivitas.
        $rolesBefore = [];

        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                \Filament\Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('phone')
                    ->label('No. WhatsApp')
                    ->searchable()
                    ->default('-'),

                \Filament\Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'super_admin' => 'danger',
                        'admin' => 'warning',
                        'cashier' => 'success',
                        'receptionist' => 'success',
                        'kitchen' => 'info',
                        'trainer' => 'purple',
                        'stylist' => 'pink',
                        default => 'gray',
                    }),

                \Filament\Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->label('Terdaftar')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->label('Filter Peran'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (! auth()->user()?->hasRole('super_admin') && isset($data['roles'])) {
                            $superAdminRole = \App\Models\Role::findByName('super_admin', 'web');
                            if ($superAdminRole) {
                                $data['roles'] = array_values(array_filter(
                                    (array) $data['roles'],
                                    fn ($r) => (string) $r !== (string) $superAdminRole->id && $r !== 'super_admin'
                                ));
                            }
                        }
                        return $data;
                    })
                    ->before(function (User $record) use (&$rolesBefore) {
                        $rolesBefore = $record->roles()->pluck('name')->all();
                    })
                    ->after(function (User $record) use (&$rolesBefore) {
                        \App\Services\Audit\ActivityLogger::userRolesChanged($record, $rolesBefore, $record->roles()->pluck('name')->all());
                    }),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
