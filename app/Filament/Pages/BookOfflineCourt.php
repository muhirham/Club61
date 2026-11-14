<?php

namespace App\Filament\Pages;

use App\Exceptions\SlotConflictException;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Support\PersonName;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

class BookOfflineCourt extends Page
{
    use \App\Livewire\Concerns\AutoPrintsReceipts;
    use \App\Livewire\Concerns\ClosesPosShift;
    use \App\Livewire\Concerns\PrintsDailySalesRecap;
    use \App\Livewire\Concerns\ResolvesPosCustomer;
    use \App\Livewire\Concerns\SettlesCoachingBills;
    use HasPageShield;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'POS Walk-In Booking';

    protected static string | UnitEnum | null $navigationGroup = 'Operasional Harian';

    protected static ?string $title = 'Walk-In Offline Booking & Frontdesk POS';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.book-offline-court';

    // Sesi Shift Kasir POS (100% Cashless — tidak ada modal kas fisik / blind cash count)
    public bool $showOpenShiftModal = false;
    public string $openingNotes = '';



    // Tanggal Booking Aktif
    public string $bookingDate;

    // Slot yang Dipilih Kasir: [$slotKey => ['court_id', 'court_name', 'start_time', 'end_time', 'time_label', 'price']]
    public array $selectedSlots = [];

    // Slot yang ditahan saat "Lanjut ke Pembayaran" (atas nama kasir, dipindah ke customer saat dibayar) — supaya kalau
    // slotnya keburu diambil customer online, kasir tahu SEBELUM customer membayar. Hanya diubah server.
    #[\Livewire\Attributes\Locked]
    public array $heldBookingIds = [];

    #[\Livewire\Attributes\Locked]
    public ?string $heldSignature = null;

    #[\Livewire\Attributes\Locked]
    public ?string $heldUntil = null;

    // Mode Pelanggan: 'quick_create' atau 'search'
    public string $customerMode = 'quick_create';

    public string $customerSearch = '';

    // Hasil pencarian customer — diisi lewat updatedCustomerSearch(), BUKAN di-query ulang di getViewData()
    // supaya query DB-nya cuma jalan pas teks pencarian beneran berubah, bukan di setiap render/klik lain.
    public array $searchResults = [];

    public ?string $selectedCustomerId = null;

    public ?string $selectedCustomerName = null;

    public ?string $selectedCustomerPhone = null;

    /** Modul 33: nomor HP yang diisi kasir untuk akun terpilih yang belum punya nomor (saveSelectedCustomerPhone). */
    public string $selectedCustomerNewPhone = '';

    public ?array $activeMembershipInfo = null;

    // Toggle kasir: pakai/tidak pakai benefit membership customer ini untuk transaksi sekarang.
    public bool $useMembershipBenefit = true;

    /** Voucher jam corporate customer (karyawan sponsor): ['organization_name', 'remaining_hours'] — null kalau tidak punya. */
    public ?array $sponsorVoucherInfo = null;

    /** Toggle kasir: pakai voucher jam corporate customer untuk transaksi ini. */
    public bool $useSponsorVoucherBenefit = true;

    private const VOUCHER_COMBINE_ERROR = 'Kode promo tidak bisa digabung dengan voucher jam corporate. Matikan voucher corporate kalau mau pakai kode promo ini.';

    // Form Walk-In Cepat
    public string $walkInName = '';

    public string $walkInPhone = '';

    public string $walkInEmail = '';

    // Add-On Sewa Alat: [$equipmentId => $quantity]
    public array $rentalQuantities = [];

    // Step Alur Terminal Kasir: 'selection' (Jadwal), 'payment' (Layar Bayar), 'receipt' (Struk)
    public string $posStep = 'selection';

    // Metode Pembayaran Kasir (100% Cashless): 'QRIS', 'DEBIT_CARD', 'CREDIT_CARD'
    public string $paymentMethod = 'QRIS';

    /** Voucher promo / voucher saldo customer (Modul 21). Potongannya selalu dihitung ulang server dari kode ini. */
    public string $voucherInput = '';

    public ?string $appliedVoucherCode = null;

    // Rincian Pembayaran Mesin EDC (Kartu Debit & Kredit)
    public string $edcTerminal = 'EDC_BCA'; // hanya EDC_BCA (PosPaymentProof::ACTIVE_EDC_TERMINALS)
    public string $edcCardType = 'DEBIT'; // DEBIT, CREDIT
    public string $edcCardNetwork = 'GPN'; // GPN, VISA, MASTERCARD, JCB, AMEX, LAINNYA
    public string $edcBank = 'BCA'; // BCA, MANDIRI, BNI, BRI, CIMB, PERMATA, DANAMON, OVERSEAS, LAINNYA
    public string $edcLast4 = '';
    public string $edcApprovalCode = '';
    public string $edcTraceNumber = '';

    // Rincian Pembayaran QRIS
    public string $qrisProvider = 'BCA_QRIS'; // BCA_QRIS, MANDIRI_QRIS, GOPAY_QRIS, LAINNYA
    public string $qrisRrn = '';
    public string $qrisSenderName = '';

    /** MIDTRANS = QR dinamis Midtrans tampil di layar (utama); MANUAL = QRIS statis + input RRN (cadangan). */
    public string $qrisMode = 'MIDTRANS';

    /** Metode "Bayar Otomatis" pilihan kasir (QRIS / VA yang dicentang "Tampil di Kasir"). Divalidasi ulang di server. */
    public string $posOnlineMethod = 'QRIS';

    /**
     * QR Midtrans yang sedang menunggu dibayar customer (popup QR + polling). Dikunci: id tagihan tidak boleh diganti
     * dari browser (bisa dipakai untuk membatalkan tagihan orang lain).
     */
    #[\Livewire\Attributes\Locked]
    public ?array $pendingQris = null;

    // Auto-Recovery Draf Transaksi POS
    public bool $hasPendingDraft = false;
    public ?array $pendingDraftSummary = null;

    // Opsi Software Auto Check-In
    public bool $isAutoCheckIn = false;


    /**
     * Tipe booking: REGULAR atau COACHING (Modul 24 Opsi B, izin book_coaching). Coaching = harga slot tarif setoran
     * coaching (CoachingPricingService), tanpa membership / voucher. Semua isian divalidasi ulang di server saat checkout.
     */
    public string $bookingType = 'REGULAR';

    public ?string $coachingCoachId = null;

    /** @var array<int, string> */
    public array $coachingExtraCoachIds = [];

    /** @var array<int, string> nama murid (maks. 2) */
    public array $coachingStudents = ['', ''];

    public string $coachingNotes = '';

    // Modal Sukses & Struk POS
    public bool $showSuccessModal = false;

    public ?array $completedOrderData = null;

    // Pelunasan selisih reschedule: kasir klik sel "Bayar" di grid (atau datang dari tautan di Kelola
    // Pemesanan), customer & nominal terisi otomatis, lalu dibayar lewat layar pembayaran yang SAMA dengan
    // walk-in biasa. Kelola Pemesanan hanya mencatat pilihan cara bayarnya.

    // #[Locked]: diisi server (startSettlement). Dulu bisa diubah dari browser — booking yang dilunasi bisa
    // diganti diam-diam, atau nominal yang tampil di layar diubah jadi Rp 0 sementara yang tercatat nominal penuh.
    #[\Livewire\Attributes\Locked]
    public ?array $settleBill = null;

    /** Dibuka dari tombol "Bayar di POS" di Kelola Pemesanan: /admin/book-offline-court?tagihan={bookingId} */
    #[\Livewire\Attributes\Url(as: 'tagihan')]
    public ?string $settleRequest = null;

    public function mount(): void
    {
        $this->bookingDate = now()->format('Y-m-d');
        $this->initializeEquipmentQuantities();
        $this->checkPendingDraft();
        // Bayar Otomatis yang masih menunggu (halaman sempat di-refresh / tertutup) → popup dilanjutkan.
        // Alat (tambah ke booking / jual / denda) dibayar di Kasir Pro Shop — Bayar Otomatisnya dilanjutkan di sana.
        $this->pendingQris = app(\App\Services\Pos\PosAutoPayment::class)->resumeFor('PADEL_FRONTDESK', 'WALK_IN_OFFLINE', auth()->id());

        if ($this->settleRequest) {
            $this->startSettlement($this->settleRequest);
        }
    }

    /** Sama persis dengan yang dihitung server saat pelunasan (tagihan MILIK booking ini). */
    protected function amountDueFor(PadelBooking $booking): float
    {
        return app(PadelBookingService::class)->amountDueForBooking($booking);
    }

    public function startSettlement(string $bookingId): void
    {
        $this->settleRequest = null;

        if (! auth()->user()?->can('settle_unpaid_booking')) {
            \App\Services\Audit\ActivityLogger::accessDenied('mencoba pelunasan tagihan tanpa izin [settle_unpaid_booking] di POS Walk-In');
            Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin [settle_unpaid_booking] untuk menerima pelunasan.')->danger()->send();

            return;
        }

        $booking = PadelBooking::with(['user', 'court', 'order.payments'])->find($bookingId);
        // Sesi tagihan berkala academy (Modul 31) dilunasi sekaligus per academy, bukan per slot.
        if ($booking && $booking->status === 'PENDING_PAYMENT' && \App\Services\Coaching\CoachingBillingService::isTabBooking($booking)) {
            $academy = $booking->coachingBooking?->academy?->name ?? 'academy';
            Notification::make()->title('Tagihan Berkala Academy')
                ->body("Sesi {$booking->booking_code} tercatat pada tagihan berkala {$academy}. Pelunasan dilakukan sekaligus melalui Pelunasan Tagihan Coaching.")
                ->info()->send();

            return;
        }
        // Kasir hanya melunasi selisih reschedule. Booking online yang belum dibayar wajib dibayar online —
        // customer yang mau bayar di kasir dibuatkan booking walk-in baru (supaya slot tidak bisa ditahan tanpa bayar).
        if ($booking && (in_array($booking->status, ['PENDING_PAYMENT', 'PENDING'], true) || ($booking->status === 'LOCKED' && (int) $booking->reschedule_count < 1))) {
            Notification::make()->title('Dibayar Online Saja')
                ->body("Booking {$booking->booking_code} dibuat online dan belum dibayar — customer menyelesaikannya lewat pembayaran online. Kalau customer mau bayar di kasir, buat booking walk-in baru.")
                ->warning()->send();

            return;
        }
        if (! $booking || $booking->status !== 'LOCKED') {
            Notification::make()->title('Tidak Ada Tagihan')->body('Booking ini tidak punya tagihan yang perlu dilunasi.')->info()->send();

            return;
        }

        // Customer sudah / sedang bayar online? Jangan sampai ditagih dua kali.
        $order = $booking->order_id ? \App\Models\Pos\Order::find($booking->order_id) : null;
        $reconciler = app(\App\Services\Payment\OnlineGatewayRouter::class);
        if ($order && $reconciler->hasOnlinePaymentAttempt($order)) {
            $online = $reconciler->reconcileOrder($order);
            $gatewayName = $reconciler->gatewayLabel($order);
            if ($online === \App\Services\Payment\OnlineGatewayRouter::PAID) {
                Notification::make()->title('Sudah Dibayar Online')
                    ->body("Customer sudah membayar tagihan {$booking->booking_code} via {$gatewayName}. Tiket otomatis aktif — jangan terima pembayaran lagi.")
                    ->success()->persistent()->send();

                return;
            }
            if ($online === \App\Services\Payment\OnlineGatewayRouter::PENDING) {
                Notification::make()->title('Customer Sedang Bayar Online')
                    ->body("{$gatewayName} masih menunggu pembayaran customer. Minta customer menyelesaikan atau membatalkan pembayaran online-nya dulu supaya tidak ditagih dua kali.")
                    ->warning()->persistent()->send();

                return;
            }
        }

        $amount = $this->amountDueFor($booking->fresh(['order.payments']));
        if ($amount <= 0) {
            Notification::make()->title('Tidak Ada Tagihan')->body('Tagihan booking ini sudah lunas.')->info()->send();

            return;
        }

        $pending = app(PadelBookingService::class)->pendingBillForBooking($booking);
        $this->settleBill = [
            'booking_id' => $booking->id,
            'code' => $booking->booking_code,
            'customer' => $booking->user?->name ?? 'Customer',
            'phone' => $booking->user?->phone,
            'court' => $booking->court?->name ?? '-',
            'schedule' => $booking->start_time->format('d M Y, H:i').' - '.$booking->end_time->format('H:i').' WIB',
            'type' => 'Selisih Reschedule',
            'schedule_before' => $pending?->payload_log['schedule_before'] ?? null,
            'amount' => $amount,
        ];

        // Keranjang dikosongkan & customer otomatis diambil dari booking — kasir tidak perlu mengetik ulang.
        $this->releaseHeldSlots();
        $this->selectedSlots = [];
        $this->initializeEquipmentQuantities();
        foreach (array_keys($this->rentalQuantities) as $equipmentId) {
            $this->rentalQuantities[$equipmentId] = 0;
        }
        $this->customerMode = 'search';
        $this->selectedCustomerId = $booking->user_id;
        $this->selectedCustomerName = $booking->user?->name;
        $this->selectedCustomerPhone = $booking->user?->phone;
        // Pelunasan tagihan: benefit sudah dihitung saat booking dibuat — jangan dipotong lagi.
        $this->loadBenefitInfo(null);
        $this->bookingDate = $booking->booking_date->format('Y-m-d');

        $this->paymentMethod = 'QRIS';
        $this->edcLast4 = '';
        $this->edcApprovalCode = '';
        $this->edcTraceNumber = '';
        $this->qrisRrn = '';
        $this->qrisSenderName = '';
        $this->posStep = 'selection';
    }

    public function cancelSettlement(): void
    {
        $this->settleBill = null;
        $this->selectedCustomerId = null;
        $this->selectedCustomerName = null;
        $this->selectedCustomerPhone = null;
        $this->customerMode = 'quick_create';
        $this->loadBenefitInfo(null);
        $this->posStep = 'selection';
    }

