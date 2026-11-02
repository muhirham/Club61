<?php

namespace App\Filament\Resources\Coaching;

use App\Filament\Resources\Coaching\Pages\ManageCoachingAcademies;
use App\Filament\Resources\Concerns\AuthorizesWithCanMethods;
use App\Models\Coaching\CoachingAcademy;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Academy partner coaching (Modul 24 Opsi B). Academy yang masih punya coach tidak bisa dihapus — nonaktifkan saja.
 * Modul 31: metode pembayaran per academy — Bayar Langsung atau Tagihan Berkala (dilunasi sekaligus di kasir per periode).
 */
class CoachingAcademyResource extends Resource
{
    use AuthorizesWithCanMethods;

    protected static ?string $model = CoachingAcademy::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Academy';

    protected static ?string $modelLabel = 'Academy';

    protected static ?string $pluralModelLabel = 'Academy';

    protected static string|UnitEnum|null $navigationGroup = 'Coaching';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('manage_coaching');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('manage_coaching');
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->can('manage_coaching');
    }

    public static function canDelete(Model $record): bool
    {
        // Masih punya sesi tagihan belum lunas → tidak bisa dihapus (academy_id sesi jadi kosong & tagihannya tidak bisa dilunasi).
        return (bool) auth()->user()?->can('manage_coaching') && ! $record->coaches()->exists()
            && ! app(\App\Services\Coaching\CoachingBillingService::class)->unpaidSessions($record->id)->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 2])->columnSpanFull()->schema([
                TextInput::make('name')->label('Nama Academy')->placeholder('Contoh: SR Academy')->required()->maxLength(100),
                TextInput::make('pic_name')->label('Nama PIC')->maxLength(100),
                TextInput::make('phone')->label('No. WhatsApp PIC')->tel()->maxLength(20)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? (PhoneNumber::normalize((string) $state) ?? trim((string) $state)) : null),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
            Section::make('Metode Pembayaran Sewa Lapangan')
                ->description('Berlaku untuk booking coaching berikutnya. Sesi yang sudah tercatat tetap mengikuti tagihan sebelumnya.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        Select::make('billing_mode')->label('Metode Pembayaran')->options(CoachingAcademy::BILLING_MODES)
                            ->default(CoachingAcademy::BILLING_TAB)->required()->native(false)->live()
                            ->helperText(fn (Get $get) => $get('billing_mode') === CoachingAcademy::BILLING_TAB
                                ? 'Resepsionis mencatat sesi ke tagihan tanpa pembayaran EDC / QRIS saat booking. Tagihan dilunasi sekaligus di kasir sesuai periode.'
                                : 'Setiap booking coaching dibayar saat booking di kasir (EDC / QRIS).'),
                        Select::make('billing_cycle')->label('Periode Tagihan')->options(CoachingAcademy::CYCLES)
                            ->default(CoachingAcademy::CYCLE_SHIFT)->native(false)->live()
                            ->visible(fn (Get $get) => $get('billing_mode') === CoachingAcademy::BILLING_TAB)
                            ->required(fn (Get $get) => $get('billing_mode') === CoachingAcademy::BILLING_TAB)
                            ->helperText(fn (Get $get) => match ($get('billing_cycle')) {
                                CoachingAcademy::CYCLE_SHIFT => 'Jatuh tempo saat shift kasir tempat booking dicatat ditutup.',
                                CoachingAcademy::CYCLE_DAILY => 'Jatuh tempo pada akhir hari tanggal main.',
                                CoachingAcademy::CYCLE_WEEKLY => 'Jatuh tempo setiap hari Minggu (periode Senin–Minggu tanggal main).',
                                CoachingAcademy::CYCLE_BIWEEKLY => 'Jatuh tempo setiap dua minggu sejak tanggal mulai periode.',
                                default => null,
                            }),
                        DatePicker::make('billing_anchor_date')->label('Tanggal Mulai Periode Dua Mingguan')->native(false)
                            ->visible(fn (Get $get) => $get('billing_mode') === CoachingAcademy::BILLING_TAB && $get('billing_cycle') === CoachingAcademy::CYCLE_BIWEEKLY)
                            ->required(fn (Get $get) => $get('billing_mode') === CoachingAcademy::BILLING_TAB && $get('billing_cycle') === CoachingAcademy::CYCLE_BIWEEKLY)
                            ->helperText('Hari pertama periode tagihan pertama, misalnya tanggal mulai kerja sama.'),
                        Toggle::make('block_when_overdue')->label('Tolak booking baru apabila ada tagihan melewati jatuh tempo')->inline(false)
                            ->visible(fn (Get $get) => $get('billing_mode') === CoachingAcademy::BILLING_TAB)
                            ->helperText('Nonaktif: resepsionis hanya menerima peringatan. Aktif: booking baru academy ini ditolak hingga tagihan dilunasi.'),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Academy')->weight('bold')->searchable()->sortable(),
                TextColumn::make('pic_name')->label('PIC')->placeholder('-'),
                TextColumn::make('phone')->label('WhatsApp')->placeholder('-'),
                TextColumn::make('coaches_count')->label('Coach')->counts('coaches'),
                TextColumn::make('billing_mode')->label('Metode Pembayaran')
                    ->formatStateUsing(fn ($state, CoachingAcademy $record) => $record->billingLabel())
                    ->description(fn (CoachingAcademy $record) => $record->usesTab() && $record->block_when_overdue ? 'Booking ditolak bila melewati jatuh tempo' : null),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCoachingAcademies::route('/')];
    }
}
