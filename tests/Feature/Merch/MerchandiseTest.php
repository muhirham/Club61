<?php

namespace Tests\Feature\Merch;

use App\Filament\Pages\KasirProShop;
use App\Filament\Pages\KatalogMerchandise;
use App\Filament\Pages\LaporanMerchandise;
use App\Models\Finance\LedgerEntry;
use App\Models\Merch\MerchProduct;
use App\Models\Merch\MerchStockMovement;
use App\Models\Merch\MerchVariant;
use App\Models\Padel\CourtEquipment;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Merch\MerchReportService;
use App\Services\Merch\MerchStockService;
use App\Services\Padel\BookingAddonService;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Pos\PosPendingOrderCanceller;
use App\Services\Pos\ProShopSaleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Merchandise (Tahap 2A): Katalog Merchandise (produk, varian, Barang Masuk, Penyesuaian, Kartu Stok), penjualan di
 * Kasir Pro Shop tab Jual tanpa akun customer, dan Laporan Merchandise.
 */
class MerchandiseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    private PosCashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->travelTo(Carbon::parse('2026-11-10 14:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->create(['name' => 'Admin Gudang', 'is_active' => true]);
        $this->admin->givePermissionTo(['View:KatalogMerchandise', KatalogMerchandise::PERMISSION_PRODUCTS, KatalogMerchandise::PERMISSION_STOCK, 'View:LaporanMerchandise', 'export_reports']);

        // Kasir khusus merchandise: tanpa izin jual perlengkapan padel.
        $this->cashier = User::factory()->create(['name' => 'Kasir Merch', 'is_active' => true]);
        $this->cashier->givePermissionTo(['View:KasirProShop', ProShopSaleService::PERMISSION_MERCH]);

        $this->shift = PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
    }

    /** Kaos (2 varian, HPP 80rb, harga 150rb, XL +10rb) dengan stok masuk lewat Barang Masuk. */
    private function shirt(int $stockM = 5, int $stockXl = 3): array
    {
        $product = MerchProduct::create(['name' => 'Kaos Club 61', 'category' => 'APPAREL', 'base_price' => 150000, 'hpp' => 80000, 'is_active' => true]);
        $m = MerchVariant::create(['product_id' => $product->id, 'sku' => 'KAOS-HTM-M', 'color' => 'Hitam', 'size' => 'M', 'additional_price' => 0, 'stock_quantity' => 0, 'is_active' => true]);
        $xl = MerchVariant::create(['product_id' => $product->id, 'sku' => 'KAOS-HTM-XL', 'color' => 'Hitam', 'size' => 'XL', 'additional_price' => 10000, 'stock_quantity' => 0, 'is_active' => true]);
        app(MerchStockService::class)->receive([
            ['variant_id' => $m->id, 'qty' => $stockM, 'total' => $stockM * 80000],
            ['variant_id' => $xl->id, 'qty' => $stockXl, 'total' => null],
        ], 'Konveksi Jaya', 'INV-01', null, $this->admin);

        return [$product, $m->fresh(), $xl->fresh()];
    }

    private function expectRejected(callable $callback, string $message, int $status): void
    {
        try {
            $callback();
            $this->fail("Seharusnya ditolak: {$message}");
        } catch (HttpException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    // ================================================================== KATALOG

    public function test_catalog_manages_products_variants_and_stock_with_a_stock_card(): void
    {
        $this->actingAs($this->admin);

        // Produk tanpa pilihan ukuran → satu varian "Standar" dengan stok 0.
        Livewire::test(KatalogMerchandise::class)
            ->call('openCreateProductModal')
            ->set('productName', 'Botol Club 61')->set('productCategory', 'BOTTLE')->set('productPrice', 75000)->set('productHpp', 30000)
            ->call('saveProduct')->assertHasNoErrors()->assertNotified('Produk Ditambahkan');
        $bottle = MerchProduct::where('name', 'Botol Club 61')->sole();
        $standard = $bottle->variants()->sole();
        $this->assertSame([true, 0, 30000.0], [$standard->isStandard(), $standard->stock_quantity, (float) $bottle->hpp]);

        // Produk dengan varian: varian ditambah lewat modal Varian; warna + ukuran yang sama ditolak.
        $page = Livewire::test(KatalogMerchandise::class)
            ->call('openCreateProductModal')
            ->set('productName', 'Topi Club 61')->set('productCategory', 'HEADWEAR')->set('productPrice', 120000)->set('productHasVariants', true)
            ->call('saveProduct')->assertHasNoErrors();
        $cap = MerchProduct::where('name', 'Topi Club 61')->sole();
        $this->assertSame(0, $cap->variants()->count());
        $page->call('openVariantModal', $cap->id)
            ->set('variantColor', 'Putih')->set('variantSize', 'All')->set('variantSku', 'topi-pth')->call('saveVariant')->assertHasNoErrors()
            ->set('variantColor', 'Putih')->set('variantSize', 'All')->call('saveVariant')->assertHasErrors('variantSize')
            ->set('variantColor', 'Hitam')->set('variantSize', 'All')->set('variantSku', 'TOPI-PTH')->call('saveVariant')->assertHasErrors('variantSku');
        $this->assertSame(['TOPI-PTH'], $cap->variants()->pluck('sku')->all(), 'SKU disimpan huruf besar & unik');

        // Barang Masuk → stok bertambah + Kartu Stok; Penyesuaian tidak boleh membuat stok minus.
        $white = $cap->variants()->sole();
        Livewire::test(KatalogMerchandise::class)->call('setActiveTab', 'stok')
            ->call('openReceiveModal')
            ->set('receiveLines', [['variant_id' => $white->id, 'qty' => 10, 'total' => 600000], ['variant_id' => '', 'qty' => null, 'total' => null]])
            ->set('receiveSupplier', 'Konveksi Jaya')
            ->call('saveReceive')->assertNotified('Barang Masuk Tercatat')
            ->call('openAdjustModal', $white->id)->set('adjustQty', 11)->set('adjustNotes', 'Rusak kena air hujan')
            ->call('saveAdjust')->assertNotified('Belum Tersimpan')
            ->call('openAdjustModal', $white->id)->set('adjustQty', 2)->set('adjustNotes', 'Rusak kena air hujan')
            ->call('saveAdjust')->assertNotified('Penyesuaian Stok Tersimpan')
            ->assertSee('Barang Masuk')->assertSee('Konveksi Jaya')->assertSee('Rusak kena air hujan');
        $this->assertSame(8, $white->fresh()->stock_quantity);
        // HPP & harga beli di Kartu Stok tidak ikut terserialisasi (data komponen yang bisa sampai ke browser).
        $this->assertArrayNotHasKey('unit_cost', MerchStockMovement::where('variant_id', $white->id)->first()->toArray());
        $this->assertArrayNotHasKey('purchase_total', MerchStockMovement::where('variant_id', $white->id)->first()->toArray());
        $this->assertSame([[MerchStockMovement::RESTOCK, 10, 10], [MerchStockMovement::ADJUSTMENT, -2, 8]],
            MerchStockMovement::where('variant_id', $white->id)->orderBy('created_at')->orderBy('id')->get()->map(fn ($m) => [$m->type, $m->qty_change, $m->stock_after])->all());

        // Hapus: yang sudah punya mutasi stok hanya dinonaktifkan; yang belum ada riwayatnya terhapus.
        Livewire::test(KatalogMerchandise::class)->call('deleteProduct', $cap->id)->assertNotified('Produk Dinonaktifkan');
        $this->assertFalse($cap->fresh()->is_active);
        Livewire::test(KatalogMerchandise::class)->call('deleteProduct', $bottle->id)->assertNotified('Produk Dihapus');
        $this->assertNull(MerchProduct::find($bottle->id));
        $this->assertSame(0, MerchVariant::where('product_id', $bottle->id)->count());
    }

    public function test_price_can_be_zero_but_never_minus(): void
    {
        [$product, $m, $xl] = $this->shirt();
        $this->actingAs($this->admin);

        // Varian Rp0 (hadiah dalam paket) tetap bisa dijual bersama barang lain.
        $free = MerchVariant::create(['product_id' => $product->id, 'sku' => 'KAOS-GRATIS', 'color' => 'Promo', 'size' => 'S', 'additional_price' => -150000, 'stock_quantity' => 2, 'is_active' => true]);
        $quote = app(ProShopSaleService::class)->quote([], [$m->id => 1, $free->id => 1]);
        $this->assertSame([2, 150000.0], [count($quote['items']), $quote['subtotal']]);

        // Menurunkan harga produk yang membuat varian jadi minus ditolak.
        Livewire::test(KatalogMerchandise::class)->call('openEditProductModal', $product->id)
            ->set('productPrice', 100000)->call('saveProduct')->assertHasErrors('productPrice');
        $this->assertSame(150000.0, (float) $product->fresh()->base_price);

        // Data lama yang sudah terlanjur minus: tidak ditawarkan & ditolak server.
        $free->update(['additional_price' => -160000]);
        $this->assertSame([1], array_map(fn ($i) => $i['quantity'], app(ProShopSaleService::class)->quote([], [$m->id => 1, $free->id => 1])['items']));
        $this->expectRejected(fn () => app(ProShopSaleService::class)->checkout([], [$m->id => 1, $free->id => 1], 140000, 'EDC_BCA',
            ['card_type' => 'DEBIT', 'card_last_4' => '3333', 'approval_code' => 'NG1', 'trace_number' => 'NG1'], 'Rina', '081377788899', $this->cashier), 'tidak dijual', 422);
        $this->assertSame(0, Order::count());
    }

    public function test_catalog_actions_follow_permissions(): void
    {
        MerchProduct::create(['name' => 'Kaos Club 61', 'category' => 'APPAREL', 'base_price' => 150000, 'hpp' => 87000, 'is_active' => true]);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('View:KatalogMerchandise');
        $this->actingAs($viewer);
        // Staf yang hanya melihat stok tidak melihat HPP (harga modal).
        Livewire::test(KatalogMerchandise::class)->assertSee('Kaos Club 61')->assertDontSee('Rp 87.000');
        $this->actingAs($this->admin);
        Livewire::test(KatalogMerchandise::class)->assertSee('Rp 87.000');
        $this->actingAs($viewer);
        Livewire::test(KatalogMerchandise::class)->assertSuccessful()->assertDontSee('+ Tambah Produk')
            ->call('openCreateProductModal')->assertForbidden();
        Livewire::test(KatalogMerchandise::class)->call('openReceiveModal')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_active' => true]));
        $this->assertFalse(KatalogMerchandise::canAccess());
        $this->assertFalse(LaporanMerchandise::canAccess());
    }

    // ================================================================== KASIR PRO SHOP

    public function test_merch_is_sold_at_pro_shop_without_an_account_and_cuts_the_stock(): void
    {
        [$product, $m, $xl] = $this->shirt();
        $this->actingAs($this->cashier);

        $page = Livewire::test(KasirProShop::class)
            ->assertSet('tab', 'jual')
            ->assertSeeHtml('data-merch-product="Kaos Club 61"')->assertDontSeeHtml('data-merch-variant="KAOS-HTM-XL"')
            ->assertDontSee('Perlengkapan Padel')   // kasir ini tidak punya izin jual perlengkapan padel
            // Ketuk kartu bervarian → popup pilih ukuran + jumlah.
            ->call('tapShopItem', 'MERCH', $product->id)->assertSet('pickerProductId', $product->id)
            ->assertSeeHtml('data-merch-variant="KAOS-HTM-XL"')
            ->call('selectPickerVariant', $m->id)->call('changePickerQty', 1)->assertSet('pickerQty', 2)
            ->call('confirmVariantPicker')->assertSet('pickerProductId', null)->assertSet('merchQuantities', [$m->id => 2])
            ->call('tapShopItem', 'MERCH', $product->id)->call('selectPickerVariant', $xl->id)->call('confirmVariantPicker')
            ->set('buyerName', 'Rina Pembeli')->set('buyerPhone', '081377788899')
            ->call('proceedToPayment')->assertSet('posStep', 'payment')->assertSee('3 barang')
            ->set('paymentMethod', 'QRIS')->set('qrisMode', 'MANUAL')->set('qrisRrn', 'RRN90909090')
            ->call('submit')->assertNotified('Penjualan Lunas')
            ->assertSee('PENJUALAN PRO SHOP:')->assertSee('Kaos Club 61 · Hitam / XL');

        $order = Order::with('items')->sole();
        $this->assertSame([null, 'Rina Pembeli', '081377788899', 'PAID'], [$order->user_id, $order->customer_name, $order->customer_phone, $order->payment_status]);
        $this->assertSame(460000.0, (float) $order->subtotal);
        $this->assertEqualsCanonicalizing([
            ['MERCH', $m->id, 2, 150000.0, 80000.0],
            ['MERCH', $xl->id, 1, 160000.0, 80000.0],
        ], $order->items->map(fn ($i) => [$i->item_type, $i->reference_id, $i->quantity, (float) $i->unit_price, (float) $i->unit_cost])->all());
        $this->assertSame([3, 2], [$m->fresh()->stock_quantity, $xl->fresh()->stock_quantity]);
        $this->assertSame(2, MerchStockMovement::where('order_id', $order->id)->where('type', MerchStockMovement::SALE)->count());
        $this->assertSame(460000.0, (float) LedgerEntry::where('order_id', $order->id)->where('category', 'MERCH')->sum('net_amount'), 'Buku Transaksi kategori Merchandise');

        // Rekap kasir (X-Report) & Z-Report shift frontdesk: dipecah per jenis transaksi.
        $recap = app(\App\Services\Pos\DailySalesRecapService::class)->build('PADEL_FRONTDESK', '2026-11-10', $this->admin);
        $this->assertSame([['label' => 'Penjualan Pro Shop', 'count' => 1, 'amount' => 460000.0]], $recap['types']);
        $this->assertSame($recap['types'], \App\Services\Pos\DailySalesRecapService::shiftTransactionTypes($this->shift));

        // Laporan: penjualan − HPP (3 × 80rb) = laba kotor.
        $s = app(MerchReportService::class)->summary('2026-11-10', '2026-11-10');
        $this->assertSame([460000.0, 240000.0, 220000.0, 3], [$s['sales'], $s['hpp'], $s['gross_profit'], $s['qty_sold']]);
        $this->actingAs($this->admin);
        Livewire::test(LaporanMerchandise::class)->assertSuccessful()->assertSee('Kaos Club 61 · Hitam / M')
            ->call('exportExcel')->assertFileDownloaded()
            ->call('exportPdf')->assertFileDownloaded();
        $this->assertTrue(\App\Models\Audit\ActivityLog::where('event', 'merch_report.exported')->count() === 2);

        // Tanpa izin export_reports: unduhan ditolak.
        $this->admin->revokePermissionTo('export_reports');
        Livewire::test(LaporanMerchandise::class)->call('exportExcel')->assertForbidden();
    }

    /** Etalase gaya kasir F&B: kategori, cari nama / SKU, ketuk kartu satu varian = +1, popup dibatasi stok, keranjang −/+ / hapus. */
    public function test_shop_grid_filters_and_variant_popup_respects_stock(): void
    {
        [$product, $m, $xl] = $this->shirt(stockM: 5, stockXl: 2);
        $bottle = MerchProduct::create(['name' => 'Botol Club 61', 'category' => 'BOTTLE', 'base_price' => 75000, 'hpp' => 30000, 'is_active' => true]);
        $bottleVariant = MerchVariant::create(['product_id' => $bottle->id, 'sku' => 'BTL-STD', 'color' => MerchVariant::STANDARD_COLOR, 'size' => MerchVariant::STANDARD_SIZE, 'stock_quantity' => 0, 'is_active' => true]);
        app(MerchStockService::class)->receive([['variant_id' => $bottleVariant->id, 'qty' => 4, 'total' => null]], null, null, null, $this->admin);
        $ball = CourtEquipment::create(['name' => 'Bola Head', 'type' => 'BALL', 'rental_price' => 50000, 'stock_quantity' => 10, 'is_active' => true]);
        $this->cashier->givePermissionTo(BookingAddonService::PERMISSION);
        $this->actingAs($this->cashier);

        $page = Livewire::test(KasirProShop::class)
            ->assertSeeHtml('data-shop-category="PADEL"')->assertSeeHtml('data-shop-category="M:APPAREL"')->assertSeeHtml('data-shop-category="M:BOTTLE"')
            ->call('setShopCategory', 'M:BOTTLE')
            ->assertSeeHtml('data-merch-product="Botol Club 61"')->assertDontSeeHtml('data-merch-product="Kaos Club 61"')->assertDontSeeHtml('data-equipment="Bola Head"')
            ->call('setShopCategory', 'ALL')->set('shopSearch', 'htm-xl')   // cari lewat SKU
            ->assertSeeHtml('data-merch-product="Kaos Club 61"')->assertDontSeeHtml('data-merch-product="Botol Club 61"')
            ->set('shopSearch', '')
            // Satu varian / perlengkapan padel → langsung masuk keranjang, tanpa popup.
            ->call('tapShopItem', 'MERCH', $bottle->id)->assertSet('pickerProductId', null)->assertSet('merchQuantities', [$bottleVariant->id => 1])
            ->call('tapShopItem', 'EQUIPMENT', $ball->id)->assertSet('quantities', [$ball->id => 1])
            // Popup: jumlah tidak bisa melebihi stok varian (XL stok 2).
            ->call('tapShopItem', 'MERCH', $product->id)->call('selectPickerVariant', $xl->id)
            ->call('changePickerQty', 1)->call('changePickerQty', 1)->call('changePickerQty', 1)->assertSet('pickerQty', 2)
            ->set('pickerQty', 9)->call('confirmVariantPicker')   // dipaksa dari browser pun dibatasi stok
            ->assertSet('merchQuantities.'.$xl->id, 2)
            // Semua stok XL sudah di keranjang → tidak bisa ditambah lagi lewat popup.
            ->call('tapShopItem', 'MERCH', $product->id)->assertSet('pickerVariantId', $m->id)
            ->call('selectPickerVariant', $xl->id)->call('confirmVariantPicker')->assertNotified('Stok Tidak Cukup')
            ->call('closeVariantPicker')
            // Keranjang: hapus baris.
            ->assertSeeHtml('data-cart-line="Kaos Club 61 · Hitam / XL"')
            ->call('removeCartItem', 'MERCH', $xl->id)->assertDontSeeHtml('data-cart-line="Kaos Club 61 · Hitam / XL"');
        $this->assertSame([$bottleVariant->id => 1], $page->get('merchQuantities'));
    }

    public function test_server_rejects_merch_without_permission_inactive_items_and_short_stock(): void
    {
        [$product, $m] = $this->shirt(stockM: 1);
        $equipmentSeller = User::factory()->create(['is_active' => true]);
        $equipmentSeller->givePermissionTo(['View:KasirProShop', BookingAddonService::PERMISSION]);
        $service = app(ProShopSaleService::class);
        $proof = ['card_type' => 'DEBIT', 'card_last_4' => '1111', 'approval_code' => 'A1', 'trace_number' => 'T1'];

        $this->expectRejected(fn () => $service->checkout([], [$m->id => 1], 150000, 'EDC_BCA', $proof, 'Rina', '081377788899', $equipmentSeller), ProShopSaleService::PERMISSION_MERCH, 403);
        $this->expectRejected(fn () => $service->checkout([], [$m->id => 2], 300000, 'EDC_BCA', $proof, 'Rina', '081377788899', $this->cashier), 'tinggal 1', 422);
        $this->expectRejected(fn () => $service->checkout([], [$m->id => 1], 150000, 'EDC_BCA', $proof, 'Rina', '0813', $this->cashier), '8–15 digit', 422);
        $m->update(['is_active' => false]);
        $this->expectRejected(fn () => $service->checkout([], [$m->id => 1], 150000, 'EDC_BCA', $proof, 'Rina', '081377788899', $this->cashier), 'tidak dijual', 422);
        $this->assertSame([0, 1], [Order::count(), $m->fresh()->stock_quantity]);

        // Varian nonaktif tidak tampil & tidak bisa dimasukkan keranjang dari browser.
        $this->actingAs($this->cashier);
        Livewire::test(KasirProShop::class)->assertDontSeeHtml('data-merch-variant="KAOS-HTM-M"')
            ->call('incrementMerch', $m->id)->assertSet('merchQuantities', []);
    }

    public function test_mixed_cart_taxes_each_module_by_its_own_setting(): void
    {
        ClubFinanceSetting::getSettings()->update([
            'is_tax_enabled' => true, 'tax_type' => 'PERCENTAGE', 'tax_rate' => 10, 'tax_channels' => 'ALL', 'is_admin_fee_enabled' => false,
            'module_overrides' => ['PADEL' => ['tax_active' => true, 'admin_fee_active' => false], 'MERCH' => ['tax_active' => false, 'admin_fee_active' => false]],
        ]);
        [$product, $m] = $this->shirt();
        $ball = CourtEquipment::create(['name' => 'Bola', 'type' => 'BALL', 'rental_price' => 50000, 'stock_quantity' => 10, 'is_active' => true]);
        $this->cashier->givePermissionTo(BookingAddonService::PERMISSION);

        $quote = app(ProShopSaleService::class)->quote([$ball->id => 2], [$m->id => 1]);
        // Pajak hanya untuk perlengkapan padel (10% × 100rb); merchandise tanpa pajak.
        $this->assertSame([250000.0, 10000.0, 260000.0], [$quote['subtotal'], $quote['tax_amount'], $quote['grand_total']]);

        $result = app(ProShopSaleService::class)->checkout([$ball->id => 2], [$m->id => 1], 260000, 'EDC_BCA',
            ['card_type' => 'DEBIT', 'card_last_4' => '2222', 'approval_code' => 'MX1', 'trace_number' => 'MX1'], 'Rina Pembeli', '081377788899', $this->cashier);
        $payment = $result['payment'];
        $this->assertSame([ProShopSaleService::SOURCE, $this->shift->id], [$payment->payload_log['source'], $payment->pos_shift_id]);
        $this->assertEqualsWithDelta(260000, (float) LedgerEntry::where('payment_id', $payment->id)->sum('total_amount'), 0.01, 'Buku Transaksi = uang masuk');
        $this->assertEqualsCanonicalizing(['ADDON_PADEL', 'MERCH'], LedgerEntry::where('payment_id', $payment->id)->pluck('category')->unique()->values()->all());
        $this->assertSame([8, 4], [$ball->fresh()->stock_quantity, $m->fresh()->stock_quantity]);
    }

    public function test_cancelled_gateway_qr_returns_the_merch_stock_once(): void
    {
        [$product, $m] = $this->shirt();
        $this->actingAs($this->cashier);

        $page = Livewire::test(KasirProShop::class)
            ->set('buyerName', 'Rina Pembeli')->set('buyerPhone', '081377788899')
            ->call('incrementMerch', $m->id)->call('incrementMerch', $m->id)
            ->call('proceedToPayment')->set('paymentMethod', 'QRIS')->assertSet('qrisMode', 'MIDTRANS')
            ->call('submit')->assertSee('Menunggu pembayaran customer');
        $this->assertSame(3, $m->fresh()->stock_quantity, 'stok ditahan selama QR menunggu');

        $pending = $page->get('pendingQris');
        $page->call('cancelPendingQris')->assertSet('pendingQris', null);
        $this->assertSame('CANCELLED', Order::find($pending['order_id'])->payment_status);
        $this->assertSame(5, $m->fresh()->stock_quantity);

        PosPendingOrderCanceller::cancel(Payment::findOrFail($pending['payment_id']), 'TEST_REPEAT');
        $this->assertSame(5, $m->fresh()->stock_quantity, 'tidak dikembalikan dua kali');
        $this->assertSame(1, MerchStockMovement::where('order_id', $pending['order_id'])->where('type', MerchStockMovement::SALE_CANCEL)->count());
    }

    /** Gateway Midtrans: notifikasi expire datang duluan (layar kasir sudah ditutup) → stok tetap kembali, sekali saja. */
    public function test_midtrans_expire_notification_returns_the_stock_of_an_unpaid_pro_shop_qr(): void
    {
        [$product, $m] = $this->shirt();
        $ball = CourtEquipment::create(['name' => 'Bola', 'type' => 'BALL', 'rental_price' => 50000, 'stock_quantity' => 10, 'is_active' => true]);
        $this->cashier->givePermissionTo(BookingAddonService::PERMISSION);
        $this->actingAs($this->cashier);

        $pending = Livewire::test(KasirProShop::class)
            ->set('buyerName', 'Rina Pembeli')->set('buyerPhone', '081377788899')
            ->call('incrementMerch', $m->id)->call('incrementMerch', $m->id)->call('incrementEquipment', $ball->id)
            ->call('proceedToPayment')->set('paymentMethod', 'QRIS')->assertSet('qrisMode', 'MIDTRANS')
            ->call('submit')->get('pendingQris');
        $this->assertSame([3, 9], [$m->fresh()->stock_quantity, $ball->fresh()->stock_quantity], 'stok ditahan selama QR menunggu');

        $payment = Payment::findOrFail($pending['payment_id']);
        $gross = number_format((float) $payment->amount, 2, '.', '');
        config(['services.midtrans.server_key' => 'SB-Mid-server-TEST-KEY']);
        $expire = fn () => $this->postJson('/api/v1/padel/webhook/midtrans', [
            'order_id' => $payment->transaction_id,
            'status_code' => '202',
            'gross_amount' => $gross,
            'signature_key' => hash('sha512', $payment->transaction_id.'202'.$gross.'SB-Mid-server-TEST-KEY'),
            'transaction_status' => 'expire',
        ])->assertOk();

        $expire();
        $this->assertSame(['CANCELLED', 'FAILED'], [Order::find($pending['order_id'])->payment_status, $payment->fresh()->status]);
        $this->assertSame([5, 10], [$m->fresh()->stock_quantity, $ball->fresh()->stock_quantity]);

        // Notifikasi diulang, pembersih / layar kasir menyusul membatalkan → stok tidak bertambah dua kali.
        $expire();
        PosPendingOrderCanceller::cancel($payment->fresh(), 'TEST_REPEAT');
        $this->assertSame([5, 10], [$m->fresh()->stock_quantity, $ball->fresh()->stock_quantity]);
        $this->assertSame(1, MerchStockMovement::where('order_id', $pending['order_id'])->where('type', MerchStockMovement::SALE_CANCEL)->count());
    }

    public function test_public_api_hides_cost_and_inactive_variants(): void
    {
        [$product, $m, $xl] = $this->shirt();
        $xl->update(['is_active' => false, 'min_stock' => 2]);
        // Foto memakai host yang sedang dibuka (bukan APP_URL) — sama dengan foto fasilitas (PublicStorageUrl).
        $product->update(['image' => 'merch/products/kaos.jpg']);
        $this->assertSame(url('/storage/merch/products/kaos.jpg'), $product->fresh()->imageUrl());

        $response = $this->getJson('/api/v1/merch/products')->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertArrayNotHasKey('hpp', $data[0]);
        $this->assertSame(['KAOS-HTM-M'], array_column($data[0]['variants'], 'sku'));
        $this->assertArrayNotHasKey('min_stock', $data[0]['variants'][0]);
        $this->assertStringNotContainsString('80000', $response->getContent());
    }
}