    public function submitSettlement(PadelBookingService $service): void
    {
        if (! $this->settleBill) {
            return;
        }

        if (! auth()->user()?->can('settle_unpaid_booking')) {
            \App\Services\Audit\ActivityLogger::accessDenied('mencoba pelunasan tagihan tanpa izin [settle_unpaid_booking] di POS Walk-In');
            Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin [settle_unpaid_booking] untuk menerima pelunasan.')->danger()->send();

            return;
        }

        [$method, $proof] = \App\Services\Pos\PosPaymentProof::fromPosForm(
            $this->paymentMethod, $this->edcTerminal, $this->edcCardType, $this->edcLast4, $this->edcApprovalCode,
            $this->edcTraceNumber, $this->qrisProvider, $this->qrisRrn, $this->qrisSenderName, $this->edcCardNetwork, $this->edcBank,
        );

        $bookingForBill = PadelBooking::with('order')->find($this->settleBill['booking_id'] ?? null);
        if (! $bookingForBill) {
            $this->cancelSettlement();

            return;
        }
        $pendingPaymentId = $service->pendingBillForBooking($bookingForBill)?->id;

        // Tagihan berubah sejak layar dibuka (dibayar online, dibatalkan, di-reschedule)? Jangan lanjut menagih.
        $currentDue = $service->amountDueForBooking($bookingForBill);
        if (abs($currentDue - (float) $this->settleBill['amount']) > 1) {
            Notification::make()->title('Tagihan Berubah')
                ->body('Nominal tagihan '.$this->settleBill['code'].' sekarang Rp '.number_format($currentDue, 0, ',', '.').'. Buka ulang tagihannya dari grid sebelum menerima pembayaran.')
                ->warning()->persistent()->send();
            $this->cancelSettlement();

            return;
        }

        try {
            // Nominal yang tampil di layar ikut dikirim: kalau tagihan berubah sejak layar dibuka, server menolak.
            $result = $service->adminSettleCashierPayment(
                bookingId: $this->settleBill['booking_id'],
                paymentMethod: $method,
                amountReceived: (float) $this->settleBill['amount'],
                cashierUser: auth()->user(),
                paymentProof: $proof,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\App\Exceptions\SlotConflictException $e) {
            Notification::make()->title('Pelunasan Gagal')->body($e->getMessage())->danger()->persistent()->send();

            return;
        } catch (\Throwable $e) {
            // Error teknis (SQL dsb.) tidak ditampilkan mentah ke kasir.
            report($e);
            Notification::make()->title('Pelunasan Gagal')->body('Terjadi kesalahan sistem. Pembayaran TIDAK tercatat — jangan anggap lunas, hubungi admin.')->danger()->persistent()->send();

            return;
        }

        Cache::forget('kelola_pemesanan_tab_counts');

        if (in_array($result['settled_via'] ?? null, ['MIDTRANS', 'DOKU'], true)) {
            Notification::make()->title('Sudah Dibayar Online')->body($result['message'])->warning()->persistent()->send();
        } else {
            Notification::make()->title('Pelunasan Berhasil')
                ->body("Tagihan {$this->settleBill['code']} Rp ".number_format($this->settleBill['amount'], 0, ',', '.').' lunas. QR tiket aktif, customer bisa check-in.')
                ->success()->send();

            // Struk pelunasan langsung tampil & bisa dicetak (sama seperti transaksi walk-in biasa).
            // Ambil PERSIS tagihan yang barusan dilunasi (bukan "pembayaran terbaru" — bisa sama detiknya
            // dengan pembayaran awal order).
            $payment = $pendingPaymentId
                ? \App\Models\Pos\Payment::whereKey($pendingPaymentId)->where('status', 'SUCCESS')->first()
                : null;
            $payment ??= \App\Models\Pos\Payment::where('order_id', PadelBooking::whereKey($this->settleBill['booking_id'])->value('order_id'))
                ->where('status', 'SUCCESS')->where('payment_gateway', 'CASHIER_POS')->latest()->orderByDesc('id')->first();
            if ($payment) {
                $receipt = $this->buildWalkInReceipt($payment);
                $this->cancelSettlement();
                $this->completedOrderData = $receipt;
                $this->showSuccessModal = true;
                // Struk langsung dicetak di aplikasi Club61 — sama dengan Bayar Otomatis (semua metode, satu alur).
                $this->queueAutoPrint($payment->order_id, 'filament.partials.walkin-receipt', ['receipt' => $receipt], 'closeSuccessModal');

                return;
            }
        }

        $this->cancelSettlement();
    }

    protected function initializeEquipmentQuantities(): void
    {
        $equipments = CourtEquipment::where('is_active', true)->get();
        foreach ($equipments as $eq) {
            if (! isset($this->rentalQuantities[$eq->id])) {
                $this->rentalQuantities[$eq->id] = 0;
            }
        }
    }

    public function setDate(string $date): void
    {
        $this->releaseHeldSlots();
        $this->bookingDate = $date;
        $this->selectedSlots = [];
    }

    public function prevDay(): void
    {
        $this->releaseHeldSlots();
        $this->bookingDate = Carbon::parse($this->bookingDate)->subDay()->format('Y-m-d');
        $this->selectedSlots = [];
    }

    /**
     * Tanggal lampau tetap bisa dibuka untuk melihat siapa yang booking, tapi slotnya tidak bisa dipilih
     * (begitu juga jam yang sudah lewat hari ini).
     */
    protected function isPastSlot(string $startTime): bool
    {
        $today = now()->format('Y-m-d');

        return $this->bookingDate < $today
            || ($this->bookingDate === $today && (int) substr($startTime, 0, 2) < (int) now()->format('H'));
    }

    public function nextDay(): void
    {
        $this->releaseHeldSlots();
        $this->bookingDate = Carbon::parse($this->bookingDate)->addDay()->format('Y-m-d');
        $this->selectedSlots = [];
    }

    public function today(): void
    {
        $this->releaseHeldSlots();
        $this->bookingDate = now()->format('Y-m-d');
        $this->selectedSlots = [];
    }

    public function toggleSlot(string $courtId, string $courtName, string $startTime, string $endTime, float $price): void
    {
        if ($this->settleBill) {
            $this->cancelSettlement();
        }
        // Pilihan jam berubah → slot yang tadi ditahan dilepas (ditahan lagi saat "Lanjut ke Pembayaran").
        $this->releaseHeldSlots();

        $slotKey = "{$courtId}_{$startTime}";

        if (isset($this->selectedSlots[$slotKey])) {
            unset($this->selectedSlots[$slotKey]);
        } else {
            if ($this->isPastSlot($startTime)) {
                Notification::make()
                    ->title('Jadwal Sudah Lewat')
                    ->body('Slot di jam atau tanggal yang sudah lewat tidak bisa dibooking.')
                    ->warning()
                    ->send();

                return;
            }

            if ($reason = \App\Support\BookingWindow::blockedReason($this->bookingDate)) {
                Notification::make()->title('Tanggal Tidak Bisa Dibooking')->body($reason)->warning()->send();

                return;
            }
            if ($reason = \App\Support\BookingWindow::closedHoursReason($this->bookingDate, $startTime, $endTime)) {
                Notification::make()->title('Jam Tidak Bisa Dibooking')->body($reason)->warning()->send();

                return;
            }

            $court = PadelCourt::find($courtId);
            if ($court) {
                $cOpen = (int) substr($court->open_time ?: '06:00', 0, 2);
                $cCloseVal = $court->close_time ?: '23:00';
                $cClose = ($cCloseVal === '00:00' || $cCloseVal === '24:00') ? 24 : (int) substr($cCloseVal, 0, 2);
                $slotH = (int) substr($startTime, 0, 2);

                if ($slotH < $cOpen || $slotH >= $cClose) {
                    Notification::make()
                        ->title('Lapangan Tutup')
                        ->body("Jam {$startTime} berada di luar jam operasional {$court->name} ({$court->open_time} - {$court->close_time} WIB).")
                        ->warning()
                        ->send();
                    return;
                }
            }

            $this->selectedSlots[$slotKey] = [
                'court_id' => $courtId,
                'court_name' => $courtName,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'time_label' => substr($startTime, 0, 5) . ' - ' . substr($endTime, 0, 5),
                'price' => $price,
            ];
        }

        if (empty($this->selectedSlots)) {
            Cache::forget($this->getDraftCacheKey());
            $this->hasPendingDraft = false;
            $this->pendingDraftSummary = null;
        } else {
            $this->saveDraft();
        }
    }

    public function removeSlot(string $slotKey): void
    {
        $this->releaseHeldSlots();
        unset($this->selectedSlots[$slotKey]);

        if (empty($this->selectedSlots)) {
            Cache::forget($this->getDraftCacheKey());
            $this->hasPendingDraft = false;
            $this->pendingDraftSummary = null;
        } else {
            $this->saveDraft();
        }
    }

    public function clearSelectedSlots(): void
    {
        $this->releaseHeldSlots();
        $this->selectedSlots = [];
        Cache::forget($this->getDraftCacheKey());
        $this->hasPendingDraft = false;
        $this->pendingDraftSummary = null;
    }

    public function incrementEquipment(string $equipmentId, int $maxStock): void
    {
        $current = $this->rentalQuantities[$equipmentId] ?? 0;
        if ($current < $maxStock) {
            $this->rentalQuantities[$equipmentId] = $current + 1;
            $this->saveDraft();
        }
    }

    public function decrementEquipment(string $equipmentId): void
    {
        $current = $this->rentalQuantities[$equipmentId] ?? 0;
        if ($current > 0) {
            $this->rentalQuantities[$equipmentId] = $current - 1;
            $this->saveDraft();
        }
    }

    public function setCustomerMode(string $mode): void
    {
        // Tab ketiga "Coaching" (sederet dengan Walk-In Baru / Cari Akun): pemesan diisi seperti Walk-In Baru
        // (nomor HP lama tetap dikenali), harga memakai tarif coaching.
        if ($mode === 'coaching') {
            if (! $this->canBookCoaching) {
                Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin membuat booking coaching.')->danger()->send();

                return;
            }
            $this->customerMode = 'quick_create';
            $this->setBookingType('COACHING');

            return;
        }

        if ($this->bookingType === 'COACHING') {
            $this->bookingType = 'REGULAR';
            $this->resetPriceCaches();
        }
        $this->customerMode = $mode === 'search' ? 'search' : 'quick_create';
        $this->loadBenefitInfo($this->benefitCustomer());
        $this->saveDraft();
    }

    public function selectCustomer(string $userId): void
    {
        $user = User::find($userId);
        if ($user) {
            $this->selectedCustomerId = $user->id;
            $this->selectedCustomerName = $user->name;
            $this->selectedCustomerPhone = $user->phone;
            $this->selectedCustomerNewPhone = '';
            $this->customerSearch = '';

            $this->loadBenefitInfo($user);

            $this->saveDraft();
        }
    }

    public function toggleMembershipBenefit(): void
    {
        $this->useMembershipBenefit = ! $this->useMembershipBenefit;
        $this->saveDraft();
    }

    public function clearSelectedCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->selectedCustomerName = null;
        $this->selectedCustomerPhone = null;
        $this->selectedCustomerNewPhone = '';
        $this->loadBenefitInfo(null);
        $this->customerSearch = '';
        $this->saveDraft();
    }

    public function toggleSponsorVoucherBenefit(): void
    {
        $this->useSponsorVoucherBenefit = ! $this->useSponsorVoucherBenefit;
        $this->benefitQuoteCache = null;
        $this->voucherResultCache = null;
        $this->financeCalculationCache = null;
        $this->subtotalCache = null;

        // Voucher corporate dinyalakan saat kode promo terpasang → kode promonya dilepas (1 transaksi 1 voucher).
        if ($this->useSponsorVoucherBenefit && $this->appliedVoucherCode && $this->voucherResult['error'] === self::VOUCHER_COMBINE_ERROR) {
            $code = $this->appliedVoucherCode;
            $this->removeVoucher();
            $this->voucherResultCache = null;
            $this->financeCalculationCache = null;
            Notification::make()->title('Kode Promo Dilepas')->body("{$code} dilepas — kode promo tidak bisa digabung dengan voucher jam corporate.")->warning()->send();
        }

        $this->saveDraft();
    }

    /**
     * Benefit customer yang tampil di layar kasir: kartu membership aktif (paling cepat kedaluwarsa dulu, sama
     * dengan auto-detect checkout) dan voucher jam corporate (karyawan sponsor). Toggle kembali ON setiap ganti customer.
     */
    protected function loadBenefitInfo(?User $user): void
    {
        $this->useMembershipBenefit = true;
        $this->useSponsorVoucherBenefit = true;
        $this->activeMembershipInfo = null;
        $this->sponsorVoucherInfo = null;
        $this->benefitQuoteCache = null;

        if (! $user) {
            return;
        }

        $activeMbr = \App\Models\Membership\UserMembership::with(['plan', 'balances'])
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderByRaw('end_date IS NULL, end_date ASC')
            ->first();

        if ($activeMbr) {
            $padelBalance = $activeMbr->balanceFor('PADEL');
            $this->activeMembershipInfo = [
                'membership_code' => $activeMbr->membership_code,
                'plan_name' => $activeMbr->plan?->name ?? 'Membership',
                'facility' => 'PADEL',
                'quota_type' => $padelBalance?->quota_type ?? 'NONE',
                'remaining_quota' => $padelBalance ? (float) $padelBalance->remaining_quota : 0.00,
                'discount_percent' => $padelBalance ? (float) $padelBalance->discount_percent : 0.00,
                'balance_id' => $padelBalance?->id,
            ];
        }

        // Modul 34: PIC perusahaan Tagihan Bulanan → sewa lapangan masuk tagihan bulanan (dicek ulang di server).
        if ($monthly = \App\Services\Membership\MonthlyBilling::cardForBooker($user)) {
            $this->activeMembershipInfo = [
                'membership_code' => $monthly->membership?->membership_code,
                'plan_name' => 'Tagihan Bulanan '.\App\Services\Membership\MonthlyBilling::billedName($monthly->membership),
                'facility' => 'PADEL',
                'quota_type' => \App\Services\Membership\MonthlyBilling::METHOD,
                'remaining_quota' => 0.00,
                'discount_percent' => 0.00,
                'balance_id' => $monthly->id,
            ];
        }

        $member = \App\Models\Sponsor\SponsorOrganizationMember::with('organization')
            ->where('user_id', $user->id)->where('status', 'ACTIVE')->first();
        if ($member) {
            $hours = (float) \App\Models\Sponsor\SponsorMemberVoucher::where('sponsor_organization_member_id', $member->id)
                ->where('expires_at', '>', now())->get()->sum(fn ($v) => $v->remainingHours());
            if ($hours > 0) {
                $this->sponsorVoucherInfo = [
                    'organization_name' => $member->organization->name ?? 'Corporate',
                    'remaining_hours' => round($hours, 2),
                ];
            }
        }
    }

    /**
     * Customer yang benefitnya dihitung di layar: dipilih lewat "Cari Akun", atau — di mode Walk-In Baru — akun
     * lama dengan nomor HP yang sama (server memakai akun itu juga saat checkout, lihat findOrCreateWalkInCustomer()).
     */
    protected function benefitCustomer(): ?User
    {
        if ($this->customerMode === 'search') {
            return $this->selectedCustomerId ? User::find($this->selectedCustomerId) : null;
        }

        // Bukan nomor HP ("" / < 8 digit) → tidak dicari sama sekali. Nomor sah = 1 query lewat index users(phone).
        $phone = app(PadelBookingService::class)->normalizePhoneNumber((string) $this->walkInPhone);

        return $phone === '' ? null : User::where('phone', $phone)->first();
    }

    public function updatedWalkInName(): void
    {
        $this->saveDraft();
    }

    public function updatedWalkInPhone(): void
    {
        // Nomor HP akun lama (mis. karyawan sponsor / member) → benefitnya langsung tampil & dihitung.
        $this->loadBenefitInfo($this->benefitCustomer());
        $this->saveDraft();
    }


    public function updatedWalkInEmail(): void
    {
        $this->saveDraft();
    }

