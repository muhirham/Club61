<?php

namespace App\Filament\Pages;

use App\Models\Coaching\CoachingAcademy;
use App\Models\Coaching\CoachingSettlement;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Order;
use App\Services\Audit\ActivityLogger;
use App\Services\Coaching\CoachingBillingService;
use App\Services\Coaching\CoachingInvoice;
use App\Services\Finance\LedgerReport;
use App\Services\Pos\PosPaymentProof;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Modul 31 — Tagihan Coaching (izin View:TagihanCoaching): sesi tagihan berkala academy (belum lunas / lunas),
 * riwayat Pelunasan Tagihan Coaching + bukti transfer, dan invoice PDF per academy per periode. Tidak ada penerimaan
 * uang di halaman ini — pelunasan dilakukan di POS Walk-In.
 */
class TagihanCoaching extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Tagihan Coaching';

    protected static string|UnitEnum|null $navigationGroup = 'Coaching';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Tagihan Coaching';

    protected string $view = 'filament.pages.tagihan-coaching';

    public const STATUS_UNPAID = 'UNPAID';

    public const STATUS_PAID = 'PAID';

    public function table(Table $table): Table
    {
        return $table
            ->query(PadelBooking::query()
                ->whereHas('coachingBooking', fn (Builder $c) => $c->where('billing', CoachingAcademy::BILLING_TAB))
                ->whereIn('status', [...CoachingBillingService::BILLABLE_STATUSES, 'PAID', 'EXPIRED'])
                ->whereHas('order', fn (Builder $o) => $o->whereIn('payment_status', ['UNPAID', 'PAID']))
                ->with(['court:id,name', 'order:id,payment_status,grand_total', 'coachingBooking.coaches', 'coachingBooking.academy:id,name',
                    'coachingBooking.settlement:id,settlement_number', 'coachingBooking.cashierShift:id,status']))
            ->defaultSort('start_time', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Tidak ada sesi tagihan berkala')
            ->columns([
                TextColumn::make('start_time')->label('Jadwal Main')->weight('bold')->sortable()
                    ->formatStateUsing(fn (PadelBooking $b) => $b->start_time->locale('id')->translatedFormat('D, d M Y'))
                    ->description(fn (PadelBooking $b) => $b->start_time->format('H:i').' – '.$b->end_time->format('H:i').' · '.($b->court?->name ?? '-')),
                TextColumn::make('coachingBooking.academy.name')->label('Academy')
                    ->description(fn (PadelBooking $b) => 'Coach '.$b->coachingBooking?->coachSummary()),
                TextColumn::make('total_amount')->label('Sewa')->money('IDR', locale: 'id')
                    ->description(fn (PadelBooking $b) => $b->booking_code),
                TextColumn::make('due')->label('Jatuh Tempo')
                    ->state(fn (PadelBooking $b) => $b->coachingBooking?->due_at?->locale('id')->translatedFormat('d M Y')
                        ?? ($b->coachingBooking?->billing_cycle === CoachingAcademy::CYCLE_SHIFT ? 'Saat shift ditutup' : '-')),
                TextColumn::make('billing_status')->label('Status')->badge()
                    ->state(fn (PadelBooking $b) => self::statusLabel($b))
                    ->color(fn (PadelBooking $b) => match (self::statusLabel($b)) {
                        'Lunas' => 'success',
                        'Melewati Jatuh Tempo' => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (PadelBooking $b) => $b->coachingBooking?->settlement?->settlement_number),
            ])
            ->filters([
                SelectFilter::make('academy')->label('Academy')
                    ->options(fn () => CoachingAcademy::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $q, $id) => $q->whereHas('coachingBooking', fn (Builder $c) => $c->where('academy_id', $id)))),
                SelectFilter::make('billing_status')->label('Status Tagihan')
                    ->options([self::STATUS_UNPAID => 'Belum Lunas', self::STATUS_PAID => 'Lunas'])
                    ->default(self::STATUS_UNPAID)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $q, $status) => $q->whereHas('order', fn (Builder $o) => $o->where('payment_status', $status)))),
                Filter::make('period')
                    ->columnSpan(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('from')->label('Main dari')->native(false),
                        DatePicker::make('until')->label('Main sampai')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(self::dateOnly($data['from'] ?? null), fn (Builder $q, $d) => $q->whereDate('booking_date', '>=', $d))
                        ->when(self::dateOnly($data['until'] ?? null), fn (Builder $q, $d) => $q->whereDate('booking_date', '<=', $d))),
            ])
            // Di atas tabel (bukan dropdown): dropdown filter terpotong kartu tabel sehingga tanggal tidak bisa diisi.
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->headerActions([$this->invoiceAction()]);
    }

    public static function statusLabel(PadelBooking $b): string
    {
        if ($b->order?->payment_status === 'PAID') {
            return 'Lunas';
        }
        $record = $b->coachingBooking;
        $overdue = $record?->due_at ? $record->due_at->lt(now())
            : ($record?->billing_cycle === CoachingAcademy::CYCLE_SHIFT && $record->cashierShift && $record->cashierShift->status !== 'OPEN');

        return $overdue ? 'Melewati Jatuh Tempo' : 'Belum Lunas';
    }

    /** Tanggal "Y-m-d" dari isian filter / form (nilai lain diabaikan). */
    public static function dateOnly(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    protected function invoiceAction(): Action
    {
        return Action::make('invoice')
            ->label('Unduh Invoice PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->modalHeading('Unduh Invoice Tagihan Academy')
            ->modalDescription('Berisi sesi yang belum dilunasi pada periode tanggal main yang dipilih. Kosongkan tanggal untuk seluruh tagihan belum lunas.')
            ->modalSubmitActionLabel('Unduh PDF')
            ->schema([
                Select::make('academy_id')->label('Academy')->required()->native(false)
                    ->options(fn () => CoachingAcademy::where('billing_mode', CoachingAcademy::BILLING_TAB)->orderBy('name')->pluck('name', 'id')->all()),
                DatePicker::make('from')->label('Main dari')->native(false),
                DatePicker::make('until')->label('Main sampai')->native(false),
            ])
            ->action(function (array $data) {
                $academy = CoachingAcademy::findOrFail($data['academy_id']);
                $invoice = app(CoachingInvoice::class)->data($academy, self::dateOnly($data['from'] ?? null), self::dateOnly($data['until'] ?? null));
                if (! $invoice) {
                    Notification::make()->title('Tidak Ada Tagihan')->body("{$academy->name} tidak memiliki sesi belum lunas pada periode tersebut.")->warning()->send();

                    return null;
                }

                ActivityLogger::record('COACHING', 'coaching_tab.invoice_downloaded',
                    "Mengunduh invoice tagihan coaching {$academy->name} ({$invoice['paid_at']}, Rp ".number_format($invoice['grand_total'], 0, ',', '.').')',
                    meta: ['nomor' => $invoice['number'], 'sesi' => $invoice['reference']],
                );
                $pdf = app(CoachingInvoice::class)->pdf($invoice);

                return response()->streamDownload(fn () => print ($pdf), $invoice['number'].'.pdf', ['Content-Type' => 'application/pdf']);
            });
    }

    /** Bukti transfer pelunasan (disk privat) — hanya pemegang izin halaman ini. */
    public function downloadProof(string $settlementId): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $settlement = CoachingSettlement::findOrFail($settlementId);
        if (! $settlement->proof_path || ! Storage::disk(CoachingBillingService::PROOF_DISK)->exists($settlement->proof_path)) {
            Notification::make()->title('Bukti Tidak Ditemukan')->danger()->send();

            return null;
        }

        return Storage::disk(CoachingBillingService::PROOF_DISK)->download($settlement->proof_path,
            'bukti-transfer-'.$settlement->settlement_number.'.'.pathinfo($settlement->proof_path, PATHINFO_EXTENSION));
    }

    protected function getViewData(): array
    {
        $billing = app(CoachingBillingService::class);
        $unpaid = $billing->unpaidSessions()->with(['coachingBooking.cashierShift:id,status'])->get();
        $overdue = $unpaid->filter(fn (PadelBooking $b) => self::statusLabel($b) === 'Melewati Jatuh Tempo');
        $monthStart = now(LedgerReport::TIMEZONE)->startOfMonth();
        $sum = fn ($bookings) => (float) Order::whereIn('id', $bookings->pluck('order_id')->unique())->sum('grand_total');

        return [
            'kpi' => [
                'unpaid' => ['amount' => $sum($unpaid), 'sessions' => $unpaid->count()],
                'overdue' => ['amount' => $sum($overdue), 'sessions' => $overdue->count()],
                'settled_month' => (float) CoachingSettlement::where('created_at', '>=', $monthStart->copy()->timezone(config('app.timezone')))->sum('total_amount'),
            ],
            'settlements' => CoachingSettlement::with('creator:id,name')->latest()->limit(30)->get(),
            'methods' => PosPaymentProof::METHODS,
            'canOpenPos' => (bool) auth()->user()?->can('View:BookOfflineCourt'),
        ];
    }
}
