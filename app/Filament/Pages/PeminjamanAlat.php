<?php

namespace App\Filament\Pages;

use App\Models\Padel\EquipmentLoan;
use App\Models\Padel\PadelBooking;
use App\Services\Finance\LedgerReport;
use App\Services\Padel\EquipmentLoanService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Peminjaman Alat: satu baris per transaksi (booking) yang meminjam raket / handuk — tercatat otomatis saat check-in.
 * Detail membuka semua alat transaksi itu: terima kembali dengan kondisi per unit, keputusan denda management, bayar
 * denda di POS kasir, dan slip peminjaman.
 */
class PeminjamanAlat extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Peminjaman Alat';

    protected static string|UnitEnum|null $navigationGroup = 'Operasional Harian';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Peminjaman Alat';

    protected string $view = 'filament.pages.peminjaman-alat';

    /** Dibuka dari Kelola Pemesanan: ?booking={kode booking}. */
    #[Url]
    public ?string $booking = null;

    /** Transaksi yang detailnya sedang dibuka — hanya lewat openDetail(). */
    #[Locked]
    public ?string $detailBookingId = null;

    /** @var array<string, array{good: int|string, minor: int|string, major: int|string, lost: int|string, notes: string}> per peminjaman */
    public array $returnForm = [];

    /** @var array<string, array{decision: string, amount: int|float|string|null, note: string}> per peminjaman */
    public array $decideForm = [];

    public const STATUS_FILTERS = [
        'open' => 'Sedang dipinjam',
        'overdue' => 'Terlambat dikembalikan',
        'fine_pending' => 'Denda menunggu keputusan',
        'fine_due' => 'Denda belum dibayar',
        'returned' => 'Sudah kembali semua',
        'all' => 'Semua',
    ];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('View:PeminjamanAlat');
    }

    /** Jumlah transaksi yang alatnya lewat batas kembali. */
    public static function overdueTransactions(): int
    {
        return EquipmentLoan::overdue()->distinct()->count('booking_id');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::overdueTransactions();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Transaksi dengan alat sewa lewat batas kembali';
    }

    public function getOverdueCountProperty(): int
    {
        return self::overdueTransactions();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PadelBooking::query()
                ->whereHas('equipmentLoans')
                ->with(['court:id,name', 'equipmentLoans' => fn ($q) => $q->orderBy('created_at'), 'equipmentLoans.handedOutBy:id,name', 'equipmentLoans.fineOrder:id,payment_status'])
                ->when($this->booking, fn (Builder $q) => $q->where('booking_code', $this->booking)))
            ->defaultSort('start_time', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Tidak ada peminjaman pada filter ini')
            ->recordAction('detail')
            ->columns([
                TextColumn::make('booking_code')->label('Transaksi')->fontFamily('mono')->weight('bold')
                    ->searchable(query: fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w->where('booking_code', 'like', "%{$search}%")
                        ->orWhereHas('equipmentLoans', fn (Builder $l) => $l->where('borrower_name', 'like', "%{$search}%")->orWhere('borrower_phone', 'like', "%{$search}%"))))
                    ->description(fn (PadelBooking $b) => ($b->court?->name ?? '-').' · '.$b->start_time->format('d M, H:i').'–'.$b->end_time->format('H:i')),
                TextColumn::make('borrower')->label('Peminjam')
                    ->state(fn (PadelBooking $b) => $b->equipmentLoans->first()?->borrower_name)
                    ->description(fn (PadelBooking $b) => $b->equipmentLoans->first()?->borrower_phone),
                TextColumn::make('items')->label('Alat')
                    ->state(fn (PadelBooking $b) => $b->equipmentLoans->sum('quantity').' alat')
                    ->description(fn (PadelBooking $b) => $b->equipmentLoans->map(fn (EquipmentLoan $l) => "{$l->quantity}× {$l->equipment_name}")->implode(', ')),
                TextColumn::make('handed_out')->label('Diserahkan')
                    ->state(fn (PadelBooking $b) => $b->equipmentLoans->min('handed_out_at')?->setTimezone(LedgerReport::TIMEZONE)->format('d M, H:i'))
                    ->description(fn (PadelBooking $b) => $b->equipmentLoans->map(fn (EquipmentLoan $l) => $l->handedOutBy?->name ?? 'Check-in otomatis')->unique()->implode(', ')),
                TextColumn::make('due')->label('Batas kembali')
                    ->state(fn (PadelBooking $b) => $b->equipmentLoans->max('due_at')?->setTimezone(LedgerReport::TIMEZONE)->format('d M, H:i'))
                    ->color(fn (PadelBooking $b) => $b->equipmentLoans->contains(fn (EquipmentLoan $l) => $l->isOverdue()) ? 'danger' : null),
                TextColumn::make('loan_status')->label('Status')->badge()
                    ->state(fn (PadelBooking $b) => $this->statusOf($b->equipmentLoans))
                    ->color(fn (string $state) => match ($state) {
                        'Terlambat' => 'danger',
                        'Dipinjam', 'Sebagian kembali' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('fine')->label('Denda')
                    ->state(fn (PadelBooking $b) => $this->fineSummary($b->equipmentLoans) ?? '-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::STATUS_FILTERS)
                    ->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'open') {
                        'overdue' => $query->whereHas('equipmentLoans', fn (Builder $l) => $l->overdue()),
                        'fine_pending' => $query->whereHas('equipmentLoans', fn (Builder $l) => $l->where('fine_status', EquipmentLoan::FINE_PENDING)),
                        'fine_due' => $query->whereHas('equipmentLoans', fn (Builder $l) => $l->where('fine_status', EquipmentLoan::FINE_APPROVED)
                            ->where(fn (Builder $q) => $q->whereNull('fine_order_id')->orWhereHas('fineOrder', fn (Builder $o) => $o->where('payment_status', '!=', 'PAID')))),
                        'returned' => $query->whereDoesntHave('equipmentLoans', fn (Builder $l) => $l->open()),
                        'all' => $query,
                        default => $query->whereHas('equipmentLoans', fn (Builder $l) => $l->open()),
                    }),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->action(fn (PadelBooking $record) => $this->openDetail($record->id)),
            ]);
    }

    /** @param  Collection<int, EquipmentLoan>  $loans */
    private function statusOf(Collection $loans): string
    {
        $open = $loans->filter(fn (EquipmentLoan $l) => $l->isOpen());

        return match (true) {
            $open->contains(fn (EquipmentLoan $l) => $l->isOverdue()) => 'Terlambat',
            $open->isNotEmpty() && $open->count() < $loans->count() => 'Sebagian kembali',
            $open->isNotEmpty() => 'Dipinjam',
            default => 'Kembali',
        };
    }

    /** @param  Collection<int, EquipmentLoan>  $loans */
    private function fineSummary(Collection $loans): ?string
    {
        $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $pending = $loans->where('fine_status', EquipmentLoan::FINE_PENDING);
        $due = $loans->sum(fn (EquipmentLoan $l) => $l->fineDue());
        $approved = $loans->where('fine_status', EquipmentLoan::FINE_APPROVED);

        return match (true) {
            $pending->isNotEmpty() => 'Menunggu keputusan · usulan '.$rupiah($pending->sum('fine_proposed')),
            $due > 0 => 'Belum dibayar '.$rupiah($due),
            $approved->isNotEmpty() => 'Lunas '.$rupiah($approved->sum('fine_amount')),
            $loans->contains('fine_status', EquipmentLoan::FINE_WAIVED) => 'Dihapus',
            default => null,
        };
    }

    // ================================================================== DETAIL TRANSAKSI

    public function openDetail(string $bookingId): void
    {
        abort_unless(static::canAccess(), 403);
        $booking = PadelBooking::whereKey($bookingId)->whereHas('equipmentLoans')->first();
        if (! $booking) {
            Notification::make()->title('Transaksi tidak ditemukan')->danger()->send();

            return;
        }
        $this->detailBookingId = $booking->id;
        $this->resetDetailForms();
    }

    public function closeDetail(): void
    {
        $this->detailBookingId = null;
        $this->returnForm = [];
        $this->decideForm = [];
        $this->resetValidation();
    }

    public function getDetailBookingProperty(): ?PadelBooking
    {
        return $this->detailBookingId
            ? PadelBooking::with(['court:id,name', 'user:id,name,phone'])->find($this->detailBookingId)
            : null;
    }

    /** @return Collection<int, EquipmentLoan> */
    public function getDetailLoansProperty(): Collection
    {
        return $this->detailBookingId
            ? EquipmentLoan::with(['equipment', 'handedOutBy:id,name', 'returnedBy:id,name', 'fineDecidedBy:id,name', 'fineOrder:id,payment_status'])
                ->where('booking_id', $this->detailBookingId)->orderBy('created_at')->get()
            : collect();
    }

    public function getCanReturnProperty(): bool
    {
        return (bool) auth()->user()?->can(EquipmentLoanService::PERMISSION_RETURN);
    }

    public function getCanDecideProperty(): bool
    {
        return (bool) auth()->user()?->can(EquipmentLoanService::PERMISSION_DECIDE);
    }

    /** Tombol "Bayar di Kasir" → tab Denda Kasir Pro Shop (butuh akses halaman itu juga). */
    public function getCanPayProperty(): bool
    {
        return (bool) auth()->user()?->can(EquipmentLoanService::PERMISSION_PAY) && KasirProShop::canAccess();
    }

    /** Terima kembali satu alat dengan kondisi yang diisi di detail. */
    public function saveReturn(string $loanId): void
    {
        $loan = $this->detailLoan($loanId);
        $form = $this->returnForm[$loanId] ?? [];
        $this->runAction(
            fn () => app(EquipmentLoanService::class)->returnLoan($loan->id, $form, $form['notes'] ?? null, auth()->user()),
            fn (EquipmentLoan $l) => $l->fine_status === EquipmentLoan::FINE_PENDING
                ? ['Alat diterima — denda menunggu keputusan', 'Usulan denda Rp '.number_format((float) $l->fine_proposed, 0, ',', '.').'. Management memutuskan di detail ini, lalu dibayar di kasir.']
                : ['Alat diterima kembali', 'Stok alat sudah bertambah lagi.'],
        );
    }

    /** Semua alat yang belum kembali diterima dalam kondisi baik sekaligus. */
    public function returnAllGood(): void
    {
        abort_unless($this->detailBookingId, 404);
        $open = $this->detailLoans->filter(fn (EquipmentLoan $l) => $l->isOpen());
        $this->runAction(function () use ($open) {
            foreach ($open as $loan) {
                app(EquipmentLoanService::class)->returnLoan($loan->id, ['good' => $loan->quantity], null, auth()->user());
            }

            return $open->sum('quantity');
        }, fn (int $count) => ["{$count} alat diterima kembali (kondisi baik)", 'Stok alat sudah bertambah lagi.']);
    }

    public function saveDecision(string $loanId): void
    {
        $loan = $this->detailLoan($loanId);
        $form = $this->decideForm[$loanId] ?? [];
        $amount = ($form['amount'] ?? '') === '' || $form['amount'] === null ? null : (float) $form['amount'];
        $this->runAction(
            fn () => app(EquipmentLoanService::class)->decideFine($loan->id, (string) ($form['decision'] ?? ''), $amount, (string) ($form['note'] ?? ''), auth()->user()),
            fn (EquipmentLoan $l) => $l->fine_status === EquipmentLoan::FINE_APPROVED
                ? ['Denda disetujui', 'Tagih Rp '.number_format((float) $l->fine_amount, 0, ',', '.').' lewat tombol Bayar di Kasir.']
                : ['Denda dihapus', null],
        );
    }

    /** Peminjaman milik transaksi yang sedang dibuka (ID dari browser tidak dipercaya begitu saja). */
    private function detailLoan(string $loanId): EquipmentLoan
    {
        abort_unless($this->detailBookingId, 404);

        return EquipmentLoan::whereKey($loanId)->where('booking_id', $this->detailBookingId)->first() ?? abort(404);
    }

    private function resetDetailForms(): void
    {
        $this->returnForm = [];
        $this->decideForm = [];
        foreach ($this->detailLoans as $loan) {
            if ($loan->isOpen()) {
                $this->returnForm[$loan->id] = ['good' => $loan->quantity, 'minor' => 0, 'major' => 0, 'lost' => 0, 'notes' => ''];
            }
            if ($loan->fine_status === EquipmentLoan::FINE_PENDING) {
                $this->decideForm[$loan->id] = ['decision' => 'APPROVE', 'amount' => (float) $loan->fine_proposed, 'note' => ''];
            }
        }
    }

    private function runAction(\Closure $callback, \Closure $message): void
    {
        try {
            [$title, $body] = $message($callback());
            Notification::make()->title($title)->body($body)->success()->send();
        } catch (HttpException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } finally {
            // Isian detail dimuat ulang dari database (sebagian alat bisa sudah tersimpan sebelum ada yang ditolak).
            unset($this->detailLoans);
            $this->resetDetailForms();
        }
    }
}
