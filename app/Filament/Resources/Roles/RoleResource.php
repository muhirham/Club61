<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Role;
use App\Services\Permission\Club61PermissionMatrix;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Roles & Hak Akses';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles & Hak Akses';

    protected static string|\UnitEnum|null $navigationGroup = 'Karyawan & Akses';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Utama Peran')
                    ->description('Tentukan nama slug sistem, nama tampilan peran, dan rute pendaratan otomatis saat pengguna masuk.')
                    ->schema([
                        TextInput::make('name')
                            ->label('SLUG')
                            ->placeholder('contoh: kasir_resto')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),

                        TextInput::make('description')
                            ->label('NAME (Nama Tampilan)')
                            ->placeholder('contoh: Kasir Restoran & Frontdesk')
                            ->maxLength(255),

                        Select::make('home_route')
                            ->label('HOME ROUTE (Pendaratan Login)')
                            ->options(Role::getHomeRouteOptions())
                            // Kosong = otomatis: login mendarat di halaman pertama yang dicentang di matriks izin
                            // (urutan menu sidebar), mis. hanya Buku Transaksi → /admin/buku-transaksi.
                            ->placeholder('Otomatis — halaman pertama sesuai permission')
                            ->helperText('Kosongkan agar pengguna langsung masuk ke halaman pertama yang dicentang di bawah.')
                            ->nullable(),

                        TextInput::make('guard_name')
                            ->default('web')
                            ->hidden(),
                    ])
                    ->columns([
                        'default' => 1,
                        'sm' => 3,
                    ])
                    ->columnSpanFull(),

                Section::make('Matriks Hak Akses Modul (Club 61 Ecosystem)')
                    ->description('Centang hak akses yang diizinkan untuk peran ini. Gunakan tombol centang semua per modul untuk kemudahan konfigurasi.')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'xl' => 2,
                        ])
                            ->schema(static::getPermissionMatrixComponents()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Komponen matriks izin untuk 11 kategori modul Club 61.
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function getPermissionMatrixComponents(): array
    {
        $matrix = Club61PermissionMatrix::getMatrix();
        $sections = [];

        foreach ($matrix as $catKey => $catData) {
            $subComponents = [];

            foreach ($catData['submodules'] as $subKey => $subData) {
                $options = $subData['actions'];
                $fieldName = 'permissions_' . $subKey;

                $subComponents[] = CheckboxList::make($fieldName)
                    ->label($subData['label'])
                    ->options($options)
                    ->bulkToggleable()
                    ->columns([
                        'default' => 1,
                        'sm' => 2,
                    ])
                    ->gridDirection('row')
                    ->afterStateHydrated(function ($component, ?Model $record) use ($options): void {
                        if ($record && method_exists($record, 'permissions')) {
                            $existing = $record->permissions->pluck('name')->toArray();
                            $active = array_values(array_intersect($existing, array_keys($options)));
                            $component->state($active);
                        }
                    });
            }

            $sections[] = Section::make($catData['title'])
                ->schema($subComponents)
                ->collapsible()
                ->compact();
        }

        return $sections;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('SLUG')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('description')
                    ->label('NAME')
                    ->searchable()
                    ->sortable()
                    ->default(fn (Model $record): string => str_replace('_', ' ', ucwords($record->name, '_'))),

                TextColumn::make('permissions_count')
                    ->label('MENUS')
                    ->counts('permissions')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('home_route')
                    ->label('HOME ROUTE')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        '/admin' => 'warning',
                        '/pos' => 'success',
                        '/kitchen' => 'info',
                        '/dashboard' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => array_key_exists((string) $state, Role::getHomeRouteOptions()) ? $state : 'Otomatis')
                    ->default('Otomatis'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Model $record): bool => ! in_array($record->name, ['super_admin', 'admin'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
