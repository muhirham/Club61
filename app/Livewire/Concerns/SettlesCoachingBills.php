<?php

namespace App\Livewire\Concerns;

use App\Models\Coaching\CoachingAcademy;
use App\Models\Coaching\CoachingSettlement;
use App\Services\Audit\ActivityLogger;
use App\Services\Coaching\CoachingBillingService;
use App\Services\Pos\PosPaymentProof;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Modul 31 — Pelunasan Tagihan Coaching di POS Walk-In (izin settle_coaching_bill): pilih academy, centang transaksi
 * yang dilunasi, satu bukti bayar (QRIS / EDC BCA / Transfer Bank + foto). Semua aturan dicek ulang di
 * CoachingBillingService::settle — isian di sini hanya bantuan tampilan.
 */
trait SettlesCoachingBills
{
    use WithFileUploads;

    public bool $showCoachingSettlement = false;

    public ?string $settleAcademyId = null;

    /** @var list<string> order yang dicentang */
    public array $settleOrderIds = [];

    public string $settleMethod = 'QRIS';

    public string $settleQrisProvider = 'BCA_QRIS';

    public string $settleQrisRrn = '';

    public string $settleQrisSender = '';

    public string $settleEdcCardType = 'DEBIT';

    public string $settleEdcLast4 = '';

    public string $settleEdcApproval = '';

    public string $settleEdcTrace = '';

    public string $settleTransferBank = 'BCA';

    public string $settleTransferReference = '';

    public string $settleTransferSender = '';

    public $settleTransferProof = null;

    /** Ringkasan pelunasan terakhir untuk dicetak. */
    #[Locked]
    public ?array $lastCoachingSettlement = null;

    public function getCanSettleCoachingBillProperty(): bool
    {
        return (bool) auth()->user()?->can('settle_coaching_bill');
    }

    public function openCoachingSettlement(): void
    {
        if (! $this->canSettleCoachingBill) {
            ActivityLogger::accessDenied('mencoba Pelunasan Tagihan Coaching tanpa izin [settle_coaching_bill] di POS Walk-In');
            Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin [settle_coaching_bill].')->danger()->send();

            return;
        }

        $this->resetCoachingSettlementForm();
        $this->lastCoachingSettlement = null;
        $academies = $this->coachingSettlementAcademies;
        if ($academies->count() === 1) {
            $this->settleAcademyId = (string) $academies->first()->id;
            $this->updatedSettleAcademyId();
        }
        $this->showCoachingSettlement = true;
    }

    public function closeCoachingSettlement(): void
    {
        $this->showCoachingSettlement = false;
        $this->resetCoachingSettlementForm();
        $this->lastCoachingSettlement = null;
    }

    /** Academy berganti → semua transaksinya dicentang (bisa dikurangi untuk pelunasan sebagian). */
    public function updatedSettleAcademyId(): void
    {
        $this->settleOrderIds = $this->coachingOpenBills->pluck('order_id')->all();
    }

    /** @return Collection<int, CoachingAcademy> academy yang masih punya tagihan berkala belum lunas */
    public function getCoachingSettlementAcademiesProperty(): Collection
    {
        if (! $this->canSettleCoachingBill) {
            return collect();
        }
        $ids = app(CoachingBillingService::class)->unpaidSessions()->with('coachingBooking:id,academy_id')->get()
            ->pluck('coachingBooking.academy_id')->filter()->unique();

        return CoachingAcademy::whereIn('id', $ids)->orderBy('name')->get();
    }

    public function getCoachingOpenBillsProperty(): Collection
    {
        $academy = $this->settleAcademyId && $this->canSettleCoachingBill ? CoachingAcademy::find($this->settleAcademyId) : null;

        return $academy ? app(CoachingBillingService::class)->openBills($academy) : collect();
    }

    public function getCoachingSettleTotalProperty(): float
    {
        return (float) $this->coachingOpenBills->whereIn('order_id', $this->settleOrderIds)->sum('amount');
    }

