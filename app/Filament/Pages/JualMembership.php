<?php

namespace App\Filament\Pages;

use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Finance\TaxAndFeeService;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Payment\PaymentOrchestratorService;
use App\Services\Pos\PosPaymentProof;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use UnitEnum;

class JualMembership extends Page
{
    use \App\Livewire\Concerns\AutoPrintsReceipts;
    use \App\Livewire\Concerns\PrintsDailySalesRecap;
    use HasPageShield;
    // Modul 33: cek email walk-in bentrok & isi nomor HP akun terpilih — sama dengan kasir Booking Lapangan.
    use \App\Livewire\Concerns\ResolvesPosCustomer;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'POS Jual Membership';

    protected static string|UnitEnum|null $navigationGroup = 'Operasional Harian';

    protected static ?string $title = 'POS Penjualan Membership Frontdesk';

    protected static ?int $navigationSort = 4;

    // Disembunyikan dari sidebar — sekarang diakses lewat tab "POS Jual Membership" di halaman
    // Walk-In Booking (lihat resources/views/filament/partials/pos-subnav.blade.php). Halaman &
    // route-nya tetap aktif penuh (HasPageShield/permission tidak berubah), cuma gak dobel muncul
    // di sidebar lagi.
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.jual-membership';

    /**
     * Loket membership berdiri di meja frontdesk yang SAMA dengan POS Walk-In Padel, jadi uangnya masuk
     * ke shift PADEL_FRONTDESK (counter 'MEMBERSHIP_DESK' tidak punya layar buka shift di mana pun —
     * dulu kasir biasa jadi tidak pernah bisa menjual, dan penjualan super_admin tercatat tanpa shift).
     */
    public const COUNTER = 'PADEL_FRONTDESK';

    // Pelanggan
    public string $customerMode = 'quick_create'; // quick_create | search
    public string $customerSearch = '';
    public ?string $selectedCustomerId = null;
    public ?string $selectedCustomerName = null;
    public ?string $selectedCustomerPhone = null;

    /** Modul 33: nomor HP yang diisi kasir untuk akun terpilih yang belum punya nomor (saveSelectedCustomerPhone). */
    public string $selectedCustomerNewPhone = '';

    // Quick create customer
    public string $walkInName = '';
    public string $walkInPhone = '';
    public string $walkInEmail = '';

    // Paket Membership
    public ?string $selectedPlanId = null;

    // Step Alur Terminal Kasir: 'selection' (Pilih Pelanggan & Paket) atau 'payment' (Layar Bayar) —
    // sama persis dengan alur BookOfflineCourt (selection/payment/receipt), minus langkah struk
    // in-page karena struk membership sudah ditampilkan lewat modal ($showSuccessModal).
    public string $posStep = 'selection';

    // Pembayaran (100% Cashless) — properti & markup form disamakan manual dengan POS Walk-In
    // Booking (BookOfflineCourt.php / book-offline-court.blade.php) supaya kedua loket konsisten.
    public string $paymentMethod = 'QRIS'; // DEBIT_CARD | CREDIT_CARD | QRIS

    public string $edcTerminal = 'EDC_BCA'; // hanya EDC_BCA (PosPaymentProof::ACTIVE_EDC_TERMINALS)
    public string $edcCardType = 'DEBIT'; // DEBIT, CREDIT
    public string $edcCardNetwork = 'GPN'; // GPN, VISA, MASTERCARD, JCB, AMEX, LAINNYA
    public string $edcBank = 'BCA'; // BCA, MANDIRI, BNI, BRI, CIMB, PERMATA, DANAMON, OVERSEAS, LAINNYA
    public string $edcLast4 = '';
    public string $edcApprovalCode = '';
    public string $edcTraceNumber = '';

    public string $qrisProvider = 'BCA_QRIS'; // BCA_QRIS, MANDIRI_QRIS, GOPAY_QRIS, LAINNYA
    public string $qrisRrn = '';
    public string $qrisSenderName = '';

    /** MIDTRANS = QR dinamis Midtrans tampil di layar (utama); MANUAL = QRIS statis + input RRN (cadangan). */
    public string $qrisMode = 'MIDTRANS';

    /** Metode "Bayar Otomatis" pilihan kasir (QRIS / VA yang dicentang "Tampil di Kasir"). Divalidasi ulang di server. */
    public string $posOnlineMethod = 'QRIS';

    /** QR Midtrans yang menunggu dibayar (popup + polling). Dikunci: id tagihan tidak boleh diganti dari browser. */
    #[\Livewire\Attributes\Locked]
    public ?array $pendingQris = null;

    // Modal Sukses & Struk
    public bool $showSuccessModal = false;
    public ?array $completedMembershipData = null;

    public function selectCustomer(string $userId): void
    {
        $user = User::find($userId);
        if ($user) {
            $this->selectedCustomerId = $user->id;
            $this->selectedCustomerName = $user->name;
            $this->selectedCustomerPhone = $user->phone;
            $this->selectedCustomerNewPhone = '';
            $this->customerSearch = '';
        }
    }

