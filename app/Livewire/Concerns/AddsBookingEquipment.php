<?php

namespace App\Livewire\Concerns;

use App\Models\Padel\PadelBooking;
use App\Services\Audit\ActivityLogger;
use App\Services\Padel\BookingAddonService;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POS Walk-In → "Tambah Alat ke Booking" (izin add_booking_equipment): alur kasir yang sama dengan walk-in, tanpa memilih
 * lapangan — 1 pilih booking hari ini / ke depan (ketuk di jadwal / cari), customer otomatis dari booking, 3 pilih alat, 4 layar
 * pembayaran yang sama (QRIS / EDC) atau "Catat ke Tagihan Berkala" untuk sesi coaching. Semua dicek ulang di
 * BookingAddonService::checkout.
 */
trait AddsBookingEquipment
{
    /** Kasir sedang memilih booking (blok booking di jadwal hari ini / ke depan bisa diketuk). */
    #[Locked]
    public bool $addonPicking = false;

    /** Booking yang ditambah alat — diisi server (pickAddonBooking). */
    #[Locked]
    public ?array $bookingAddon = null;

    public string $addonSearch = '';

    protected ?array $bookingAddonQuoteCache = null;

    public function getCanAddBookingEquipmentProperty(): bool
    {
        return (bool) auth()->user()?->can(BookingAddonService::PERMISSION);
    }

    public function getIsBookingAddonModeProperty(): bool
    {
        return $this->addonPicking || $this->bookingAddon !== null;
    }

    protected function denyBookingAddon(): bool
    {
        if ($this->canAddBookingEquipment) {
            return false;
        }
        ActivityLogger::accessDenied('mencoba Tambah Alat ke Booking tanpa izin [add_booking_equipment] di POS Walk-In');
        Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin [add_booking_equipment].')->danger()->send();

        return true;
    }

    public function startBookingAddon(): void
    {
        if ($this->denyBookingAddon()) {
            return;
        }
        if ($this->settleBill) {
            $this->cancelSettlement();
        }
        $this->releaseHeldSlots();
        $this->selectedSlots = [];
        $this->resetBookingAddonCart();
        $this->bookingAddon = null;
        $this->addonSearch = '';
        $this->addonPicking = true;
        $this->bookingDate = now()->format('Y-m-d');
        $this->posStep = 'selection';
        $this->dispatch('pos-go-step', step: 1);
    }

    public function pickAddonBooking(string $bookingId): void
    {
        if ($this->denyBookingAddon()) {
            return;
        }

        $service = app(BookingAddonService::class);
        $booking = PadelBooking::with(['user', 'court', 'coachingBooking.academy'])->find($bookingId);
        if (! $booking || ! $service->mode($booking)) {
            Notification::make()->title('Booking Tidak Bisa Ditambah Alat')->body($service->ineligibleReason($booking))->warning()->send();

            return;
        }

        $this->bookingAddon = $service->summary($booking);
        $this->addonPicking = false;
        $this->addonSearch = '';
        $this->resetBookingAddonCart();
        // Customer otomatis dari booking; tanpa membership / voucher / coaching (hanya harga alat).
        $this->customerMode = 'search';
        $this->selectedCustomerId = $booking->user_id;
        $this->selectedCustomerName = $booking->user?->name;
        $this->selectedCustomerPhone = $booking->user?->phone;
        $this->loadBenefitInfo(null);
        $this->dispatch('pos-go-step', step: 3);
    }

    /** Kembali ke walk-in biasa. */
    public function cancelBookingAddon(): void
    {
        $this->exitBookingAddon();
        $this->posStep = 'selection';
        $this->dispatch('pos-go-step', step: 1);
    }

    protected function exitBookingAddon(): void
    {
        if (! $this->isBookingAddonMode) {
            return;
        }
        $this->addonPicking = false;
        $this->bookingAddon = null;
        $this->addonSearch = '';
        $this->resetBookingAddonCart();
        $this->selectedCustomerId = null;
        $this->selectedCustomerName = null;
        $this->selectedCustomerPhone = null;
        $this->customerMode = 'quick_create';
        $this->loadBenefitInfo(null);
    }

    protected function resetBookingAddonCart(): void
    {
        $this->initializeEquipmentQuantities();
        foreach (array_keys($this->rentalQuantities) as $equipmentId) {
            $this->rentalQuantities[$equipmentId] = 0;
        }
        $this->removeVoucher();
        $this->resetCoaching();
        $this->bookingType = 'REGULAR';
        $this->paymentMethod = 'QRIS';
        $this->edcLast4 = '';
        $this->edcApprovalCode = '';
        $this->edcTraceNumber = '';
        $this->qrisRrn = '';
        $this->qrisSenderName = '';
    }

    /** @return list<array{id: string, code: string, customer: string, court: string, schedule: string, tab: bool}> */
    public function getAddonSearchResultsProperty(): array
    {
        if (! $this->addonPicking || ! $this->canAddBookingEquipment) {
            return [];
        }
        $service = app(BookingAddonService::class);

        return $service->search($this->addonSearch)->map(fn (PadelBooking $b) => [
            'id' => $b->id,
            'code' => $b->booking_code,
            'customer' => $b->user?->name ?? 'Customer',
            'court' => $b->court?->name ?? '-',
            'schedule' => $b->booking_date->locale('id')->translatedFormat('D, d M').' · '.$b->start_time->format('H:i').' - '.$b->end_time->format('H:i'),
            'tab' => $service->mode($b) === BookingAddonService::MODE_TAB,
        ])->all();
    }

