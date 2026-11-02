<?php

namespace App\Filament\Pages;

use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Payment;
use App\Services\Padel\PadelBookingService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Livewire\WithPagination;
use UnitEnum;

class KelolaPemesanan extends Page
{
    use HasPageShield;
    use WithPagination;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Kelola Pemesanan';

    protected static string | UnitEnum | null $navigationGroup = 'Operasional Harian';

    protected static ?string $title = 'Kelola Pemesanan & Tiket';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.kelola-pemesanan';

    // Filter & Search State
    public string $activeTab = 'ALL';
    public string $search = '';
    public int $perPage = 10;

    /**
     * Periode tanggal main yang ditampilkan: current (bawaan: bulan ini & mendatang — booking minggu depan yang sudah
     * masuk bulan berikutnya tetap terlihat) / day / week / month / range / all. Data tidak dihapus — hanya tampilan yang
     * dibatasi supaya tidak menumpuk. Pencarian selalu mencakup semua periode.
     */
    public string $period = 'current';
    public string $periodDate = '';
    public ?string $rangeStart = null;
    public ?string $rangeEnd = null;

    /** Status batal / refund / kedaluwarsa — tab sendiri, tidak ikut "Semua Aktif". */
    public const INACTIVE_STATUSES = ['CANCELLED', 'REFUND_PENDING', 'REFUNDED', 'EXPIRED'];

    // Modal State
    public bool $showRescheduleModal = false;
    public bool $showCancelRefundModal = false;

    // Reschedule Form State
    public ?string $selectedBookingId = null;
    public ?array $selectedBookingData = null;
    public ?string $rescheduleCourtId = null;
    public ?string $rescheduleDate = null;
    public ?string $rescheduleStartTime = null;
    public int $rescheduleDurationHours = 1;
    public array $availableSlots = [];
    public float $rescheduleEstimatedFee = 0.0;
    public float $rescheduleDelta = 0.0;
    /** Pilihan customer untuk membayar selisih: CASHIER (POS Walk-In) / ONLINE (Midtrans via invoice). */
    public string $rescheduleDeltaChannel = 'CASHIER';
    public string $rescheduleReason = '';
    /** Rincian selisih slot terpilih (hasil PadelBookingService::quoteReschedule, sama persis dengan yang ditagih). */
    public array $rescheduleQuote = [];
    // Cancel & Refund Form State
    public ?string $cancelBookingId = null;
    public ?string $cancelBookingCode = null;
    public ?string $cancelCustomerName = null;
    public float $originalTotalAmount = 0.0;
    /** Ditampilkan saja (tidak bisa diubah): seluruh uang yang masuk untuk booking ini, tanpa potongan (Modul 21). */
    public float $refundAmount = 0.0;
    public string $refundCategory = 'PERMINTAAN_CUSTOMER';
    public string $refundNotes = '';

    // Batalkan sesi tagihan berkala coaching (Modul 31) — belum dibayar, jadi tanpa refund.
    public bool $showCancelTabModal = false;
    public ?string $cancelTabBookingId = null;
    public string $cancelTabReason = '';

    // Check-In Gate Modal State
    public bool $showCheckInModal = false;
    public string $checkInQuery = '';
    public ?array $checkInResult = null;

    public function mount(): void
    {
        $this->rescheduleDate = now()->format('Y-m-d');
        $this->periodDate = now()->format('Y-m-d');
    }

    public function updatedPeriod(): void
    {
        $this->period = in_array($this->period, ['current', 'day', 'week', 'month', 'range', 'all'], true) ? $this->period : 'current';
        $this->periodDate = now()->format('Y-m-d');
        $this->resetPage();
    }

    public function updatedRangeStart(): void
    {
        $this->resetPage();
    }

    public function updatedRangeEnd(): void
    {
        $this->resetPage();
    }

    /** ‹ / › — mundur / maju satu hari, minggu, atau bulan. */
    public function shiftPeriod(int $direction): void
    {
        $date = Carbon::parse($this->periodDate ?: now());
        $step = $direction > 0 ? 1 : -1;
        $this->periodDate = (match ($this->period) {
            'day' => $date->addDays($step),
            'week' => $date->addWeeks($step),
            default => $date->startOfMonth()->addMonthsNoOverflow($step),
        })->format('Y-m-d');
        $this->resetPage();
    }