    public function updatedCustomerSearch(): void
    {
        $term = trim($this->customerSearch);

        if (strlen($term) < 2) {
            $this->searchResults = [];
            return;
        }

        $this->searchResults = User::where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        })
            ->limit(5)
            ->get()
            ->all();
    }

    // Memoisasi per-request buat rantai kalkulasi harga (courtTotal -> membershipDiscountAmount ->
    // equipmentTotal -> subtotal -> financeCalculation -> grandTotal/taxAmount/adminFeeAmount/dst).
    // Properti Filament Page pakai magic __get biasa (BUKAN #[Computed] Livewire yang otomatis
    // di-memoize) — tanpa cache manual ini, tiap `$this->grandTotal` dipanggil di Blade akan
    // mengeksekusi ULANG seluruh rantai dari nol, termasuk query DB di getEquipmentTotalProperty()
    // dan getFinanceCalculationProperty(). Karena properti-properti cache ini `protected` (bukan
    // `public`), Livewire TIDAK menyertakannya saat hydrate/dehydrate antar request — otomatis
    // "kosong lagi" di setiap request baru, jadi tidak ada resiko data basi nyangkut ke transaksi lain.
    protected ?float $courtTotalCache = null;
    protected ?float $equipmentTotalCache = null;
    protected ?float $membershipDiscountAmountCache = null;
    protected ?float $subtotalCache = null;
    protected ?array $financeCalculationCache = null;
    protected ?array $voucherResultCache = null;
    protected ?array $benefitQuoteCache = null;

    public function getCourtTotalProperty(): float
    {
        if ($this->isCoaching) {
            return $this->courtTotalCache ??= (float) $this->coachingQuote['total'];
        }

        return $this->courtTotalCache ??= (float) array_sum(array_column($this->selectedSlots, 'price'));
    }

    // ===================== COACHING (Modul 24 Opsi B) =====================

    protected ?array $coachingQuoteCache = null;

    public function getCanBookCoachingProperty(): bool
    {
        return (bool) auth()->user()?->can('book_coaching');
    }

    /** Mode coaching aktif — selalu dicek ulang izinnya (properti bisa diubah dari browser). */
    public function getIsCoachingProperty(): bool
    {
        return $this->bookingType === 'COACHING' && ! $this->settleBill && $this->canBookCoaching;
    }

    /** @return array<string, string> coach aktif: id => "Nama (Head Coach · Academy)" */
    public function getCoachOptionsProperty(): array
    {
        if (! $this->canBookCoaching) {
            return [];
        }

        return $this->coachOptionsCache ??= \App\Models\Coaching\Coach::with('academy:id,name')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (\App\Models\Coaching\Coach $c) => [$c->id => $c->optionLabel()])->all();
    }

    protected ?array $coachOptionsCache = null;

    /** Coach tambahan yang sah (aktif, bukan coach utama). Server memvalidasi ulang saat checkout. */
    protected function coachingExtraIds(): array
    {
        $valid = array_keys($this->coachOptions);

        return array_values(array_unique(array_filter(
            array_map('strval', $this->coachingExtraCoachIds),
            fn ($id) => $id !== '' && $id !== $this->coachingCoachId && in_array($id, $valid, true)
        )));
    }

    /**
     * Harga coaching slot terpilih — fungsi yang SAMA dengan checkout server (CoachingPricingService).
     *
     * @return array{total: float, error: ?string, slots: array<string, float>} slots: "court_id|H:i" => harga
     */
    public function getCoachingQuoteProperty(): array
    {
        if ($this->coachingQuoteCache !== null) {
            return $this->coachingQuoteCache;
        }
        if (empty($this->selectedSlots)) {
            return $this->coachingQuoteCache = ['total' => 0.0, 'error' => null, 'slots' => []];
        }

        try {
            $quote = app(\App\Services\Coaching\CoachingPricingService::class)
                ->quoteSlots($this->slotsPayload(), $this->bookingDate, count($this->coachingExtraIds()));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->coachingQuoteCache = ['total' => 0.0, 'error' => $e->getMessage(), 'slots' => []];
        }

        $slots = [];
        foreach ($quote['slots'] as $slot) {
            $slots[$slot['court_id'].'|'.substr($slot['start_time'], 0, 5)] = $slot['fee'];
        }

        return $this->coachingQuoteCache = ['total' => $quote['total'], 'error' => null, 'slots' => $slots];
    }

    /** Harga slot yang tampil di daftar slot: tarif coaching (mode coaching) atau harga reguler. */
    public function slotDisplayPrice(array $slot): float
    {
        if (! $this->isCoaching) {
            return (float) $slot['price'];
        }

        return (float) ($this->coachingQuote['slots'][$slot['court_id'].'|'.substr($slot['start_time'], 0, 5)] ?? 0);
    }

    public function setBookingType(string $type): void
    {
        $type = strtoupper($type) === 'COACHING' ? 'COACHING' : 'REGULAR';
        if ($type === 'COACHING' && ! $this->canBookCoaching) {
            Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin membuat booking coaching.')->danger()->send();

            return;
        }

        $this->bookingType = $type;
        // Coaching memakai tarif khusus: voucher promo dilepas; membership / voucher corporate otomatis tidak dipakai.
        if ($type === 'COACHING') {
            $this->removeVoucher();
        }
        $this->resetPriceCaches();
        $this->saveDraft();
    }

    public function updatedCoachingCoachId(): void
    {
        $this->resetPriceCaches();
        $this->saveDraft();
    }

    public function updatedCoachingExtraCoachIds(): void
    {
        $this->resetPriceCaches();
        $this->saveDraft();
    }

    /**
     * Modul 31: academy coach utama menggunakan Tagihan Berkala → booking dicatat ke tagihan (tanpa pembayaran saat ini).
     * Hanya untuk tampilan; server menentukan ulang dari pengaturan academy saat checkout.
     */
    public function getCoachingTabAcademyProperty(): ?\App\Models\Coaching\CoachingAcademy
    {
        if (! $this->isCoaching || ! $this->coachingCoachId || ! array_key_exists($this->coachingCoachId, $this->coachOptions)) {
            return null;
        }
        $academy = \App\Models\Coaching\Coach::with('academy')->find($this->coachingCoachId)?->academy;

        return $academy?->usesTab() ? $academy : null;
    }

    /** @return array{sessions: int, amount: float} tagihan academy yang lewat jatuh tempo (peringatan ke resepsionis) */
    public function getCoachingTabOverdueProperty(): array
    {
        $academy = $this->coachingTabAcademy;

        return $academy ? app(\App\Services\Coaching\CoachingBillingService::class)->overdue($academy) : ['sessions' => 0, 'amount' => 0.0];
    }

    protected function resetPriceCaches(): void
    {
        $this->courtTotalCache = null;
        $this->coachingQuoteCache = null;
        $this->benefitQuoteCache = null;
        $this->voucherResultCache = null;
        $this->financeCalculationCache = null;
        $this->subtotalCache = null;
        $this->membershipDiscountAmountCache = null;
    }

    /** Data coaching untuk checkout server (null = booking reguler). */
    protected function coachingPayload(): ?array
    {
        if (! $this->isCoaching) {
            return null;
        }

        return [
            'head_coach_id' => (string) $this->coachingCoachId,
            'extra_coach_ids' => $this->coachingExtraIds(),
            'students' => array_slice(array_values((array) $this->coachingStudents), 0, \App\Models\Coaching\CoachingBooking::MAX_STUDENTS),
            'notes' => $this->coachingNotes,
        ];
    }

    /** Isian coaching lengkap & tarifnya ada; kalau tidak → pemberitahuan ke kasir. */
    protected function assertCoachingReady(): bool
    {
        if (! $this->coachingCoachId || ! array_key_exists($this->coachingCoachId, $this->coachOptions)) {
            Notification::make()->title('Coach Belum Dipilih')->body('Pilih coach utama untuk booking coaching.')->warning()->send();

            return false;
        }
        if ($error = $this->coachingQuote['error']) {
            Notification::make()->title('Tarif Coaching Belum Ada')->body($error)->warning()->send();

            return false;
        }

        return true;
    }

    protected function resetCoaching(): void
    {
        $this->bookingType = 'REGULAR';
        $this->coachingCoachId = null;
        $this->coachingExtraCoachIds = [];
        $this->coachingStudents = ['', ''];
        $this->coachingNotes = '';
        $this->coachingQuoteCache = null;
    }

    public function getEquipmentTotalProperty(): float
    {
        if ($this->equipmentTotalCache !== null) {
            return $this->equipmentTotalCache;
        }

        $selectedIds = array_keys(array_filter($this->rentalQuantities, fn ($qty) => $qty > 0));
        if (empty($selectedIds)) {
            return $this->equipmentTotalCache = 0.0;
        }

        // Satu query batch (bukan find() di dalam loop) — menghindari N+1 per macam alat yang disewa.
        $equipmentsById = CourtEquipment::whereIn('id', $selectedIds)->where('is_active', true)->get()->keyBy('id');

        $total = 0.0;
        foreach ($this->rentalQuantities as $eqId => $qty) {
            if ($qty > 0 && isset($equipmentsById[$eqId])) {
                $total += ((float) $equipmentsById[$eqId]->rental_price * $qty);
            }
        }

        return $this->equipmentTotalCache = $total;
    }

    /**
     * Preview (read-only) potongan membership terhadap slot yang lagi dipilih — dihitung pakai aturan
     * yang SAMA persis dengan ManagesCheckoutAndPayments::applyMembershipBenefitToCourtBookings() (kuota
     * jam menutup penuh biaya lapangan sampai kuota habis, sisanya/kalau NONE pakai diskon persen flat)
     * supaya angka yang keliatan di layar kasir gak pernah beda sama yang beneran dipotong pas checkout.
     */
    public function getMembershipDiscountAmountProperty(): float
    {
        return $this->membershipDiscountAmountCache ??= min((float) $this->benefitQuote['membership_discount'], $this->courtTotal);
    }

    /** Potongan voucher jam corporate (karyawan sponsor) atas sisa sewa lapangan setelah membership. */
    public function getSponsorDiscountAmountProperty(): float
    {
        return min((float) $this->benefitQuote['sponsor_discount'], max(0, $this->courtTotal - $this->membershipDiscountAmount));
    }

    /**
     * Rincian potongan benefit per booking (jam berurutan digabung) dari PadelBookingService::quoteWalkInBenefits() —
     * fungsi yang sama dipakai pola potong checkout, jadi angka layar = angka yang dicatat server.
     */
    public function getBenefitQuoteProperty(): array
    {
        if ($this->benefitQuoteCache !== null) {
            return $this->benefitQuoteCache;
        }

        $empty = ['lines' => [], 'membership_discount' => 0.0, 'sponsor_discount' => 0.0,
            'membership' => ['applies' => false, 'balance_id' => null, 'rejected_reason' => null, 'hours' => 0.0, 'plan_name' => null],
            'sponsor' => ['applies' => false, 'organization_name' => null, 'hours' => 0.0, 'hours_left' => 0.0, 'hours_available' => 0.0]];

        // Coaching: tarif khusus, kuota membership & voucher jam corporate tidak dipakai (sama dengan server).
        $customer = $this->settleBill || $this->isCoaching || empty($this->selectedSlots) ? null : $this->benefitCustomer();
        if (! $customer) {
            return $this->benefitQuoteCache = $empty;
        }

        return $this->benefitQuoteCache = app(PadelBookingService::class)->quoteWalkInBenefits(
            array_values(array_map(fn ($slot) => [
                'court_id' => $slot['court_id'], 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time'],
            ], $this->selectedSlots)),
            $this->bookingDate,
            $customer,
            $this->useMembershipBenefit ? ($this->activeMembershipInfo['balance_id'] ?? null) : 'NONE',
            $this->useSponsorVoucherBenefit ? null : 'NONE',
        );
    }

    public function getSubtotalProperty(): float
    {
        return $this->subtotalCache ??= max(0, $this->courtTotal - $this->membershipDiscountAmount - $this->sponsorDiscountAmount) + $this->equipmentTotal;
    }

    public function getFinanceCalculationProperty(): array
    {
        return $this->financeCalculationCache ??= app(\App\Services\Finance\TaxAndFeeService::class)->calculate(
            subtotal: $this->subtotal,
            discountAmount: $this->voucherDiscount,
            channel: 'POS_WALKIN',
            module: 'PADEL'
        );
    }

    /** Hasil cek voucher terpasang terhadap customer & subtotal saat ini (aturan sama dengan checkout). */
    public function getVoucherResultProperty(): array
    {
        if ($this->voucherResultCache !== null) {
            return $this->voucherResultCache;
        }
        if (! $this->appliedVoucherCode || $this->settleBill || $this->isCoaching) {
            return $this->voucherResultCache = ['voucher' => null, 'discount' => 0.0, 'error' => null];
        }

        $customer = $this->selectedCustomerId ? User::find($this->selectedCustomerId) : null;

        $courtAfterBenefits = max(0, $this->courtTotal - $this->membershipDiscountAmount - $this->sponsorDiscountAmount);
        $result = app(\App\Services\Finance\VoucherService::class)
            ->resolve($this->appliedVoucherCode, $customer, $courtAfterBenefits + $this->equipmentTotal, context: [
                'channel' => \App\Models\Pos\Voucher::CHANNEL_POS,
                'amounts' => ['PADEL' => $courtAfterBenefits, 'PADEL_ADDON' => (float) $this->equipmentTotal],
                'membership_applied' => $this->membershipDiscountAmount > 0,
            ]);

        // 1 transaksi 1 voucher (sama dengan server): kode promo tidak bisa digabung voucher jam corporate. Voucher saldo boleh.
        if ($result['voucher'] && ! $result['voucher']->isCredit() && $this->sponsorDiscountAmount > 0) {
            $result = ['voucher' => null, 'discount' => 0.0, 'error' => self::VOUCHER_COMBINE_ERROR];
        }

        return $this->voucherResultCache = $result;
    }

    public function getVoucherDiscountProperty(): float
    {
        return (float) $this->voucherResult['discount'];
    }

    /** Voucher saldo milik customer terpilih yang masih bisa dipakai. */
    public function getCustomerCreditVouchersProperty(): \Illuminate\Support\Collection
    {
        $customer = $this->selectedCustomerId ? User::find($this->selectedCustomerId) : null;

        return $customer ? app(\App\Services\Finance\VoucherService::class)->walletFor($customer) : collect();
    }

    /** Promo hasil klaim (cara sebar C) milik customer terpilih yang bisa dipakai sekarang — aturan tetap dicek resolve(). */
    public function getCustomerClaimedPromosProperty(): array
    {
        $customer = $this->selectedCustomerId ? User::find($this->selectedCustomerId) : null;

        return $customer ? app(\App\Services\Finance\VoucherClaimService::class)->usableForApi($customer) : [];
    }

    public function applyVoucher(?string $code = null): void
    {
        $code = strtoupper(trim((string) ($code ?? $this->voucherInput)));
        if ($code === '') {
            return;
        }
        if ($this->isCoaching) {
            Notification::make()->title('Voucher Tidak Berlaku')->body('Booking coaching sudah memakai tarif khusus — voucher tidak bisa dipakai.')->warning()->send();

            return;
        }

        $this->appliedVoucherCode = mb_substr($code, 0, 30);
        $this->voucherInput = $this->appliedVoucherCode;
        $this->voucherResultCache = null;
        $this->financeCalculationCache = null;

        if ($error = $this->voucherResult['error']) {
            $this->appliedVoucherCode = null;
            Notification::make()->title('Voucher Tidak Bisa Dipakai')->body($error)->warning()->send();
        }
    }

    public function removeVoucher(): void
    {
        $this->appliedVoucherCode = null;
        $this->voucherInput = '';
    }

    public function getTaxAmountProperty(): int
    {
        return $this->financeCalculation['tax_amount'];
    }

    public function getTaxNameProperty(): string
    {
        return $this->financeCalculation['tax_name'] ?: 'PB1 Pajak Daerah / PPh';
    }

    public function getIsTaxEnabledProperty(): bool
    {
        return $this->financeCalculation['tax_enabled'];
    }

    public function getAdminFeeAmountProperty(): int
    {
        return $this->financeCalculation['admin_fee_amount'];
    }

    public function getAdminFeeNameProperty(): string
    {
        return $this->financeCalculation['admin_fee_name'] ?: 'Biaya Layanan';
    }

    public function getIsAdminFeeEnabledProperty(): bool
    {
        return $this->financeCalculation['admin_fee_enabled'];
    }

    public function getGrandTotalProperty(): float
    {
        if ($this->settleBill) {
            return (float) $this->settleBill['amount'];
        }

        return (float) $this->financeCalculation['grand_total'];
    }

    public function getActiveShiftProperty(): ?PosCashierShift
    {
        return PosCashierShift::getActiveShift('PADEL_FRONTDESK');
    }

    public function openShiftModal(): void
    {
        $this->openingNotes = '';
        $this->showOpenShiftModal = true;
    }

    public function executeOpenShift(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && $user->can('open_pos_shift'),
            403,
            'Akses ditolak: Anda tidak memiliki izin [open_pos_shift] untuk membuka sesi shift kasir.'
        );

        // Anti-race: bungkus cek+create shift dengan distributed lock (pola sama persis dengan
        // padel_lock di ManagesScheduleAndSlots). Tanpa ini, 2 admin (atau 1 admin yang double-klik
        // saat koneksi lemot) bisa sama-sama lolos cek "belum ada shift aktif" di bawah dan membentuk
        // 2 shift OPEN sekaligus untuk counter yang sama — tidak ada unique constraint DB yang menahan
        // ini (pos_cashier_shifts cuma unique di shift_number, bukan di kombinasi counter+status).
        $lock = Cache::lock('pos_open_shift:PADEL_FRONTDESK', 10);
        if (! $lock->get()) {
            Notification::make()
                ->title('Sedang Diproses')
                ->body('Ada permintaan buka shift lain yang sedang diproses. Silakan coba lagi sesaat.')
                ->warning()
                ->send();
            return;
        }

        try {
            if ($this->activeShift) {
                Notification::make()
                    ->title('Shift Sudah Terbuka')
                    ->body('Loket Padel Frontdesk sudah memiliki sesi shift yang aktif.')
                    ->warning()
                    ->send();
                $this->showOpenShiftModal = false;
                return;
            }

            $shiftNumber = PosCashierShift::generateShiftNumber('PADEL_FRONTDESK');

            // 100% Cashless: tidak ada modal kas fisik, jadi starting_cash/expected_cash selalu 0.
            $shift = PosCashierShift::create([
                'shift_number' => $shiftNumber,
                'counter' => 'PADEL_FRONTDESK',
                'status' => 'OPEN',
                'opened_by_id' => $user->id,
                'opened_at' => Carbon::now('Asia/Jakarta'),
                'starting_cash' => 0.00,
                'expected_cash' => 0.00,
                'opening_notes' => trim($this->openingNotes) ?: null,
            ]);
            $this->recordOpeningSettlements($shift);

            Notification::make()
                ->title('Shift Kasir Berhasil Dibuka')
                ->body("Sesi {$shiftNumber} aktif. Loket beroperasi 100% Cashless (QRIS / EDC / Transfer).")
                ->success()
                ->send();

            $this->showOpenShiftModal = false;
        } finally {
            $lock->release();
        }
    }

    protected function shiftCounter(): string
    {
        return 'PADEL_FRONTDESK';
    }

    protected function shiftNotice(string $level, string $title, string $body): void
    {
        $notification = Notification::make()->title($title)->body($body);
        match ($level) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            default => $notification->warning(),
        };
        $notification->send();
    }

    protected function getDraftCacheKey(): string
    {
        $userId = auth()->id() ?? 'guest';
        return "pos_walkin_draft:{$userId}";
    }

    public function checkPendingDraft(): void
    {
        $key = $this->getDraftCacheKey();
        $draft = Cache::get($key);

        if ($draft && ! empty($draft['selectedSlots'])) {
            $this->hasPendingDraft = true;
            $this->pendingDraftSummary = [
                'bookingDate' => $draft['bookingDate'] ?? now()->format('Y-m-d'),
                'slotsCount' => count($draft['selectedSlots']),
                'customerName' => ! empty($draft['walkInName']) ? $draft['walkInName'] : (! empty($draft['selectedCustomerName']) ? $draft['selectedCustomerName'] : 'Pelanggan Walk-In'),
                'savedAt' => $draft['savedAt'] ?? now('Asia/Jakarta')->format('H:i'),
                'grandTotal' => $draft['grandTotal'] ?? 0,
            ];
        } else {
            $this->hasPendingDraft = false;
            $this->pendingDraftSummary = null;
        }
    }

    public function saveDraft(): void
    {
        if (empty($this->selectedSlots)) {
            return;
        }

        $key = $this->getDraftCacheKey();
        Cache::put($key, [
            'bookingDate' => $this->bookingDate,
            'selectedSlots' => $this->selectedSlots,
            'customerMode' => $this->customerMode,
            'selectedCustomerId' => $this->selectedCustomerId,
            'selectedCustomerName' => $this->selectedCustomerName,
            'selectedCustomerPhone' => $this->selectedCustomerPhone,
            'walkInName' => $this->walkInName,
            'walkInPhone' => $this->walkInPhone,
            'walkInEmail' => $this->walkInEmail,
            'rentalQuantities' => $this->rentalQuantities,
            'paymentMethod' => $this->paymentMethod,
            'edcTerminal' => $this->edcTerminal,
            'edcCardType' => $this->edcCardType,
            'edcCardNetwork' => $this->edcCardNetwork,
            'edcBank' => $this->edcBank,
            'edcLast4' => $this->edcLast4,
            'edcApprovalCode' => $this->edcApprovalCode,
            'edcTraceNumber' => $this->edcTraceNumber,
            'qrisProvider' => $this->qrisProvider,
            'qrisRrn' => $this->qrisRrn,
            'qrisSenderName' => $this->qrisSenderName,
            'isAutoCheckIn' => $this->isAutoCheckIn,
            'useMembershipBenefit' => $this->useMembershipBenefit,
            'useSponsorVoucherBenefit' => $this->useSponsorVoucherBenefit,
            'bookingType' => $this->bookingType,
            'coachingCoachId' => $this->coachingCoachId,
            'coachingExtraCoachIds' => $this->coachingExtraCoachIds,
            'coachingStudents' => $this->coachingStudents,
            'coachingNotes' => $this->coachingNotes,
            'grandTotal' => $this->grandTotal,
            'heldBookingIds' => $this->heldBookingIds,
            'heldSignature' => $this->heldSignature,
            'savedAt' => now('Asia/Jakarta')->format('H:i'),
        ], 7200);
    }

    public function resumeDraft(): void
    {
        $key = $this->getDraftCacheKey();
        $draft = Cache::get($key);

        if (! $draft) {
            $this->hasPendingDraft = false;
            $this->pendingDraftSummary = null;
            return;
        }

        $this->bookingDate = $draft['bookingDate'] ?? now()->format('Y-m-d');
        $this->customerMode = $draft['customerMode'] ?? 'quick_create';
        $this->selectedCustomerId = $draft['selectedCustomerId'] ?? null;
        $this->selectedCustomerName = $draft['selectedCustomerName'] ?? null;
        $this->selectedCustomerPhone = $draft['selectedCustomerPhone'] ?? null;
        $this->walkInName = $draft['walkInName'] ?? '';
        $this->walkInPhone = $draft['walkInPhone'] ?? '';
        $this->walkInEmail = $draft['walkInEmail'] ?? '';
        $this->rentalQuantities = $draft['rentalQuantities'] ?? [];
        $this->paymentMethod = $draft['paymentMethod'] ?? 'QRIS';
        // Draf lama bisa menyimpan mesin EDC yang sudah tidak dipakai (hanya BCA sejak 28 Okt 2026).
        $this->edcTerminal = in_array($draft['edcTerminal'] ?? null, \App\Services\Pos\PosPaymentProof::ACTIVE_EDC_TERMINALS, true) ? $draft['edcTerminal'] : 'EDC_BCA';
        $this->edcCardType = $draft['edcCardType'] ?? 'DEBIT';
        $this->edcCardNetwork = $draft['edcCardNetwork'] ?? 'GPN';
        $this->edcBank = $draft['edcBank'] ?? 'BCA';
        $this->edcLast4 = $draft['edcLast4'] ?? '';
        $this->edcApprovalCode = $draft['edcApprovalCode'] ?? '';
        $this->edcTraceNumber = $draft['edcTraceNumber'] ?? '';
        $this->qrisProvider = $draft['qrisProvider'] ?? 'BCA_QRIS';
        $this->qrisRrn = $draft['qrisRrn'] ?? '';
        $this->qrisSenderName = $draft['qrisSenderName'] ?? '';
        $this->isAutoCheckIn = $draft['isAutoCheckIn'] ?? false;
        $this->loadBenefitInfo($this->benefitCustomer());
        $this->useMembershipBenefit = $draft['useMembershipBenefit'] ?? true;
        $this->useSponsorVoucherBenefit = $draft['useSponsorVoucherBenefit'] ?? true;
        // Coaching hanya dipulihkan kalau kasir ini memang boleh membuatnya.
        $this->bookingType = ($draft['bookingType'] ?? 'REGULAR') === 'COACHING' && $this->canBookCoaching ? 'COACHING' : 'REGULAR';
        $this->coachingCoachId = $draft['coachingCoachId'] ?? null;
        $this->coachingExtraCoachIds = array_values(array_map('strval', (array) ($draft['coachingExtraCoachIds'] ?? [])));
        $this->coachingStudents = array_pad(array_slice(array_values((array) ($draft['coachingStudents'] ?? [])), 0, 2), 2, '');
        $this->coachingNotes = (string) ($draft['coachingNotes'] ?? '');
        // Slot yang tadi ditahan kasir ini (bukan bentrok) — dipakai lagi kalau waktu tahannya belum habis.
        $this->heldBookingIds = array_values(array_filter((array) ($draft['heldBookingIds'] ?? [])));
        $this->heldSignature = $draft['heldSignature'] ?? null;

        $draftSlots = $draft['selectedSlots'] ?? [];
        $conflicted = false;
        $validSlots = [];

        foreach ($draftSlots as $keySlot => $slot) {
            $startDt = "{$this->bookingDate} {$slot['start_time']}";
            $endDt = "{$this->bookingDate} {$slot['end_time']}";

            // Termasuk draf dari tanggal yang sudah lewat (draf disimpan sampai 2 jam).
            if ($this->isPastSlot($slot['start_time'])) {
                $conflicted = true;
                continue;
            }

            $exists = PadelBooking::where('court_id', $slot['court_id'])
                ->whereDate('booking_date', $this->bookingDate)
                ->whereIn('status', ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN', 'COMPLETED'])
                ->whereNotIn('id', $this->heldBookingIds)
                ->where(function ($q) use ($startDt, $endDt) {
                    $q->where('start_time', '<', $endDt)
                        ->where('end_time', '>', $startDt);
                })
                ->exists();

            if ($exists) {
                $conflicted = true;
            } else {
                $validSlots[$keySlot] = $slot;
            }
        }

        $this->selectedSlots = $validSlots;
        $this->hasPendingDraft = false;
        $this->pendingDraftSummary = null;

        if ($conflicted || empty($validSlots)) {
            $this->releaseHeldSlots();
            $this->posStep = 'selection';
            Notification::make()
                ->title('Beberapa Slot Tidak Tersedia')
                ->body('Satu atau lebih slot draf sebelumnya telah terisi atau kedaluwarsa. Data pelanggan telah dipulihkan, silakan sesuaikan slot lapangan pada jadwal.')
                ->warning()
                ->send();
        } elseif (! $this->holdSelectedSlots()) {
            $this->posStep = 'selection';
        } else {
            $this->posStep = 'payment';
            Notification::make()
                ->title('Draf Transaksi Dipulihkan')
                ->body('Layar pembayaran berhasil dipulihkan dari transaksi sebelumnya.')
                ->success()
                ->send();
        }
    }

    public function discardDraft(): void
    {
        $this->releaseHeldSlots();
        Cache::forget($this->getDraftCacheKey());
        $this->hasPendingDraft = false;
        $this->pendingDraftSummary = null;
        $this->clearSelectedSlots();
        $this->walkInName = '';
        $this->walkInPhone = '';
        $this->walkInEmail = '';
        $this->clearSelectedCustomer();
        $this->initializeEquipmentQuantities();
        $this->resetCoaching();
        $this->posStep = 'selection';

        Notification::make()
            ->title('Draf Dihapus')
            ->body('Draf transaksi kasir telah dibersihkan.')
            ->info()
            ->send();
    }

    public function proceedToPayment(): void
    {
        // 0. Guard Shift Kasir Aktif PADEL_FRONTDESK
        $activeShift = $this->activeShift;

        if ($this->settleBill) {
            if (! $activeShift) {
                // Pelunasan tagihan wajib masuk shift (berlaku juga untuk super_admin) supaya ikut rekap setoran.
                Notification::make()->title('Shift Kasir Belum Dibuka')->body('Buka shift kasir dulu sebelum menerima pelunasan.')->danger()->send();

                return;
            }

            $this->posStep = 'payment';

            return;
        }

        // Berlaku untuk semua user termasuk super_admin: transaksi tanpa shift tidak ikut rekap setoran.
        if (! $activeShift) {
            Notification::make()
                ->title('Shift Kasir Belum Dibuka')
                ->body('Silakan buka sesi shift kasir terlebih dahulu sebelum melanjutkan ke pembayaran.')
                ->danger()
                ->send();
            return;
        }

        // 1. Validasi slot
        if (empty($this->selectedSlots)) {
            Notification::make()
                ->title('Slot Belum Dipilih')
                ->body('Silakan pilih minimal satu slot jam bermain pada timetable grid sebelum melanjutkan.')
                ->warning()
                ->send();
            return;
        }

        // 2. Validasi Customer
        if ($this->customerMode === 'search') {
            if (empty($this->selectedCustomerId)) {
                Notification::make()
                    ->title('Customer Belum Dipilih')
                    ->body('Silakan cari dan pilih pelanggan terdaftar, atau beralih ke form Walk-In Baru.')
                    ->warning()
                    ->send();
                return;
            }
        } else {
            $name = trim($this->walkInName);
            $phone = trim($this->walkInPhone);

            if (empty($name)) {
                Notification::make()
                    ->title('Nama Wajib Diisi')
                    ->body('Silakan masukkan nama pelanggan walk-in.')
                    ->warning()
                    ->send();
                return;
            }

            if (! PersonName::isValid($name)) {
                Notification::make()
                    ->title('Nama Tidak Valid')
                    ->body(PersonName::MESSAGE)
                    ->warning()
                    ->send();
                return;
            }

            if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 8) {
                Notification::make()
                    ->title('Nomor Telepon Tidak Valid')
                    ->body('Silakan masukkan nomor WhatsApp / telepon aktif minimal 8 digit.')
                    ->warning()
                    ->send();
                return;
            }

            // Modul 33: email milik akun lain → arahkan ke Cari Akun (dicek lagi saat transaksi diproses).
            if (! $this->walkInEmailAvailable(app(PadelBookingService::class))) {
                return;
            }
        }

        // 2b. Coaching: coach utama wajib & semua jam harus punya tarif coaching (server memeriksa ulang).
        if ($this->isCoaching && ! $this->assertCoachingReady()) {
            return;
        }

        // 3. Tahan slotnya sekarang — sebelum customer membayar. Keduluan customer online → kasir langsung tahu.
        if (! $this->holdSelectedSlots()) {
            return;
        }

        $this->saveDraft();
        $this->posStep = 'payment';
    }

    /** Kembali ke pilih jadwal: slot tetap ditahan (kasir mungkin hanya membetulkan data customer). */
    public function backToSelection(): void
    {
        $this->saveDraft();
        $this->posStep = 'selection';
    }

    /**
     * Tahan slot yang dipilih atas nama kasir (sama dengan customer online menekan Continue). Tahanan sebelumnya yang
     * masih sah untuk jam yang sama dipakai lagi. Gagal → pemberitahuan, slot yang sudah diambil orang dilepas dari
     * pilihan, kasir tetap di layar pilih jadwal.
     */
    protected function holdSelectedSlots(): bool
    {
        abort_unless(auth()->user()?->can('process_walkin_booking'), 403, 'Akses ditolak: Anda tidak memiliki izin untuk memproses transaksi walk-in.');

        $service = app(PadelBookingService::class);
        $signature = $this->holdSignature();

        if ($this->heldBookingIds !== [] && $this->heldSignature === $signature
            && $service->walkInHoldIsValid($this->heldBookingIds, auth()->user(), $this->slotsPayload(), $this->bookingDate)) {
            $until = PadelBooking::whereIn('id', $this->heldBookingIds)->min('expires_at');
            $this->heldUntil = $until ? Carbon::parse($until)->toIso8601String() : null;

            return true;
        }

        $this->releaseHeldSlots();

        // Tanggal tutup / belum dibuka: jangan menahan slot (checkout juga menolak di server).
        if ($reason = \App\Support\BookingWindow::blockedReason($this->bookingDate)) {
            Notification::make()->title('Tanggal Tidak Bisa Dibooking')->body($reason)->danger()->send();

            return false;
        }

        try {
            $hold = $service->holdBatchSlots($this->slotsPayload(), $this->bookingDate, auth()->user());
        } catch (SlotConflictException $e) {
            $taken = $this->takenSelectedSlotKeys();
            $labels = collect($taken)->map(fn ($key) => $this->selectedSlots[$key]['court_name'].' '.$this->selectedSlots[$key]['time_label'])->implode(', ');
            foreach ($taken as $key) {
                unset($this->selectedSlots[$key]);
            }
            $this->saveDraft();

            Notification::make()
                ->title('Slot Baru Saja Diambil')
                ->body($taken !== []
                    ? "{$labels} baru saja diambil customer lain dan dihapus dari pilihan. Belum ada pembayaran — pilih jam lain."
                    : ($e->getMessage() ?: 'Slot sedang ditahan customer lain. Pilih jam lain atau coba lagi sebentar.'))
                ->warning()
                ->persistent()
                ->send();

            return false;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Notification::make()->title('Slot Tidak Bisa Ditahan')->body($e->getMessage())->warning()->send();

            return false;
        }

        $this->heldBookingIds = collect($hold['bookings'])->pluck('id')->values()->all();
        $this->heldSignature = $signature;
        $this->heldUntil = Carbon::parse($hold['expires_at'])->toIso8601String();

        return true;
    }

    /** Lepas slot yang sedang ditahan kasir ini (hanya yang masih LOCKED atas namanya — yang sudah dibayar tidak tersentuh). */
    protected function releaseHeldSlots(): void
    {
        if ($this->heldBookingIds !== [] && auth()->user()) {
            try {
                app(PadelBookingService::class)->releaseSlots($this->heldBookingIds, auth()->user(), onlyLocked: true);
            } catch (\Throwable $e) {
                report($e); // gagal lepas → slot tetap terlepas sendiri saat waktu tahannya habis
            }
        }
        $this->forgetHeldSlots();
    }

    /** Tahanan sudah menjadi booking customer (dibayar / menunggu QR) — cukup dilupakan, jangan dilepas. */
    protected function forgetHeldSlots(): void
    {
        $this->heldBookingIds = [];
        $this->heldSignature = null;
        $this->heldUntil = null;
    }

    protected function holdSignature(): string
    {
        $keys = array_keys($this->selectedSlots);
        sort($keys);

        return md5($this->bookingDate.'|'.implode(',', $keys));
    }

    /** @return list<array{court_id: string, start_time: string, end_time: string}> */
    protected function slotsPayload(): array
    {
        return array_values(array_map(fn ($slot) => [
            'court_id' => $slot['court_id'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
        ], $this->selectedSlots));
    }

    /** Slot pilihan kasir yang sudah terisi booking lain (bukan tahanan kasir ini sendiri). */
    protected function takenSelectedSlotKeys(): array
    {
        if ($this->selectedSlots === []) {
            return [];
        }

        $others = PadelBooking::whereDate('booking_date', $this->bookingDate)
            ->whereIn('court_id', collect($this->selectedSlots)->pluck('court_id')->unique())
            ->whereIn('status', ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN', 'COMPLETED'])
            ->whereNotIn('id', $this->heldBookingIds)
            ->get(['id', 'court_id', 'start_time', 'end_time']);

        return collect($this->selectedSlots)->filter(function ($slot) use ($others) {
            $start = "{$this->bookingDate} {$slot['start_time']}";
            $end = "{$this->bookingDate} {$slot['end_time']}";

            return $others->contains(fn ($b) => $b->court_id === $slot['court_id']
                && $b->start_time->format('Y-m-d H:i:s') < $end
                && $b->end_time->format('Y-m-d H:i:s') > $start);
        })->keys()->all();
    }

    public function startNewTransaction(): void
    {
        $this->releaseHeldSlots();
        Cache::forget($this->getDraftCacheKey());
        $this->hasPendingDraft = false;
        $this->pendingDraftSummary = null;
        $this->selectedSlots = [];
        $this->removeVoucher();
        $this->initializeEquipmentQuantities();
        $this->walkInName = '';
        $this->walkInPhone = '';
        $this->walkInEmail = '';
        $this->selectedCustomerId = null;
        $this->selectedCustomerName = null;
        $this->selectedCustomerPhone = null;
        $this->loadBenefitInfo(null);
        $this->paymentMethod = 'QRIS';
        $this->edcLast4 = '';
        $this->edcApprovalCode = '';
        $this->edcTraceNumber = '';
        $this->qrisRrn = '';
        $this->qrisSenderName = '';
        $this->resetCoaching();
        $this->showSuccessModal = false;
        $this->completedOrderData = null;
        $this->posStep = 'selection';
    }

    public function setPaymentMethod(string $method): void
    {
        $this->paymentMethod = $method;

        if ($method === 'DEBIT_CARD' || $method === 'DEBIT') {
            $this->edcCardType = 'DEBIT';
            if (! in_array($this->edcCardNetwork, ['GPN', 'MASTERCARD', 'VISA'])) {
                $this->edcCardNetwork = 'GPN';
            }
        } elseif ($method === 'CREDIT_CARD' || $method === 'CREDIT') {
            $this->edcCardType = 'CREDIT';
            if (! in_array($this->edcCardNetwork, ['VISA', 'MASTERCARD', 'JCB', 'AMEX', 'UNIONPAY'])) {
                $this->edcCardNetwork = 'VISA';
            }
        }

        $this->saveDraft();
    }

    public function submitWalkInBooking(PadelBookingService $service): void
    {
        // Masih ada Bayar Otomatis yang menunggu customer → selesaikan / batalkan dulu (popup tetap tampil).
        if ($this->pendingQris) {
            Notification::make()->title('Masih Menunggu Pembayaran')->body('Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.')->warning()->send();

            return;
        }

        if ($this->settleBill) {
            $this->submitSettlement($service);

            return;
        }

        // 0. Guard Permission
        abort_unless(auth()->user() && auth()->user()->can('process_walkin_booking'), 403, 'Akses ditolak: Anda tidak memiliki izin untuk memproses transaksi walk-in.');

        // 0.1 Guard Shift Kasir Aktif
        $activeShift = $this->activeShift;

        if (! $activeShift) {
            Notification::make()
                ->title('Shift Kasir Belum Dibuka')
                ->body('Silakan buka sesi shift kasir terlebih dahulu sebelum melayani transaksi walk-in.')
                ->danger()
                ->send();
            return;
        }

        // 1. Validasi slot
        if (empty($this->selectedSlots)) {
            Notification::make()
                ->title('Slot Belum Dipilih')
                ->body('Silakan pilih minimal satu slot jam bermain pada timetable grid sebelum melanjutkan.')
                ->warning()
                ->send();
            return;
        }

        // 2. Identifikasi Customer
        $customer = $this->resolvePosCustomer($service);
        if (! $customer) {
            return;
        }

        // 2.0 Coaching: isian & tarif dicek lagi (bisa berubah sejak "Lanjut ke Pembayaran").
        if ($this->isCoaching && ! $this->assertCoachingReady()) {
            return;
        }


        // 2.1 Validasi Ketat Metode Pembayaran
        $method = strtoupper($this->paymentMethod);
        $grandTotal = $this->grandTotal;
        $paymentMeta = [];

        if ($grandTotal > 0 && $this->coachingTabAcademy) {
            // Modul 31: academy Tagihan Berkala — tanpa pembayaran saat ini, dilunasi di kasir sesuai periode.
            $method = 'TAGIHAN';
        } elseif ($grandTotal <= 0) {
            // Seluruh tagihan ditanggung voucher promo / kuota membership / voucher jam corporate: tidak ada uang
            // masuk, jadi tanpa bukti EDC / QRIS dan tanpa Midtrans. Server menentukan label sumbernya.
            $method = 'VOUCHER';
        } elseif (in_array($method, ['CASH', 'TUNAI'])) {
            Notification::make()
                ->title('Metode Pembayaran Ditolak')
                ->body('Pembayaran tunai (CASH) tidak diperbolehkan. Venue Club 61 beroperasi 100% Cashless — gunakan QRIS, EDC, atau Transfer.')
                ->danger()
                ->send();
            return;
        } elseif (in_array($method, ['DEBIT_CARD', 'CREDIT_CARD', 'EDC_BCA', 'EDC_MANDIRI', 'DEBIT', 'CREDIT'])) {
            $last4 = trim($this->edcLast4);
            $approvalCode = trim($this->edcApprovalCode);
            $traceNumber = trim($this->edcTraceNumber);

            if (! preg_match('/^[0-9]{4}$/', $last4)) {
                Notification::make()
                    ->title('4 Digit Kartu Tidak Valid')
                    ->body('Silakan masukkan tepat 4 digit angka terakhir dari kartu debit/kredit pelanggan.')
                    ->danger()
                    ->send();
                return;
            }

            if (empty($approvalCode) || strlen($approvalCode) < 3) {
                Notification::make()
                    ->title('Approval Code Wajib Diisi')
                    ->body('Silakan masukkan nomor otorisasi/approval code dari slip transaksi mesin EDC.')
                    ->danger()
                    ->send();
                return;
            }

            if (empty($traceNumber) || strlen($traceNumber) < 3) {
                Notification::make()
                    ->title('Trace Number Wajib Diisi')
                    ->body('Silakan masukkan nomor trace / audit number dari slip transaksi mesin EDC.')
                    ->danger()
                    ->send();
                return;
            }

            $cardType = (str_contains($method, 'CREDIT') || $this->edcCardType === 'CREDIT') ? 'CREDIT' : 'DEBIT';
            $terminal = ! empty($this->edcTerminal) ? $this->edcTerminal : ($method === 'EDC_MANDIRI' ? 'EDC_MANDIRI' : 'EDC_BCA');

            $paymentMeta = [
                'terminal' => $terminal,
                'card_type' => $cardType,
                'card_network' => $this->edcCardNetwork,
                'card_issuer' => $this->edcBank,
                'card_last_4' => $last4,
                'approval_code' => $approvalCode,
                'trace_number' => $traceNumber,
                'charged_amount' => (float) $grandTotal,
            ];
        } elseif (in_array($method, ['QRIS_STATIS', 'QRIS']) && $this->qrisMode === 'MIDTRANS' && \App\Services\Pos\PosMidtransQrisService::resolveMethod($this->posOnlineMethod, (float) $grandTotal) !== null) {
            // QR Midtrans: tidak ada bukti yang diketik kasir — lunas dikonfirmasi Midtrans.
            $method = 'QRIS_MIDTRANS';
            $paymentMeta = ['qris_provider' => \App\Services\Pos\PosMidtransQrisService::PROVIDER, 'pos_online_method' => $this->posOnlineMethod];
        } elseif (in_array($method, ['QRIS_STATIS', 'QRIS'])) {
            $rrn = trim($this->qrisRrn);

            if (empty($rrn) || strlen($rrn) < 6) {
                Notification::make()
                    ->title('Nomor RRN QRIS Wajib Diisi')
                    ->body('Silakan masukkan nomor RRN (Retrieval Reference Number) minimal 6 digit dari bukti bayar customer.')
                    ->danger()
                    ->send();
                return;
            }

            $paymentMeta = [
                'qris_provider' => $this->qrisProvider,
                'qris_rrn' => $rrn,
                'qris_sender_name' => trim($this->qrisSenderName) ?: null,
            ];
        } else {
            // Metode pembayaran di luar daftar yang dikenali (misal permintaan hasil rekayasa
            // langsung ke Livewire, bukan lewat UI <select>/tab) WAJIB ditolak — tanpa else ini,
            // order bisa lolos ditandai LUNAS tanpa satu pun bukti bayar (approval code/RRN)
            // tersimpan, membuka celah fraud pada kebijakan 100% Cashless.
            Notification::make()
                ->title('Metode Pembayaran Tidak Dikenali')
                ->body('Pilih salah satu metode pembayaran yang tersedia: QRIS, Kartu Debit, atau Kartu Kredit.')
                ->danger()
                ->send();
            return;
        }

        // 3. Susun array slots untuk service
        $slotsPayload = $this->slotsPayload();

        // 4. Susun array equipments
        $equipmentsPayload = [];
        foreach ($this->rentalQuantities as $eqId => $qty) {
            if ($qty > 0) {
                $equipmentsPayload[] = [
                    'equipment_id' => $eqId,
                    'quantity' => (int) $qty,
                ];
            }
        }

        // 5. Kasir bertugas
        $cashier = auth()->user() ?? User::role(['cashier', 'admin', 'super_admin'])->first();

        // 6. Eksekusi transaksi dengan penanganan SlotConflictException
        try {
            $result = $service->processWalkInCheckout(
                customer: $customer,
                slots: $slotsPayload,
                bookingDate: $this->bookingDate,
                equipments: $equipmentsPayload,
                paymentMethod: in_array($method, ['VOUCHER', 'QRIS_MIDTRANS', 'TAGIHAN'], true) ? $method : $this->paymentMethod,
                cashier: $cashier,
                autoCheckIn: $this->isAutoCheckIn,
                paymentMeta: $paymentMeta,
                // 'NONE' kalau kasir sengaja matiin toggle benefit membership untuk transaksi ini —
                // konsisten dengan guard yang sama dipakai di jalur online checkout.
                // Benefit yang dihitung di layar (quoteWalkInBenefits) — kartu di luar jendela waktunya dimatikan supaya
                // checkout tidak gagal; 'NONE' kalau kasir mematikan toggle.
                membershipBalanceId: $this->useMembershipBenefit && $this->benefitQuote['membership']['applies'] ? $this->benefitQuote['membership']['balance_id'] : 'NONE',
                sponsorVoucherId: $this->useSponsorVoucherBenefit ? null : 'NONE',
                // Voucher hanya dikirim kalau di layar memang berlaku — dulu voucher milik customer yang belum dipilih
                // (mode Walk-In Cepat) tetap dipakai server walau layar tidak menampilkan potongannya.
                voucherCode: $this->appliedVoucherCode && ! $this->voucherResult['error'] ? $this->appliedVoucherCode : null,
                // Total yang dilihat & ditagih kasir wajib sama dengan yang dicatat server.
                expectedGrandTotal: (float) $grandTotal,
                // Slot yang ditahan saat "Lanjut ke Pembayaran" (habis / berubah → server menahan ulang).
                heldBookingIds: $this->heldBookingIds ?: null,
                holder: auth()->user(),
                // Booking coaching: tarif setoran coaching (server menghitung ulang & mengecek jadwal coach).
                coaching: $this->coachingPayload(),
            );
            // Tahanan sudah menjadi booking customer.
            $this->forgetHeldSlots();

            // QR Midtrans: order & slot sudah ditahan, tinggal tunggu customer scan. Struk keluar setelah lunas.
            if (! empty($result['pending_qris'])) {
                $this->pendingQris = $result['pending_qris'];
                Cache::forget($this->getDraftCacheKey());
                $this->hasPendingDraft = false;
                $this->pendingDraftSummary = null;
                $this->resetWalkInCart();

                return;
            }

            // Siapkan data struk POS thermal
            $this->completedOrderData = [
                'order_number' => $result['order']->order_number,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'cashier_name' => $cashier->name,
                'booking_date' => Carbon::parse($this->bookingDate)->translatedFormat('d F Y'),
                // Pembayaran di kasir: label EDC/QRIS frontdesk, bukan label metode online.
                // Metode FINAL dari server: tagihan Rp0 tercatat sebagai "Voucher Jam Corporate (Gratis)" dsb. — dulu struk
                // tetap menulis pilihan di layar (mis. QRIS) walau tidak ada uang yang dibayar.
                'payment_method' => $service->formatPaymentMethodLabel($result['payment_method'] ?? $this->paymentMethod, ['cashier_id' => auth()->id()]),
                'subtotal' => $result['order']->subtotal,
                'discount_amount' => (float) $result['order']->discount_amount,
                'voucher_code' => $result['order']->voucher_code,
                'voucher_note' => app(\App\Services\Finance\PromoVoucherService::class)->receiptNote($result['order']->voucher_code),
                'tax_amount' => $result['order']->tax_amount,
                'tax_name' => $this->taxName,
                'service_charge' => $result['order']->service_charge,
                'admin_fee_name' => $this->adminFeeName,
                'grand_total' => $result['grand_total'],
                'billed_to_tab' => $result['billed_to_tab'] ?? false,
                'auto_checked_in' => $result['auto_checked_in'],
                'created_at' => now()->format('d/m/Y H:i:s'),
                'payment_meta' => $paymentMeta,
                'bookings' => $this->receiptBookingLines($result['bookings']),
                'benefit_notes' => $this->receiptBenefitNotes($customer, $result['bookings']),
                'equipments' => array_values(array_filter(array_map(function ($item) {
                    $eq = CourtEquipment::find($item['equipment_id']);
                    return ($eq && $eq->is_active) ? [
                        'name' => $eq->name,
                        'quantity' => $item['quantity'],
                        'price' => (float) $eq->rental_price * $item['quantity'],
                    ] : null;
                }, $equipmentsPayload))),
            ];

            // Hapus Draf Kasir setelah transaksi berhasil diselesaikan
            Cache::forget($this->getDraftCacheKey());
            $this->hasPendingDraft = false;
            $this->pendingDraftSummary = null;

            // Transisi ke layar Struk Kasir (In-Page)
            $this->posStep = 'receipt';
            $this->showSuccessModal = false;

            Notification::make()
                ->title('Pemesanan Walk-In Berhasil!')
                ->body("Order #{$result['order']->order_number} berhasil dibayar lunas dan e-tiket telah aktif.")
                ->success()
                ->send();

            // Struk langsung dicetak di aplikasi Club61 — sama dengan Bayar Otomatis (semua metode, satu alur).
            $this->queueAutoPrint($result['order']->id, 'filament.partials.walkin-receipt', ['receipt' => $this->completedOrderData], 'startNewTransaction');

            // Reset seleksi keranjang untuk transaksi berikutnya
            $this->resetWalkInCart();

        } catch (SlotConflictException $e) {
            // Hanya terjadi kalau tahanan kasir sudah habis lalu slotnya keburu diambil orang.
            $this->releaseHeldSlots();
            Notification::make()
                ->title('Slot Tidak Tersedia')
                ->body($e->getMessage() ?: 'Slot jam tersebut baru saja diambil customer online atau sedang di-hold. Silakan pilih slot lain.')
                ->warning()
                ->send();
            $this->posStep = 'selection';
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Memproses Pemesanan Walk-In')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Baris lapangan untuk struk: harga normal + potongan kuota member / voucher jam corporate yang dipakai, supaya
     * struk Rp0 tetap menjelaskan apa yang ditanggung (dulu hanya "Court A … Rp 0").
     */
    protected function receiptBookingLines(\Illuminate\Support\Collection $bookings, ?callable $courtDeltaFor = null): array
    {
        return $bookings->map(function ($b) use ($courtDeltaFor) {
            $courtFee = (float) $b->court_fee - ($courtDeltaFor ? $courtDeltaFor($b) : 0);
            $memberDiscount = (float) $b->member_discount_court;
            $sponsorDiscount = (float) $b->sponsor_discount_court;

            return [
                'booking_code' => $b->booking_code,
                'court_name' => ($b->court?->name ?? 'Lapangan Padel').($b->coaching_booking_id ? ' (Coaching)' : ''),
                'time_label' => $b->start_time->format('H:i').' - '.$b->end_time->format('H:i'),
                'court_fee' => $courtFee,
                'normal_fee' => $courtFee + $memberDiscount + $sponsorDiscount,
                'member_hours' => (float) $b->member_hours_consumed,
                'member_discount' => $memberDiscount,
                'sponsor_hours' => (float) $b->sponsor_hours_consumed,
                'sponsor_discount' => $sponsorDiscount,
                'sponsor_org' => $sponsorDiscount > 0 ? $b->sponsorOrganization?->name : null,
                'status' => $b->status,
                'qr_code_hash' => $b->qr_code_hash,
            ];
        })->values()->all();
    }

    /** Sisa saldo benefit customer setelah transaksi (hanya di struk penjualan, bukan cetak ulang). */
    protected function receiptBenefitNotes(?User $customer, \Illuminate\Support\Collection $bookings): array
    {
        if (! $customer) {
            return [];
        }

        $hours = fn ($h) => rtrim(rtrim(number_format((float) $h, 1, ',', '.'), '0'), ',');
        $notes = [];

        if ($bookings->contains(fn ($b) => (float) $b->member_hours_consumed > 0)) {
            $left = \App\Models\Membership\UserMembershipBalance::whereIn('id', $bookings->pluck('membership_balance_id')->filter()->unique())->sum('remaining_quota');
            $notes[] = 'Sisa kuota member: '.$hours($left).' jam';
        }

        if ($bookings->contains(fn ($b) => (float) $b->sponsor_hours_consumed > 0)) {
            $member = \App\Models\Sponsor\SponsorOrganizationMember::where('user_id', $customer->id)->where('status', 'ACTIVE')->first();
            $left = $member
                ? \App\Models\Sponsor\SponsorMemberVoucher::where('sponsor_organization_member_id', $member->id)->where('expires_at', '>', now())->get()->sum(fn ($v) => $v->remainingHours())
                : 0;
            $notes[] = 'Sisa jam corporate: '.$hours($left).' jam';
        }

        return $notes;
    }

    /** Kosongkan keranjang & data customer untuk transaksi berikutnya. */
    protected function resetWalkInCart(): void
    {
        $this->forgetHeldSlots();
        $this->selectedSlots = [];
        $this->removeVoucher();
        $this->initializeEquipmentQuantities();
        $this->walkInName = '';
        $this->walkInPhone = '';
        $this->walkInEmail = '';
        $this->selectedCustomerId = null;
        $this->selectedCustomerName = null;
        $this->selectedCustomerPhone = null;
        $this->loadBenefitInfo(null);
        $this->qrisRrn = '';
        $this->qrisSenderName = '';
        $this->resetCoaching();
    }

    // ===================== QRIS MIDTRANS (QR DI LAYAR KASIR) =====================

    /** Tagihan QR yang sedang ditampilkan — milik loket ini & dibuat dari layar kasir. */
    protected function pendingQrisPayment(): ?\App\Models\Pos\Payment
    {
        $id = $this->pendingQris['payment_id'] ?? null;
        $payment = $id ? \App\Models\Pos\Payment::find($id) : null;

        return $payment && ($payment->payload_log['counter'] ?? null) === 'PADEL_FRONTDESK' && isset($payment->payload_log['pos_qris'])
            ? $payment
            : null;
    }

    /** Dipanggil wire:poll popup QR: lunas → struk; kedaluwarsa / batal → beri tahu kasir. */
    public function pollPendingQris(): void
    {
        $payment = $this->pendingQrisPayment();
        if (! $payment) {
            $this->pendingQris = null;

            return;
        }

        $status = app(\App\Services\Pos\PosAutoPayment::class)->status($payment->id);

        if ($status === \App\Services\Pos\PosAutoPayment::PAID) {
            $this->completePendingQris($payment->fresh());
        } elseif ($status !== \App\Services\Pos\PosAutoPayment::PENDING) {
            $this->pendingQris = null;
            $this->posStep = 'selection';
            Notification::make()
                ->title($status === \App\Services\Pos\PosAutoPayment::EXPIRED ? 'QR Kedaluwarsa' : 'QR Dibatalkan')
                ->body('Pembayaran QRIS tidak diterima. Pesanan dibatalkan dan slot lapangan dilepas.')
                ->warning()
                ->send();
        }
    }

    public function cancelPendingQris(): void
    {
        abort_unless(auth()->user()?->can('process_walkin_booking'), 403);
        $payment = $this->pendingQrisPayment();
        if (! $payment) {
            $this->pendingQris = null;

            return;
        }

        $status = app(\App\Services\Pos\PosAutoPayment::class)->cancel($payment->id);

        if ($status === \App\Services\Pos\PosAutoPayment::PAID) {
            // Customer ternyata sudah bayar tepat sebelum dibatalkan — uang sudah masuk, transaksi diteruskan.
            $this->completePendingQris($payment->fresh());

            return;
        }
        if ($status === \App\Services\Pos\PosAutoPayment::PENDING) {
            Notification::make()->title('QR Belum Bisa Dibatalkan')->body(\App\Services\Pos\PosAutoPayment::cannotCancelYetMessage())->warning()->send();

            return;
        }

        $this->pendingQris = null;
        $this->posStep = 'selection';
        Notification::make()->title('QR Dibatalkan')->body('Pesanan dibatalkan dan slot lapangan dilepas.')->success()->send();
    }

    /** Hanya di laptop developer tanpa server key Midtrans (QR mock). */
    public function simulatePendingQrisPaid(): void
    {
        $payment = $this->pendingQrisPayment();
        abort_unless($payment && ($this->pendingQris['is_mock'] ?? false) && ! app()->environment('production'), 403);

        app(\App\Services\Pos\PosAutoPayment::class)->simulatePaid($payment->id);
        $this->pollPendingQris();
    }

    protected function completePendingQris(\App\Models\Pos\Payment $payment): void
    {
        $autoCheckIn = (bool) ($payment->payload_log['auto_check_in'] ?? false);
        if ($autoCheckIn) {
            PadelBooking::where('order_id', $payment->order_id)->where('status', 'PAID')->update(['status' => 'CHECKED_IN', 'checked_in_at' => now()]);
            app(\App\Services\Realtime\RealtimeSignal::class)->bookings(PadelBooking::where('order_id', $payment->order_id)->pluck('id'));
        }

        $this->completedOrderData = $this->buildWalkInReceipt($payment);
        $this->completedOrderData['auto_checked_in'] = $autoCheckIn;
        $this->pendingQris = null;
        $this->posStep = 'receipt';
        $this->showSuccessModal = false;

        Notification::make()
            ->title('Pembayaran QRIS Diterima')
            ->body("Order #{$payment->order?->order_number} lunas dan e-tiket telah aktif.")
            ->success()
            ->send();

        // Lunas lewat Bayar Otomatis → struk langsung dicetak di aplikasi Club61 (tanpa buka modal / tekan Cetak Struk).
        $this->queueAutoPrint($payment->order_id, 'filament.partials.walkin-receipt', ['receipt' => $this->completedOrderData], 'startNewTransaction');
    }

    public function closeSuccessModal(): void
    {
        $this->showSuccessModal = false;
        $this->completedOrderData = null;
        $this->posStep = $this->receiptFromHistory ? 'history' : 'selection';
        $this->receiptFromHistory = false;
    }

    // ===================== RIWAYAT TRANSAKSI & CETAK ULANG STRUK =====================

    public string $historyDate = '';

    public string $historySearch = '';

    /** Struk yang sedang tampil dibuka dari Riwayat (tutup modal = kembali ke Riwayat). */
    public bool $receiptFromHistory = false;

    /** Riwayat & cetak ulang struk = izin yang sama dengan yang boleh menerima pembayaran di loket ini. */
    protected function canViewWalkInHistory(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('process_walkin_booking') || $user->can('settle_unpaid_booking'));
    }

    public function getCanShowHistoryTabProperty(): bool
    {
        return $this->canViewWalkInHistory();
    }

    public function showHistory(): void
    {
        if (! $this->canViewWalkInHistory()) {
            \App\Services\Audit\ActivityLogger::accessDenied('membuka riwayat transaksi POS Walk-In tanpa izin');
            Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin untuk melihat riwayat transaksi loket.')->danger()->send();

            return;
        }

        $this->historyDate = $this->historyDate ?: now('Asia/Jakarta')->toDateString();
        $this->posStep = 'history';
    }

    public function showCashier(): void
    {
        $this->posStep = 'selection';
    }

    /**
     * Semua pembayaran yang diterima di loket padel pada tanggal terpilih: transaksi walk-in, pelunasan
     * selisih reschedule, dan pelunasan booking online di kasir. Kosong (bukan 403) untuk staf tanpa izin.
     */
    public function getTransactionHistoryProperty(): \Illuminate\Support\Collection
    {
        if (! $this->canViewWalkInHistory() || $this->posStep !== 'history') {
            return collect();
        }

        return self::frontdeskHistoryRows($this->resolvedHistoryDate(), trim($this->historySearch), proShop: false);
    }

    /**
     * Baris Riwayat Transaksi loket frontdesk padel pada tanggal itu. Transaksi Kasir Pro Shop (jual, tambah alat ke
     * booking, denda alat — payload_log.source) tampil di Riwayat Pro Shop ($proShop = true), sisanya di POS Walk-In;
     * penjualan membership punya riwayat sendiri (order_type MEMBERSHIP). Pemanggil wajib sudah mengecek izin.
     * SENGAJA static: method publik non-static komponen Livewire bisa dipanggil langsung dari browser (hasilnya ikut
     * terkirim) — riwayat tanpa cek izin tidak boleh bisa diambil begitu saja.
     */
    public static function frontdeskHistoryRows(string $date, string $search, bool $proShop): \Illuminate\Support\Collection
    {
        $page = new self;
        $proShopSources = \App\Filament\Pages\KasirProShop::HISTORY_SOURCES;

        // paid_at = saat pembayaran jadi SUCCESS (Modul 17; dulu didekati dengan updated_at, yang ikut berubah setiap kali
        // baris pembayaran disentuh). created_at tagihan selisih = saat reschedule (bisa berhari-hari sebelumnya).
        return \App\Models\Pos\Payment::query()
            ->with(['order.user:id,name,phone', 'order.cashier:id,name', 'order.padelBookings.court:id,name', 'order.refunds', 'order.payments'])
            ->where('status', 'SUCCESS')
            ->where(fn ($q) => $q->where('payment_gateway', 'CASHIER_POS')->orWhereNotNull('pos_shift_id')) // + QR Midtrans dari layar kasir
            ->whereHas('order', fn ($q) => $q->whereIn('order_type', ['WALK_IN', 'ONLINE_BOOKING']))
            ->when(
                $proShop,
                fn ($q) => $q->whereIn('payload_log->source', $proShopSources),
                // Pembayaran tanpa source (walk-in lama, pelunasan) tetap tampil di POS Walk-In.
                fn ($q) => $q->where(fn ($w) => $w->whereNull('payload_log->source')->orWhereNotIn('payload_log->source', $proShopSources)),
            )
            ->whereBetween('paid_at', [
                Carbon::parse($date, 'Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone')),
                Carbon::parse($date, 'Asia/Jakarta')->endOfDay()->setTimezone(config('app.timezone')),
            ])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%")
                    // Pembeli Kasir Pro Shop tidak punya akun — nama & HP-nya tersimpan di order.
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('padelBookings', fn ($b) => $b->where('booking_code', 'like', "%{$search}%")))))
            ->latest('paid_at')
            ->limit(100)
            ->get()
            ->map(function (\App\Models\Pos\Payment $payment) use ($page) {
                $order = $payment->order;
                $log = is_array($payment->payload_log) ? $payment->payload_log : [];

                return [
                    'payment_id' => $payment->id,
                    'time' => ($payment->paid_at ?? $payment->updated_at)->setTimezone('Asia/Jakarta')->format('H:i'),
                    'status' => $page->historyStatusLabel($order),
                    'order_number' => $order->order_number,
                    'type' => $page->transactionTypeLabel($payment, $log),
                    'customer' => $order->user?->name ?? $order->customer_name ?? 'Walk-In',
                    'detail' => $order->padelBookings->isEmpty() && ! empty($log['booking_addon'])
                        ? 'Alat untuk #'.$log['booking_addon']['booking_code']
                        : (in_array($log['source'] ?? null, [\App\Services\Pos\ProShopSaleService::SOURCE,\App\Services\Padel\EquipmentLoanService::SOURCE_FINE], true) ? 'Tanpa booking' : $order->padelBookings->map(fn ($b) => ($b->court?->name ?? 'Lapangan').' '.$b->start_time->format('H:i').'-'.$b->end_time->format('H:i'))->implode(', ')),
                    'cashier' => $log['settled_by_name'] ?? $log['cashier_name'] ?? $order->cashier?->name ?? '-',
                    'method' => app(PadelBookingService::class)->formatPaymentMethodLabel($payment->payment_method, $log),
                    'amount' => (float) $payment->amount,
                ];
            });
    }

    // Rekap Penjualan Harian (X-Report) — tanggal = filter tanggal di tab Riwayat.
    protected function salesRecapCounter(): string
    {
        return 'PADEL_FRONTDESK';
    }

    protected function salesRecapDate(): string
    {
        return $this->resolvedHistoryDate();
    }

    protected function salesRecapNotice(string $level, string $title, string $body): void
    {
        $this->shiftNotice($level, $title, $body);
    }

    /** Tanggal riwayat yang aman dipakai (input dari browser bisa berupa teks sembarang → Carbon::parse error). */
    protected function resolvedHistoryDate(): string
    {
        $date = \DateTime::createFromFormat('!Y-m-d', $this->historyDate);

        return $date && $date->format('Y-m-d') === $this->historyDate ? $this->historyDate : now('Asia/Jakarta')->toDateString();
    }

    /** Status order SEKARANG — transaksi yang sudah direfund/dibatalkan tidak boleh tetap tampil "LUNAS". */
    protected function historyStatusLabel(\App\Models\Pos\Order $order): string
    {
        $refunded = (float) $order->refunds->whereIn('status', ['APPROVED', 'PROCESSED'])->sum('refund_amount');
        $refundPending = $order->refunds->where('status', 'PENDING')->isNotEmpty();
        $paid = (float) $order->payments->where('status', 'SUCCESS')->sum('amount');

        return match (true) {
            $refunded > 0 && $refunded >= $paid - 1 => 'DIREFUND',
            $refunded > 0 => 'DIREFUND SEBAGIAN',
            $refundPending => 'REFUND DIPROSES',
            $order->padelBookings->isNotEmpty() && $order->padelBookings->every(fn ($b) => $b->status === 'CANCELLED') => 'DIBATALKAN',
            default => 'LUNAS',
        };
    }

    protected function transactionTypeLabel(\App\Models\Pos\Payment $payment, array $log): string
    {
        return match (true) {
            ($log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA' => 'Pelunasan Selisih Reschedule',
            ! empty($log['booking_addon']) => 'Tambahan Alat',
            ($log['source'] ?? null) === \App\Services\Pos\ProShopSaleService::SOURCE => 'Penjualan Pro Shop',
            ($log['source'] ?? null) === \App\Services\Padel\EquipmentLoanService::SOURCE_FINE => 'Denda Alat',
            $payment->order->order_type === 'WALK_IN' => 'Booking Walk-In',
            default => 'Pelunasan Booking',
        };
    }

    public function viewTransactionReceipt(string $paymentId): void
    {
        abort_unless($this->canViewWalkInHistory(), 403, 'Akses ditolak: Anda tidak memiliki izin untuk melihat struk transaksi loket.');

        $payment = \App\Models\Pos\Payment::query()
            ->where('status', 'SUCCESS')
            ->where(fn ($q) => $q->where('payment_gateway', 'CASHIER_POS')->orWhereNotNull('pos_shift_id')) // + QR Midtrans dari layar kasir
            ->whereHas('order', fn ($q) => $q->whereIn('order_type', ['WALK_IN', 'ONLINE_BOOKING']))
            ->findOrFail($paymentId);

        $this->completedOrderData = $this->buildWalkInReceipt($payment, reprint: true);
        $this->receiptFromHistory = true;
        $this->showSuccessModal = true;
    }

    /**
     * Susun data struk dari data TERSIMPAN (order, booking, alat sewa, pembayaran) — dipakai untuk cetak
     * ulang dari Riwayat dan struk pelunasan, supaya isinya selalu sama dengan yang tercatat di sistem.
     */
    public function buildWalkInReceipt(\App\Models\Pos\Payment $payment, bool $reprint = false): array
    {
        $order = $payment->order()->with(['user', 'cashier', 'padelBookings.court', 'payments'])->firstOrFail();
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];
        $bookings = $order->padelBookings->sortBy('start_time')->values();
        $settings = \App\Models\Pos\ClubFinanceSetting::getSettings();

        $paymentMeta = [];
        if (! empty($log['edc_details'])) {
            $paymentMeta = array_intersect_key($log['edc_details'], array_flip(['terminal', 'card_type', 'card_network', 'card_issuer', 'card_last_4', 'approval_code', 'trace_number']));
        } elseif (! empty($log['qris_details'])) {
            $paymentMeta = [
                'qris_provider' => $log['qris_details']['provider'] ?? null,
                'qris_rrn' => $log['qris_details']['rrn'] ?? null,
            ];
        }

        $paidBefore = (float) $order->payments
            ->where('status', 'SUCCESS')
            // Pembayaran yang terjadi SEBELUM pembayaran ini (waktu, lalu id ULID sebagai penentu kalau sama detik).
            ->filter(fn ($p) => $p->id !== $payment->id
                && (($p->paid_at ?? $p->updated_at)->lt($payment->paid_at ?? $payment->updated_at)
                    || (($p->paid_at ?? $p->updated_at)->eq($payment->paid_at ?? $payment->updated_at) && strcmp($p->id, $payment->id) < 0)))
            ->sum('amount');
        $isSettlement = $paidBefore > 0 || $order->order_type !== 'WALK_IN';

        // Struk PENJUALAN dicetak ulang setelah booking di-reschedule: total order sekarang sudah ikut menghitung
        // tagihan selisih. Kembalikan angka ke nilai saat transaksi (dikurangi seluruh tagihan selisih) supaya
        // struk tidak menampilkan nominal yang tidak pernah dibayar customer di transaksi ini.
        $deltaBills = $order->payments->filter(fn ($p) => in_array($p->status, ['SUCCESS', 'PENDING'], true)
            && (($p->payload_log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA') && $p->id !== $payment->id);
        $sumDelta = fn (string $key) => (float) $deltaBills->sum(fn ($p) => (float) ($p->payload_log[$key] ?? 0));
        $courtDeltaFor = fn ($b) => (float) $deltaBills->filter(fn ($p) => ($p->payload_log['booking_id'] ?? null) === $b->id)
            ->sum(fn ($p) => (float) ($p->payload_log['court_delta'] ?? 0));
        $atSale = ! $isSettlement;
        $wasRescheduled = $bookings->contains(fn ($b) => (int) $b->reschedule_count > 0);
        // Tambah Alat ke Booking: transaksi alat saja, untuk booking yang sudah ada.
        $addonBooking = ! empty($log['booking_addon']['booking_id']) ? PadelBooking::with('court')->find($log['booking_addon']['booking_id']) : null;
        // Penjualan Kasir Pro Shop / denda Peminjaman Alat: transaksi tanpa booking, rinciannya dari item order.
        $isEquipmentSale = ($log['source'] ?? null) === \App\Services\Pos\ProShopSaleService::SOURCE;
        $isEquipmentFine = ($log['source'] ?? null) === \App\Services\Padel\EquipmentLoanService::SOURCE_FINE;

        return [
            'order_number' => $order->order_number,
            'customer_name' => $order->user?->name ?? $order->customer_name ?? 'Walk-In',
            'customer_phone' => $order->user?->phone ?? $order->customer_phone ?? '-',
            'cashier_name' => $log['settled_by_name'] ?? $log['cashier_name'] ?? $order->cashier?->name ?? (auth()->user()?->name ?? '-'),
            'booking_date' => ($bookings->first() ?? $addonBooking)?->booking_date?->translatedFormat('d F Y') ?? '-',
            'addon_for' => $addonBooking ? [
                'code' => $addonBooking->booking_code,
                'court' => $addonBooking->court?->name ?? '-',
                'schedule' => $addonBooking->start_time->format('H:i').'-'.$addonBooking->end_time->format('H:i'),
            ] : null,
            'payment_method' => app(PadelBookingService::class)->formatPaymentMethodLabel($payment->payment_method, $log),
            'subtotal' => (float) $order->subtotal - ($atSale ? $sumDelta('court_delta') : 0),
            'tax_amount' => (float) $order->tax_amount - ($atSale ? $sumDelta('tax_delta') : 0),
            'tax_name' => $settings->tax_name,
            'service_charge' => (float) $order->service_charge - ($atSale ? $sumDelta('admin_fee_delta') : 0),
            'admin_fee_name' => $settings->admin_fee_name,
            'grand_total' => $atSale ? (float) $payment->amount : (float) $order->grand_total,
            'auto_checked_in' => false,
            // Waktu uang diterima (pembayaran jadi SUCCESS), bukan waktu tagihan dibuat.
            'created_at' => ($payment->paid_at ?? $payment->updated_at)->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s'),
            'payment_meta' => $paymentMeta,
            'note' => $atSale && $wasRescheduled ? 'Jadwal di bawah adalah jadwal TERBARU (booking sudah dipindah setelah transaksi ini).' : null,
            'bookings' => $this->receiptBookingLines($bookings, $atSale ? $courtDeltaFor : null),
            // Potongan voucher promo ikut tampil di cetak ulang (dulu hilang — hanya struk pertama yang memuatnya).
            'discount_amount' => (float) $order->discount_amount,
            'voucher_code' => $order->voucher_code,
            'voucher_note' => app(\App\Services\Finance\PromoVoucherService::class)->receiptNote($order->voucher_code),
            'equipment_sale' => $isEquipmentSale,
            'equipment_fine' => $isEquipmentFine,
            // Transaksi Kasir Pro Shop (jual / tambah alat ke booking / denda): kepala & penutup struk "Pro Shop".
            'pro_shop' => $isEquipmentSale || $isEquipmentFine || $addonBooking !== null,
            // Penjualan Pro Shop / denda tidak punya baris alat booking — rinciannya dari item order.
            'equipments' => $isEquipmentSale || $isEquipmentFine
                ? $order->items()->whereIn('item_type', ['EQUIPMENT', 'EQUIPMENT_FINE', 'MERCH'])->get()
                    ->map(fn ($i) => ['name' => $i->item_name, 'quantity' => $i->quantity, 'unit_price' => (float) $i->unit_price, 'price' => (float) $i->subtotal])->all()
                : \App\Models\Padel\PadelBookingEquipment::with('equipment')
                    ->where('order_id', $order->id)
                    ->get()
                    ->map(fn ($e) => ['name' => $e->equipment?->name ?? 'Alat Sewa', 'quantity' => $e->quantity, 'unit_price' => (float) $e->unit_price, 'price' => (float) $e->subtotal])
                    ->all(),
            'receipt_type' => $isSettlement ? 'SETTLEMENT' : 'SALE',
            'paid_before' => $paidBefore,
            'paid_now' => (float) $payment->amount,
            'settlement_label' => ($log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA' ? 'PELUNASAN SELISIH' : 'PELUNASAN',
            'schedule_before' => $log['schedule_before'] ?? null,
            'is_reprint' => $reprint,
            'reprinted_at' => $reprint ? now('Asia/Jakarta')->format('d/m/Y H:i') : null,
        ];
    }

    protected function getViewData(): array
    {
        // WAJIB reset cache kalkulasi harga tepat sebelum render — menjamin angka yang ditampilkan SELALU
        // dihitung ulang dari state TERBARU (misal setelah selectedSlots di-reset pasca pembayaran sukses
        // di request yang sama), bukan angka basi yang keburu ke-cache dari pembacaan lebih awal di action
        // method (misal validasi cashReceived saat checkout). Memoisasi di getter-getter-nya tetap berlaku
        // SELAMA render ini berlangsung (jadi tetap cuma dihitung sekali walau dipanggil ~18x di Blade).
        $this->courtTotalCache = null;
        $this->equipmentTotalCache = null;
        $this->membershipDiscountAmountCache = null;
        $this->benefitQuoteCache = null;
        $this->subtotalCache = null;
        $this->financeCalculationCache = null;
        $this->voucherResultCache = null;

        // Fail-safe sinkronisasi kedaluwarsa — di-throttle max 1x per 15 detik (bukan tiap render/klik).
        // Cache::add() atomic: cuma proses PERTAMA dalam window 15 detik yang benar-benar menjalankan sync,
        // proses lain di window yang sama otomatis skip. Ini murni fail-safe cepat; penegakan expiry yang
        // sebenarnya sudah dijamin scheduled command terpisah, jadi telat beberapa detik di sini aman.
        if (Cache::add('padel_offline_sync_throttle', true, 15)) {
            app(PadelBookingService::class)->syncExpiredAndCompletedBookings();
        }

        $courts = PadelCourt::where('is_active', true)->ordered()->get();
        $peakHours = app(\App\Services\Padel\PeakHourService::class); // jam peak diatur di Master Data
        $isToday = $this->bookingDate === now()->format('Y-m-d');
        // Tanggal tutup / belum dibuka / jam tutup (App\Support\BookingWindow): slot kosong tampil Tutup + banner pesannya.
        $bookingWindowNotice = \App\Support\BookingWindow::dayNotice($this->bookingDate);
        $bookingDateBlocked = \App\Support\BookingWindow::blockedReason($this->bookingDate) !== null;

        // Tarik seluruh booking pada tanggal aktif untuk evaluasi cepat
        $existingBookings = PadelBooking::with(['court', 'user', 'coachingBooking:id,billing'])
            ->whereDate('booking_date', $this->bookingDate)
            ->whereIn('status', ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN', 'COMPLETED'])
            ->get();

        // Susun grid jam operasional dinamis sesuai jam buka & jam tutup lapangan
        $minOpenHour = 6;
        $maxCloseHour = 23;

        if ($courts->isNotEmpty()) {
            $minOpenHour = $courts->min(function ($c) {
                return (int) substr($c->open_time ?: '06:00', 0, 2);
            }) ?? 6;

            $maxCloseHour = $courts->max(function ($c) {
                $val = $c->close_time ?: '23:00';
                return ($val === '00:00' || $val === '24:00') ? 24 : (int) substr($val, 0, 2);
            }) ?? 23;

            $minOpenHour = max(0, min($minOpenHour, 23));
            $maxCloseHour = max($minOpenHour + 1, min($maxCloseHour, 24));
        }

        $operationalHours = [];
        for ($h = $minOpenHour; $h < $maxCloseHour; $h++) {
            $operationalHours[] = [
                'hour' => $h,
                'start_time' => sprintf('%02d:00:00', $h),
                'end_time' => sprintf('%02d:00:00', $h + 1),
                'label' => sprintf('%02d:00', $h),
                'full_label' => sprintf('%02d:00 - %02d:00', $h, $h + 1),
            ];
        }

        // Ambil SEMUA kemungkinan cache lock key sekaligus (1 query batch), bukan Cache::has() satu-satu
        // per slot di dalam loop di bawah — dengan CACHE_STORE=database, itu berarti puluhan query terpisah
        // per render kalau tidak di-batch (misal 5 lapangan x 17 jam operasional = 85+ query Cache::has()).
        $allLockKeys = [];
        foreach ($courts as $court) {
            foreach ($operationalHours as $oh) {
                $allLockKeys[] = "padel_lock:{$court->id}:{$this->bookingDate}:" . sprintf('%02d00', $oh['hour']);
            }
        }
        $lockValues = ! empty($allLockKeys) ? Cache::many($allLockKeys) : [];

        $gridData = [];
        foreach ($courts as $court) {
            $courtRow = [
                'court' => $court,
                'slots' => [],
            ];

            $courtOpen = (int) substr($court->open_time ?: '06:00', 0, 2);
            $courtCloseVal = $court->close_time ?: '23:00';
            $courtClose = ($courtCloseVal === '00:00' || $courtCloseVal === '24:00') ? 24 : (int) substr($courtCloseVal, 0, 2);

            foreach ($operationalHours as $oh) {
                $h = $oh['hour'];
                $startTimeStr = $oh['start_time'];
                $endTimeStr = $oh['end_time'];
                $slotKey = "{$court->id}_{$startTimeStr}";

                $slotRate = $peakHours->rateFor($court, Carbon::parse("{$this->bookingDate} {$startTimeStr}"));
                $isPrime = $slotRate['prime'];
                $rate = $slotRate['price'];

                // Tentukan status slot
                $status = 'AVAILABLE';
                $bookingDetail = null;

                if ($h < $courtOpen || $h >= $courtClose) {
                    $status = 'CLOSED';
                } else {
                    $slotStartDt = "{$this->bookingDate} {$startTimeStr}";
                    $slotEndDt = "{$this->bookingDate} {$endTimeStr}";

                    // Cari booking database yang overlap (slot yang sedang ditahan kasir ini sendiri tidak dihitung)
                    $matchedBooking = $existingBookings->first(function ($b) use ($court, $slotStartDt, $slotEndDt) {
                        return $b->court_id === $court->id
                            && ! in_array($b->id, $this->heldBookingIds, true)
                            && $b->start_time->format('Y-m-d H:i:s') < $slotEndDt
                            && $b->end_time->format('Y-m-d H:i:s') > $slotStartDt;
                    });

                    if (isset($this->selectedSlots[$slotKey])) {
                        // Dipilih kasir tapi keburu diambil customer online / kasir lain (terlihat lewat update realtime)
                        // → kasir tahu sebelum customer membayar.
                        $status = $matchedBooking ? 'TAKEN' : 'SELECTED';
                    } elseif ($matchedBooking) {
                        if ($matchedBooking->status === 'LOCKED' && (int) $matchedBooking->reschedule_count > 0) {
                            // Jadwal hasil reschedule yang selisihnya belum dibayar — kasir klik untuk melunasi.
                            $status = 'UNPAID_DELTA';
                            $bookingDetail = [
                                'id' => $matchedBooking->id,
                                'code' => $matchedBooking->booking_code,
                                'player' => $matchedBooking->user?->name ?? 'Pemain',
                                'status' => 'LOCKED',
                                'is_active_bill' => ($this->settleBill['booking_id'] ?? null) === $matchedBooking->id,
                            ];
                        } elseif (in_array($matchedBooking->status, ['PAID', 'CHECKED_IN', 'COMPLETED'])
                            || ($matchedBooking->status === 'PENDING_PAYMENT' && $matchedBooking->coachingBooking?->isTab())) {
                            // Sesi tagihan berkala coaching (Modul 31): slot terkunci tetap, bukan hold checkout.
                            $status = 'BOOKED';
                            $bookingDetail = [
                                'id' => $matchedBooking->id,
                                'code' => $matchedBooking->booking_code,
                                'player' => $matchedBooking->user?->name ?? 'Pemain',
                                'status' => $matchedBooking->status,
                                'tab' => $matchedBooking->status === 'PENDING_PAYMENT',
                            ];
                        } else {
                            $status = 'LOCKED';
                            $bookingDetail = [
                                'code' => $matchedBooking->booking_code,
                                'player' => 'Checkout...',
                                'status' => 'LOCKED',
                            ];
                        }
                    } else {
                        // Cek Cache Lock (Hold transaksi online) — dari batch $lockValues yang sudah diambil
                        // sekaligus di atas, bukan query Cache::has() baru per slot.
                        $lockKey = "padel_lock:{$court->id}:{$this->bookingDate}:" . sprintf('%02d00', $h);
                        if (! empty($lockValues[$lockKey])) {
                            $status = 'LOCKED';
                            $bookingDetail = [
                                'code' => 'HOLD',
                                'player' => 'Sedang Dipilih',
                                'status' => 'LOCKED',
                            ];
                        }
                    }
                }

                // Jam / tanggal yang sudah lewat: booking lunas & tagihan selisih tetap tampil (riwayat siapa yang main),
                // slot kosong / hold jadi arsir dan tidak bisa dipilih.
                if (in_array($status, ['AVAILABLE', 'LOCKED'], true) && $this->isPastSlot($startTimeStr)) {
                    $status = 'PAST';
                    $bookingDetail = null;
                } elseif ($status === 'AVAILABLE' && ($bookingDateBlocked || \App\Support\BookingWindow::closedHoursReason($this->bookingDate, $startTimeStr, $endTimeStr) !== null)) {
                    $status = 'CLOSED';
                }

                $courtRow['slots'][] = [
                    'slot_key' => $slotKey,
                    'hour' => $h,
                    'start_time' => $startTimeStr,
                    'end_time' => $endTimeStr,
                    'label' => $oh['label'],
                    'full_label' => $oh['full_label'],
                    'rate' => $rate,
                    'is_prime' => $isPrime,
                    'status' => $status,
                    'booking' => $bookingDetail,
                ];
            }

            // Harga termurah lapangan di tanggal ini (Harga Khusus / Harga Dasar) untuk keterangan di kolom lapangan.
            $courtRow['from_rate'] = (float) (collect($courtRow['slots'])->filter(fn ($s) => $s['hour'] >= $courtOpen && $s['hour'] < $courtClose)->min('rate')
                ?? $court->hourly_rate_regular);
            $gridData[] = $courtRow;
        }

        // Ringkasan okupansi tanggal yang sedang ditampilkan di grid
        $totalSlotsAll = 0;
        $bookedSlotsAll = 0;
        foreach ($gridData as $courtRow) {
            foreach ($courtRow['slots'] as $slot) {
                if ($slot['status'] === 'PAST' || $this->isPastSlot($slot['start_time'])) {
                    continue;
                }
                $totalSlotsAll++;
                if (in_array($slot['status'], ['BOOKED', 'LOCKED', 'SELECTED', 'TAKEN', 'UNPAID_DELTA'], true)) {
                    $bookedSlotsAll++;
                }
            }
        }

        // Statistik & riwayat transaksi walk-in HARI INI — di-cache 10 detik. Angka ini cuma buat
        // ditampilin di pojok layar (bukan input keputusan transaksi), jadi selisih beberapa detik
        // gak masalah, tapi query-nya sendiri gak perlu jalan ulang di SETIAP klik slot/tombol lain.
        // "Hari ini" = hari WIB sebagai rentang created_at (bukan whereDate) supaya index orders(order_type, created_at)
        // terpakai — whereDate membungkus kolom dengan DATE() dan memaksa MySQL menyisir semua order.
        $todayRange = [
            Carbon::now('Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone')),
            Carbon::now('Asia/Jakarta')->endOfDay()->setTimezone(config('app.timezone')),
        ];
        $walkInStatsCacheKey = 'pos_walkin_stats_today:' . now('Asia/Jakarta')->format('Y-m-d');
        $walkInStatsToday = Cache::remember($walkInStatsCacheKey, 10, function () use ($todayRange) {
            $query = \App\Models\Pos\Order::where('order_type', 'WALK_IN')->whereBetween('created_at', $todayRange);

            // Omzet hanya order yang sudah lunas — tagihan coaching belum dilunasi / order batal tidak ikut terhitung.
            return [
                'count' => (clone $query)->count(),
                'revenue' => (float) (clone $query)->where('payment_status', 'PAID')->sum('grand_total'),
            ];
        });

        $recentWalkInOrdersCacheKey = 'pos_walkin_recent_orders:' . now('Asia/Jakarta')->format('Y-m-d');
        $recentWalkInOrderIds = Cache::remember($recentWalkInOrdersCacheKey, 10, function () use ($todayRange) {
            return \App\Models\Pos\Order::where('order_type', 'WALK_IN')
                ->whereBetween('created_at', $todayRange)
                ->latest()
                ->limit(8)
                ->pluck('id');
        });
        // Eager-load relasi tetap dijalankan tiap render (bukan ikut di-cache) supaya data user/booking
        // yang ditampilkan selalu representasi terbaru, hanya DAFTAR ID order-nya yang di-throttle.
        $recentWalkInOrders = \App\Models\Pos\Order::whereIn('id', $recentWalkInOrderIds)
            ->with(['user', 'padelBookings.court'])
            ->latest()
            ->get();

        $equipments = CourtEquipment::where('is_active', true)->orderBy('type')->orderBy('name')->get();

        // Hasil pencarian customer sekarang diisi via updatedCustomerSearch() (lifecycle hook), bukan
        // di-query ulang di sini setiap render — lihat method updatedCustomerSearch().
        $searchResults = $this->searchResults;

        // Slot pilihan kasir yang keburu diambil orang — ditandai juga di daftar "Slot Dipilih".
        $takenSlotKeys = collect($gridData)->flatMap(fn ($row) => $row['slots'])->where('status', 'TAKEN')->pluck('slot_key')->all();

        return [
            'gridData' => $gridData,
            'takenSlotKeys' => $takenSlotKeys,
            'operationalHours' => $operationalHours,
            'equipments' => $equipments,
            'searchResults' => $searchResults,
            'isToday' => $isToday,
            'bookingWindowNotice' => $bookingWindowNotice,
            'totalSlotsAll' => $totalSlotsAll,
            'bookedSlotsAll' => $bookedSlotsAll,
            'walkInStatsToday' => $walkInStatsToday,
            'recentWalkInOrders' => $recentWalkInOrders,
            'activeShift' => $this->activeShift,
        ];
    }
}
