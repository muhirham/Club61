<?php

namespace App\Filament\Pages;

use App\Models\Merch\MerchProduct;
use App\Models\Merch\MerchVariant;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\EquipmentLoan;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Services\Audit\ActivityLogger;
use App\Services\Padel\BookingAddonService;
use App\Services\Padel\EquipmentLoanService;
use App\Services\Pos\PosAutoPayment;
use App\Services\Pos\PosMidtransQrisService;
use App\Services\Pos\PosPaymentProof;
use App\Services\Pos\ProShopSaleService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Kasir Pro Shop — loket alat di meja frontdesk, shift PADEL_FRONTDESK yang sama dengan POS Walk-In:
 *  - Jual: perlengkapan padel bersifat Jual (bola dll.) + merchandise, tanpa booking & tanpa akun customer (nama + HP
 *    pembeli disimpan di order) → ProShopSaleService::checkout.
 *  - Sewa: tambah alat ke booking hari ini / ke depan (lunas, check-in, atau sesi Tagihan Berkala coaching) →
 *    BookingAddonService::checkout. Raket / handuk tercatat di Peminjaman Alat (langsung bila booking sudah check-in,
 *    selain itu saat check-in).
 *  - Denda: denda Peminjaman Alat yang sudah disetujui management → EquipmentLoanService::payFine.
 * Total, stok & izin dicek ulang di service; layar ini hanya mengumpulkan pilihan kasir.
 */
class KasirProShop extends Page
{
    use \App\Livewire\Concerns\AutoPrintsReceipts;
    use \App\Livewire\Concerns\PrintsDailySalesRecap;
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Kasir Pro Shop';

    protected static string|UnitEnum|null $navigationGroup = 'Operasional Harian';

    protected static ?string $title = 'Kasir Pro Shop';

    protected static ?int $navigationSort = 3;

    // Diakses lewat tab "Kasir Pro Shop" di baris atas POS (filament.partials.pos-subnav), sama dengan POS Jual Membership.
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.kasir-pro-shop';

    /** Meja frontdesk yang sama dengan POS Walk-In — uangnya masuk shift loket padel. */
    public const COUNTER = 'PADEL_FRONTDESK';

    public const TABS = ['jual' => 'Jual', 'sewa' => 'Sewa', 'denda' => 'Denda'];

    /** Sumber Bayar Otomatis dari layar ini (payload_log.source). */
    private const AUTO_PAY_SOURCES = ['BOOKING_ADDON', ProShopSaleService::SOURCE];

    /** Transaksi yang tampil di Riwayat Pro Shop (bukan di Riwayat POS Walk-In): jual, tambah alat ke booking, denda alat. */
    public const HISTORY_SOURCES = ['BOOKING_ADDON', ProShopSaleService::SOURCE, EquipmentLoanService::SOURCE_FINE];

    #[Locked]
    public string $tab = 'jual';

    /** 'selection' (pilih alat / booking / denda) atau 'payment' (layar bayar). */
    public string $posStep = 'selection';

    // Pembeli tab Jual — tidak dibuatkan akun; nama & nomor HP disimpan di order.
    public string $buyerName = '';

    public string $buyerPhone = '';

    /** Keranjang alat: [equipment_id => jumlah]. Harga & stok dihitung ulang server. */
    public array $quantities = [];

    /** Keranjang merchandise (tab Jual): [variant_id => jumlah]. */
    public array $merchQuantities = [];

    // Etalase tab Jual (pola kasir F&B): filter kategori + pencarian; barang bervarian dipilih lewat popup.
    public string $shopCategory = 'ALL';

    public string $shopSearch = '';

    public ?string $pickerProductId = null;

    public ?string $pickerVariantId = null;

    public int $pickerQty = 1;

    // Tab Sewa: booking yang ditambah alat — diisi server (pickBooking).
    public string $bookingSearch = '';

    #[Locked]
    public ?array $rentBooking = null;

    // Tab Denda: dibuka dari tombol "Bayar di Kasir" di Peminjaman Alat → /admin/kasir-pro-shop?denda={loanId}
    #[Url(as: 'denda')]
    public ?string $fineRequest = null;

    #[Locked]
    public ?array $fineBill = null;

    // Pembayaran (100% Cashless) — properti yang dibaca form bersama pos.partials.payment-method-form.
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

    /** MIDTRANS = QR dinamis tampil di layar (utama); MANUAL = QRIS statis + input RRN (cadangan). */
    public string $qrisMode = 'MIDTRANS';

    public string $posOnlineMethod = 'QRIS';

    /** QR yang menunggu dibayar (popup + polling). Dikunci: id tagihan tidak boleh diganti dari browser. */
    #[Locked]
    public ?array $pendingQris = null;

    public bool $showSuccessModal = false;

    public ?array $completedOrderData = null;

    /** Rincian keranjang dari service, disimpan selama satu request (tidak ikut dehydrate). */
    protected ?array $quoteCache = null;