    /** @return array{0: string, 1: ?string}|null rentang tanggal main (Y-m-d; akhir null = tanpa batas), null = semua periode */
    public function periodRange(): ?array
    {
        $date = Carbon::parse($this->periodDate ?: now());

        return match ($this->period) {
            'current' => [now()->startOfMonth()->toDateString(), null],
            'day' => [$date->toDateString(), $date->toDateString()],
            'week' => [$date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $date->copy()->endOfWeek(Carbon::SUNDAY)->toDateString()],
            'range' => $this->rangeStart && $this->rangeEnd
                ? [min($this->rangeStart, $this->rangeEnd), max($this->rangeStart, $this->rangeEnd)]
                : null,
            'all' => null,
            default => [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()],
        };
    }

    public function periodLabel(): string
    {
        $range = $this->periodRange();
        if (! $range) {
            return 'Semua periode';
        }
        if ($range[1] === null) {
            return 'Sejak '.Carbon::parse($range[0])->translatedFormat('d F Y');
        }
        [$from, $to] = array_map(fn ($d) => Carbon::parse($d), $range);

        return match ($this->period) {
            'day' => $from->translatedFormat('l, d F Y'),
            'month' => $from->translatedFormat('F Y'),
            default => $from->translatedFormat('d M').' – '.$to->translatedFormat('d M Y'),
        };
    }

