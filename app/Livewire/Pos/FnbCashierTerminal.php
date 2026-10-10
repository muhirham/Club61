<?php

namespace App\Livewire\Pos;

use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Pos\PosCashierShift;
use App\Services\Fnb\FnbPosService;
use App\Services\Fnb\StationTicketService;
use App\Services\Finance\TaxAndFeeService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kasir F&B — katalog & keranjang menu real (bukan lagi statis/dummy) yang disambungkan ke
 * modul "Kelola Menu F&B" (Modul 15). Skema pembayarannya SENGAJA dibuat identik dengan
 * walk-in booking lapangan (BookOfflineCourt + PadelBookingService::processWalkInCheckout()):
 * shift kasir wajib aktif, kebijakan 100% Cashless (QRIS/EDC, CASH ditolak), dan pelunasan
 * lewat satu-satunya penulis status pembayaran di aplikasi ini (PaymentOrchestratorService).
 */
class FnbCashierTerminal extends Component
{
    use \App\Livewire\Concerns\AutoPrintsReceipts;
    use WithPagination;

    private const COUNTER = 'FNB_COUNTER';

    // 2 baris x 4 kolom di layar kasir desktop
    public const MENUS_PER_PAGE = 8;

    // selection | payment | receipt
    public string $posStep = 'selection';

    /** @var array<string, array{menu_id: string, name: string, price: float, quantity: int}> */
    public array $cart = [];

    public string $activeCategoryId = 'ALL';

    public string $search = '';

    public string $orderType = 'DINE_IN';

    public string $tableNumber = '';

    public string $customerName = '';

    public string $paymentMethod = 'QRIS';

    public string $edcTerminal = 'EDC_BCA';

    public string $edcCardType = 'DEBIT';

    public string $edcCardNetwork = 'GPN';

    public string $edcBank = 'BCA';

    public string $edcLast4 = '';

    public string $edcApprovalCode = '';

    public string $edcTraceNumber = '';

    public string $qrisProvider = 'BCA_QRIS';

    public string $qrisRrn = '';

    public string $qrisSenderName = '';

    /** MIDTRANS = QR dinamis Midtrans tampil di layar (utama); MANUAL = QRIS statis + input RRN (cadangan). */
    public string $qrisMode = 'MIDTRANS';

    /** Metode "Bayar Otomatis" pilihan kasir (QRIS / VA yang dicentang "Tampil di Kasir"). Divalidasi ulang di server. */
    public string $posOnlineMethod = 'QRIS';

    /** QR Midtrans yang menunggu dibayar (popup + polling). Dikunci: id tagihan tidak boleh diganti dari browser. */
    #[\Livewire\Attributes\Locked]
    public ?array $pendingQris = null;

    public bool $showOpenShiftModal = false;

    public bool $showCloseShiftModal = false;

    public bool $showShiftReportModal = false;

    public string $openingNotes = '';

    public string $closingNotes = '';

    public ?array $closingShiftSummary = null;

    /** Rincian per kategori settlement saat modal tutup shift dibuka (snapshot sistem). */
    public array $closingBreakdown = [];

    /** Angka dari struk settlement EDC / mutasi QRIS yang diketik kasir, per kunci kategori. */
    public array $settlementInputs = [];

    public ?array $reportShiftData = null;

    public ?array $completedOrderData = null;

    /** Struk sukses ditampilkan sebagai popup di atas layar POS (tidak menutupi seluruh halaman). */
    public bool $showReceiptModal = false;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        abort_unless(auth()->user() && auth()->user()->isStaff() && auth()->user()->can('access_pos_terminal'), 403, 'Akses Ditolak: Hanya staf kasir atau admin yang dapat mengakses terminal POS.');