    // Ditulis eksplisit: halaman baru tidak dikenali HasPageShield, yang lalu jatuh ke "boleh semua" (sama dengan PeminjamanAlat).
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('View:KasirProShop');
    }

    public function mount(): void
    {
        $this->tab = $this->allowedTabs()[0] ?? 'jual';
        // Bayar Otomatis yang masih menunggu (halaman sempat di-refresh / tertutup) → popup dilanjutkan.
        foreach (self::AUTO_PAY_SOURCES as $source) {
            $this->pendingQris ??= app(PosAutoPayment::class)->resumeFor(self::COUNTER, $source, auth()->id());
        }

        if ($this->fineRequest) {
            $this->startFine($this->fineRequest);
        }
    }

    // ===================== IZIN & TAB =====================

    /** Perlengkapan padel (tab Jual) & Sewa = izin tambah alat (sama dengan dulu di POS Walk-In). */
    public function getCanSellProperty(): bool
    {
        return (bool) auth()->user()?->can(BookingAddonService::PERMISSION);
    }

    /** Merchandise di tab Jual = izin terpisah, bisa diberikan ke staf yang berbeda. */
    public function getCanSellMerchProperty(): bool
    {
        return (bool) auth()->user()?->can(ProShopSaleService::PERMISSION_MERCH);
    }

    /** Denda = izin menerima pelunasan di kasir. */
    public function getCanTakeFineProperty(): bool
    {
        return (bool) auth()->user()?->can(EquipmentLoanService::PERMISSION_PAY);
    }

    /** @return list<string> */
    public function allowedTabs(): array
    {
        return array_values(array_filter(array_keys(self::TABS), fn (string $tab) => match ($tab) {
            'jual' => $this->canSell || $this->canSellMerch,
            'sewa' => $this->canSell,
            default => $this->canTakeFine,
        }));
    }

    protected function denyAccess(string $permission, string $action): void
    {
        ActivityLogger::accessDenied("mencoba {$action} tanpa izin [{$permission}] di Kasir Pro Shop");
        Notification::make()->title('Akses Ditolak')->body("Anda tidak memiliki izin [{$permission}].")->danger()->send();
    }

    protected function denyUnlessCanSell(): bool
    {
        if ($this->canSell) {
            return false;
        }
        $this->denyAccess(BookingAddonService::PERMISSION, 'menjual / menambah alat');

        return true;
    }

    /** Tab Jual: izin perlengkapan padel ATAU merchandise (izin per barang dicek ulang di ProShopSaleService). */
    protected function denyUnlessCanSellAnything(): bool
    {
        if ($this->canSell || $this->canSellMerch) {
            return false;
        }
        $this->denyAccess(BookingAddonService::PERMISSION.' / '.ProShopSaleService::PERMISSION_MERCH, 'menjual barang Pro Shop');

        return true;
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS)) {
            return;
        }
        if (! in_array($tab, $this->allowedTabs(), true)) {
            $permission = match ($tab) {
                'denda' => EquipmentLoanService::PERMISSION_PAY,
                'jual' => BookingAddonService::PERMISSION.' / '.ProShopSaleService::PERMISSION_MERCH,
                default => BookingAddonService::PERMISSION,
            };
            $this->denyAccess($permission, 'membuka tab '.self::TABS[$tab]);

            return;
        }
        if ($this->pendingQris) {
            Notification::make()->title('Masih Menunggu Pembayaran')->body('Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.')->warning()->send();

            return;
        }

        $this->tab = $tab;
        $this->resetCart();
    }

    public function getActiveShiftProperty(): ?PosCashierShift
    {
        return PosCashierShift::getActiveShift(self::COUNTER);
    }

    /** Uang yang diterima wajib masuk shift loket yang terbuka (berlaku juga untuk super_admin). */
    protected function ensureActiveShift(): bool
    {
        if ($this->activeShift) {
            return true;
        }

        Notification::make()
            ->title('Shift Kasir Belum Dibuka')
            ->body('Buka shift kasir Padel Frontdesk dulu di halaman POS Walk-In Booking sebelum menerima pembayaran.')
            ->danger()
            ->send();

        return false;
    }

    // ===================== KERANJANG ALAT =====================

    /** Alat yang ditawarkan di tab ini: tab Jual hanya alat bersifat Jual (alat Sewa wajib lewat booking). */
    public function getEquipmentsProperty(): Collection
    {
        if ($this->tab === 'denda' || ! $this->canSell) {
            return collect();
        }
        $equipments = CourtEquipment::where('is_active', true)->orderBy('type')->orderBy('name')->get();

        return $this->tab === 'jual' ? $equipments->filter(fn (CourtEquipment $eq) => $eq->isSale())->values() : $equipments;
    }

    /** Etalase merchandise tab Jual: produk aktif yang punya minimal satu varian aktif. */
    public function getMerchProductsProperty(): Collection
    {
        if ($this->tab !== 'jual' || ! $this->canSellMerch) {
            return collect();
        }

        return MerchProduct::with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('color')->orderBy('size')])
            ->where('is_active', true)
            ->orderBy('category')->orderBy('name')
            ->get()
            ->filter(fn (MerchProduct $p) => $p->variants->isNotEmpty())
            ->values();
    }

    public function incrementMerch(string $variantId): void
    {
        if ($this->posStep !== 'selection' || $this->tab !== 'jual' || ! $this->canSellMerch) {
            return;
        }
        $variant = MerchVariant::with('product')->find($variantId);
        if (! $variant || ! ProShopSaleService::sellable($variant)) {
            return;
        }
        $current = (int) ($this->merchQuantities[$variant->id] ?? 0);
        if ($current < min((int) $variant->stock_quantity, ProShopSaleService::MAX_QUANTITY)) {
            $this->merchQuantities[$variant->id] = $current + 1;
            $this->quoteCache = null;
        }
    }

    public function decrementMerch(string $variantId): void
    {
        if ($this->posStep !== 'selection') {
            return;
        }
        $current = (int) ($this->merchQuantities[$variantId] ?? 0);
        if ($current > 1) {
            $this->merchQuantities[$variantId] = $current - 1;
        } else {
            unset($this->merchQuantities[$variantId]);
        }
        $this->quoteCache = null;
    }

    /** Hapus satu baris keranjang tab Jual (EQUIPMENT / MERCH). */
    public function removeCartItem(string $kind, string $id): void
    {
        if ($this->posStep !== 'selection') {
            return;
        }
        if ($kind === 'MERCH') {
            unset($this->merchQuantities[$id]);
        } else {
            unset($this->quantities[$id]);
        }
        $this->quoteCache = null;
    }

    // ===================== ETALASE TAB JUAL =====================

    /** Pilihan kategori: Semua, Perlengkapan Padel (bila boleh jual), lalu kategori merchandise yang punya barang. */
    public function getShopCategoriesProperty(): array
    {
        $categories = ['ALL' => 'Semua'];
        if ($this->canSell) {
            $categories['PADEL'] = 'Perlengkapan Padel';
        }
        foreach ($this->merchProducts->pluck('category')->unique() as $key) {
            $key = array_key_exists((string) $key, MerchProduct::CATEGORIES) ? $key : 'OTHER';
            $categories['M:'.$key] = MerchProduct::CATEGORIES[$key];
        }

        return $categories;
    }

    public function setShopCategory(string $category): void
    {
        $this->shopCategory = array_key_exists($category, $this->shopCategories) ? $category : 'ALL';
    }

    /**
     * Kartu etalase tab Jual (seragam untuk perlengkapan padel & merchandise), sesuai kategori & pencarian (nama / SKU).
     *
     * @return list<array{kind: string, id: string, name: string, category: string, price: float, price_varies: bool, stock: int, image: ?string, variants: int, picked: int}>
     */
    public function getShopItemsProperty(): array
    {
        if ($this->tab !== 'jual') {
            return [];
        }
        $term = mb_strtolower(trim($this->shopSearch));
        $matches = fn (string ...$texts) => $term === '' || collect($texts)->contains(fn ($t) => str_contains(mb_strtolower($t), $term));
        $items = [];

        if (in_array($this->shopCategory, ['ALL', 'PADEL'], true)) {
            foreach ($this->equipments as $eq) {
                if (! $matches($eq->name)) {
                    continue;
                }
                $items[] = [
                    'kind' => 'EQUIPMENT', 'id' => $eq->id, 'name' => $eq->name, 'category' => 'Perlengkapan Padel',
                    'price' => (float) $eq->rental_price, 'price_varies' => false, 'stock' => (int) $eq->stock_quantity,
                    'image' => null, 'variants' => 1, 'picked' => (int) ($this->quantities[$eq->id] ?? 0),
                ];
            }
        }

        foreach ($this->merchProducts as $product) {
            $category = array_key_exists((string) $product->category, MerchProduct::CATEGORIES) ? $product->category : 'OTHER';
            if (! in_array($this->shopCategory, ['ALL', 'M:'.$category], true)
                || ! $matches($product->name, ...$product->variants->pluck('sku')->all())) {
                continue;
            }
            $prices = $product->variants->map(fn (MerchVariant $v) => (float) $product->base_price + (float) $v->additional_price);
            $items[] = [
                'kind' => 'MERCH', 'id' => $product->id, 'name' => $product->name, 'category' => MerchProduct::CATEGORIES[$category],
                'price' => (float) $prices->min(), 'price_varies' => $prices->unique()->count() > 1,
                'stock' => (int) $product->variants->sum('stock_quantity'), 'image' => $product->imageUrl(),
                'variants' => $product->variants->count(),
                'picked' => (int) $product->variants->sum(fn (MerchVariant $v) => (int) ($this->merchQuantities[$v->id] ?? 0)),
            ];
        }

        return $items;
    }

    /** Ketuk kartu: perlengkapan padel & produk satu varian langsung +1 (seperti kasir F&B); produk bervarian → popup. */
    public function tapShopItem(string $kind, string $id): void
    {
        if ($this->posStep !== 'selection' || $this->tab !== 'jual') {
            return;
        }
        if ($kind === 'EQUIPMENT') {
            $this->incrementEquipment($id);

            return;
        }

        $product = $this->merchProducts->firstWhere('id', $id);
        if (! $product) {
            return;
        }
        if ($product->variants->count() === 1) {
            $this->incrementMerch($product->variants->first()->id);

            return;
        }

        $this->pickerProductId = $product->id;
        $this->pickerVariantId = $product->variants->first(fn (MerchVariant $v) => $v->stock_quantity > (int) ($this->merchQuantities[$v->id] ?? 0))?->id;
        $this->pickerQty = 1;
    }

    public function getPickerProductProperty(): ?MerchProduct
    {
        return $this->pickerProductId ? $this->merchProducts->firstWhere('id', $this->pickerProductId) : null;
    }

    public function selectPickerVariant(string $variantId): void
    {
        if ($this->pickerProduct?->variants->contains('id', $variantId)) {
            $this->pickerVariantId = $variantId;
            $this->pickerQty = 1;
        }
    }

    public function changePickerQty(int $delta): void
    {
        $this->pickerQty = max(1, min($this->pickerQty + $delta, max(1, $this->pickerRoom())));
    }

    /** Sisa yang masih bisa ditambahkan untuk varian terpilih (stok & batas per baris dikurangi isi keranjang). */
    public function pickerRoom(): int
    {
        $variant = $this->pickerProduct?->variants->firstWhere('id', $this->pickerVariantId);
        if (! $variant) {
            return 0;
        }

        return max(0, min((int) $variant->stock_quantity, ProShopSaleService::MAX_QUANTITY) - (int) ($this->merchQuantities[$variant->id] ?? 0));
    }

    public function confirmVariantPicker(): void
    {
        if ($this->posStep !== 'selection' || $this->tab !== 'jual' || ! $this->canSellMerch) {
            return;
        }
        $variant = $this->pickerVariantId ? MerchVariant::with('product')->find($this->pickerVariantId) : null;
        if (! $variant || $variant->product_id !== $this->pickerProductId || ! ProShopSaleService::sellable($variant)) {
            Notification::make()->title('Varian Tidak Tersedia')->body('Pilih ukuran / warna yang masih dijual.')->warning()->send();

            return;
        }
        $room = max(0, min((int) $variant->stock_quantity, ProShopSaleService::MAX_QUANTITY) - (int) ($this->merchQuantities[$variant->id] ?? 0));
        if ($room < 1) {
            Notification::make()->title('Stok Tidak Cukup')->body("Stok {$variant->label()} sudah habis atau sudah semuanya ada di keranjang.")->warning()->send();

            return;
        }

        $this->merchQuantities[$variant->id] = (int) ($this->merchQuantities[$variant->id] ?? 0) + min(max(1, $this->pickerQty), $room);
        $this->quoteCache = null;
        $this->closeVariantPicker();
    }

    public function closeVariantPicker(): void
    {
        $this->pickerProductId = null;
        $this->pickerVariantId = null;
        $this->pickerQty = 1;
    }

    public function incrementEquipment(string $equipmentId): void
    {
        if ($this->posStep !== 'selection' || $this->tab === 'denda' || ! $this->canSell) {
            return;
        }
        $eq = CourtEquipment::where('is_active', true)->find($equipmentId);
        if (! $eq || ($this->tab === 'jual' && ! $eq->isSale())) {
            return;
        }
        $current = (int) ($this->quantities[$eq->id] ?? 0);
        if ($current < min((int) $eq->stock_quantity, BookingAddonService::MAX_QUANTITY)) {
            $this->quantities[$eq->id] = $current + 1;
            $this->quoteCache = null;
        }
    }

    public function decrementEquipment(string $equipmentId): void
    {
        if ($this->posStep !== 'selection') {
            return;
        }
        $current = (int) ($this->quantities[$equipmentId] ?? 0);
        if ($current > 1) {
            $this->quantities[$equipmentId] = $current - 1;
        } else {
            unset($this->quantities[$equipmentId]);
        }
        $this->quoteCache = null;
    }

    /** Rincian & total (sama dengan yang dihitung server saat disimpan). Null = belum ada yang bisa dihitung. */
    public function getQuoteProperty(): ?array
    {
        if ($this->quoteCache !== null) {
            return $this->quoteCache;
        }
        if ($this->tab === 'jual') {
            return $this->quoteCache = app(ProShopSaleService::class)->quote($this->quantities, $this->merchQuantities);
        }
        $service = app(BookingAddonService::class);
        if ($this->tab === 'sewa' && $this->rentBooking) {
            $booking = PadelBooking::with('order')->find($this->rentBooking['booking_id']);

            return $booking ? $this->quoteCache = $service->quote($booking, $this->quantities, mode: $this->rentBooking['mode']) : null;
        }

        return null;
    }

    /** Sesi coaching Tagihan Berkala: alat masuk tagihan academy, tanpa pembayaran sekarang. */
    public function getIsTabBillProperty(): bool
    {
        return $this->tab === 'sewa' && ($this->rentBooking['mode'] ?? null) === BookingAddonService::MODE_TAB;
    }

    public function getGrandTotalProperty(): float
    {
        return $this->tab === 'denda'
            ? (float) ($this->fineBill['amount'] ?? 0)
            : (float) ($this->quote['grand_total'] ?? 0);
    }

    public function getItemCountProperty(): int
    {
        return (int) array_sum(array_map('intval', $this->quantities)) + (int) array_sum(array_map('intval', $this->merchQuantities));
    }

    // ===================== BOOKING (TAB SEWA) =====================

    /** Booking hari ini yang bisa ditambah alat — tampil tanpa harus mencari. */
    public function getTodayBookingsProperty(): array
    {
        if ($this->tab !== 'sewa' || $this->rentBooking || ! $this->canSell) {
            return [];
        }
        $service = app(BookingAddonService::class);

        return $service->todayEligible()->map(fn (PadelBooking $b) => $this->bookingRow($b, $service))->all();
    }

    /** Cari booking hari ini & ke depan: kode booking, nama, atau nomor HP. */
    public function getBookingSearchResultsProperty(): array
    {
        if ($this->tab !== 'sewa' || $this->rentBooking || ! $this->canSell) {
            return [];
        }
        $service = app(BookingAddonService::class);

        return $service->search($this->bookingSearch)->map(fn (PadelBooking $b) => $this->bookingRow($b, $service))->all();
    }

    protected function bookingRow(PadelBooking $b, BookingAddonService $service): array
    {
        return [
            'id' => $b->id,
            'code' => $b->booking_code,
            'customer' => $b->user?->name ?? 'Customer',
            'court' => $b->court?->name ?? '-',
            'schedule' => $b->booking_date->locale('id')->translatedFormat('D, d M').' · '.$b->start_time->format('H:i').' - '.$b->end_time->format('H:i'),
            'time' => $b->start_time->format('H:i').' - '.$b->end_time->format('H:i'),
            'checked_in' => $b->status === 'CHECKED_IN',
            'tab' => $service->mode($b) === BookingAddonService::MODE_TAB,
        ];
    }

    public function pickBooking(string $bookingId): void
    {
        if ($this->denyUnlessCanSell() || $this->tab !== 'sewa' || $this->posStep !== 'selection') {
            return;
        }

        $service = app(BookingAddonService::class);
        $booking = PadelBooking::with(['user', 'court', 'coachingBooking.academy'])->find($bookingId);
        if (! $booking || ! $service->mode($booking)) {
            Notification::make()->title('Booking Tidak Bisa Ditambah Alat')->body($service->ineligibleReason($booking))->warning()->send();

            return;
        }

        $this->rentBooking = $service->summary($booking);
        $this->bookingSearch = '';
        $this->quantities = [];
        $this->quoteCache = null;
    }

    public function clearBooking(): void
    {
        if ($this->posStep !== 'selection') {
            return;
        }
        $this->rentBooking = null;
        $this->quantities = [];
        $this->quoteCache = null;
    }

    // ===================== DENDA =====================

    /** Denda yang sudah disetujui management dan belum lunas (sama dengan EquipmentLoan::fineDue() > 0). */
    protected function payableFineQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return EquipmentLoan::where('fine_status', EquipmentLoan::FINE_APPROVED)
            ->where('fine_amount', '>', 0)
            ->whereDoesntHave('fineOrder', fn ($q) => $q->where('payment_status', 'PAID'));
    }

    /** Angka di tab Denda: kasir langsung tahu ada denda yang harus ditagih. */
    public function getPayableFineCountProperty(): int
    {
        return $this->canTakeFine ? $this->payableFineQuery()->count() : 0;
    }

    public function getPayableFinesProperty(): Collection
    {
        if ($this->tab !== 'denda' || $this->fineBill || ! $this->canTakeFine) {
            return collect();
        }

        return $this->payableFineQuery()
            ->with(['booking:id,booking_code', 'fineOrder:id,payment_status'])
            ->latest('fine_decided_at')
            ->limit(100)
            ->get()
            ->filter(fn (EquipmentLoan $loan) => $loan->fineDue() > 0)
            ->values();
    }

    public function startFine(string $loanId): void
    {
        $this->fineRequest = null;
        if (! $this->canTakeFine) {
            $this->denyAccess(EquipmentLoanService::PERMISSION_PAY, 'menerima pembayaran denda alat');

            return;
        }
        if ($this->pendingQris) {
            Notification::make()->title('Masih Menunggu Pembayaran')->body('Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.')->warning()->send();

            return;
        }
        $loan = EquipmentLoan::find($loanId);
        if (! $loan || $loan->fineDue() <= 0) {
            Notification::make()->title('Tidak Ada Denda')->body('Denda ini belum disetujui, sudah dihapus, atau sudah lunas.')->info()->send();

            return;
        }

        $this->tab = 'denda';
        $this->resetCart();
        $this->fineBill = app(EquipmentLoanService::class)->fineBillFor($loan);
    }

    public function clearFine(): void
    {
        if ($this->posStep !== 'selection') {
            return;
        }
        $this->fineBill = null;
    }

    // ===================== PEMBAYARAN =====================

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

    public function proceedToPayment(): void
    {
        if ($this->pendingQris) {
            Notification::make()->title('Masih Menunggu Pembayaran')->body('Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.')->warning()->send();

            return;
        }

        if ($this->tab === 'denda') {
            if (! $this->canTakeFine) {
                $this->denyAccess(EquipmentLoanService::PERMISSION_PAY, 'menerima pembayaran denda alat');

                return;
            }
            if (! $this->fineBill) {
                Notification::make()->title('Denda Belum Dipilih')->body('Pilih denda yang akan dibayar.')->warning()->send();

                return;
            }
            if ($this->ensureActiveShift()) {
                $this->posStep = 'payment';
            }

            return;
        }

        if ($this->tab === 'jual') {
            if ($this->denyUnlessCanSellAnything()) {
                return;
            }
            // Pembeli tidak dibuatkan akun — nama & nomor HP saja (dicek ulang di server saat bayar).
            if (trim($this->buyerName) === '' || strlen(preg_replace('/[^0-9]/', '', $this->buyerPhone)) < 8) {
                Notification::make()->title('Data Pembeli Belum Lengkap')->body('Isi nama dan nomor WhatsApp (minimal 8 digit) pembeli.')->warning()->send();

                return;
            }
            if (($this->quote['items'] ?? []) === []) {
                Notification::make()->title('Barang Belum Dipilih')->body('Pilih minimal satu barang yang dijual.')->warning()->send();

                return;
            }
            if ($this->ensureActiveShift()) {
                $this->posStep = 'payment';
            }

            return;
        }

        if ($this->denyUnlessCanSell()) {
            return;
        }

        if ($this->tab === 'sewa') {
            if (! $this->rentBooking) {
                Notification::make()->title('Booking Belum Dipilih')->body('Pilih booking yang akan ditambah alat.')->warning()->send();

                return;
            }
            if (($this->quote['items'] ?? []) === []) {
                Notification::make()->title('Alat Belum Dipilih')->body('Pilih minimal satu alat yang ditambahkan ke booking ini.')->warning()->send();

                return;
            }
            if (! $this->isTabBill && ! $this->ensureActiveShift()) {
                return;
            }
            $this->posStep = 'payment';
        }
    }

    public function backToSelection(): void
    {
        $this->posStep = 'selection';
    }

    public function submit(): void
    {
        // Masih ada Bayar Otomatis yang menunggu customer → selesaikan / batalkan dulu (popup tetap tampil).
        if ($this->pendingQris) {
            Notification::make()->title('Masih Menunggu Pembayaran')->body('Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.')->warning()->send();

            return;
        }
        if ($this->posStep !== 'payment') {
            return;
        }

        match ($this->tab) {
            'jual' => $this->submitSale(),
            'sewa' => $this->submitRent(),
            'denda' => $this->submitFine(),
        };
    }

    /** QRIS otomatis (payment gateway) — syarat yang sama dengan POS Walk-In; selain itu bukti QRIS statis / EDC. */
    protected function usesAutoPay(float $total): bool
    {
        return in_array(strtoupper($this->paymentMethod), ['QRIS', 'QRIS_STATIS'], true)
            && $this->qrisMode === 'MIDTRANS' && PosMidtransQrisService::resolveMethod($this->posOnlineMethod, $total) !== null;
    }

    /** @return array{0: string, 1: array} */
    protected function typedProof(): array
    {
        return PosPaymentProof::fromPosForm(
            $this->paymentMethod, $this->edcTerminal, $this->edcCardType, $this->edcLast4, $this->edcApprovalCode,
            $this->edcTraceNumber, $this->qrisProvider, $this->qrisRrn, $this->qrisSenderName, $this->edcCardNetwork, $this->edcBank,
        );
    }

    protected function submitSale(): void
    {
        if ($this->denyUnlessCanSellAnything()) {
            return;
        }

        $buyerName = trim($this->buyerName);
        $shownTotal = (float) ($this->quote['grand_total'] ?? 0);
        $autoPay = $this->usesAutoPay($shownTotal);
        [$method, $proof] = $autoPay ? ['QRIS_MIDTRANS', []] : $this->typedProof();

        try {
            $result = app(ProShopSaleService::class)->checkout(
                equipmentQty: $this->quantities,
                merchQty: $this->merchQuantities,
                expectedTotal: $shownTotal,
                method: $method,
                proof: $proof,
                buyerName: $buyerName,
                buyerPhone: $this->buyerPhone,
                cashier: auth()->user(),
                onlineMethod: $autoPay ? $this->posOnlineMethod : null,
            );
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                throw $e;
            }
            Notification::make()->title('Penjualan Belum Tersimpan')->body($e->getMessage())->danger()->persistent()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Penjualan Belum Tersimpan')->body('Terjadi kesalahan sistem. Pembayaran TIDAK tercatat — jangan anggap lunas, hubungi admin.')->danger()->persistent()->send();

            return;
        }

        $this->resetCart();
        if (! empty($result['pending_qris'])) {
            $this->pendingQris = $result['pending_qris'];

            return;
        }

        Notification::make()->title('Penjualan Lunas')
            ->body("{$buyerName} · Rp ".number_format($result['quote']['grand_total'], 0, ',', '.').'.')
            ->success()->send();
        if ($result['payment']) {
            $this->showReceipt($result['payment']);
        }
    }

    protected function submitRent(): void
    {
        if ($this->denyUnlessCanSell() || ! $this->rentBooking) {
            return;
        }

        $booking = $this->rentBooking;
        $shownTotal = (float) ($this->quote['grand_total'] ?? 0);
        $autoPay = $booking['mode'] === BookingAddonService::MODE_PAID && $this->usesAutoPay($shownTotal);
        [$method, $proof] = $autoPay ? ['QRIS_MIDTRANS', []] : $this->typedProof();

        try {
            $result = app(BookingAddonService::class)->checkout(
                bookingId: $booking['booking_id'],
                quantities: $this->quantities,
                expectedMode: $booking['mode'],
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

        $this->resetCart();
        // Bayar Otomatis: popup QR (pollPendingQris → struk; batal / kedaluwarsa → order batal).
        if (! empty($result['pending_qris'])) {
            $this->pendingQris = $result['pending_qris'];

            return;
        }

        if ($result['mode'] === BookingAddonService::MODE_TAB) {
            Notification::make()->title('Dicatat ke Tagihan Berkala')
                ->body("Alat untuk {$booking['code']} ditambahkan ke tagihan {$booking['academy']} (Rp ".number_format($result['quote']['grand_total'], 0, ',', '.').'). Dilunasi melalui Pelunasan Tagihan Coaching.')
                ->success()->send();

            return;
        }

        Notification::make()->title('Tambahan Alat Lunas')
            ->body("Alat untuk {$booking['code']} lunas Rp ".number_format($result['quote']['grand_total'], 0, ',', '.').'.')
            ->success()->send();
        if ($result['payment']) {
            $this->showReceipt($result['payment']);
        }
    }

    protected function submitFine(): void
    {
        if (! $this->fineBill) {
            return;
        }
        if (! $this->canTakeFine) {
            $this->denyAccess(EquipmentLoanService::PERMISSION_PAY, 'menerima pembayaran denda alat');

            return;
        }

        $bill = $this->fineBill;
        [$method, $proof] = $this->typedProof();
        try {
            $payment = app(EquipmentLoanService::class)->payFine($bill['loan_id'], (float) $bill['amount'], $method, $proof, auth()->user());
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                throw $e;
            }
            Notification::make()->title('Pembayaran Denda Gagal')->body($e->getMessage())->danger()->persistent()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Pembayaran Denda Gagal')->body('Terjadi kesalahan sistem. Pembayaran TIDAK tercatat — jangan anggap lunas, hubungi admin.')->danger()->persistent()->send();

            return;
        }

        Notification::make()->title('Denda Lunas')
            ->body("Denda {$bill['court']} atas nama {$bill['customer']} Rp ".number_format($bill['amount'], 0, ',', '.').' lunas.')
            ->success()->send();
        $this->resetCart();
        $this->showReceipt($payment);
    }

    /** Struk loket padel yang sama (BookOfflineCourt::buildWalkInReceipt — juga dipakai Riwayat & Buku Transaksi). */
    protected function showReceipt(Payment $payment): void
    {
        $this->completedOrderData = (new BookOfflineCourt)->buildWalkInReceipt($payment);
        $this->showSuccessModal = true;
        $this->queueAutoPrint($payment->order_id, 'filament.partials.walkin-receipt', ['receipt' => $this->completedOrderData], 'closeSuccessModal');
    }

    public function closeSuccessModal(): void
    {
        $this->showSuccessModal = false;
        $this->completedOrderData = null;
        $this->posStep = $this->receiptFromHistory ? 'history' : 'selection';
        $this->receiptFromHistory = false;
    }

    // ===================== RIWAYAT TRANSAKSI & CETAK ULANG STRUK =====================
    // Pola sama dengan POS Walk-In & POS Jual Membership (pos-history-tabs / pos-history-table, Cetak Rekap).

    public string $historyDate = '';

    public string $historySearch = '';

    /** Struk yang sedang tampil dibuka dari Riwayat (tutup = kembali ke Riwayat). */
    public bool $receiptFromHistory = false;

    /** Riwayat & cetak ulang = staf yang boleh memakai salah satu tab kasir ini. */
    public function getCanShowHistoryTabProperty(): bool
    {
        return $this->allowedTabs() !== [];
    }

    public function showHistory(): void
    {
        if (! $this->canShowHistoryTab) {
            $this->denyAccess(BookingAddonService::PERMISSION.' / '.ProShopSaleService::PERMISSION_MERCH.' / '.EquipmentLoanService::PERMISSION_PAY, 'membuka riwayat transaksi Kasir Pro Shop');

            return;
        }
        if ($this->pendingQris) {
            Notification::make()->title('Masih Menunggu Pembayaran')->body('Selesaikan atau batalkan pembayaran otomatis sebelumnya dulu.')->warning()->send();

            return;
        }

        $this->historyDate = $this->resolvedHistoryDate();
        $this->closeVariantPicker();
        $this->posStep = 'history';
    }

    public function showCashier(): void
    {
        $this->posStep = 'selection';
    }

    public function updatedHistoryDate(): void
    {
        $this->historyDate = $this->resolvedHistoryDate();
    }

    /** Tanggal yang aman dipakai query — historyDate bisa dikirim apa saja dari browser. */
    protected function resolvedHistoryDate(): string
    {
        $date = trim($this->historyDate);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? $date
            : now('Asia/Jakarta')->toDateString();
    }

    public function getTransactionHistoryProperty(): Collection
    {
        if (! $this->canShowHistoryTab || $this->posStep !== 'history') {
            return collect();
        }

        return BookOfflineCourt::frontdeskHistoryRows($this->resolvedHistoryDate(), trim($this->historySearch), proShop: true);
    }

    public function viewTransactionReceipt(string $paymentId): void
    {
        abort_unless($this->canShowHistoryTab, 403, 'Akses ditolak: Anda tidak memiliki izin untuk melihat struk Kasir Pro Shop.');

        $payment = Payment::query()
            ->where('status', 'SUCCESS')
            ->where(fn ($q) => $q->where('payment_gateway', 'CASHIER_POS')->orWhereNotNull('pos_shift_id'))
            ->whereIn('payload_log->source', self::HISTORY_SOURCES)
            ->findOrFail($paymentId);

        $this->completedOrderData = (new BookOfflineCourt)->buildWalkInReceipt($payment, reprint: true);
        $this->receiptFromHistory = true;
        $this->showSuccessModal = true;
    }

    // Rekap Penjualan Harian (X-Report) — loket & shift yang sama dengan POS Walk-In; tanggal = filter tanggal Riwayat.
    protected function salesRecapCounter(): string
    {
        return self::COUNTER;
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

    /** Kosongkan keranjang, booking / denda terpilih, pembeli & bukti bayar untuk transaksi berikutnya. */
    protected function resetCart(): void
    {
        $this->posStep = 'selection';
        $this->quantities = [];
        $this->merchQuantities = [];
        $this->shopCategory = 'ALL';
        $this->shopSearch = '';
        $this->closeVariantPicker();
        $this->quoteCache = null;
        $this->rentBooking = null;
        $this->bookingSearch = '';
        $this->fineBill = null;
        $this->buyerName = '';
        $this->buyerPhone = '';
        $this->paymentMethod = 'QRIS';
        $this->edcLast4 = '';
        $this->edcApprovalCode = '';
        $this->edcTraceNumber = '';
        $this->qrisRrn = '';
        $this->qrisSenderName = '';
    }

    // ===================== BAYAR OTOMATIS (QR DI LAYAR KASIR) =====================

    /** Tagihan QR yang sedang ditampilkan — milik loket ini & dibuat dari layar Kasir Pro Shop. */
    protected function pendingQrisPayment(): ?Payment
    {
        $id = $this->pendingQris['payment_id'] ?? null;
        $payment = $id ? Payment::find($id) : null;

        return $payment && ($payment->payload_log['counter'] ?? null) === self::COUNTER
            && in_array($payment->payload_log['source'] ?? null, self::AUTO_PAY_SOURCES, true) && isset($payment->payload_log['pos_qris'])
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

        $status = app(PosAutoPayment::class)->status($payment->id);
        if ($status === PosAutoPayment::PAID) {
            $this->completePendingQris($payment->fresh());
        } elseif ($status !== PosAutoPayment::PENDING) {
            $this->pendingQris = null;
            Notification::make()
                ->title($status === PosAutoPayment::EXPIRED ? 'QR Kedaluwarsa' : 'QR Dibatalkan')
                ->body('Pembayaran QRIS tidak diterima. Transaksi alat dibatalkan.')
                ->warning()
                ->send();
        }
    }

    public function cancelPendingQris(): void
    {
        abort_unless($this->canSell || $this->canSellMerch, 403);
        $payment = $this->pendingQrisPayment();
        if (! $payment) {
            $this->pendingQris = null;

            return;
        }

        $status = app(PosAutoPayment::class)->cancel($payment->id);
        if ($status === PosAutoPayment::PAID) {
            // Customer ternyata sudah bayar tepat sebelum dibatalkan — uang sudah masuk, transaksi diteruskan.
            $this->completePendingQris($payment->fresh());

            return;
        }
        if ($status === PosAutoPayment::PENDING) {
            Notification::make()->title('QR Belum Bisa Dibatalkan')->body(PosAutoPayment::cannotCancelYetMessage())->warning()->send();

            return;
        }

        $this->pendingQris = null;
        Notification::make()->title('QR Dibatalkan')->body('Transaksi alat dibatalkan.')->success()->send();
    }

    /** Hanya di laptop developer tanpa server key Midtrans (QR mock). */
    public function simulatePendingQrisPaid(): void
    {
        $payment = $this->pendingQrisPayment();
        abort_unless($payment && ($this->pendingQris['is_mock'] ?? false) && ! app()->environment('production'), 403);

        app(PosAutoPayment::class)->simulatePaid($payment->id);
        $this->pollPendingQris();
    }

    protected function completePendingQris(Payment $payment): void
    {
        $this->pendingQris = null;
        Notification::make()
            ->title('Pembayaran QRIS Diterima')
            ->body("Order #{$payment->order?->order_number} lunas.")
            ->success()
            ->send();
        $this->showReceipt($payment);
    }

    protected function getViewData(): array
    {
        $this->quoteCache = null;

        return [
            'equipments' => $this->equipments,
            'merchProducts' => $this->merchProducts,
            'activeShift' => $this->activeShift,
        ];
    }
}