    /** Rincian & total tambahan alat (sama dengan yang dihitung server saat disimpan). */
    public function getBookingAddonQuoteProperty(): ?array
    {
        if (! $this->bookingAddon) {
            return null;
        }
        if ($this->bookingAddonQuoteCache !== null) {
            return $this->bookingAddonQuoteCache;
        }
        $booking = PadelBooking::with('order')->find($this->bookingAddon['booking_id']);

        return $this->bookingAddonQuoteCache = $booking
            ? app(BookingAddonService::class)->quote($booking, $this->rentalQuantities, mode: $this->bookingAddon['mode'])
            : null;
    }

    public function getIsAddonTabProperty(): bool
    {
        return ($this->bookingAddon['mode'] ?? null) === BookingAddonService::MODE_TAB;
    }

    /** Langkah Tambahan → Pembayaran. */
    protected function proceedBookingAddonToPayment(): void
    {
        if ($this->denyBookingAddon()) {
            return;
        }
        $quote = $this->bookingAddonQuote;
        if (! $quote || $quote['items'] === []) {
            Notification::make()->title('Alat Belum Dipilih')->body('Pilih minimal satu alat yang ditambahkan ke booking ini.')->warning()->send();

            return;
        }
        if (! $this->isAddonTab && ! $this->activeShift) {
            Notification::make()->title('Shift Kasir Belum Dibuka')->body('Buka shift kasir dulu sebelum menerima pembayaran tambahan alat.')->danger()->send();

            return;
        }
        $this->posStep = 'payment';
    }

    protected function submitBookingAddon(): void
    {
        if ($this->denyBookingAddon() || ! $this->bookingAddon) {
            return;
        }

        $addon = $this->bookingAddon;
        $shownTotal = (float) ($this->bookingAddonQuote['grand_total'] ?? 0);
        // QRIS otomatis (payment gateway) — syarat yang sama dengan walk-in; selain itu bukti QRIS statis / EDC.
        $autoPay = $addon['mode'] === BookingAddonService::MODE_PAID && in_array(strtoupper($this->paymentMethod), ['QRIS', 'QRIS_STATIS'], true)
            && $this->qrisMode === 'MIDTRANS' && \App\Services\Pos\PosMidtransQrisService::resolveMethod($this->posOnlineMethod, $shownTotal) !== null;
        [$method, $proof] = $autoPay ? ['QRIS_MIDTRANS', []] : \App\Services\Pos\PosPaymentProof::fromPosForm(
            $this->paymentMethod, $this->edcTerminal, $this->edcCardType, $this->edcLast4, $this->edcApprovalCode,
            $this->edcTraceNumber, $this->qrisProvider, $this->qrisRrn, $this->qrisSenderName, $this->edcCardNetwork, $this->edcBank,
        );

        try {
            $result = app(BookingAddonService::class)->checkout(
                bookingId: $addon['booking_id'],
                quantities: $this->rentalQuantities,
                expectedMode: $addon['mode'],
                expectedTotal: $shownTotal,
                method: $method,
                proof: $proof,
                cashier: auth()->user(),
                onlineMethod: $autoPay ? $this->posOnlineMethod : null,
            );
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                throw $e;
            }
            Notification::make()->title('Tambahan Alat Belum Tersimpan')->body($e->getMessage())->danger()->persistent()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Tambahan Alat Belum Tersimpan')->body('Terjadi kesalahan sistem. Pembayaran TIDAK tercatat — jangan anggap lunas, hubungi admin.')->danger()->persistent()->send();

            return;
        }

        // Bayar Otomatis: popup QR yang sama dengan walk-in (pollPendingQris → struk; batal / kedaluwarsa → order batal).
        if (! empty($result['pending_qris'])) {
            $this->exitBookingAddon();
            $this->pendingQris = $result['pending_qris'];

            return;
        }

        $this->exitBookingAddon();
        $this->posStep = 'selection';
        $this->dispatch('pos-go-step', step: 1);

        if ($result['mode'] === BookingAddonService::MODE_TAB) {
            Notification::make()->title('Dicatat ke Tagihan Berkala')
                ->body("Alat untuk {$addon['code']} ditambahkan ke tagihan {$addon['academy']} (Rp ".number_format($result['quote']['grand_total'], 0, ',', '.').'). Dilunasi melalui Pelunasan Tagihan Coaching.')
                ->success()->send();

            return;
        }

        Notification::make()->title('Tambahan Alat Lunas')
            ->body("Alat untuk {$addon['code']} lunas Rp ".number_format($result['quote']['grand_total'], 0, ',', '.').'.')
            ->success()->send();
        if ($result['payment']) {
            $receipt = $this->buildWalkInReceipt($result['payment']);
            $this->completedOrderData = $receipt;
            $this->showSuccessModal = true;
            $this->queueAutoPrint($result['order']->id, 'filament.partials.walkin-receipt', ['receipt' => $receipt], 'closeSuccessModal');
        }
    }
}