    /** @param  mixed  $expectedTotal  total yang tampil di layar saat tombol ditekan (dicocokkan server di dalam kunci) */
    public function submitCoachingSettlement(mixed $expectedTotal = null): void
    {
        $billing = app(CoachingBillingService::class);
        $academy = $this->settleAcademyId ? CoachingAcademy::find($this->settleAcademyId) : null;
        if (! $academy) {
            Notification::make()->title('Academy Belum Dipilih')->body('Pilih academy yang akan dilunasi tagihannya.')->warning()->send();

            return;
        }
        if ($this->settleMethod === 'TRANSFER_BANK') {
            $this->validateSettleTransferProof();
        }

        try {
            $settlement = $billing->settle($academy, $this->settleOrderIds, $this->settleMethod, $this->coachingSettlementProofInput(),
                $this->settleMethod === 'TRANSFER_BANK' ? $this->settleTransferProof : null, auth()->user(),
                is_numeric($expectedTotal) ? (float) $expectedTotal : null);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                throw $e;
            }
            Notification::make()->title('Pelunasan Belum Tersimpan')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->lastCoachingSettlement = $this->coachingSettlementSummary($settlement);
        $this->resetCoachingSettlementForm();
        Notification::make()->title('Pelunasan Tersimpan')
            ->body("{$settlement->settlement_number}: {$settlement->session_count} sesi {$academy->name}, Rp ".number_format((float) $settlement->total_amount, 0, ',', '.').'. Tagihan sudah lunas.')
            ->success()->send();
    }

    /** Foto bukti transfer langsung dicek begitu selesai diunggah → pratinjau hanya tampil untuk file yang sah. */
    public function updatedSettleTransferProof(): void
    {
        if ($this->settleTransferProof === null) {
            $this->resetErrorBag('settleTransferProof');

            return;
        }
        $this->validateSettleTransferProof();
    }

    protected function validateSettleTransferProof(): void
    {
        $this->validate(['settleTransferProof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']], [
            'settleTransferProof.required' => 'Unggah foto bukti transfer.',
        ], ['settleTransferProof' => 'bukti transfer']);
    }

    /** @return array<string, string> isian bukti sesuai metode (format PosPaymentProof::validate) */
    protected function coachingSettlementProofInput(): array
    {
        return match ($this->settleMethod) {
            'QRIS' => ['qris_provider' => $this->settleQrisProvider, 'qris_rrn' => $this->settleQrisRrn, 'qris_sender_name' => $this->settleQrisSender],
            'EDC_BCA' => ['card_type' => $this->settleEdcCardType, 'card_last_4' => $this->settleEdcLast4, 'approval_code' => $this->settleEdcApproval, 'trace_number' => $this->settleEdcTrace],
            default => ['transfer_bank' => $this->settleTransferBank, 'transfer_reference' => $this->settleTransferReference, 'transfer_sender_name' => $this->settleTransferSender],
        };
    }

    protected function coachingSettlementSummary(CoachingSettlement $settlement): array
    {
        $settlement->load(['coachingBookings.bookings.court', 'creator:id,name']);

        return [
            'number' => $settlement->settlement_number,
            'academy' => $settlement->academy_name,
            'method' => PosPaymentProof::METHODS[$settlement->payment_method] ?? $settlement->payment_method,
            'reference' => $settlement->reference,
            'total' => (float) $settlement->total_amount,
            'sessions' => $settlement->coachingBookings->flatMap->bookings->sortBy('start_time')->map(fn ($b) => [
                'code' => $b->booking_code,
                'schedule' => $b->start_time->format('d/m/Y H:i').'–'.$b->end_time->format('H:i'),
                'court' => $b->court?->name ?? '-',
            ])->values()->all(),
            'cashier' => $settlement->creator?->name,
            'paid_at' => $settlement->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i'),
        ];
    }

    protected function resetCoachingSettlementForm(): void
    {
        $this->resetErrorBag();
        $this->settleAcademyId = null;
        $this->settleOrderIds = [];
        $this->settleMethod = 'QRIS';
        $this->settleQrisProvider = 'BCA_QRIS';
        $this->settleQrisRrn = $this->settleQrisSender = '';
        $this->settleEdcCardType = 'DEBIT';
        $this->settleEdcLast4 = $this->settleEdcApproval = $this->settleEdcTrace = '';
        $this->settleTransferBank = 'BCA';
        $this->settleTransferReference = $this->settleTransferSender = '';
        $this->settleTransferProof = null;
    }
}