        // Bayar Otomatis yang masih menunggu (halaman sempat di-refresh / tertutup) → popup dilanjutkan.
        $this->pendingQris = app(\App\Services\Pos\PosMidtransQrisService::class)->resumeFor(self::COUNTER, 'FNB_POS', auth()->id());
    }

    public function getCategoriesProperty()
    {
        return FnbCategory::orderBy('sort_order')->get();
    }

    public function getMenusProperty()
    {
        $query = FnbMenu::query()->where('is_available', true)->with(['category', 'station']);

        if ($this->activeCategoryId !== 'ALL') {
            $query->where('category_id', $this->activeCategoryId);
        }

        if (trim($this->search) !== '') {
            $query->where('name', 'like', '%'.trim($this->search).'%');
        }

        return $query->orderBy('name')->paginate(self::MENUS_PER_PAGE);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Bawa Pulang tidak punya meja — nomor meja yang sempat diisi dibuang supaya tidak ikut tercatat. */
    public function setOrderType(string $type): void
    {
        $this->orderType = $type === 'TAKE_AWAY' ? 'TAKE_AWAY' : 'DINE_IN';
        if ($this->orderType === 'TAKE_AWAY') {
            $this->tableNumber = '';
        }
    }

    public function getActiveShiftProperty(): ?PosCashierShift
    {
        return PosCashierShift::getActiveShift(self::COUNTER);
    }

    public function getCartSubtotalProperty(): float
    {
        return collect($this->cart)->sum(fn (array $item) => $item['price'] * $item['quantity']);
    }

    public function getFinanceProperty(): array
    {
        return app(TaxAndFeeService::class)->calculate($this->cartSubtotal, 0, 'POS_WALKIN', 'FNB');
    }

    public function getGrandTotalProperty(): float
    {
        return (float) $this->finance['grand_total'];
    }

    public function setActiveCategory(string $categoryId): void
    {
        $this->activeCategoryId = $categoryId;
        $this->resetPage();
    }

    public function addToCart(string $menuId): void
    {
        $this->errorMessage = null;

        $menu = FnbMenu::find($menuId);
        if (! $menu || ! $menu->is_available) {
            $this->errorMessage = 'Menu ini sudah tidak tersedia.';

            return;
        }

        if (isset($this->cart[$menuId])) {
            $this->cart[$menuId]['quantity']++;
        } else {
            $this->cart[$menuId] = [
                'menu_id' => $menu->id,
                'name' => $menu->name,
                'price' => (float) $menu->base_price,
                'quantity' => 1,
                'notes' => '',
            ];
        }
    }

    public function incrementCartItem(string $menuId): void
    {
        if (isset($this->cart[$menuId])) {
            $this->cart[$menuId]['quantity']++;
        }
    }

    public function decrementCartItem(string $menuId): void
    {
        if (! isset($this->cart[$menuId])) {
            return;
        }

        $this->cart[$menuId]['quantity']--;
        if ($this->cart[$menuId]['quantity'] <= 0) {
            unset($this->cart[$menuId]);
        }
    }

    public function removeCartItem(string $menuId): void
    {
        unset($this->cart[$menuId]);
    }

    // ==========================================
    // SHIFT KASIR — pola identik BookOfflineCourt, counter beda ('FNB_COUNTER')
    // ==========================================

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

        // Anti-race: distributed lock sebelum cek+create — pola sama persis dengan
        // BookOfflineCourt::executeOpenShift(), supaya 2 klik/2 kasir bersamaan tidak membentuk
        // 2 shift OPEN sekaligus untuk counter yang sama.
        $lock = Cache::lock('pos_open_shift:'.self::COUNTER, 10);
        if (! $lock->get()) {
            $this->errorMessage = 'Ada permintaan buka shift lain yang sedang diproses. Silakan coba lagi sesaat.';

            return;
        }

        try {
            if ($this->activeShift) {
                $this->errorMessage = 'Loket F&B sudah memiliki sesi shift yang aktif.';
                $this->showOpenShiftModal = false;

                return;
            }

            $shiftNumber = PosCashierShift::generateShiftNumber(self::COUNTER);

            // 100% Cashless: tidak ada modal kas fisik.
            PosCashierShift::create([
                'shift_number' => $shiftNumber,
                'counter' => self::COUNTER,
                'status' => 'OPEN',
                'opened_by_id' => $user->id,
                'opened_at' => Carbon::now('Asia/Jakarta'),
                'starting_cash' => 0.00,
                'expected_cash' => 0.00,
                'opening_notes' => trim($this->openingNotes) ?: null,
            ]);

            $this->errorMessage = null;
            $this->showOpenShiftModal = false;
        } finally {
            $lock->release();
        }
    }

    public function prepareCloseShift(): void
    {
        $shift = $this->activeShift;
        if (! $shift) {
            $this->errorMessage = 'Belum ada shift kasir F&B yang terbuka saat ini.';

            return;
        }

        if ($shift->pendingAutoPaymentCount() > 0) {
            $this->errorMessage = PosCashierShift::PENDING_AUTO_PAYMENT_MESSAGE;

            return;
        }

        $this->closingShiftSummary = $shift->calculateSummary();
        $this->closingBreakdown = $shift->settlementBreakdown();
        $this->settlementInputs = collect($this->closingBreakdown)->mapWithKeys(fn (array $row) => [$row['key'] => ''])->all();
        $this->closingNotes = '';
        $this->errorMessage = null;
        $this->showCloseShiftModal = true;
    }

    /**
     * Tutup shift + rekonsiliasi settlement per kategori pembayaran. Angka sistem SELALU
     * dihitung ulang dari database di dalam transaksi terkunci (lockForUpdate), bukan dari
     * snapshot yang dikirim balik oleh browser — supaya tidak bisa dimanipulasi dari sisi
     * klien, dan supaya 2 kasir yang menekan "Tutup Shift" bersamaan tidak menutup shift
     * yang sama dua kali.
     */
    public function executeCloseShift(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && $user->can('close_pos_shift'),
            403,
            'Akses ditolak: Anda tidak memiliki izin [close_pos_shift] untuk menutup sesi shift kasir.'
        );

        $this->errorMessage = null;

        $result = DB::transaction(function () use ($user) {
            $shift = PosCashierShift::query()
                ->where('counter', self::COUNTER)
                ->where('status', 'OPEN')
                ->latest('opened_at')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                return ['status' => 'NO_SHIFT'];
            }

            if ($shift->pendingAutoPaymentCount() > 0) {
                return ['status' => 'PENDING_AUTO'];
            }

            $breakdown = $shift->settlementBreakdown();

            // Ada transaksi baru masuk setelah modal closing dibuka — angka yang diketik kasir
            // tidak lagi sesuai kondisi sistem, jadi minta cek ulang, jangan dipaksakan.
            if ($this->breakdownSignature($breakdown) !== $this->breakdownSignature($this->closingBreakdown)) {
                return ['status' => 'CHANGED', 'breakdown' => $breakdown];
            }

            $reconciliation = [];
            $totalDifference = 0.0;
            $hasDifference = false;

            foreach ($breakdown as $row) {
                $normalized = str_replace(['.', ',', ' ', 'Rp', 'rp'], '', trim((string) ($this->settlementInputs[$row['key']] ?? '')));

                if ($normalized === '' || ! ctype_digit($normalized)) {
                    return ['status' => 'INVALID', 'label' => $row['label']];
                }

                $settled = (float) $normalized;
                $difference = $settled - $row['system_amount'];
                $totalDifference += $difference;
                $hasDifference = $hasDifference || abs($difference) > 0.009;

                $reconciliation[] = $row + [
                    'settled_amount' => $settled,
                    'difference' => $difference,
                ];
            }

            if ($hasDifference && trim($this->closingNotes) === '') {
                return ['status' => 'NEED_NOTES'];
            }

            $summary = $shift->calculateSummary();

            $shift->update([
                'status' => 'CLOSED',
                'closed_by_id' => $user->id,
                'closed_at' => Carbon::now('Asia/Jakarta'),
                'expected_cash' => $summary['expected_cash'],
                'actual_cash' => 0.00,
                'cash_difference' => 0.00,
                'total_cash_sales' => $summary['total_cash_sales'],
                'total_edc_bca_sales' => $summary['total_edc_bca_sales'],
                'total_edc_mandiri_sales' => $summary['total_edc_mandiri_sales'],
                'total_qris_sales' => $summary['total_qris_sales'],
                'total_other_sales' => $summary['total_other_sales'],
                'total_sales' => $summary['total_sales'],
                'total_transactions' => $summary['total_transactions'],
                'settlement_reconciliation' => $reconciliation,
                'settlement_difference' => $totalDifference,
                'closing_notes' => trim($this->closingNotes) ?: null,
            ]);

            return [
                'status' => 'CLOSED',
                'shift' => $shift,
                'summary' => $summary,
                'reconciliation' => $reconciliation,
                'total_difference' => $totalDifference,
            ];
        });

        if ($result['status'] === 'NO_SHIFT') {
            $this->showCloseShiftModal = false;

            return;
        }

        if ($result['status'] === 'PENDING_AUTO') {
            $this->showCloseShiftModal = false;
            $this->errorMessage = PosCashierShift::PENDING_AUTO_PAYMENT_MESSAGE;

            return;
        }

        if ($result['status'] === 'CHANGED') {
            $previousInputs = $this->settlementInputs;
            $this->closingBreakdown = $result['breakdown'];
            $this->settlementInputs = collect($result['breakdown'])->mapWithKeys(fn (array $row) => [$row['key'] => $previousInputs[$row['key']] ?? ''])->all();
            $this->errorMessage = 'Ada transaksi baru masuk saat closing. Angka sistem sudah diperbarui — cek ulang nominal settlement sebelum menutup shift.';

            return;
        }

        if ($result['status'] === 'INVALID') {
            $this->errorMessage = "Isi nominal settlement untuk kategori \"{$result['label']}\" (angka rupiah, contoh: 150000).";

            return;
        }

        if ($result['status'] === 'NEED_NOTES') {
            $this->errorMessage = 'Ada selisih antara catatan POS dan settlement EDC/QRIS. Wajib isi catatan penutupan sebagai penjelasan selisih.';

            return;
        }

        $shift = $result['shift'];

        $this->reportShiftData = [
            'shift_number' => $shift->shift_number,
            'counter' => $shift->counter,
            'opened_by' => $shift->openedBy?->name ?? 'Kasir',
            'closed_by' => $user->name,
            'opened_at' => $shift->opened_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'),
            'closed_at' => Carbon::now('Asia/Jakarta')->format('d/m/Y H:i'),
            'total_sales' => $result['summary']['total_sales'],
            'total_transactions' => $result['summary']['total_transactions'],
            'reconciliation' => $result['reconciliation'],
            'total_difference' => $result['total_difference'],
            'closing_notes' => $shift->closing_notes,
        ];

        $this->closingBreakdown = [];
        $this->settlementInputs = [];
        $this->showCloseShiftModal = false;
        $this->showShiftReportModal = true;
    }

    private function breakdownSignature(array $breakdown): string
    {
        return collect($breakdown)
            ->map(fn (array $row) => $row['key'].':'.$row['count'].':'.number_format((float) $row['system_amount'], 2, '.', ''))
            ->implode('|');
    }

    // ==========================================
    // PEMBAYARAN
    // ==========================================

    public function setPaymentMethod(string $method): void
    {
        $this->paymentMethod = $method;

        if ($method === 'DEBIT_CARD' || $method === 'DEBIT') {
            $this->edcCardType = 'DEBIT';
            if (! in_array($this->edcCardNetwork, ['GPN', 'MASTERCARD', 'VISA'], true)) {
                $this->edcCardNetwork = 'GPN';
            }
        } elseif ($method === 'CREDIT_CARD' || $method === 'CREDIT') {
            $this->edcCardType = 'CREDIT';
            if (! in_array($this->edcCardNetwork, ['VISA', 'MASTERCARD', 'JCB', 'AMEX', 'UNIONPAY'], true)) {
                $this->edcCardNetwork = 'VISA';
            }
        }
    }

    public function proceedToPayment(): void
    {
        $this->errorMessage = null;

        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;
        if (! $this->activeShift && ! $isSuperAdmin) {
            $this->errorMessage = 'Shift kasir F&B belum dibuka. Buka shift dulu sebelum lanjut ke pembayaran.';
            $this->openShiftModal();

            return;
        }

        if (empty($this->cart)) {
            $this->errorMessage = 'Keranjang masih kosong. Silakan pilih menu terlebih dahulu.';

            return;
        }

        $this->posStep = 'payment';
    }

    public function backToSelection(): void
    {
        $this->posStep = 'selection';
    }

    public function submitFnbCheckout(FnbPosService $service): void
    {
        $this->errorMessage = null;

        // Masih ada Bayar Otomatis yang menunggu customer → selesaikan / batalkan dulu (popup tetap tampil).
        if ($this->pendingQris) {
            $this->errorMessage = 'Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.';

            return;
        }

        abort_unless(
            auth()->user() && auth()->user()->can('process_fnb_order'),
            403,
            'Akses ditolak: Anda tidak memiliki izin [process_fnb_order] untuk memproses transaksi F&B.'
        );

        $isSuperAdmin = auth()->user()->hasRole('super_admin');
        if (! $this->activeShift && ! $isSuperAdmin) {
            $this->errorMessage = 'Shift kasir F&B belum dibuka. Silakan buka sesi shift terlebih dahulu.';

            return;
        }

        if (empty($this->cart)) {
            $this->errorMessage = 'Keranjang masih kosong.';

            return;
        }

        // Validasi ketat metode pembayaran — pola & pesan identik BookOfflineCourt, supaya
        // kasir yang sudah terbiasa dengan layar walk-in booking tidak perlu belajar ulang.
        $method = strtoupper($this->paymentMethod);
        $paymentMeta = [];

        if (in_array($method, ['CASH', 'TUNAI'], true)) {
            $this->errorMessage = 'Pembayaran tunai (CASH) tidak diperbolehkan. Venue Club 61 beroperasi 100% Cashless — gunakan QRIS, EDC, atau Transfer.';

            return;
        }

        if (in_array($method, ['DEBIT_CARD', 'CREDIT_CARD', 'EDC_BCA', 'EDC_MANDIRI', 'DEBIT', 'CREDIT'], true)) {
            $last4 = trim($this->edcLast4);
            $approvalCode = trim($this->edcApprovalCode);
            $traceNumber = trim($this->edcTraceNumber);

            if (! preg_match('/^[0-9]{4}$/', $last4)) {
                $this->errorMessage = 'Silakan masukkan tepat 4 digit angka terakhir dari kartu debit/kredit pelanggan.';

                return;
            }

            if (strlen($approvalCode) < 3) {
                $this->errorMessage = 'Approval code wajib diisi minimal 3 karakter dari slip transaksi mesin EDC.';

                return;
            }

            if (strlen($traceNumber) < 3) {
                $this->errorMessage = 'Trace number wajib diisi minimal 3 karakter dari slip transaksi mesin EDC.';

                return;
            }

            $paymentMeta = [
                'terminal' => $this->edcTerminal,
                'card_type' => (str_contains($method, 'CREDIT') || $this->edcCardType === 'CREDIT') ? 'CREDIT' : 'DEBIT',
                'card_network' => $this->edcCardNetwork,
                'card_issuer' => $this->edcBank,
                'card_last_4' => $last4,
                'approval_code' => $approvalCode,
                'trace_number' => $traceNumber,
            ];
        } elseif (in_array($method, ['QRIS_STATIS', 'QRIS'], true) && $this->qrisMode === 'MIDTRANS' && \App\Services\Pos\PosMidtransQrisService::resolveMethod($this->posOnlineMethod, (float) $this->grandTotal) !== null) {
            // QR Midtrans: tidak ada bukti yang diketik kasir — lunas dikonfirmasi Midtrans.
            $method = 'QRIS_MIDTRANS';
            $paymentMeta = ['pos_online_method' => $this->posOnlineMethod];
        } elseif (in_array($method, ['QRIS_STATIS', 'QRIS'], true)) {
            $rrn = trim($this->qrisRrn);

            if (strlen($rrn) < 6) {
                $this->errorMessage = 'Nomor RRN (Retrieval Reference Number) QRIS wajib diisi minimal 6 digit dari bukti bayar customer.';

                return;
            }

            $paymentMeta = [
                'qris_provider' => $this->qrisProvider,
                'qris_rrn' => $rrn,
                'qris_sender_name' => trim($this->qrisSenderName) ?: null,
            ];
        } else {
            // Metode di luar daftar yang dikenali WAJIB ditolak — tanpa ini, order bisa lolos
            // ditandai LUNAS tanpa satu pun bukti bayar tersimpan (celah fraud 100% Cashless).
            $this->errorMessage = 'Metode pembayaran tidak dikenali. Pilih QRIS, Kartu Debit, atau Kartu Kredit.';

            return;
        }

        $itemsPayload = collect($this->cart)->map(fn (array $item) => [
            'menu_id' => $item['menu_id'],
            'quantity' => $item['quantity'],
            'notes' => $item['notes'] ?? null,
        ])->values()->all();

        try {
            $result = $service->checkout(
                items: $itemsPayload,
                orderType: $this->orderType,
                tableNumber: $this->orderType === 'DINE_IN' ? (trim($this->tableNumber) ?: null) : null,
                cashier: auth()->user(),
                paymentMethod: $method === 'QRIS_MIDTRANS' ? $method : $this->paymentMethod,
                paymentMeta: $paymentMeta,
                customerName: trim($this->customerName) ?: null,
            );
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        // QR Midtrans: order menunggu customer scan. Struk keluar setelah lunas (pollPendingQris).
        if (! empty($result['pending_qris'])) {
            $this->pendingQris = $result['pending_qris'];
            $this->resetFnbCart();

            return;
        }

        $order = $result['order'];

        $this->completedOrderData = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'queue_number' => $order->queue_number,
            'order_type' => $order->order_type,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'items' => $this->receiptItems($order->items),
            'stations' => app(StationTicketService::class)->statusFor($order),
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'tax_name' => $result['finance']['tax_name'],
            'service_charge' => (float) $order->service_charge,
            'grand_total' => (float) $order->grand_total,
            'payment_method' => $method,
            'payment_method_label' => $this->formatPaymentMethodLabel($method),
            'payment_meta' => $paymentMeta,
            'cashier_name' => auth()->user()->name,
            'created_at' => $order->created_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'),
        ];

        $this->resetFnbCart();
        $this->posStep = 'selection';
        $this->showReceiptModal = true;

        // Struk customer langsung dicetak di aplikasi Club61 — sama dengan Bayar Otomatis (semua metode, satu alur).
        // Slip pesanan dikirim terpisah ke printer stasiunnya masing-masing (Kitchen, dst.).
        $this->queueAutoPrint($order->id, 'pos.receipts.fnb', ['receipt' => $this->completedOrderData], 'startNewTransaction');
        $this->dispatchStationPrint($order, onlyUnprinted: true);
    }

    // ===================== SLIP PESANAN KE PRINTER STASIUN (KITCHEN, DST.) =====================

    /** Kirim slip pesanan order ini ke tablet kasir → printer LAN tiap stasiun (pos.partials.receipt-print-script). */
    protected function dispatchStationPrint(\App\Models\Pos\Order $order, bool $onlyUnprinted = false): void
    {
        $jobs = app(StationTicketService::class)->printJobs($order, $onlyUnprinted);
        if ($jobs === []) {
            return;
        }

        $this->dispatch('club61-station-print', wireId: $this->getId(), queueNumber: $order->queue_number, jobs: $jobs);
    }

    /** Tombol "Kirim Ulang ke Stasiun" di modal struk (transaksi baru maupun dari Riwayat). */
    public function resendStationTickets(string $orderId): void
    {
        $this->authorizeViewFnbHistory();

        $order = \App\Models\Pos\Order::query()
            ->whereHas('items', fn ($q) => $q->where('item_type', 'FNB'))
            ->where('payment_status', 'PAID')
            ->findOrFail($orderId);

        // Order lunas sebelum fitur stasiun ada → slipnya dibuat sekarang.
        app(StationTicketService::class)->createForOrder($order);
        $this->refreshStationStatus($order);

        if (! $order->kitchenTickets()->exists()) {
            $this->js('window.club61Toast('.json_encode('Pesanan ini tidak punya menu stasiun (semua dibuat di kasir) — tidak ada slip yang dikirim.').')');

            return;
        }

        $this->dispatchStationPrint($order);
    }

    /** Hasil kirim slip dari tablet kasir (berhasil / gagal + alasannya) — dicatat supaya slip yang belum tercetak ketahuan. */
    public function reportStationPrint(string $ticketId, bool $ok, ?string $error = null): void
    {
        $this->authorizeViewFnbHistory();

        $ticket = \App\Models\Pos\KitchenTicket::with('order')->findOrFail($ticketId);
        app(StationTicketService::class)->recordPrintResult($ticket, $ok, $error);
        $this->refreshStationStatus($ticket->order);
    }

    protected function refreshStationStatus(\App\Models\Pos\Order $order): void
    {
        if (($this->completedOrderData['order_id'] ?? null) === $order->id) {
            $this->completedOrderData['stations'] = app(StationTicketService::class)->statusFor($order);
        }
    }

    protected function resetFnbCart(): void
    {
        $this->cart = [];
        $this->tableNumber = '';
        $this->customerName = '';
        $this->edcLast4 = '';
        $this->edcApprovalCode = '';
        $this->edcTraceNumber = '';
        $this->qrisRrn = '';
        $this->qrisSenderName = '';
    }

    // ===================== QRIS MIDTRANS (QR DI LAYAR KASIR) =====================

    /** Tagihan QR yang sedang ditampilkan — milik loket F&B & dibuat dari layar kasir. */
    protected function pendingQrisPayment(): ?\App\Models\Pos\Payment
    {
        $id = $this->pendingQris['payment_id'] ?? null;
        $payment = $id ? \App\Models\Pos\Payment::find($id) : null;

        return $payment && ($payment->payload_log['counter'] ?? null) === self::COUNTER && isset($payment->payload_log['pos_qris'])
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

        $status = app(\App\Services\Pos\PosMidtransQrisService::class)->status($payment->id);

        if ($status === \App\Services\Pos\PosMidtransQrisService::PAID) {
            $this->completePendingQris($payment);
        } elseif ($status !== \App\Services\Pos\PosMidtransQrisService::PENDING) {
            $this->pendingQris = null;
            $this->posStep = 'selection';
            $this->errorMessage = $status === \App\Services\Pos\PosMidtransQrisService::EXPIRED
                ? 'QR kedaluwarsa — pembayaran tidak diterima, pesanan dibatalkan.'
                : 'QR dibatalkan — pesanan dibatalkan.';
        }
    }

    public function cancelPendingQris(): void
    {
        abort_unless(auth()->user()?->can('process_fnb_order'), 403);
        $payment = $this->pendingQrisPayment();
        if (! $payment) {
            $this->pendingQris = null;

            return;
        }

        if (app(\App\Services\Pos\PosMidtransQrisService::class)->cancel($payment->id) === \App\Services\Pos\PosMidtransQrisService::PAID) {
            // Customer ternyata sudah bayar tepat sebelum dibatalkan — uang sudah masuk, transaksi diteruskan.
            $this->completePendingQris($payment);

            return;
        }

        $this->pendingQris = null;
        $this->posStep = 'selection';
    }

    /** Hanya di laptop developer tanpa server key Midtrans (QR mock). */
    public function simulatePendingQrisPaid(): void
    {
        $payment = $this->pendingQrisPayment();
        abort_unless($payment && ($this->pendingQris['is_mock'] ?? false) && ! app()->environment('production'), 403);

        app(\App\Services\Pos\PosMidtransQrisService::class)->simulatePaid($payment->id);
        $this->pollPendingQris();
    }

    protected function completePendingQris(\App\Models\Pos\Payment $payment): void
    {
        $order = \App\Models\Pos\Order::with(['items', 'payments', 'cashier'])->findOrFail($payment->order_id);

        $this->completedOrderData = $this->receiptDataFor($order);
        $this->pendingQris = null;
        $this->posStep = 'selection';
        $this->showReceiptModal = true;
        // Lunas lewat Bayar Otomatis → struk langsung dicetak di aplikasi Club61 (tanpa buka modal / tekan Cetak Struk),
        // slip pesanan ke printer stasiunnya.
        $this->queueAutoPrint($order->id, 'pos.receipts.fnb', ['receipt' => $this->completedOrderData], 'startNewTransaction');
        $this->dispatchStationPrint($order, onlyUnprinted: true);
    }

    /** Buka kembali struk transaksi lama dari daftar riwayat (rekonstruksi dari data tersimpan). */
    public function viewOrderReceipt(string $orderId): void
    {
        $this->authorizeViewFnbHistory();

        $order = \App\Models\Pos\Order::query()
            ->whereHas('items', fn ($q) => $q->where('item_type', 'FNB'))
            ->with(['items', 'payments', 'cashier'])
            ->findOrFail($orderId);

        $this->completedOrderData = $this->receiptDataFor($order);
        $this->showReceiptModal = true;
    }

    /** Label metode bayar di Riwayat (QR Midtrans dari layar kasir dibedakan dari QRIS manual). */
    public function paymentLabelFor(?\App\Models\Pos\Payment $payment): string
    {
        if (! $payment) {
            return '-';
        }

        return \App\Services\Pos\PosMidtransQrisService::labelFor($payment->payload_log)
            ?? $this->formatPaymentMethodLabel($payment->payment_method);
    }

    /** Baris struk customer + catatan item. */
    protected function receiptItems(\Illuminate\Support\Collection $items): array
    {
        return $items->map(fn ($item) => [
            'name' => $item->item_name,
            'quantity' => $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
            'notes' => $item->notes,
        ])->values()->all();
    }

    /** Data struk dari order tersimpan (cetak ulang dari Riwayat & struk setelah QR Midtrans lunas). */

    protected function receiptDataFor(\App\Models\Pos\Order $order): array
    {
        $payment = $order->payments->firstWhere('status', 'SUCCESS') ?? $order->payments->first();
        $method = $payment?->payment_method ?? 'UNKNOWN';
        $log = $payment?->payload_log ?? [];
        $paymentMeta = [];

        if (! empty($log['qris_details'])) {
            $paymentMeta = [
                'qris_provider' => $log['qris_details']['provider'] ?? null,
                'qris_rrn' => $log['qris_details']['rrn'] ?? null,
                'qris_sender_name' => $log['qris_details']['sender_name'] ?? null,
            ];
        } elseif (! empty($log['edc_details'])) {
            $paymentMeta = [
                'terminal' => $log['edc_details']['terminal'] ?? null,
                'card_network' => $log['edc_details']['card_network'] ?? null,
                'card_issuer' => $log['edc_details']['card_issuer'] ?? null,
                'card_last_4' => $log['edc_details']['card_last_4'] ?? null,
                'approval_code' => $log['edc_details']['approval_code'] ?? null,
                'trace_number' => $log['edc_details']['trace_number'] ?? null,
            ];
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_status' => $order->payment_status,
            'stations' => app(StationTicketService::class)->statusFor($order),
            'queue_number' => $order->queue_number,
            'order_type' => $order->order_type,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'items' => $this->receiptItems($order->items->where('item_type', 'FNB')),
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'tax_name' => \App\Models\Pos\ClubFinanceSetting::getSettings()->tax_name,
            'service_charge' => (float) $order->service_charge,
            'grand_total' => (float) $order->grand_total,
            'payment_method' => $method,
            'payment_method_label' => \App\Services\Pos\PosMidtransQrisService::labelFor($log) ?? $this->formatPaymentMethodLabel($method),
            'payment_meta' => $paymentMeta,
            'cashier_name' => $order->cashier?->name ?? '-',
            'created_at' => $order->created_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'),
        ];
    }

    /**
     * Daftar transaksi F&B shift yang sedang aktif (fallback: 50 transaksi terakhir kalau tidak
     * ada shift aktif). Sengaja dikembalikan kosong (bukan abort) untuk staf tanpa izin — dipanggil
     * otomatis tiap render(), jadi harus aman dari state posStep yang berubah tak terduga; enforcement
     * "keras" (403) tetap ada di viewOrderReceipt() saat benar-benar membuka struk transaksi orang lain.
     */
    public function getOrderHistoryProperty()
    {
        if (! $this->canViewFnbHistory()) {
            return collect();
        }

        $query = \App\Models\Pos\Order::query()
            ->whereHas('items', fn ($q) => $q->where('item_type', 'FNB'))
            ->with(['items', 'cashier', 'payments'])
            ->latest();

        if ($this->activeShift) {
            $query->whereHas('payments', fn ($q) => $q->where('pos_shift_id', $this->activeShift->id));
        }

        return $query->limit(50)->get();
    }

    /** Riwayat & struk transaksi F&B butuh izin yang sama dengan yang boleh memproses transaksinya. */
    private function canViewFnbHistory(): bool
    {
        $user = auth()->user();

        return $user && $user->can('process_fnb_order');
    }

    /** Dipakai di Blade untuk menyembunyikan tab "Riwayat Transaksi" dari staf tanpa izin (kosmetik — enforcement asli di canViewFnbHistory()/authorizeViewFnbHistory()). */
    public function getCanShowFnbHistoryTabProperty(): bool
    {
        return $this->canViewFnbHistory();
    }

    private function authorizeViewFnbHistory(): void
    {
        abort_unless(
            $this->canViewFnbHistory(),
            403,
            'Akses ditolak: Anda tidak memiliki izin [process_fnb_order] untuk melihat riwayat transaksi F&B.'
        );
    }

    /** Label pembayaran yang sama persis dengan ringkasan "Metode Pembayaran" di BookOfflineCourt. */
    public function formatPaymentMethodLabel(string $method): string
    {
        return match ($method) {
            'DEBIT_CARD', 'DEBIT' => 'Kartu Debit (EDC)',
            'CREDIT_CARD', 'CREDIT' => 'Kartu Kredit (EDC)',
            'EDC_BCA' => 'Mesin EDC BCA',
            'EDC_MANDIRI' => 'Mesin EDC Mandiri',
            'QRIS', 'QRIS_STATIS' => 'QRIS Kasir Frontdesk',
            default => $method,
        };
    }

    /**
     * Tutup popup struk (tombol ×, klik di luar popup, atau Esc). Dari tab Riwayat tetap di Riwayat; setelah
     * pembayaran lanjut ke transaksi baru.
     */
    public function closeReceiptModal(): void
    {
        if ($this->posStep === 'history') {
            $this->showReceiptModal = false;
            $this->completedOrderData = null;

            return;
        }

        $this->startNewTransaction();
    }

    public function startNewTransaction(): void
    {
        $this->completedOrderData = null;
        $this->showReceiptModal = false;
        $this->errorMessage = null;
        $this->posStep = 'selection';
    }

    public function render(): View
    {
        return view('livewire.pos.fnb-cashier-terminal');
    }
}
