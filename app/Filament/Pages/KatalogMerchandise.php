<?php

namespace App\Filament\Pages;

use App\Models\Merch\MerchProduct;
use App\Models\Merch\MerchStockMovement;
use App\Models\Merch\MerchVariant;
use App\Models\Pos\OrderItem;
use App\Services\Audit\ActivityLogger;
use App\Services\Media\SecureImageUploader;
use App\Services\Merch\MerchStockService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Katalog Merchandise (Kasir Pro Shop tab Jual): produk + varian ukuran / warna, Barang Masuk, Penyesuaian & Kartu Stok.
 * Terpisah dari Perlengkapan Padel di Master Data — merchandise tidak pernah muncul sebagai add-on booking.
 *  - Produk & varian: izin manage_merch_products. Varian baru mulai dari stok 0.
 *  - Barang Masuk / Penyesuaian: izin manage_merch_stock, lewat MerchStockService (Kartu Stok + Log Aktivitas).
 *  - Produk / varian yang sudah punya transaksi atau mutasi stok tidak bisa dihapus, hanya dinonaktifkan.
 */
class KatalogMerchandise extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Katalog Merchandise';

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $title = 'Katalog Merchandise';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.katalog-merchandise';

    public const PERMISSION_PRODUCTS = 'manage_merch_products';

    public const PERMISSION_STOCK = 'manage_merch_stock';

    public string $activeTab = 'produk';

    public string $productFilter = 'ALL';

    // Modal produk
    public bool $showProductModal = false;

    public ?string $editingProductId = null;

    public string $productName = '';

    public string $productCategory = 'APPAREL';

    public string $productDescription = '';

    public int|float|string|null $productPrice = null;

    public int|float|string|null $productHpp = null;

    public bool $productIsActive = true;

    /** Produk baru tanpa pilihan ukuran / warna → otomatis satu varian "Standar". */
    public bool $productHasVariants = false;

    public $productPhotoUpload = null;

    public bool $removeProductPhoto = false;

    // Modal varian
    public ?string $variantProductId = null;

    public ?string $editingVariantId = null;

    public string $variantColor = '';

    public string $variantSize = '';

    public string $variantSku = '';

    public int|float|string|null $variantAdditionalPrice = 0;

    public int|string|null $variantMinStock = null;

    // Modal Barang Masuk
    public bool $showReceiveModal = false;

    /** @var array<int, array{variant_id: string, qty: int|string|null, total: int|float|string|null}> */
    public array $receiveLines = [];

    public string $receiveSupplier = '';

    public string $receiveInvoice = '';

    public string $receiveNotes = '';

    // Modal Penyesuaian
    public bool $showAdjustModal = false;

    public string $adjustVariantId = '';

    public string $adjustDirection = 'OUT';

    public int|string|null $adjustQty = null;

    public string $adjustReason = 'DAMAGED';

    public string $adjustNotes = '';

    // Kartu Stok
    public string $movementVariantFilter = '';

    // Ditulis eksplisit: halaman baru tidak dikenali HasPageShield, yang lalu jatuh ke "boleh semua" (sama dengan PeminjamanAlat).
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('View:KatalogMerchandise');
    }

    public function getCanManageProductsProperty(): bool
    {
        return (bool) auth()->user()?->can(self::PERMISSION_PRODUCTS);
    }

    public function getCanManageStockProperty(): bool
    {
        return (bool) auth()->user()?->can(self::PERMISSION_STOCK);
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['produk', 'stok'], true) ? $tab : 'produk';
    }

    public function setProductFilter(string $filter): void
    {
        $this->productFilter = in_array($filter, ['ALL', 'ACTIVE', 'INACTIVE'], true) ? $filter : 'ALL';
    }

    // ===================== PRODUK =====================

    public function openCreateProductModal(): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        $this->resetProductForm();
        $this->showProductModal = true;
    }

    public function openEditProductModal(string $productId): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        $product = MerchProduct::find($productId);
        if (! $product) {
            Notification::make()->title('Produk tidak ditemukan')->danger()->send();

            return;
        }

        $this->resetProductForm();
        $this->editingProductId = $product->id;
        $this->productName = (string) $product->name;
        $this->productCategory = array_key_exists((string) $product->category, MerchProduct::CATEGORIES) ? $product->category : 'OTHER';
        $this->productDescription = (string) $product->description;
        $this->productPrice = (float) $product->base_price;
        $this->productHpp = (float) $product->hpp;
        $this->productIsActive = (bool) $product->is_active;
        $this->showProductModal = true;
    }

    public function closeProductModal(): void
    {
        $this->showProductModal = false;
        $this->resetProductForm();
    }

    public function saveProduct(): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);

        $this->validate([
            'productName' => ['required', 'string', 'max:100'],
            'productCategory' => ['required', Rule::in(array_keys(MerchProduct::CATEGORIES))],
            'productDescription' => ['nullable', 'string', 'max:1000'],
            'productPrice' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'productHpp' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'productPhotoUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'productName.required' => 'Nama produk wajib diisi.',
            'productPrice.required' => 'Harga jual wajib diisi.',
        ]);

        $product = $this->editingProductId ? MerchProduct::find($this->editingProductId) : null;
        if ($this->editingProductId && ! $product) {
            Notification::make()->title('Produk tidak ditemukan')->danger()->send();

            return;
        }
        // Harga boleh Rp0, tidak boleh minus: varian dengan tambahan harga negatif dicek terhadap harga produk yang baru.
        $lowestAdditional = $product ? (float) $product->variants()->min('additional_price') : 0.0;
        if ($product && round((float) $this->productPrice + $lowestAdditional, 2) < 0) {
            $this->addError('productPrice', 'Harga ini membuat varian dengan potongan harga '.\App\Filament\Pages\BukuTransaksi::rupiah($lowestAdditional).' bernilai minus. Ubah tambahan harga variannya dulu.');

            return;
        }

        // Foto lama diambil dari DATABASE (bukan dari properti browser); file lama dibuang MerchProduct::booted()
        // setelah perubahan tersimpan. Foto selalu diproses ulang (validasi isi file, buang metadata).
        $photoPath = $product?->image;
        $newPhotoPath = null;
        if ($this->productPhotoUpload) {
            $photoPath = $newPhotoPath = SecureImageUploader::store($this->productPhotoUpload, 'merch/products');
        } elseif ($this->removeProductPhoto) {
            $photoPath = null;
        }

        $data = [
            'name' => trim($this->productName),
            'category' => $this->productCategory,
            'description' => trim($this->productDescription) ?: null,
            'image' => $photoPath,
            'base_price' => round((float) $this->productPrice, 2),
            'hpp' => round((float) ($this->productHpp ?: 0), 2),
            'is_active' => $this->productIsActive,
        ];

        try {
            DB::transaction(function () use (&$product, $data) {
                if ($product) {
                    $before = ['harga' => (float) $product->base_price, 'hpp' => (float) $product->hpp];
                    $product->update($data);
                    ActivityLogger::record('MERCH', 'merch_product.updated', "Mengubah produk merchandise {$product->name}",
                        subject: $product,
                        meta: ['harga' => ActivityLogger::rupiah($before['harga']).' → '.ActivityLogger::rupiah((float) $product->base_price),
                            'hpp' => ActivityLogger::rupiah($before['hpp']).' → '.ActivityLogger::rupiah((float) $product->hpp),
                            'status' => $product->is_active ? 'Aktif' : 'Nonaktif'],
                    );

                    return;
                }

                $product = MerchProduct::create($data);
                if (! $this->productHasVariants) {
                    MerchVariant::create([
                        'product_id' => $product->id,
                        'sku' => $this->uniqueSku(),
                        'color' => MerchVariant::STANDARD_COLOR,
                        'size' => MerchVariant::STANDARD_SIZE,
                        'additional_price' => 0,
                        'stock_quantity' => 0,
                        'is_active' => true,
                    ]);
                }
                ActivityLogger::record('MERCH', 'merch_product.created', "Menambah produk merchandise {$product->name}",
                    subject: $product,
                    meta: ['harga' => ActivityLogger::rupiah((float) $product->base_price), 'hpp' => ActivityLogger::rupiah((float) $product->hpp)],
                );
            });
        } catch (\Throwable $e) {
            if ($newPhotoPath) {
                Storage::disk('public')->delete($newPhotoPath);
            }

            throw $e;
        }

        $created = $product->wasRecentlyCreated;
        $this->closeProductModal();
        Notification::make()
            ->title($created ? 'Produk Ditambahkan' : 'Produk Diperbarui')
            ->body($created && $this->hasOnlyStandardVariant($product)
                ? 'Stok awal 0 — catat stok lewat tab Stok & Kartu Stok → Barang Masuk.'
                : ($created ? 'Tambahkan varian ukuran / warna lewat tombol Varian.' : null))
            ->success()->send();
    }

    public function toggleProductStatus(string $productId): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        $product = MerchProduct::find($productId);
        if (! $product) {
            return;
        }
        $product->update(['is_active' => ! $product->is_active]);
        ActivityLogger::record('MERCH', 'merch_product.status', ($product->is_active ? 'Mengaktifkan' : 'Menonaktifkan')." produk merchandise {$product->name}", subject: $product);
        Notification::make()->title('Status Produk Diperbarui')->body("{$product->name} kini ".($product->is_active ? 'aktif' : 'nonaktif').'.')->success()->send();
    }

    /** Hapus permanen hanya bila belum ada transaksi / mutasi stok sama sekali; selain itu dinonaktifkan. */
    public function deleteProduct(string $productId): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);

        $result = DB::transaction(function () use ($productId) {
            $product = MerchProduct::with('variants')->lockForUpdate()->find($productId);
            if (! $product) {
                return 'NOT_FOUND';
            }
            if ($this->variantIdsWithHistory($product->variants->pluck('id'))->isNotEmpty()) {
                $product->update(['is_active' => false]);
                ActivityLogger::record('MERCH', 'merch_product.status', "Menonaktifkan produk merchandise {$product->name} (sudah punya riwayat transaksi)", subject: $product);

                return 'DEACTIVATED';
            }
            ActivityLogger::record('MERCH', 'merch_product.deleted', "Menghapus produk merchandise {$product->name}", severity: ActivityLogger::WARNING, subjectLabel: $product->name);
            $product->delete(); // varian ikut terhapus (cascade)

            return 'DELETED';
        });

        match ($result) {
            'NOT_FOUND' => Notification::make()->title('Produk tidak ditemukan')->danger()->send(),
            'DEACTIVATED' => Notification::make()->title('Produk Dinonaktifkan')->body('Produk ini sudah memiliki riwayat transaksi atau mutasi stok, sehingga tidak dihapus — hanya dinonaktifkan.')->warning()->send(),
            default => Notification::make()->title('Produk Dihapus')->success()->send(),
        };
    }

    // ===================== VARIAN =====================

    public function openVariantModal(string $productId): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        if (! MerchProduct::whereKey($productId)->exists()) {
            Notification::make()->title('Produk tidak ditemukan')->danger()->send();

            return;
        }
        $this->variantProductId = $productId;
        $this->resetVariantForm();
    }

    public function closeVariantModal(): void
    {
        $this->variantProductId = null;
        $this->resetVariantForm();
    }

    public function editVariant(string $variantId): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        $variant = MerchVariant::where('product_id', $this->variantProductId)->find($variantId);
        if (! $variant) {
            return;
        }
        $this->resetErrorBag();
        $this->editingVariantId = $variant->id;
        $this->variantColor = (string) $variant->color;
        $this->variantSize = (string) $variant->size;
        $this->variantSku = (string) $variant->sku;
        $this->variantAdditionalPrice = (float) $variant->additional_price;
        $this->variantMinStock = $variant->min_stock;
    }

    public function cancelVariantEdit(): void
    {
        $this->resetVariantForm();
    }

    public function saveVariant(): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        $product = $this->variantProductId ? MerchProduct::find($this->variantProductId) : null;
        if (! $product) {
            Notification::make()->title('Produk tidak ditemukan')->danger()->send();

            return;
        }
        $variant = $this->editingVariantId ? MerchVariant::where('product_id', $product->id)->find($this->editingVariantId) : null;

        $this->variantSku = strtoupper(trim($this->variantSku));
        $this->validate([
            'variantColor' => ['required', 'string', 'max:30'],
            'variantSize' => ['required', 'string', 'max:10'],
            'variantSku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9\-_.]*$/', Rule::unique('merch_variants', 'sku')->ignore($variant?->id)],
            'variantAdditionalPrice' => ['nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'variantMinStock' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], [
            'variantColor.required' => 'Warna wajib diisi (isi "Standar" bila tidak ada pilihan warna).',
            'variantSize.required' => 'Ukuran wajib diisi (isi "All" bila tidak ada pilihan ukuran).',
            'variantSku.unique' => 'SKU sudah dipakai varian lain.',
            'variantSku.regex' => 'SKU hanya boleh huruf, angka, tanda minus, titik, atau garis bawah.',
        ]);

        $color = trim($this->variantColor);
        $size = trim($this->variantSize);
        $duplicate = MerchVariant::where('product_id', $product->id)->where('color', $color)->where('size', $size)
            ->when($variant, fn ($q) => $q->whereKeyNot($variant->id))->exists();
        if ($duplicate) {
            $this->addError('variantSize', 'Varian dengan warna & ukuran yang sama sudah ada.');

            return;
        }
        $additional = round((float) ($this->variantAdditionalPrice ?: 0), 2);
        if ((float) $product->base_price + $additional < 0) {
            $this->addError('variantAdditionalPrice', 'Harga varian (harga produk + tambahan) tidak boleh minus.');

            return;
        }

        $data = [
            'color' => $color,
            'size' => $size,
            'sku' => $this->variantSku !== '' ? $this->variantSku : ($variant?->sku ?? $this->uniqueSku()),
            'additional_price' => $additional,
            'min_stock' => $this->variantMinStock === null || $this->variantMinStock === '' ? null : (int) $this->variantMinStock,
        ];
        if ($variant) {
            $variant->update($data);
        } else {
            $variant = MerchVariant::create($data + ['product_id' => $product->id, 'stock_quantity' => 0, 'is_active' => true]);
        }
        ActivityLogger::record('MERCH', $variant->wasRecentlyCreated ? 'merch_variant.created' : 'merch_variant.updated',
            ($variant->wasRecentlyCreated ? 'Menambah' : 'Mengubah')." varian {$product->name} · {$variant->label()}",
            subject: $product, meta: ['sku' => $variant->sku]);

        Notification::make()->title($variant->wasRecentlyCreated ? 'Varian Ditambahkan' : 'Varian Diperbarui')
            ->body($variant->wasRecentlyCreated ? 'Stok awal 0 — catat stok lewat Barang Masuk.' : null)->success()->send();
        $this->resetVariantForm();
    }

    public function toggleVariantStatus(string $variantId): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        $variant = MerchVariant::with('product')->where('product_id', $this->variantProductId)->find($variantId);
        if (! $variant) {
            return;
        }
        $variant->update(['is_active' => ! $variant->is_active]);
        ActivityLogger::record('MERCH', 'merch_variant.status', ($variant->is_active ? 'Mengaktifkan' : 'Menonaktifkan')." varian {$variant->product?->name} · {$variant->label()}", subject: $variant->product);
    }

    public function deleteVariant(string $variantId): void
    {
        $this->authorizeAction(self::PERMISSION_PRODUCTS);
        $variant = MerchVariant::with('product')->where('product_id', $this->variantProductId)->find($variantId);
        if (! $variant) {
            return;
        }
        if ($this->variantIdsWithHistory(collect([$variant->id]))->isNotEmpty()) {
            $variant->update(['is_active' => false]);
            Notification::make()->title('Varian Dinonaktifkan')->body('Varian ini sudah memiliki riwayat transaksi atau mutasi stok, sehingga hanya dinonaktifkan.')->warning()->send();

            return;
        }
        ActivityLogger::record('MERCH', 'merch_variant.deleted', "Menghapus varian {$variant->product?->name} · {$variant->label()}", subject: $variant->product, severity: ActivityLogger::WARNING);
        $variant->delete();
        if ($this->editingVariantId === $variantId) {
            $this->resetVariantForm();
        }
        Notification::make()->title('Varian Dihapus')->success()->send();
    }

    // ===================== STOK =====================

    public function openReceiveModal(): void
    {
        $this->authorizeAction(self::PERMISSION_STOCK);
        $this->resetErrorBag();
        $this->receiveLines = [['variant_id' => '', 'qty' => null, 'total' => null]];
        $this->receiveSupplier = '';
        $this->receiveInvoice = '';
        $this->receiveNotes = '';
        $this->showReceiveModal = true;
    }

    public function addReceiveLine(): void
    {
        if (count($this->receiveLines) < MerchStockService::MAX_RECEIPT_LINES) {
            $this->receiveLines[] = ['variant_id' => '', 'qty' => null, 'total' => null];
        }
    }

    public function removeReceiveLine(int $index): void
    {
        unset($this->receiveLines[$index]);
        $this->receiveLines = array_values($this->receiveLines);
        if ($this->receiveLines === []) {
            $this->receiveLines = [['variant_id' => '', 'qty' => null, 'total' => null]];
        }
    }

    public function closeReceiveModal(): void
    {
        $this->showReceiveModal = false;
    }

    public function saveReceive(): void
    {
        $this->authorizeAction(self::PERMISSION_STOCK);
        // Baris yang benar-benar kosong (belum dipilih & belum diisi) dilewati; baris setengah terisi tetap dicek service.
        $lines = array_values(array_filter($this->receiveLines, fn ($l) => (string) ($l['variant_id'] ?? '') !== '' || (string) ($l['qty'] ?? '') !== ''));
        $lines = array_map(fn ($l) => ['variant_id' => (string) ($l['variant_id'] ?? ''), 'qty' => $l['qty'] ?? null, 'total' => $l['total'] ?? null], $lines);

        $reference = $this->attempt(fn () => app(MerchStockService::class)->receive($lines, $this->receiveSupplier, $this->receiveInvoice, $this->receiveNotes, auth()->user()));
        if ($reference === null) {
            return;
        }
        $this->showReceiveModal = false;
        Notification::make()->title('Barang Masuk Tercatat')->body("Nomor penerimaan {$reference}.")->success()->send();
    }

    public function openAdjustModal(?string $variantId = null): void
    {
        $this->authorizeAction(self::PERMISSION_STOCK);
        $this->resetErrorBag();
        $this->adjustVariantId = (string) $variantId;
        $this->adjustDirection = 'OUT';
        $this->adjustQty = null;
        $this->adjustReason = 'DAMAGED';
        $this->adjustNotes = '';
        $this->showAdjustModal = true;
    }

    public function closeAdjustModal(): void
    {
        $this->showAdjustModal = false;
    }

    public function saveAdjust(): void
    {
        $this->authorizeAction(self::PERMISSION_STOCK);
        $variant = MerchVariant::find($this->adjustVariantId);
        if (! $variant) {
            Notification::make()->title('Pilih Varian')->body('Pilih varian yang disesuaikan stoknya.')->warning()->send();

            return;
        }
        if (! is_numeric($this->adjustQty) || (int) $this->adjustQty != $this->adjustQty || (int) $this->adjustQty < 1) {
            Notification::make()->title('Jumlah Tidak Valid')->body('Jumlah penyesuaian harus bilangan bulat minimal 1.')->warning()->send();

            return;
        }
        $change = ($this->adjustDirection === 'IN' ? 1 : -1) * (int) $this->adjustQty;

        $movement = $this->attempt(fn () => app(MerchStockService::class)->adjust($variant, $change, $this->adjustReason, $this->adjustNotes, auth()->user()));
        if ($movement === null) {
            return;
        }
        $this->showAdjustModal = false;
        Notification::make()->title('Penyesuaian Stok Tersimpan')->body("Stok sekarang {$movement->stock_after}.")->success()->send();
    }

    // ===================== DATA =====================

    public function getProductsProperty(): Collection
    {
        return MerchProduct::with(['variants' => fn ($q) => $q->orderBy('color')->orderBy('size')])
            ->when($this->productFilter === 'ACTIVE', fn ($q) => $q->where('is_active', true))
            ->when($this->productFilter === 'INACTIVE', fn ($q) => $q->where('is_active', false))
            ->orderBy('category')->orderBy('name')
            ->get();
    }

    public function getVariantProductProperty(): ?MerchProduct
    {
        return $this->variantProductId
            ? MerchProduct::with(['variants' => fn ($q) => $q->orderBy('color')->orderBy('size')])->find($this->variantProductId)
            : null;
    }

    /** Semua varian untuk pilihan Barang Masuk / Penyesuaian / filter Kartu Stok. */
    public function getVariantOptionsProperty(): Collection
    {
        return MerchVariant::with('product:id,name,is_active')
            ->get()
            ->sortBy(fn (MerchVariant $v) => mb_strtolower(($v->product?->name ?? '').' '.$v->label()))
            ->mapWithKeys(fn (MerchVariant $v) => [$v->id => ($v->product?->name ?? '-').' · '.$v->label().' (stok '.$v->stock_quantity.')'.($v->is_active && $v->product?->is_active ? '' : ' — nonaktif')]);
    }

    /** Stok per varian, yang menipis / habis di atas. */
    public function getStockRowsProperty(): Collection
    {
        return MerchVariant::with('product:id,name,is_active,hpp')
            ->get()
            ->sortBy(fn (MerchVariant $v) => [$v->isLowStock() ? 0 : 1, mb_strtolower(($v->product?->name ?? '').' '.$v->label())])
            ->values();
    }

    public function getMovementsProperty(): Collection
    {
        return MerchStockMovement::with(['creator:id,name', 'order:id,order_number'])
            ->when($this->movementVariantFilter !== '', fn ($q) => $q->where('variant_id', $this->movementVariantFilter))
            ->latest('created_at')
            ->limit(100)
            ->get();
    }

    /** Varian yang pernah dipakai transaksi kasir atau punya mutasi stok — tidak boleh dihapus permanen. */
    protected function variantIdsWithHistory(Collection $ids): Collection
    {
        return MerchStockMovement::whereIn('variant_id', $ids)->distinct()->pluck('variant_id')
            ->merge(OrderItem::where('item_type', 'MERCH')->whereIn('reference_id', $ids)->distinct()->pluck('reference_id'))
            ->unique()->values();
    }

    protected function hasOnlyStandardVariant(MerchProduct $product): bool
    {
        $variants = $product->variants()->get();

        return $variants->count() === 1 && $variants->first()->isStandard();
    }

    protected function uniqueSku(): string
    {
        do {
            $sku = 'C61-'.strtoupper(Str::random(8));
        } while (MerchVariant::where('sku', $sku)->exists());

        return $sku;
    }

    protected function resetProductForm(): void
    {
        $this->resetErrorBag();
        $this->editingProductId = null;
        $this->productName = '';
        $this->productCategory = 'APPAREL';
        $this->productDescription = '';
        $this->productPrice = null;
        $this->productHpp = null;
        $this->productIsActive = true;
        $this->productHasVariants = false;
        $this->productPhotoUpload = null;
        $this->removeProductPhoto = false;
    }

    protected function resetVariantForm(): void
    {
        $this->resetErrorBag();
        $this->editingVariantId = null;
        $this->variantColor = '';
        $this->variantSize = '';
        $this->variantSku = '';
        $this->variantAdditionalPrice = 0;
        $this->variantMinStock = null;
    }

    protected function authorizeAction(string $permission): void
    {
        if (! auth()->user()?->can($permission)) {
            ActivityLogger::accessDenied("mencoba aksi tanpa izin [{$permission}] di Katalog Merchandise");
            abort(403, "Akses ditolak: Anda tidak memiliki izin [{$permission}].");
        }
    }

    /** Jalankan aksi stok; pesan validasi service (422) tampil sebagai notifikasi. Null = gagal. */
    protected function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                throw $e;
            }
            Notification::make()->title('Belum Tersimpan')->body($e->getMessage())->danger()->persistent()->send();

            return null;
        }
    }
}