    /** Batasi ke periode (tanggal main). Bentuk between supaya index booking_date terpakai. */
    private function applyPeriod($query)
    {
        if ($range = $this->periodRange()) {
            $range[1] === null
                ? $query->where('booking_date', '>=', $range[0])
                : $query->whereBetween('booking_date', [$range[0], $range[1].' 23:59:59']);
        }

        return $query;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function getCanRescheduleProperty(): bool
    {
        return (bool) auth()->user()?->can('reschedule_padel_booking');
    }

    public function getCanRefundProperty(): bool
    {
        return (bool) auth()->user()?->can('request_refund_padel');
    }

    /**
     * REFUND_PENDING dari pengajuan customer lewat web (fitur lama, sebelum Modul 21) yang belum punya catatan refund —
     * staf perlu memasukkannya ke Antrian Refund, kalau tidak booking itu tertahan selamanya.
     */
    public static function isLegacyRefundRequest(PadelBooking $booking): bool
    {
        if ($booking->status !== 'REFUND_PENDING') {
            return false;
        }

        $refunds = $booking->relationLoaded('order') && $booking->order?->relationLoaded('refunds')
            ? $booking->order->refunds
            : \App\Models\Pos\Refund::where('padel_booking_id', $booking->id)->get();

        return ! $refunds->contains(fn ($r) => $r->padel_booking_id === $booking->id && $r->status === 'PENDING');
    }

    /** Tombol "Ajukan Pembatalan & Refund": booking aktif yang jam mainnya belum mulai, atau pengajuan lama customer. */
    public function canRequestRefundFor(PadelBooking $booking): bool
    {
        return $this->canRefund && (
            (in_array($booking->status, ['PAID', 'LOCKED'], true) && $booking->start_time->gt(now()))
            || self::isLegacyRefundRequest($booking)
        );
    }

    /** Sesi tagihan berkala coaching yang belum dilunasi (status PENDING_PAYMENT). */
    public function isUnpaidCoachingTab(PadelBooking $booking): bool
    {
        return $booking->status === 'PENDING_PAYMENT' && (bool) $booking->coachingBooking?->isTab();
    }

    /** Sesi tagihan coaching yang belum lunas & belum dimulai (batas waktunya dicek lagi di server). */
    public function canCancelTabFor(PadelBooking $booking): bool
    {
        return (bool) auth()->user()?->can('book_coaching')
            && $booking->status === 'PENDING_PAYMENT'
            && $booking->coachingBooking?->isTab()
            && $booking->start_time->gt(now());
    }

    public function openCancelTabModal(string $bookingId): void
    {
        if ($this->deniedWithout('book_coaching')) {
            return;
        }
        $this->cancelTabBookingId = PadelBooking::whereKey($bookingId)->value('id');
        $this->cancelTabReason = '';
        $this->showCancelTabModal = $this->cancelTabBookingId !== null;
    }

    public function executeCancelTab(\App\Services\Coaching\CoachingBillingService $billing): void
    {
        if ($this->deniedWithout('book_coaching')) {
            return;
        }

        try {
            $billing->cancelTab(PadelBooking::findOrFail($this->cancelTabBookingId), $this->cancelTabReason, auth()->user());
            Cache::forget('kelola_pemesanan_tab_counts');
            Notification::make()->title('Sesi Dibatalkan')->body('Sesi dihapus dari tagihan academy dan slot kembali tersedia.')->success()->send();
            $this->showCancelTabModal = false;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Notification::make()->title('Tidak Bisa Dibatalkan')->body($e->getMessage())->danger()->send();
        }
    }

    public function getCanSettleProperty(): bool
    {
        return (bool) auth()->user()?->can('settle_unpaid_booking');
    }

    public function getCanOpenPosProperty(): bool
    {
        return \App\Filament\Pages\BookOfflineCourt::canAccess();
    }

    public function getCanCheckInProperty(): bool
    {
        return (bool) auth()->user()?->can('checkin_padel_ticket');
    }

    /** true = aksi ditolak (notifikasi sudah dikirim); tombolnya juga disembunyikan di Blade. */
    protected function deniedWithout(string $permission): bool
    {
        if (auth()->user()?->can($permission)) {
            return false;
        }

        \App\Services\Audit\ActivityLogger::accessDenied("mencoba aksi tanpa izin [{$permission}] di Kelola Pemesanan");

        Notification::make()
            ->title('Akses Ditolak')
            ->body("Anda tidak memiliki izin [{$permission}] untuk aksi ini.")
            ->danger()
            ->send();

        return true;
    }

    public function openRescheduleModal(string $bookingId, PadelBookingService $service): void
    {
        if ($this->deniedWithout('reschedule_padel_booking')) {
            return;
        }

        $booking = PadelBooking::with(['court', 'user', 'order', 'coachingBooking'])->findOrFail($bookingId);
        // Modul 31: sesi tagihan berkala coaching belum dibayar juga boleh dipindah (tagihannya ikut tarif jadwal baru).
        $isTab = $this->isUnpaidCoachingTab($booking);

        if ($booking->status !== 'PAID' && ! $isTab) {
            Notification::make()
                ->title('Tidak Bisa Dipindah')
                ->body($booking->status === 'LOCKED' && $booking->reschedule_count > 0
                    ? 'Booking ini masih punya tagihan selisih reschedule yang belum lunas. Lunasi dulu di POS Walk-In (klik slot "Bayar" di grid jadwal).'
                    : "Hanya booking yang sudah lunas yang bisa dipindah jadwalnya (status saat ini: {$booking->status}).")
                ->warning()
                ->send();

            return;
        }

        if (now()->gte(PadelBookingService::rescheduleDeadline($booking))) {
            Notification::make()
                ->title('Tidak Bisa Dipindah')
                ->body('Reschedule paling lambat '.PadelBookingService::rescheduleCutoffLabel($booking).' sebelum jam main ('.PadelBookingService::rescheduleDeadline($booking)->format('d M Y H:i').' WIB).')
                ->warning()
                ->send();

            return;
        }

        if (PadelBookingService::rescheduleLimitReached($booking)) {
            Notification::make()
                ->title('Tidak Bisa Dipindah')
                ->body('Booking ini sudah dipindah '.PadelBookingService::MAX_RESCHEDULES.'x — batas maksimal reschedule.')
                ->warning()
                ->send();

            return;
        }

        $this->selectedBookingId = $booking->id;
        $this->rescheduleCourtId = $booking->court_id;

        // Anti-tanggal lampau: jika tanggal booking sudah lewat, gunakan hari ini
        $isPastDate = $booking->booking_date->isPast() && ! $booking->booking_date->isToday();
        $this->rescheduleDate = $isPastDate ? now()->format('Y-m-d') : $booking->booking_date->format('Y-m-d');

        // Kunci durasi multi-jam (Anti-Jebakan Durasi)
        $duration = (int) $booking->start_time->diffInHours($booking->end_time);
        $this->rescheduleDurationHours = max(1, $duration);

        $this->selectedBookingData = [
            'id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'customer_name' => $booking->user?->name ?? 'Guest',
            'court_name' => $booking->court?->name ?? '-',
            'original_date' => $booking->booking_date->format('d M Y'),
            'original_time' => $booking->start_time->format('H:i') . ' - ' . $booking->end_time->format('H:i') . ' WIB',
            'original_court_fee' => (float) $booking->court_fee,
            'original_benefit' => (float) $booking->member_discount_court + (float) $booking->sponsor_discount_court,
            'duration_hours' => $this->rescheduleDurationHours,
            'reschedule_count' => (int) $booking->reschedule_count,
            'is_tab' => $isTab,
            'tab_total' => $isTab ? (float) $booking->order?->grand_total : null,
        ];

        $this->rescheduleReason = '';
        $this->rescheduleDeltaChannel = 'CASHIER';
        $this->rescheduleQuote = [];

        $this->loadAvailableSlots($service);
        $this->showRescheduleModal = true;
    }

    public function updatedRescheduleCourtId(): void
    {
        $this->loadAvailableSlots(app(PadelBookingService::class));
    }

    public function updatedRescheduleDate(): void
    {
        // Validasi anti-tanggal lampau pada input Livewire
        if ($this->rescheduleDate) {
            $parsed = Carbon::parse($this->rescheduleDate)->startOfDay();
            if ($parsed->isPast() && ! $parsed->isToday()) {
                $this->rescheduleDate = now()->format('Y-m-d');
                Notification::make()
                    ->title('Tanggal Lampau Ditolak')
                    ->body('Jadwal hanya dapat dipindahkan ke hari ini atau masa depan.')
                    ->warning()
                    ->send();
            }
        }

        $this->loadAvailableSlots(app(PadelBookingService::class));
    }

    public function updatedRescheduleStartTime(): void
    {
        $this->updatePriceDelta();
    }

    public function loadAvailableSlots(PadelBookingService $service): void
    {
        if (! $this->selectedBookingId || ! $this->rescheduleCourtId || ! $this->rescheduleDate) {
            $this->availableSlots = [];
            return;
        }

        try {
            $result = $service->getAvailableRescheduleSlots(
                $this->selectedBookingId,
                $this->rescheduleCourtId,
                $this->rescheduleDate
            );

            $this->availableSlots = $result['slots'];

            if (! empty($this->availableSlots)) {
                // Pilih slot pertama secara default jika pilihan sebelumnya tidak lagi tersedia
                $exists = collect($this->availableSlots)->contains('start_time', $this->rescheduleStartTime);
                if (! $exists) {
                    $this->rescheduleStartTime = $this->availableSlots[0]['start_time'];
                }
            } else {
                $this->rescheduleStartTime = null;
            }

            $this->updatePriceDelta();
        } catch (\Throwable $e) {
            $this->availableSlots = [];
            $this->rescheduleStartTime = null;
            $this->rescheduleEstimatedFee = 0.0;
            $this->rescheduleDelta = 0.0;

            // Tanggal tutup / belum dibuka (App\Support\BookingWindow): beri tahu alasannya, bukan daftar slot kosong.
            if ($reason = \App\Support\BookingWindow::blockedReason($this->rescheduleDate)) {
                Notification::make()->title('Tanggal Tidak Bisa Dibooking')->body($reason)->warning()->send();
            }
        }
    }

    protected function updatePriceDelta(): void
    {
        if (empty($this->availableSlots) || ! $this->rescheduleStartTime) {
            $this->rescheduleEstimatedFee = 0.0;
            $this->rescheduleDelta = 0.0;
            $this->rescheduleQuote = [];
            return;
        }

        $chosenSlot = collect($this->availableSlots)->firstWhere('start_time', $this->rescheduleStartTime);
        if ($chosenSlot) {
            $this->rescheduleEstimatedFee = (float) $chosenSlot['estimated_fee'];
            $this->rescheduleDelta = (float) $chosenSlot['delta'];
            $this->rescheduleQuote = $chosenSlot;
        }
    }

    public function executeReschedule(PadelBookingService $service): void
    {
        if ($this->deniedWithout('reschedule_padel_booking')) {
            return;
        }

        if (! $this->rescheduleStartTime) {
            Notification::make()
                ->title('Pilih Jam Main')
                ->body('Silakan pilih salah satu slot jam main yang tersedia.')
                ->danger()
                ->send();
            return;
        }

        try {
            $adminUser = auth()->user() ?? \App\Models\User::role(['admin', 'super_admin'])->first();

            $result = $service->adminRescheduleBooking(
                bookingId: $this->selectedBookingId,
                newCourtId: $this->rescheduleCourtId,
                newDate: $this->rescheduleDate,
                newStartTimeStr: $this->rescheduleStartTime,
                reason: $this->rescheduleReason ?: 'Permintaan Reschedule via Admin',
                adminUser: $adminUser,
                isDeltaPaid: false, // pembayaran dieksekusi di POS Walk-In / Midtrans, bukan di sini
                deltaPaymentChannel: $this->rescheduleDeltaChannel === 'ONLINE' ? 'ONLINE' : 'CASHIER',
            );

            Cache::forget('kelola_pemesanan_tab_counts');

            $rupiah = fn (float $v) => 'Rp '.number_format($v, 0, ',', '.');
            Notification::make()
                ->title('Reschedule Berhasil')
                ->body((! empty($result['benefit_dropped_reason']) ? 'Benefit gugur: '.$result['benefit_dropped_reason'].' ' : '').match (true) {
                    ! empty($result['coaching_tab']) => 'Sesi coaching dipindahkan. Tagihan berkala academy untuk transaksi ini '
                        .$rupiah($result['coaching_tab']['before']).' → '.$rupiah($result['coaching_tab']['after']).', tetap belum dibayar dan dilunasi melalui Pelunasan Tagihan Coaching.',
                    ($result['total_charge'] ?? 0) > 0 => 'Jadwal dipindahkan & slot ditahan. Selisih '.$rupiah($result['total_charge']).($this->rescheduleDeltaChannel === 'ONLINE'
                        ? ' dibayar customer lewat pembayaran online dari halaman invoice-nya.'
                        : ' dilunasi di POS Walk-In (klik slot "Bayar" di grid jadwal) saat customer datang.').' QR & check-in terkunci sampai lunas.',
                    ($result['forfeited'] ?? 0) > 0 => 'Jadwal dipindahkan ke jam lebih murah. Selisih '.$rupiah($result['forfeited']).' hangus sesuai kebijakan (tidak dikembalikan).',
                    default => 'Jadwal reservasi berhasil dipindahkan tanpa selisih biaya.',
                })
                ->success()
                ->send();

            $this->showRescheduleModal = false;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Reschedule')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** Customer bilang "sudah bayar tapi masih pending": tanya langsung ke gateway tagihannya (lewat pengarah). */
    public function checkMidtransPayment(string $bookingId): void
    {
        if ($this->deniedWithout('settle_unpaid_booking')) {
            return;
        }

        $booking = PadelBooking::findOrFail($bookingId);
        $order = $booking->order_id ? \App\Models\Pos\Order::find($booking->order_id) : null;

        if (! $order) {
            Notification::make()->title('Tidak Ada Transaksi Online')->body("Tiket {$booking->booking_code} tidak punya order pembayaran online.")->warning()->send();

            return;
        }

        $router = app(\App\Services\Payment\OnlineGatewayRouter::class);
        $result = $router->reconcileOrder($order);
        $gatewayName = $router->gatewayLabel($order);
        Cache::forget('kelola_pemesanan_tab_counts');

        $booking->refresh();
        $service = app(PadelBookingService::class);
        $openBill = $service->pendingBillForBooking($booking);
        if ($result === \App\Services\Payment\OnlineGatewayRouter::NOT_PAID && $openBill) {
            Notification::make()->title('Tagihan Belum Dibayar')
                ->body("Belum ada pembayaran untuk tagihan {$booking->booking_code} Rp ".number_format((float) $openBill->amount, 0, ',', '.').'. Tagihan masih terbuka: dibayar di POS Walk-In atau via invoice customer.')
                ->warning()->send();

            return;
        }

        [$title, $body, $color] = match ($result) {
            \App\Services\Payment\OnlineGatewayRouter::PAID => ['Pembayaran Terkonfirmasi', "{$gatewayName} mencatat order {$order->order_number} LUNAS. Tiket {$booking->booking_code} ".($booking->status === 'LOCKED' ? 'masih menunggu tagihan lain.' : 'sudah diaktifkan.'), 'success'],
            \App\Services\Payment\OnlineGatewayRouter::PENDING => ['Belum Dibayar', "{$gatewayName} masih menunggu pembayaran order {$order->order_number}.", 'warning'],
            \App\Services\Payment\OnlineGatewayRouter::NOT_PAID => ['Tidak Ada Pembayaran', "{$gatewayName} tidak mencatat pembayaran lunas untuk order {$order->order_number} (kedaluwarsa / dibatalkan / tidak ditemukan).", 'danger'],
            default => ["Gagal Menghubungi {$gatewayName}", "Coba lagi beberapa saat, atau cek langsung di dashboard {$gatewayName}.", 'danger'],
        };

        Notification::make()->title($title)->body($body)->color($color)->send();
    }

    /**
     * Modul 21: staf (kasir / resepsionis / admin) mengajukan pembatalan + refund. Booking langsung batal, uangnya
     * menunggu persetujuan di Antrian Refund — halaman ini tidak pernah mengeluarkan uang.
     */
    public function openCancelRefundModal(string $bookingId): void
    {
        if ($this->deniedWithout('request_refund_padel')) {
            return;
        }

        $booking = PadelBooking::with(['user', 'order'])->findOrFail($bookingId);

        if (! self::isLegacyRefundRequest($booking) && $booking->start_time->lte(now())) {
            Notification::make()
                ->title('Tidak Bisa Dibatalkan')
                ->body('Jam main booking ini sudah dimulai atau lewat. Booking yang hangus tidak bisa dibatalkan, di-refund, maupun di-reschedule.')
                ->warning()
                ->send();

            return;
        }

        // Nominal = uang yang benar-benar sudah dibayar untuk booking ini (tanpa potongan).
        $refundable = app(PadelBookingService::class)->refundableAmountForBooking($booking);
        if ($refundable <= 0 && $booking->status === 'PAID' && (! $booking->order_id || ! \App\Models\Pos\Payment::where('order_id', $booking->order_id)->exists())) {
            $refundable = (float) $booking->total_amount; // data legacy tanpa catatan pembayaran
        }

        $this->cancelBookingId = $booking->id;
        $this->cancelBookingCode = $booking->booking_code;
        $this->cancelCustomerName = $booking->user?->name ?? 'Guest';
        $this->originalTotalAmount = $refundable;
        $this->refundAmount = $refundable;
        $this->refundCategory = 'PERMINTAAN_CUSTOMER';
        $this->refundNotes = '';

        $this->showCancelRefundModal = true;
    }

    public function executeCancelRefund(PadelBookingService $service): void
    {
        if ($this->deniedWithout('request_refund_padel')) {
            return;
        }

        if (! in_array($this->refundCategory, ['PERMINTAAN_CUSTOMER', 'FORCE_MAJEURE', 'SALAH_BAYAR', 'KESALAHAN_VENUE'], true)) {
            $this->refundCategory = 'PERMINTAAN_CUSTOMER';
        }

        if (mb_strlen(trim($this->refundNotes)) < 5) {
            Notification::make()->title('Alasan Wajib Diisi')->body('Tulis alasan pembatalan (minimal 5 karakter) untuk pemeriksa di Antrian Refund.')->danger()->send();

            return;
        }

        try {
            $result = $service->requestCancelAndRefund(
                bookingId: (string) $this->cancelBookingId,
                reasonCategory: $this->refundCategory,
                notes: mb_substr(trim($this->refundNotes), 0, 500),
                requester: auth()->user(),
            );

            Cache::forget('kelola_pemesanan_tab_counts');

            Notification::make()
                ->title('Reservasi Dibatalkan')
                ->body($result['message'].' Tiket QR sudah dinonaktifkan dan slot dilepas.')
                ->success()
                ->send();

            $this->showCancelRefundModal = false;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Membatalkan')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function openCheckInModal(?string $code = null): void
    {
        $this->checkInQuery = $code ?? '';
        $this->checkInResult = null;
        $this->showCheckInModal = true;
    }

    public function closeCheckInModal(): void
    {
        $this->showCheckInModal = false;
        $this->checkInQuery = '';
        $this->checkInResult = null;
    }

    public function executeCheckIn(PadelBookingService $service): void
    {
        if ($this->deniedWithout('checkin_padel_ticket')) {
            return;
        }

        $code = trim($this->checkInQuery);
        if (empty($code)) {
            Notification::make()
                ->title('Input Kosong')
                ->body('Silakan scan barcode atau masukkan Kode Booking / Hash QR.')
                ->warning()
                ->send();
            return;
        }

        try {
            $staffUser = auth()->user() ?? \App\Models\User::role(['admin', 'super_admin'])->first();
            $result = $service->checkIn($code, $staffUser);
            $this->checkInResult = $result;

            Notification::make()
                ->title($result['already_checked_in'] ? 'Sudah Pernah Check-In' : 'Check-In Berhasil!')
                ->body($result['message'])
                ->color($result['already_checked_in'] ? 'warning' : 'success')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Check-In')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function executeComplete(string $bookingId, PadelBookingService $service): void
    {
        if ($this->deniedWithout('checkin_padel_ticket')) {
            return;
        }

        try {
            $staffUser = auth()->user() ?? \App\Models\User::role(['admin', 'super_admin'])->first();
            $booking = $service->completeBooking($bookingId, $staffUser);

            Notification::make()
                ->title('Sesi Bermain Selesai')
                ->body("Sesi untuk tiket {$booking->booking_code} telah ditandai COMPLETED.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Menyelesaikan Sesi')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function executeReturnEquipment(string $bookingId, PadelBookingService $service): void
    {
        if ($this->deniedWithout('checkin_padel_ticket')) {
            return;
        }

        try {
            $staffUser = auth()->user() ?? \App\Models\User::role(['admin', 'super_admin'])->first();
            $result = $service->returnEquipment($bookingId, $staffUser);

            $summary = collect($result['items'])->map(fn ($i) => "{$i['quantity']}x {$i['name']}")->join(', ');

            Notification::make()
                ->title('Alat Sewa Dikembalikan')
                ->body("Tiket {$result['booking_code']}: {$summary} telah di-restock ke stok alat.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Memproses Retur Alat')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getViewData(): array
    {
        // REAKTIF FAIL-SAFE: Otomatis sinkronkan tiket kedaluwarsa & selesai setiap halaman dibuka
        app(PadelBookingService::class)->syncExpiredAndCompletedBookings();

        $query = PadelBooking::with(['user', 'court', 'order.payments', 'order.refunds', 'equipments.equipment', 'equipments.order:id,payment_status', 'recurringSchedule', 'coachingBooking.academy'])
            ->latest('start_time');

        // Pencarian (kode tiket / nama / HP …) mencakup semua periode — customer bisa datang membawa tiket lama.
        if (trim($this->search) === '') {
            $this->applyPeriod($query);
        }

        if ($this->activeTab === 'ALL') {
            $query->whereNotIn('status', self::INACTIVE_STATUSES);
        } elseif ($this->activeTab === 'CONFIRMED') {
            $query->where('status', 'PAID');
        } elseif ($this->activeTab === 'LOCKED_PENDING') {
            $query->where('status', 'LOCKED');
        } elseif ($this->activeTab === 'COMPLETED') {
            $query->whereIn('status', ['CHECKED_IN', 'COMPLETED']);
        } elseif ($this->activeTab === 'CANCELLED') {
            $query->whereIn('status', self::INACTIVE_STATUSES);
        }

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function ($q) use ($term) {
                $q->where('booking_code', 'like', "%{$term}%")
                    ->orWhereHas('user', function ($u) use ($term) {
                        $u->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    })
                    ->orWhereHas('court', function ($c) use ($term) {
                        $c->where('name', 'like', "%{$term}%");
                    });
            });
        }

        $bookings = $query->paginate($this->perPage);

        // Angka tab mengikuti periode yang dipilih. Dihitung langsung (query ber-index), tidak di-cache: kunci cache
        // global lama tidak bisa mewakili setiap periode.
        $counts = (function () {
            $rawCounts = $this->applyPeriod(DB::table('padel_bookings')->whereNull('deleted_at'))
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'PAID' THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN status = 'LOCKED' THEN 1 ELSE 0 END) as locked,
                    SUM(CASE WHEN status IN ('CHECKED_IN', 'COMPLETED') THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status IN ('CANCELLED', 'REFUND_PENDING', 'REFUNDED', 'EXPIRED') THEN 1 ELSE 0 END) as cancelled
                ")
                ->first();

            return [
                'ALL' => (int) ($rawCounts->total ?? 0) - (int) ($rawCounts->cancelled ?? 0),
                'CONFIRMED' => (int) ($rawCounts->confirmed ?? 0),
                'LOCKED_PENDING' => (int) ($rawCounts->locked ?? 0),
                'COMPLETED' => (int) ($rawCounts->completed ?? 0),
                'CANCELLED' => (int) ($rawCounts->cancelled ?? 0),
            ];
        })();

        $courts = Cache::remember('active_padel_courts_list', 120, function () {
            return PadelCourt::where('is_active', true)->ordered()->get();
        });

        return [
            'bookings' => $bookings,
            'counts' => $counts,
            'courts' => $courts,
        ];
    }
}