    public function clearCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->selectedCustomerName = null;
        $this->selectedCustomerPhone = null;
        $this->selectedCustomerNewPhone = '';
        $this->customerSearch = '';
    }

    public function selectPlan(string $planId): void
    {
        $this->selectedPlanId = $planId;
    }

    // Sama persis dengan BookOfflineCourt::setPaymentMethod() — markup form pembayaran di
    // jual-membership.blade.php ditulis manual meniru book-offline-court.blade.php.
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
    }

    public function getSelectedPlanProperty(): ?MembershipPlan
    {
        return $this->selectedPlanId
            ? MembershipPlan::with('benefits')->find($this->selectedPlanId)
            : null;
    }

    public function getPlansProperty()
    {
        return MembershipPlan::with('benefits')
            ->where('is_active', true)
            ->get();
    }

    public function getSearchResultsProperty(): array
    {
        $term = trim($this->customerSearch);
        if (strlen($term) < 2) {
            return [];
        }

        return User::where('name', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")
            ->limit(8)
            ->get(['id', 'name', 'phone', 'email'])
            ->toArray();
    }

    public function getSubtotalProperty(): float
    {
        return $this->selectedPlan ? (float) $this->selectedPlan->price : 0.00;
    }

    public function getFinanceCalculationProperty(): array
    {
        return app(TaxAndFeeService::class)->calculate(
            subtotal: $this->subtotal,
            discountAmount: 0,
            channel: 'POS_WALKIN',
            module: 'MEMBERSHIP'
        );
    }

    public function getGrandTotalProperty(): float
    {
        return (float) $this->financeCalculation['grand_total'];
    }

    /** Shift kasir aktif di meja frontdesk (dipakai bersama POS Walk-In Padel). */
    public function getActiveShiftProperty(): ?PosCashierShift
    {
        return PosCashierShift::getActiveShift(self::COUNTER);
    }

    /**
     * Setiap uang yang masuk WAJIB tercatat di shift kasir yang terbuka — berlaku juga untuk super_admin,
     * kalau tidak penjualannya tidak pernah muncul di rekap setoran tutup shift.
     */
    protected function ensureActiveShift(): bool
    {
        if ($this->activeShift) {
            return true;
        }

        Notification::make()
            ->title('Shift Kasir Belum Dibuka')
            ->body('Buka shift kasir Padel Frontdesk dulu di halaman POS Walk-In Booking sebelum menerima pembayaran membership. Semua penjualan wajib masuk rekap shift (berlaku juga untuk super admin).')
            ->danger()
            ->send();

        return false;
    }

    // Sama persis dengan BookOfflineCourt::proceedToPayment() — validasi paket & data pelanggan
    // dulu sebelum pindah ke layar pembayaran khusus (posStep 'payment').
    public function proceedToPayment(): void
    {
        if (! $this->ensureActiveShift()) {
            return;
        }

        if (! $this->selectedPlan) {
            Notification::make()->title('Paket Belum Dipilih')->body('Silakan pilih salah satu paket membership terlebih dahulu.')->warning()->send();
            return;
        }

        if ($this->customerMode === 'search') {
            if (empty($this->selectedCustomerId)) {
                Notification::make()->title('Customer Belum Dipilih')->body('Silakan cari dan pilih pelanggan terdaftar, atau beralih ke form Walk-In Baru.')->warning()->send();
                return;
            }
        } else {
            $name = trim($this->walkInName);
            $phone = trim($this->walkInPhone);

            if (empty($name)) {
                Notification::make()->title('Nama Wajib Diisi')->body('Silakan masukkan nama pelanggan walk-in.')->warning()->send();
                return;
            }

            if (! \App\Support\PersonName::isValid($name)) {
                Notification::make()->title('Nama Tidak Valid')->body(\App\Support\PersonName::MESSAGE)->warning()->send();
                return;
            }

            if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 8) {
                Notification::make()->title('Nomor Telepon Tidak Valid')->body('Silakan masukkan nomor WhatsApp / telepon aktif minimal 8 digit.')->warning()->send();
                return;
            }

            // Modul 33: email milik akun lain → arahkan ke Cari Akun (dicek lagi saat transaksi diproses).
            if (! $this->walkInEmailAvailable(app(\App\Services\Padel\PadelBookingService::class))) {
                return;
            }
        }

        $this->posStep = 'payment';
    }

    public function backToSelection(): void
    {
        $this->posStep = 'selection';
    }

    public function mount(): void
    {
        // Bayar Otomatis yang masih menunggu (halaman sempat di-refresh / tertutup) → popup dilanjutkan.
        $this->pendingQris = app(\App\Services\Pos\PosAutoPayment::class)->resumeFor(self::COUNTER, 'MEMBERSHIP_POS', auth()->id());
    }

    public function submitSale(): void
    {
        // Masih ada Bayar Otomatis yang menunggu customer → selesaikan / batalkan dulu (popup tetap tampil).
        if ($this->pendingQris) {
            Notification::make()->title('Masih Menunggu Pembayaran')->body('Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.')->warning()->send();

            return;
        }

        // 0. Guard izin di SERVER — tombol di UI bukan pengaman (request Livewire bisa direkayasa).
        if (! auth()->user()?->can('sell_membership')) {
            ActivityLogger::accessDenied('mencoba menjual membership di POS Jual Membership tanpa izin [sell_membership]');
        }
        abort_unless(auth()->user()?->can('sell_membership'), 403, 'Akses ditolak: Anda tidak memiliki izin [sell_membership] untuk menjual membership.');

        // 0.1 Anti submit ganda: selama struk transaksi sebelumnya masih tampil, keranjang itu SUDAH lunas.
        // Tanpa guard ini submit kedua menerbitkan order PAID + kartu kedua (perpanjangan = kuota dobel).
        if ($this->showSuccessModal) {
            Notification::make()->title('Transaksi Sudah Diproses')->body('Tutup struk lalu mulai transaksi baru untuk penjualan berikutnya.')->warning()->send();

            return;
        }

        $plan = $this->selectedPlan;
        if (! $plan) {
            Notification::make()->title('Silakan pilih paket membership terlebih dahulu.')->danger()->send();

            return;
        }

        // 0.2 Shift kasir wajib terbuka untuk SEMUA user (termasuk super_admin) — dicek di halaman sebelum
        // transaksi DB dimulai, tidak bergantung pada pengecualian di PaymentOrchestratorService.
        if (! $this->ensureActiveShift()) {
            return;
        }

        // 1. Venue 100% cashless.
        $method = strtoupper(trim($this->paymentMethod));
        if (in_array($method, ['CASH', 'TUNAI'], true)) {
            Notification::make()->title('Pembayaran tunai (CASH) tidak diperbolehkan. Venue Club 61 beroperasi 100% Cashless.')->danger()->send();

            return;
        }

        // QR Midtrans di layar kasir: tidak ada bukti yang diketik kasir — lunas dikonfirmasi Midtrans.
        $useQr = in_array($method, ['QRIS', 'QRIS_STATIS'], true) && $this->qrisMode === 'MIDTRANS'
            && \App\Services\Pos\PosMidtransQrisService::resolveMethod($this->posOnlineMethod, (float) $this->grandTotal) !== null;

        // 2. Bukti bayar divalidasi helper yang SAMA dengan POS Walk-In / pelunasan tagihan: metode tak dikenal
        // (mis. 'GRATIS' hasil rekayasa request) ditolak, dan satu RRN / approval code EDC hanya boleh
        // melunasi satu transaksi.
        [$proofMethod, $proofInput] = PosPaymentProof::fromPosForm(
            $this->paymentMethod, $this->edcTerminal, $this->edcCardType, $this->edcLast4, $this->edcApprovalCode,
            $this->edcTraceNumber, $this->qrisProvider, $this->qrisRrn, $this->qrisSenderName, $this->edcCardNetwork, $this->edcBank,
        );

        $cashier = auth()->user();
        $grandTotal = (float) $this->grandTotal;
        $financeCalculation = $this->financeCalculation;

        // 3. Anti double-click / dua tab: request kedua dengan isian sama ditolak selama yang pertama diproses.
        // Validasi bukti bayar dijalankan DI DALAM lock dan transaksi sudah commit sebelum lock dilepas, jadi
        // request susulan dengan RRN/approval yang sama pasti tertahan cek duplikat PosPaymentProof.
        $customerKey = $this->selectedCustomerId ?: (preg_replace('/\D/', '', $this->walkInPhone) ?: 'walkin');
        $lock = Cache::lock('pos_membership_sale:'.$cashier->id.':'.$plan->id.':'.$customerKey, 30);
        if (! $lock->get()) {
            Notification::make()->title('Transaksi Sedang Diproses')->body('Penjualan yang sama sedang diproses. Tunggu sebentar, jangan klik dua kali.')->warning()->send();

            return;
        }

        try {
            try {
                $proofPayload = $useQr ? [] : PosPaymentProof::validate($proofMethod, $proofInput, $grandTotal);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                Notification::make()->title('Bukti Pembayaran Tidak Valid')->body($e->getMessage())->danger()->send();

                return;
            }

            $balanceService = app(MembershipBalanceService::class);
            $orchestrator = app(PaymentOrchestratorService::class);

            $receipt = DB::transaction(function () use ($balanceService, $orchestrator, $cashier, $plan, $method, $proofPayload, $grandTotal, $financeCalculation, $useQr) {
                // 1. Resolve customer
                if ($this->selectedCustomerId) {
                    $customer = User::findOrFail($this->selectedCustomerId);
                } else {
                    $phone = trim($this->walkInPhone);
                    if (empty($phone)) {
                        throw new \DomainException('Nomor WhatsApp customer walk-in wajib diisi.');
                    }

                    // Modul 33: satu jalur pembuatan akun kasir (sama dengan Booking Lapangan) — nomor HP dinormalisasi
                    // sebelum dicari (dulu "0812-…" / "+62…" tidak cocok dengan akun yang sama → akun dobel), email milik
                    // akun lain ditolak, password awal 6 digit terakhir HP (bisa login dengan nomor HP).
                    $customer = app(\App\Services\Padel\PadelBookingService::class)->findOrCreateWalkInCustomer(
                        name: \App\Support\PersonName::clean($this->walkInName) ?: 'Member Walk-In',
                        phone: $phone,
                        email: trim($this->walkInEmail) !== '' ? trim($this->walkInEmail) : null,
                        rejectTakenEmail: true,
                    );
                }

                $orderNumber = 'ORD-POS-MBR-' . strtoupper(Str::random(8));

                // 2. Buat Order POS — order_type 'MEMBERSHIP' (BUKAN 'WALK_IN'), sama seperti jalur
                // pembelian membership online di MembershipController.php. Statistik & daftar
                // "Transaksi Walk-In Terakhir" di halaman Walk-In Booking cuma nge-query order_type
                // 'WALK_IN' (khusus booking lapangan) — kalau di sini ikut 'WALK_IN', omzet & histori
                // penjualan membership akan nyasar tercampur ke situ.
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'user_id' => $customer->id,
                    'cashier_id' => $cashier->id,
                    'order_type' => 'MEMBERSHIP',
                    'subtotal' => $financeCalculation['subtotal'],
                    'discount_amount' => 0.00,
                    'voucher_code' => null,
                    'tax_amount' => $financeCalculation['tax_amount'],
                    'service_charge' => $financeCalculation['admin_fee_amount'],
                    'grand_total' => $grandTotal,
                    'payment_status' => 'UNPAID',
                ]);

                $order->items()->create([
                    'item_type' => 'MEMBERSHIP',
                    'reference_id' => $plan->id,
                    'item_name' => 'Membership: ' . $plan->name,
                    'quantity' => 1,
                    'unit_price' => $plan->price,
                    'subtotal' => $plan->price,
                ]);

                // Anti-race: kunci row customer ini DULU sebelum cek existing membership. Krusial khusus
                // buat customer yang BELUM punya UserMembership sama sekali — lockForUpdate() di query
                // existingActive di bawah tidak mengunci apa pun kalau belum ada row yang match, jadi tanpa
                // baris ini 2 kasir yang submit hampir bersamaan buat customer yang sama bisa sama-sama
                // lolos "belum ada yang aktif" dan bikin 2 kartu ACTIVE + kuota ke-top-up dua kali padahal
                // uang yang masuk kasir cuma sekali. Locking row user memaksa transaksi kedua menunggu
                // commit yang pertama selesai.
                User::where('id', $customer->id)->lockForUpdate()->first();

                // 2b. Cek apakah customer ini sudah punya membership AKTIF (row-lock: cegah 2 transaksi
                // kasir bersamaan buat customer yang sama menghasilkan 2 kartu ganda -> lihat invarian 5.1/5.2 PRD Modul 05)
                $existingActive = UserMembership::where('user_id', $customer->id)
                    ->where('status', 'ACTIVE')
                    ->where(function ($q) {
                        $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
                    })
                    ->lockForUpdate()
                    ->first();

                $membershipOptions = [
                    'order_id' => $order->id,
                    'sold_by_admin_id' => $cashier->id,
                ];

                if ($existingActive && $existingActive->plan_id === $plan->id) {
                    // 3a. Paket SAMA yang masih aktif -> RENEWAL. Kuota digabung ke kartu LAMA (bukan kartu baru
                    // yang berdiri sendiri), lewat renewal_of_id -> ditangani MembershipFulfillmentHandler saat lunas.
                    $membershipOptions['status'] = 'PENDING_PAYMENT';
                    $membershipOptions['renewal_of_id'] = $existingActive->id;
                    $balanceService->purchasePlan($customer, $plan, $membershipOptions);
                } elseif ($existingActive && $useQr) {
                    // Upgrade langsung menguras kuota kartu lama (tidak bisa dibalik kalau QR batal / kedaluwarsa).
                    throw new \DomainException('Upgrade ke paket lain belum bisa dibayar dengan QR Midtrans karena kartu lama langsung dialihkan. Gunakan Kartu Debit/Kredit (EDC) atau QRIS Manual.');
                } elseif ($existingActive) {
                    // 3b. Paket BEDA sementara kartu lama masih aktif -> UPGRADE. Sisa kuota lama di-rollover
                    // (ROLLOVER_OUT/ROLLOVER_IN, bukan hangus diam-diam), kartu lama ditandai UPGRADED.
                    // Aktivasi instan karena kasir sudah terima pembayaran EDC/QRIS di tempat.
                    $membershipOptions['activate_now'] = true;
                    $balanceService->upgradeMembership($existingActive, $plan, $membershipOptions);
                } else {
                    // 3c. Belum punya membership aktif sama sekali -> pembelian baru murni.
                    $membershipOptions['status'] = 'PENDING_PAYMENT';
                    $balanceService->purchasePlan($customer, $plan, $membershipOptions);
                }

                // 4. Susun payload_log audit finansial — bukti bayar tervalidasi (qris_details / edc_details)
                // berformat identik dengan POS Walk-In, jadi rekap settlement tutup shift membacanya sama.
                $payloadLog = array_merge([
                    'cashier_id' => $cashier->id,
                    'cashier_name' => $cashier->name,
                    'source' => 'MEMBERSHIP_POS',
                    'payment_method' => $method,
                ], $proofPayload);

                // QR Midtrans: kartu tetap PENDING_PAYMENT sampai Midtrans mengonfirmasi (lalu aktif lewat fulfillment).
                if ($useQr) {
                    $payloadLog['payment_method'] = 'QRIS';

                    return ['pending_qris' => app(\App\Services\Pos\PosAutoPayment::class)->open($order, self::COUNTER, $cashier, $payloadLog, $this->posOnlineMethod)];
                }

                // 5. Mark Order As Paid (eksekusi pelunasan kasir) di shift meja frontdesk.
                $orchestrator->markOrderAsPaid($order, [
                    'payment_gateway' => 'CASHIER_POS',
                    'counter' => self::COUNTER,
                    'transaction_id' => $orderNumber,
                    'payment_method' => $method,
                    'amount' => $grandTotal,
                    'cashier_id' => $cashier->id,
                    'payload_log' => $payloadLog,
                ]);

                // 5b. Pengaman terakhir: shift bisa saja ditutup di antara cek di atas & pelunasan. Uang tanpa
                // shift tidak pernah muncul di rekap setoran -> batalkan seluruh transaksi.
                $payment = Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->latest()->first();
                if (! $payment || ! $payment->pos_shift_id) {
                    throw new \DomainException('Pembayaran tidak tercatat di shift kasir yang aktif. Transaksi dibatalkan — pastikan shift Padel Frontdesk terbuka lalu ulangi.');
                }

                // 6. Data Struk Sukses — penyusun yang SAMA dengan cetak ulang dari Riwayat Transaksi. Isinya
                // dibekukan ke payload_log['receipt_snapshot'] supaya cetak ulang menampilkan kuota & masa
                // aktif SAAT PENJUALAN, bukan saldo terkini (yang sudah terpakai / diperpanjang lagi).
                $receipt = $this->buildMembershipReceipt($order->fresh(['user', 'cashier', 'items', 'payments']));
                $payment->update([
                    'payload_log' => array_merge($payment->payload_log ?? [], [
                        'receipt_snapshot' => array_intersect_key($receipt, array_flip(self::RECEIPT_SNAPSHOT_KEYS)),
                    ]),
                ]);

                return $receipt;
            });
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Transaksi Gagal: ' . $e->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            $lock->release();
        }

        // QR Midtrans: tunggu customer scan, struk keluar setelah lunas (pollPendingQris).
        if (isset($receipt['pending_qris'])) {
            $this->clearCart();
            $this->pendingQris = $receipt['pending_qris'];

            return;
        }

        // 7. Keranjang & bukti bayar langsung dikosongkan — yang tersisa cuma data struk. Submit ulang (klik
        // ganda / request susulan) tidak punya paket lagi untuk diproses.
        $this->clearCart();
        $this->completedMembershipData = $receipt;
        $this->receiptFromHistory = false;
        $this->showSuccessModal = true;

        Notification::make()
            ->title('Membership berhasil diterbitkan dan langsung aktif!')
            ->success()
            ->send();

        // Struk langsung dicetak di aplikasi Club61 — sama dengan Bayar Otomatis (semua metode, satu alur).
        if ($orderId = Order::where('order_number', $receipt['order_number'] ?? null)->value('id')) {
            $this->queueAutoPrint($orderId, 'pos.receipts.membership', ['receipt' => $receipt], 'closeReceipt');
        }
    }

    // ===================== QRIS MIDTRANS (QR DI LAYAR KASIR) =====================

    /** Tagihan QR yang sedang ditampilkan — milik loket ini & dibuat dari layar Jual Membership. */
    protected function pendingQrisPayment(): ?Payment
    {
        $id = $this->pendingQris['payment_id'] ?? null;
        $payment = $id ? Payment::with('order')->find($id) : null;

        return $payment && ($payment->payload_log['counter'] ?? null) === self::COUNTER
            && ($payment->payload_log['source'] ?? null) === 'MEMBERSHIP_POS' && isset($payment->payload_log['pos_qris'])
            ? $payment
            : null;
    }

    public function pollPendingQris(): void
    {
        $payment = $this->pendingQrisPayment();
        if (! $payment) {
            $this->pendingQris = null;

            return;
        }

        $status = app(\App\Services\Pos\PosAutoPayment::class)->status($payment->id);

        if ($status === \App\Services\Pos\PosAutoPayment::PAID) {
            $this->completePendingQris($payment);
        } elseif ($status !== \App\Services\Pos\PosAutoPayment::PENDING) {
            $this->pendingQris = null;
            Notification::make()
                ->title($status === \App\Services\Pos\PosAutoPayment::EXPIRED ? 'QR Kedaluwarsa' : 'QR Dibatalkan')
                ->body('Pembayaran QRIS tidak diterima. Penjualan membership dibatalkan.')
                ->warning()
                ->send();
        }
    }

    public function cancelPendingQris(): void
    {
        abort_unless(auth()->user()?->can('sell_membership'), 403);
        $payment = $this->pendingQrisPayment();
        if (! $payment) {
            $this->pendingQris = null;

            return;
        }

        $status = app(\App\Services\Pos\PosAutoPayment::class)->cancel($payment->id);
        if ($status === \App\Services\Pos\PosAutoPayment::PAID) {
            // Customer ternyata sudah bayar tepat sebelum dibatalkan — uang sudah masuk, transaksi diteruskan.
            $this->completePendingQris($payment);

            return;
        }
        if ($status === \App\Services\Pos\PosAutoPayment::PENDING) {
            Notification::make()->title('QR Belum Bisa Dibatalkan')->body(\App\Services\Pos\PosAutoPayment::cannotCancelYetMessage())->warning()->send();

            return;
        }

        $this->pendingQris = null;
        Notification::make()->title('QR Dibatalkan')->body('Penjualan membership dibatalkan.')->success()->send();
    }

    /** Hanya di laptop developer tanpa server key Midtrans (QR mock). */
    public function simulatePendingQrisPaid(): void
    {
        $payment = $this->pendingQrisPayment();
        abort_unless($payment && ($this->pendingQris['is_mock'] ?? false) && ! app()->environment('production'), 403);

        app(\App\Services\Pos\PosAutoPayment::class)->simulatePaid($payment->id);
        $this->pollPendingQris();
    }

    protected function completePendingQris(Payment $payment): void
    {
        $payment = $payment->fresh();
        $receipt = $this->buildMembershipReceipt(Order::with(['user', 'cashier', 'items', 'payments'])->findOrFail($payment->order_id));

        // Sama dengan penjualan EDC/QRIS manual: isi struk dibekukan untuk cetak ulang dari Riwayat.
        $payment->update([
            'payload_log' => array_merge($payment->payload_log ?? [], [
                'receipt_snapshot' => array_intersect_key($receipt, array_flip(self::RECEIPT_SNAPSHOT_KEYS)),
            ]),
        ]);

        $this->pendingQris = null;
        $this->completedMembershipData = $receipt;
        $this->receiptFromHistory = false;
        $this->showSuccessModal = true;

        Notification::make()->title('Pembayaran QRIS diterima — membership aktif!')->success()->send();

        // Lunas lewat Bayar Otomatis → struk langsung dicetak di aplikasi Club61 (tanpa buka modal / tekan Cetak Struk).
        $this->queueAutoPrint($payment->order_id, 'pos.receipts.membership', ['receipt' => $receipt], 'closeReceipt');
    }

    // ===================== RIWAYAT TRANSAKSI & CETAK ULANG STRUK =====================

    public string $historyDate = '';

    public string $historySearch = '';

    /** Struk yang sedang tampil dibuka dari Riwayat (tutup = kembali ke Riwayat, keranjang tidak direset). */
    public bool $receiptFromHistory = false;

    /** Riwayat & cetak ulang = izin yang sama dengan yang boleh menjual membership di loket. */
    protected function canViewMembershipHistory(): bool
    {
        return (bool) auth()->user()?->can('sell_membership');
    }

    public function getCanShowHistoryTabProperty(): bool
    {
        return $this->canViewMembershipHistory();
    }

    public function showHistory(): void
    {
        if (! $this->canViewMembershipHistory()) {
            \App\Services\Audit\ActivityLogger::accessDenied('membuka riwayat transaksi POS Jual Membership tanpa izin [sell_membership]');
            Notification::make()->title('Akses Ditolak')->body('Anda tidak memiliki izin [sell_membership] untuk melihat riwayat penjualan membership.')->danger()->send();

            return;
        }

        $this->historyDate = $this->resolvedHistoryDate();
        $this->posStep = 'history';
    }

    /**
     * Tanggal riwayat yang aman dipakai query. historyDate bisa dikirim apa saja lewat Livewire — string
     * rusak bikin Carbon::parse melempar exception saat render, jadi selain format Y-m-d yang valid
     * dipakai tanggal hari ini.
     */
    protected function resolvedHistoryDate(): string
    {
        $date = trim($this->historyDate);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $date;
        }

        return now('Asia/Jakarta')->toDateString();
    }

    public function updatedHistoryDate(): void
    {
        $this->historyDate = $this->resolvedHistoryDate();
    }

    // Rekap Penjualan Harian (X-Report) — loket & shift yang sama dengan POS Walk-In; tanggal = filter tanggal Riwayat.
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
        $notification = Notification::make()->title($title)->body($body);
        match ($level) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            default => $notification->warning(),
        };
        $notification->send();
    }

    public function showCashier(): void
    {
        $this->posStep = 'selection';
    }

    /** Penjualan membership di loket (bukan pembelian online) pada tanggal terpilih. */
    public function getTransactionHistoryProperty(): \Illuminate\Support\Collection
    {
        if (! $this->canViewMembershipHistory() || $this->posStep !== 'history') {
            return collect();
        }

        $date = $this->resolvedHistoryDate();
        $search = trim($this->historySearch);

        return Order::query()
            ->with(['user:id,name,phone', 'cashier:id,name', 'items', 'payments', 'refunds'])
            ->where('order_type', 'MEMBERSHIP')
            ->whereHas('payments', fn ($q) => $q->where('status', 'SUCCESS')->where(fn ($g) => $g->where('payment_gateway', 'CASHIER_POS')->orWhereNotNull('pos_shift_id'))) // + QR Midtrans dari layar kasir
            ->whereBetween('created_at', [
                \Carbon\Carbon::parse($date, 'Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone')),
                \Carbon\Carbon::parse($date, 'Asia/Jakarta')->endOfDay()->setTimezone(config('app.timezone')),
            ])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->latest()
            ->limit(100)
            ->get()
            ->map(function (Order $order) {
                $payment = $order->payments->where('status', 'SUCCESS')->first();
                $log = is_array($payment?->payload_log) ? $payment->payload_log : [];

                return [
                    'order_id' => $order->id,
                    'time' => $order->created_at->setTimezone('Asia/Jakarta')->format('H:i'),
                    'order_number' => $order->order_number,
                    'type' => 'Penjualan Membership',
                    'customer' => $order->user?->name ?? '-',
                    'detail' => $order->items->pluck('item_name')->implode(', '),
                    'cashier' => $log['cashier_name'] ?? $order->cashier?->name ?? '-',
                    'method' => $this->paymentMethodLabel((string) $payment?->payment_method),
                    'amount' => (float) $order->grand_total,
                    'status' => $this->historyStatusLabel($order),
                ];
            });
    }

    /** Status terkini penjualan di Riwayat — jangan tulis "LUNAS" kalau belakangan di-refund / dibatalkan. */
    protected function historyStatusLabel(Order $order): string
    {
        $refunded = (float) $order->refunds->whereIn('status', ['APPROVED', 'PROCESSED'])->sum('refund_amount');
        $refundPending = $order->refunds->where('status', 'PENDING')->isNotEmpty();
        $paymentStatus = strtoupper((string) $order->payment_status);

        return match (true) {
            $paymentStatus === 'REFUNDED' || ($refunded > 0 && $refunded >= (float) $order->grand_total) => 'DIREFUND',
            $refunded > 0 => 'DIREFUND SEBAGIAN',
            $refundPending => 'REFUND DIPROSES',
            in_array($paymentStatus, ['CANCELLED', 'VOID', 'VOIDED'], true) => 'DIBATALKAN',
            default => 'LUNAS',
        };
    }

    public function viewTransactionReceipt(string $orderId): void
    {
        abort_unless($this->canViewMembershipHistory(), 403, 'Akses ditolak: Anda tidak memiliki izin [sell_membership] untuk melihat struk penjualan membership.');

        $order = Order::query()
            ->where('order_type', 'MEMBERSHIP')
            ->whereHas('payments', fn ($q) => $q->where('status', 'SUCCESS')->where(fn ($g) => $g->where('payment_gateway', 'CASHIER_POS')->orWhereNotNull('pos_shift_id'))) // + QR Midtrans dari layar kasir
            ->with(['user', 'cashier', 'items', 'payments'])
            ->findOrFail($orderId);

        $this->completedMembershipData = $this->buildMembershipReceipt($order, reprint: true);
        $this->receiptFromHistory = true;
        $this->showSuccessModal = true;
    }

    /** Isi struk yang dibekukan saat penjualan (payload_log['receipt_snapshot']) untuk cetak ulang. */
    protected const RECEIPT_SNAPSHOT_KEYS = [
        'membership_code', 'plan_name', 'customer_name', 'customer_phone', 'start_date', 'end_date', 'balances',
        'subtotal', 'tax_amount', 'tax_name', 'service_charge', 'admin_fee_name', 'grand_total',
    ];

    /**
     * Struk membership dari data TERSIMPAN. Penjualan baru menyimpan snapshot struk saat transaksi, jadi
     * cetak ulang menampilkan kartu, masa aktif & kuota SAAT DIJUAL. Penjualan lama (sebelum ada snapshot)
     * jatuh ke data kartu membership + saldo kuota saat ini.
     */
    public function buildMembershipReceipt(Order $order, bool $reprint = false): array
    {
        $payment = $order->payments->where('status', 'SUCCESS')->first();
        $log = is_array($payment?->payload_log) ? $payment->payload_log : [];
        $snapshot = is_array($log['receipt_snapshot'] ?? null) ? $log['receipt_snapshot'] : null;

        // Bukti bayar dinormalisasi seperti BookOfflineCourt::buildWalkInReceipt — format lama penjualan
        // membership (qris_provider/qris_rrn) tetap terbaca.
        $paymentMeta = [];
        if (! empty($log['edc_details'])) {
            $paymentMeta = array_intersect_key($log['edc_details'], array_flip(['terminal', 'card_type', 'card_network', 'card_issuer', 'card_last_4', 'approval_code', 'trace_number']));
        } elseif (! empty($log['qris_details'])) {
            $paymentMeta = [
                'qris_provider' => $log['qris_details']['provider'] ?? $log['qris_details']['qris_provider'] ?? null,
                'qris_rrn' => $log['qris_details']['rrn'] ?? $log['qris_details']['qris_rrn'] ?? null,
            ];
        }

        $common = [
            'order_number' => $order->order_number,
            'cashier_name' => $log['cashier_name'] ?? $order->cashier?->name ?? '-',
            'payment_method' => \App\Services\Pos\PosMidtransQrisService::labelFor($payment?->payload_log) ?? $this->paymentMethodLabel((string) $payment?->payment_method),
            'payment_meta' => $paymentMeta,
            'created_at' => $order->created_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'),
            'is_reprint' => $reprint,
            'reprinted_at' => $reprint ? now('Asia/Jakarta')->format('d/m/Y H:i') : null,
        ];

        if ($snapshot) {
            return array_merge(array_fill_keys(self::RECEIPT_SNAPSHOT_KEYS, null), $snapshot, ['balances' => $snapshot['balances'] ?? []], $common);
        }

        $card = UserMembership::with(['plan', 'balances', 'parentMembership.balances', 'parentMembership.plan'])
            ->where('order_id', $order->id)
            ->latest()
            ->first();
        $isRenewal = $card?->renewal_of_id !== null;
        // Perpanjangan digabung ke kartu lama — kartu itulah yang dipegang member.
        $shownCard = $isRenewal && $card->parentMembership ? $card->parentMembership : $card;
        $settings = \App\Models\Pos\ClubFinanceSetting::getSettings();

        return array_merge([
            'membership_code' => $shownCard?->membership_code ?? '-',
            'plan_name' => ($card?->plan?->name ?? str_replace('Membership: ', '', (string) $order->items->first()?->item_name)).($isRenewal ? ' (Perpanjangan)' : ''),
            'customer_name' => $order->user?->name ?? '-',
            'customer_phone' => $order->user?->phone,
            'start_date' => $shownCard?->start_date ? \Carbon\Carbon::parse($shownCard->start_date)->format('Y-m-d') : '-',
            'end_date' => $shownCard?->end_date ? \Carbon\Carbon::parse($shownCard->end_date)->format('Y-m-d') : '-',
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'tax_name' => $settings->tax_name,
            'service_charge' => (float) $order->service_charge,
            'admin_fee_name' => $settings->admin_fee_name,
            'grand_total' => (float) $order->grand_total,
            'balances' => ($shownCard?->balances ?? collect())->map(fn ($b) => [
                'facility' => $b->facility,
                'quota_type' => $b->quota_type,
                'remaining_quota' => (float) $b->remaining_quota,
                'discount_percent' => $b->discount_percent,
            ])->values()->all(),
        ], $common);
    }

    protected function paymentMethodLabel(string $method): string
    {
        return match (strtoupper($method)) {
            'DEBIT_CARD', 'DEBIT' => 'Kartu Debit (EDC)',
            'CREDIT_CARD', 'CREDIT' => 'Kartu Kredit (EDC)',
            'EDC_BCA' => 'Mesin EDC BCA',
            'EDC_MANDIRI' => 'Mesin EDC Mandiri',
            'QRIS', 'QRIS_STATIS' => 'QRIS Kasir Frontdesk',
            '' => '-',
            default => $method,
        };
    }

    public function closeReceipt(): void
    {
        if ($this->receiptFromHistory) {
            $this->showSuccessModal = false;
            $this->completedMembershipData = null;
            $this->receiptFromHistory = false;
            $this->posStep = 'history';

            return;
        }

        $this->resetSale();
    }

    public function resetSale(): void
    {
        $this->showSuccessModal = false;
        $this->completedMembershipData = null;
        $this->clearCart();
    }

    /** Kosongkan keranjang (pelanggan, paket, bukti bayar) tanpa menyentuh struk yang sedang tampil. */
    protected function clearCart(): void
    {
        $this->selectedPlanId = null;
        $this->selectedCustomerId = null;
        $this->selectedCustomerName = null;
        $this->selectedCustomerPhone = null;
        $this->walkInName = '';
        $this->walkInPhone = '';
        $this->walkInEmail = '';
        $this->paymentMethod = 'QRIS';
        $this->edcLast4 = '';
        $this->edcApprovalCode = '';
        $this->edcTraceNumber = '';
        $this->qrisRrn = '';
        $this->qrisSenderName = '';
        $this->posStep = 'selection';
    }
}
