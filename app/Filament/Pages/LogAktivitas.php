<?php

namespace App\Filament\Pages;

use App\Models\Audit\ActivityLog;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;
use UnitEnum;

/**
 * Modul 16 — Panel Log Aktivitas (READ-ONLY). Tidak ada satu pun aksi tulis di halaman ini:
 * log tidak bisa diedit / dihapus dari aplikasi, termasuk oleh super_admin.
 */
class LogAktivitas extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationLabel = 'Log Aktivitas';

    protected static string|UnitEnum|null $navigationGroup = 'Karyawan & Akses';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Log Aktivitas & Jejak Audit';

    protected string $view = 'filament.pages.log-aktivitas';

    private const TIMEZONE = 'Asia/Jakarta';

    public const MODULES = [
        'FINANCE' => 'Keuangan & Transaksi',
        'PADEL' => 'Padel & Booking',
        'POS' => 'Shift Kasir',
        'FNB' => 'F&B',
        'MERCH' => 'Merchandise',
        'MEMBERSHIP' => 'Membership',
        'SPONSOR' => 'Sponsor',
        'COACHING' => 'Coaching',
        'MARKETING' => 'Marketing & Promo',
        'MASTER_DATA' => 'Master Data',
        'USER_ROLE' => 'User & Hak Akses',
        'CONTENT' => 'Konten Website',
        'SIGNATURE' => 'Digital Signage',
        'AUTH' => 'Login & Keamanan',
        'SYSTEM' => 'Sistem',
    ];

    public const CHANNELS = [
        'ADMIN_PANEL' => 'Panel Admin',
        'POS_WALKIN' => 'POS Walk-in Padel',
        'POS_FNB' => 'POS Kasir F&B',
        'POS_CHECKIN' => 'Gate Check-in',
        'KDS' => 'Dapur (KDS)',
        'LOGIN_PAGE' => 'Halaman Login',
        'CUSTOMER_WEB' => 'Web Customer',
        'MOBILE_API' => 'Aplikasi Mobile',
        'WEBHOOK' => 'Webhook Midtrans',
        'SCHEDULER' => 'Scheduler',
        'CONSOLE' => 'Console Server',
        'SYSTEM' => 'Sistem',
    ];

    public const ACTOR_TYPES = [
        'STAFF' => 'Staf',
        'CUSTOMER' => 'Customer',
        'SYSTEM' => 'Sistem',
        'WEBHOOK' => 'Webhook',
        'GUEST' => 'Tamu (belum login)',
    ];

    public const SEVERITIES = [
        'INFO' => 'Info',
        'WARNING' => 'Perlu Perhatian',
        'CRITICAL' => 'Kritis',
    ];

    public const CSV_HEADER = ['Waktu (WIB)', 'Pengguna', 'Role', 'Jenis Pelaku', 'Modul', 'Aksi', 'Aktivitas', 'Data', 'Dari', 'Tingkat', 'IP', 'Perubahan', 'Detail'];

    /**
     * Tautan dari halaman lain (mis. detail Buku Transaksi) membuka log yang sudah tersaring: ?cari=ORD-xxx.
     * Filter tanggal default (hari ini) dikosongkan supaya log order lama tetap terlihat.
     */
    public function mount(): void
    {
        $search = trim((string) request()->query('cari', ''));
        if ($search !== '') {
            $this->tableSearch = mb_substr($search, 0, 200);
            $this->tableFilters['tanggal']['dari'] = null;
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ActivityLog::query())
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            // Pencarian lewat satu fungsi yang sama dengan route export, supaya hasil CSV = isi tabel.
            ->searchUsing(fn (Builder $query, string $search) => self::applySearch($query, $search))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->emptyStateHeading('Belum ada aktivitas pada filter ini')
            ->emptyStateDescription('Ubah rentang tanggal atau hapus filter untuk melihat aktivitas lain.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i:s', self::TIMEZONE)
                    ->sortable()
                    ->description(fn (ActivityLog $log) => $log->created_at?->timezone(self::TIMEZONE)->diffForHumans()),
                TextColumn::make('causer_name')
                    ->label('Pengguna')
                    ->searchable()
                    ->weight('bold')
                    ->getStateUsing(fn (ActivityLog $log) => $log->causer_name ?? match ($log->actor_type) {
                        'SYSTEM' => 'Sistem',
                        'WEBHOOK' => 'Midtrans (Webhook)',
                        default => 'Tamu (belum login)',
                    })
                    ->description(fn (ActivityLog $log) => $log->causer_role ?? (self::ACTOR_TYPES[$log->actor_type] ?? $log->actor_type)),
                TextColumn::make('module')
                    ->label('Modul')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::MODULES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'FINANCE' => 'success',
                        'USER_ROLE', 'AUTH' => 'danger',
                        'PADEL', 'POS' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('description')
                    ->label('Aktivitas')
                    ->searchable(['description', 'subject_label', 'event'])
                    ->wrap()
                    ->description(fn (ActivityLog $log) => $log->event),
                TextColumn::make('channel')
                    ->label('Dari')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => self::CHANNELS[$state] ?? $state)
                    ->toggleable(),
                TextColumn::make('severity')
                    ->label('Tingkat')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::SEVERITIES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'CRITICAL' => 'danger',
                        'WARNING' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('tanggal')
                    ->schema([
                        DatePicker::make('dari')->label('Dari tanggal')->native(false)->displayFormat('d M Y')->default(now(self::TIMEZONE)->toDateString()),
                        DatePicker::make('sampai')->label('Sampai tanggal')->native(false)->displayFormat('d M Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyDateRange($query, $data['dari'] ?? null, $data['sampai'] ?? null))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['dari'] ?? null) {
                            $indicators[] = 'Dari '.Carbon::parse($data['dari'])->translatedFormat('d M Y');
                        }
                        if ($data['sampai'] ?? null) {
                            $indicators[] = 'Sampai '.Carbon::parse($data['sampai'])->translatedFormat('d M Y');
                        }

                        return $indicators;
                    }),
                SelectFilter::make('causer_id')
                    ->label('Pengguna')
                    ->relationship('causer', 'name')
                    ->searchable(),
                SelectFilter::make('module')->label('Modul')->options(self::MODULES)->multiple(),
                SelectFilter::make('severity')->label('Tingkat')->options(self::SEVERITIES)->multiple(),
                SelectFilter::make('channel')->label('Dari')->options(self::CHANNELS)->multiple(),
                SelectFilter::make('actor_type')->label('Jenis Pelaku')->options(self::ACTOR_TYPES),
                SelectFilter::make('event')
                    ->label('Jenis Aksi')
                    ->options(fn () => self::eventOptions())
                    ->searchable()
                    ->multiple(),
            ])
            ->filtersFormColumns(2)
            ->recordAction('detail')
            ->recordActions([
                Action::make('detail')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth('3xl')
                    ->modalHeading(fn (ActivityLog $record) => Str::limit($record->description, 90))
                    ->modalDescription(fn (ActivityLog $record) => $record->created_at?->timezone(self::TIMEZONE)->translatedFormat('l, d F Y · H:i:s').' WIB')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (ActivityLog $record) => view('filament.pages.partials.activity-log-detail', [
                        'log' => $record,
                        'related' => $record->batch_id
                            ? ActivityLog::query()->inBatch($record->batch_id)->whereKeyNot($record->getKey())->orderBy('created_at')->orderBy('id')->limit(50)->get()
                            : collect(),
                    ])),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->can('export_activity_logs') ?? false)
                    // Download lewat route GET biasa (ActivityLogExportController), BUKAN dari aksi Livewire:
                    // respons aksi Livewire di-buffer penuh di memori + base64 dalam JSON → crash di puluhan ribu baris.
                    ->url(fn () => $this->exportUrl()),
            ]);
    }

    /** URL export berisi filter, pencarian & arah urut yang sedang aktif di tabel (sebagai query string). */
    public function exportUrl(): string
    {
        $filters = $this->tableFilters ?? [];
        $direction = $this->getTableSortColumn() === 'created_at' ? $this->getTableSortDirection() : null;
        // State DatePicker bisa berupa "Y-m-d H:i:s" — route export hanya menerima "Y-m-d".
        $date = fn ($value) => filled($value) ? Carbon::parse($value)->toDateString() : null;

        return route('admin.log-aktivitas.export', array_filter([
            'dari' => $date($filters['tanggal']['dari'] ?? null),
            'sampai' => $date($filters['tanggal']['sampai'] ?? null),
            'causer_id' => $filters['causer_id']['value'] ?? null,
            'module' => $filters['module']['values'] ?? null,
            'severity' => $filters['severity']['values'] ?? null,
            'channel' => $filters['channel']['values'] ?? null,
            'actor_type' => $filters['actor_type']['value'] ?? null,
            'event' => $filters['event']['values'] ?? null,
            'search' => $this->tableSearch ?: null,
            'arah' => $direction === 'asc' ? 'asc' : null,
        ], fn ($value) => filled($value)));
    }

    /**
     * Rentang tanggal (hari kalender WIB). created_at disimpan dalam zona waktu aplikasi (bukan UTC),
     * jadi batas hari WIB dikonversi ke zona aplikasi — BUKAN ke UTC (dulu ->utc() menggeser rentang
     * 7 jam: aktivitas 17:00-24:00 WIB jatuh ke hari berikutnya).
     */
    public static function applyDateRange(Builder $query, ?string $from, ?string $until): Builder
    {
        $appTimezone = config('app.timezone');

        return $query
            ->when($from, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date, self::TIMEZONE)->startOfDay()->setTimezone($appTimezone)))
            ->when($until, fn (Builder $q, $date) => $q->where('created_at', '<=', Carbon::parse($date, self::TIMEZONE)->endOfDay()->setTimezone($appTimezone)));
    }

    public static function applySearch(Builder $query, string $search): Builder
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            foreach (['causer_name', 'description', 'subject_label', 'event'] as $column) {
                $q->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * Opsi filter "Jenis Aksi". SELECT DISTINCT event di tabel besar = scan index penuh, jadi
     * di-cache 10 menit (bukan dihitung ulang tiap render tabel).
     */
    public static function eventOptions(): array
    {
        $query = fn () => ActivityLog::query()->distinct()->orderBy('event')->limit(300)->pluck('event', 'event')->all();

        try {
            return Cache::remember('audit:event_options', 600, $query);
        } catch (Throwable) {
            return $query();
        }
    }

    /**
     * Whitelist parameter route export. Semua nilai enum dicek terhadap daftar yang dikenal, sisanya
     * dibatasi format & panjang — parameter lain diabaikan.
     */
    public static function exportFilterRules(): array
    {
        return [
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
            'causer_id' => ['nullable', 'string', 'ulid'],
            'module' => ['nullable', 'array', 'max:'.count(self::MODULES)],
            'module.*' => ['string', Rule::in(array_keys(self::MODULES))],
            'severity' => ['nullable', 'array', 'max:'.count(self::SEVERITIES)],
            'severity.*' => ['string', Rule::in(array_keys(self::SEVERITIES))],
            'channel' => ['nullable', 'array', 'max:'.count(self::CHANNELS)],
            'channel.*' => ['string', Rule::in(array_keys(self::CHANNELS))],
            'actor_type' => ['nullable', 'string', Rule::in(array_keys(self::ACTOR_TYPES))],
            'event' => ['nullable', 'array', 'max:50'],
            'event.*' => ['string', 'max:60', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'search' => ['nullable', 'string', 'max:200'],
            'arah' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /** Terapkan filter (yang sudah lolos exportFilterRules) — logika sama dengan filter tabel. */
    public static function applyExportFilters(Builder $query, array $filters): Builder
    {
        self::applyDateRange($query, $filters['dari'] ?? null, $filters['sampai'] ?? null);

        $query
            ->when($filters['causer_id'] ?? null, fn (Builder $q, $value) => $q->where('causer_id', $value))
            ->when($filters['module'] ?? null, fn (Builder $q, $values) => $q->whereIn('module', $values))
            ->when($filters['severity'] ?? null, fn (Builder $q, $values) => $q->whereIn('severity', $values))
            ->when($filters['channel'] ?? null, fn (Builder $q, $values) => $q->whereIn('channel', $values))
            ->when($filters['actor_type'] ?? null, fn (Builder $q, $value) => $q->where('actor_type', $value))
            ->when($filters['event'] ?? null, fn (Builder $q, $values) => $q->whereIn('event', $values));

        return self::applySearch($query, (string) ($filters['search'] ?? ''));
    }

    /** @return array<int, string> satu baris CSV, sudah dinetralkan dari formula injection */
    public static function csvRow(ActivityLog $log): array
    {
        return array_map([self::class, 'csvSafe'], [
            $log->created_at?->timezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
            $log->causer_name,
            $log->causer_role,
            self::ACTOR_TYPES[$log->actor_type] ?? $log->actor_type,
            self::MODULES[$log->module] ?? $log->module,
            $log->event,
            $log->description,
            $log->subject_label,
            self::CHANNELS[$log->channel] ?? $log->channel,
            self::SEVERITIES[$log->severity] ?? $log->severity,
            $log->ip_address,
            $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '',
            $log->meta ? json_encode($log->meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '',
        ]);
    }

    /**
     * Cegah CSV/Formula injection: sel yang diawali = + - @ (atau tab/CR) dieksekusi sebagai rumus
     * oleh Excel. Isi log bisa berasal dari input user (nama, catatan refund), jadi wajib dinetralkan.
     */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
