<?php

namespace Tests\Feature;

use App\Livewire\Pos\FnbCashierTerminal;
use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Fnb\FnbStation;
use App\Models\Pos\KitchenTicket;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Checkout kasir F&B — sengaja mengikuti pola & skema pembayaran yang sama persis dengan
 * WalkInBookingTest.php (walk-in booking lapangan): shift kasir wajib aktif, kebijakan 100%
 * Cashless (CASH ditolak), dan pelunasan lewat PaymentOrchestratorService::markOrderAsPaid().
 */
class FnbPosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected FnbCategory $category;

    protected FnbMenu $latte;

    protected FnbMenu $toast;

    protected FnbStation $kitchen;

    protected function setUp(): void
    {
        parent::setUp();

        Club61PermissionMatrix::syncAllPermissions('web');

        $this->cashier = User::factory()->cashier()->create(['is_active' => true]);
        $this->cashier->givePermissionTo([
            'access_pos_terminal',
            'process_fnb_order',
            'open_pos_shift',
            'close_pos_shift',
        ]);

        $this->category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);
        $this->kitchen = FnbStation::create(['name' => 'Kitchen', 'printer_host' => '192.168.1.50', 'printer_port' => 9100]);
        $this->latte = FnbMenu::create([
            'category_id' => $this->category->id,
            'name' => 'Iced Spanish Latte',
            'base_price' => 38000,
            'is_available' => true,
        ]);
        $this->toast = FnbMenu::create([
            'category_id' => $this->category->id,
            'name' => 'Smashed Avocado Toast',
            'base_price' => 55000,
            'station_id' => $this->kitchen->id,
            'is_available' => true,
        ]);
    }

    /**
     * Kasir yang izin process_fnb_order-nya dicabut dari role (misal lewat menu Roles & Hak
     * Akses). $this->cashier tetap punya izin itu karena diberikan langsung di setUp().
     */
    protected function cashierWithoutFnbPermission(): User
    {
        \App\Models\Role::findByName('cashier', 'web')->revokePermissionTo('process_fnb_order');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return User::factory()->cashier()->create();
    }

    protected function openFnbShift(): PosCashierShift
    {
        return PosCashierShift::create([
            'shift_number' => 'SFT-FNB-'.now()->format('Ymd').'-0001',
            'counter' => 'FNB_COUNTER',
            'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id,
            'opened_at' => now(),
            'starting_cash' => 0,
            'expected_cash' => 0,
        ]);
    }

    public function test_cashier_can_add_items_to_cart_with_live_total(): void
    {
        $this->actingAs($this->cashier);

        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('addToCart', $this->latte->id)
            ->call('addToCart', $this->toast->id);

        $cart = $component->get('cart');
        $this->assertSame(2, $cart[$this->latte->id]['quantity']);
        $this->assertSame(1, $cart[$this->toast->id]['quantity']);
        $component->assertSet('cartSubtotal', 38000 * 2 + 55000);
    }

    public function test_menu_grid_is_paginated_and_resets_to_first_page_on_category_change(): void
    {
        $this->actingAs($this->cashier);

        // setUp sudah bikin 2 menu; tambah sampai lewat 1 halaman
        for ($i = 1; $i <= FnbCashierTerminal::MENUS_PER_PAGE; $i++) {
            FnbMenu::create([
                'category_id' => $this->category->id,
                'name' => sprintf('Menu Tambahan %02d', $i),
                'base_price' => 10000,
                'is_available' => true,
            ]);
        }

        $component = Livewire::test(FnbCashierTerminal::class);
        $this->assertCount(FnbCashierTerminal::MENUS_PER_PAGE, $component->instance()->menus);
        $this->assertTrue($component->instance()->menus->hasMorePages());

        $component->call('nextPage');
        $this->assertSame(2, $component->instance()->menus->currentPage());
        $this->assertCount(2, $component->instance()->menus);

        $component->call('setActiveCategory', $this->category->id);
        $this->assertSame(1, $component->instance()->menus->currentPage());
    }

    public function test_checkout_blocked_without_active_shift(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->assertSet('posStep', 'selection')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'Shift kasir F&B belum dibuka'))
            ->assertSet('showOpenShiftModal', true);

        $this->assertSame(0, Order::count());
    }

    public function test_opening_shift_clears_error_and_allows_proceeding_to_payment(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('executeOpenShift')
            ->assertSet('errorMessage', null)
            ->call('proceedToPayment')
            ->assertSet('posStep', 'payment');
    }

    public function test_full_checkout_flow_creates_order_and_marks_paid_via_qris(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('addToCart', $this->toast->id)
            ->set('tableNumber', 'Meja 05')
            ->set('customerName', 'Budi Santoso')
            ->call('proceedToPayment')
            ->assertSet('posStep', 'payment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012')
            ->call('submitFnbCheckout')
            ->assertSet('posStep', 'selection')
            ->assertSet('showReceiptModal', true)
            ->assertSet('errorMessage', null)
            // QRIS manual / EDC: struk langsung dicetak di aplikasi Club61, sama dengan Bayar Otomatis.
            ->assertDispatched('club61-auto-print', fn ($event, $params) => $params['next'] === 'startNewTransaction' && str_contains($params['html'], 'fnbpos-receipt') && str_contains($params['html'], 'Meja 05'));

        $order = Order::where('order_type', 'DINE_IN')->firstOrFail();
        $this->assertSame('Meja 05', $order->table_number);
        $this->assertSame('Budi Santoso', $order->customer_name);
        $this->assertSame('PAID', $order->payment_status);
        $this->assertSame($this->cashier->id, $order->cashier_id);
        $this->assertEqualsWithDelta(38000 + 55000, (float) $order->subtotal, 0.01);

        $itemTypes = $order->items->pluck('item_type')->unique()->all();
        $this->assertSame(['FNB'], $itemTypes);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('SUCCESS', $payment->status);
        $this->assertSame('CASHIER_POS', $payment->payment_gateway);
        $this->assertSame('QRIS', $payment->payment_method);
        $this->assertNotNull($payment->pos_shift_id);
        $this->assertSame('FNB_COUNTER', PosCashierShift::find($payment->pos_shift_id)->counter);
    }

    public function test_paid_order_prints_customer_receipt_at_cashier_and_sends_kitchen_slip_to_station_printer(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        $receiptHtml = null;
        $jobs = null;
        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('addToCart', $this->latte->id)
            ->call('addToCart', $this->toast->id)
            ->set("cart.{$this->latte->id}.notes", '  Less   sugar, tanpa es ')
            ->set("cart.{$this->toast->id}.notes", 'Tanpa bawang')
            ->set('tableNumber', '7')
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012')
            ->call('submitFnbCheckout')
            ->assertDispatched('club61-auto-print', function ($event, $params) use (&$receiptHtml) {
                $receiptHtml = $params['html'];

                return true;
            })
            ->assertDispatched('club61-station-print', function ($event, $params) use (&$jobs) {
                $jobs = $params['jobs'];

                return true;
            });

        $order = Order::where('order_type', 'DINE_IN')->firstOrFail();
        $this->assertSame('Less sugar, tanpa es', $order->items->firstWhere('reference_id', $this->latte->id)->notes);

        // Printer kasir: struk customer saja — tidak ada lagi slip dapur / bar ikut tercetak.
        $this->assertStringContainsString('id="fnbpos-receipt"', $receiptHtml);
        $this->assertStringNotContainsString('PESANAN', $receiptHtml);

        // Printer Kitchen: satu slip berisi menu Kitchen saja, tanpa harga. Minuman dibuat di kasir → tanpa slip.
        $this->assertCount(1, $jobs);
        $this->assertSame('Kitchen', $jobs[0]['station_name']);
        $this->assertSame('192.168.1.50', $jobs[0]['host']);
        $this->assertSame(9100, $jobs[0]['port']);
        $this->assertStringContainsString('PESANAN KITCHEN', $jobs[0]['html']);
        $this->assertStringContainsString('1x '.$this->toast->name, $jobs[0]['html']);
        $this->assertStringContainsString('* Tanpa bawang', $jobs[0]['html']);
        $this->assertStringContainsString('MEJA 7', $jobs[0]['html']);
        // Gaya slip dapur: antrian sebaris dengan meja, nama menu panjang turun menjorok, catatan di bawahnya, jumlah item.
        $this->assertStringContainsString('ANTRIAN ', $jobs[0]['html']);
        $this->assertStringContainsString('data-print-hang="3">1x '.$this->toast->name, $jobs[0]['html']);
        $this->assertStringContainsString('data-print-indent="3" data-print-hang="2">* Tanpa bawang', $jobs[0]['html']);
        $this->assertMatchesRegularExpression('/<span>Jumlah item:<\/span><span>1<\/span>/', $jobs[0]['html']);
        $this->assertStringNotContainsString($this->latte->name, $jobs[0]['html']);
        $this->assertStringNotContainsString('Rp ', $jobs[0]['html']);

        $ticket = KitchenTicket::where('order_id', $order->id)->sole();
        $this->assertSame($jobs[0]['ticket_id'], $ticket->id);
        $this->assertSame($this->kitchen->id, $ticket->station_id);
        $this->assertNull($ticket->printed_at);

        // Pesanan hanya minuman → tidak ada slip stasiun sama sekali.
        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('setOrderType', 'TAKE_AWAY')
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789013')
            ->call('submitFnbCheckout')
            ->assertDispatched('club61-auto-print')
            ->assertNotDispatched('club61-station-print');
        $this->assertSame(1, KitchenTicket::count());
    }

    public function test_station_print_result_is_recorded_and_slip_can_be_resent(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->toast->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789014')
            ->call('submitFnbCheckout');
        $ticket = KitchenTicket::sole();

        // Tablet gagal mengirim → alasan tercatat & tampil di modal struk.
        $component->call('reportStationPrint', $ticket->id, false, 'Printer Kitchen tidak merespons')
            ->assertSet('completedOrderData.stations.0.error', 'Printer Kitchen tidak merespons')
            ->assertSee('Gagal: Printer Kitchen tidak merespons');
        $this->assertNull($ticket->fresh()->printed_at);
        $this->assertSame(1, $ticket->fresh()->print_attempts);

        // Kirim ulang → slip yang sama dikirim lagi; berhasil → tercatat terkirim.
        $component->call('resendStationTickets', $ticket->order_id)
            ->assertDispatched('club61-station-print', fn ($event, $params) => count($params['jobs']) === 1 && $params['jobs'][0]['ticket_id'] === $ticket->id);
        $component->call('reportStationPrint', $ticket->id, true, null)
            ->assertSee('Terkirim');
        $this->assertNotNull($ticket->fresh()->printed_at);
        $this->assertNull($ticket->fresh()->last_print_error);
        $this->assertSame(1, KitchenTicket::count(), 'kirim ulang tidak membuat slip ganda');
    }

    /** Kasir di browser PC (bukan aplikasi): STATION_PRINT_DRIVER=mock → slip disimpan & ditampilkan, tidak ke printer. */
    public function test_browser_mock_driver_stores_the_slip_bytes_and_returns_readable_text(): void
    {
        config(['station_print.driver' => 'mock']);
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->toast->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789015')
            ->call('submitFnbCheckout');
        $ticket = KitchenTicket::sole();

        // Byte ESC/POS sama bentuknya dengan club61LayoutToEscPos (geser kiri 156 + lebar 384 titik, judul rata tengah,
        // item, potong kertas). Perintah geser tidak boleh muncul sebagai teks.
        $bytes = "\x1B\x40\x1B\x74\x00\x1D\x4C\x9C\x00\x1D\x57\x80\x01\x1B\x61\x01\x1B\x45\x01\x1D\x21\x01PESANAN KITCHEN\n\x1B\x61\x00\x1B\x45\x00\x1D\x21\x001x Smashed Avocado Toast\n\x1B\x64\x03\x1D\x56\x42\x00";
        $text = $component->call('saveMockStationPrint', $ticket->id, base64_encode($bytes))->effects['returns'][0] ?? null;

        $this->assertIsString($text);
        $this->assertStringStartsWith('        PESANAN KITCHEN', $text, 'judul rata tengah (32 kolom), tanpa sisa perintah geser');
        $this->assertStringContainsString("\n1x Smashed Avocado Toast\n", $text);
        $this->assertStringContainsString('(potong)', $text);

        $files = glob(storage_path('logs/print-mock/*-'.$ticket->id.'.*'));
        $this->assertCount(2, $files, '.bin mentah + .txt');
        $bin = collect($files)->first(fn ($f) => str_ends_with($f, '.bin'));
        $this->assertSame($bytes, file_get_contents($bin), 'byte disimpan apa adanya');
        array_map('unlink', $files);
    }

    public function test_mock_endpoint_is_refused_outside_mock_mode_and_rejects_garbage(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);
        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->toast->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789016')
            ->call('submitFnbCheckout');
        $ticket = KitchenTicket::sole();

        // Komponen baru tiap panggilan: setelah 403/422 snapshot Livewire tidak dipakai ulang.
        config(['station_print.driver' => 'tablet']);
        Livewire::test(FnbCashierTerminal::class)->call('saveMockStationPrint', $ticket->id, base64_encode('x'))->assertForbidden();

        config(['station_print.driver' => 'mock']);
        Livewire::test(FnbCashierTerminal::class)->call('saveMockStationPrint', $ticket->id, '%%%bukan-base64%%%')->assertStatus(422);
        $this->assertSame([], glob(storage_path('logs/print-mock/*-'.$ticket->id.'.*')));
    }

    /** Token agent hanya dikirim ke halaman kalau driver agent dipakai; bawaan (tablet) tidak membocorkan apa pun. */
    public function test_print_script_exposes_agent_settings_only_in_agent_mode(): void
    {
        // left_margin dipatok 0: .env local developer boleh berisi STATION_PRINT_LEFT_MARGIN.
        config(['station_print.driver' => 'tablet', 'station_print.agent_token' => 'token-rahasia-agent-123456789', 'station_print.left_margin' => 0]);
        // @js menulis JSON.parse('...') berisi string JS ter-escape → dibaca balik jadi array.
        $printConfig = function (string $html): array {
            $this->assertSame(1, preg_match("/club61StationPrintConfig = JSON\\.parse\\('(.*?)'\\);/", $html, $m));

            return json_decode(json_decode('"'.$m[1].'"'), true);
        };

        $html = view('pos.partials.receipt-print-script')->render();
        $this->assertSame(['driver' => 'tablet'], $printConfig($html));
        $this->assertStringNotContainsString('token-rahasia-agent-123456789', $html);

        config(['station_print.driver' => 'agent', 'station_print.agent_url' => 'http://127.0.0.1:9120']);
        // Pengaman: kalau skrip ini termuat di halaman panel yang bisa dibuka tamu, alamat & token agent tidak ikut.
        $this->assertGuest();
        $html = view('pos.partials.receipt-print-script')->render();
        $this->assertSame(['driver' => 'agent'], $printConfig($html));
        $this->assertStringNotContainsString('token-rahasia-agent-123456789', $html);
        // Panel tanpa halaman login sendiri: tamu dilempar ke /login, yang tidak memuat skrip cetak sama sekali.
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertDontSee('token-rahasia-agent-123456789', false)->assertDontSee('club61StationPrintConfig', false);

        $this->actingAs($this->cashier);
        $html = view('pos.partials.receipt-print-script')->render();
        $this->assertSame(
            ['driver' => 'agent', 'agentUrl' => 'http://127.0.0.1:9120', 'agentToken' => 'token-rahasia-agent-123456789'],
            $printConfig($html)
        );
        // Jalur aplikasi Android tetap ada & tidak berubah.
        $this->assertStringContainsString("action: 'lan_print'", $html);

        // Geser kertas 58mm di printer 80mm: dikirim ke halaman (aplikasi & browser) kalau diisi; dibatasi 0–192 titik.
        config(['station_print.driver' => 'tablet', 'station_print.left_margin' => 156]);
        $this->assertSame(['driver' => 'tablet', 'leftMargin' => 156], $printConfig(view('pos.partials.receipt-print-script')->render()));
        foreach (['500' => 192, '-20' => 0, 'abc' => 0, '156' => 156] as $env => $expected) {
            putenv("STATION_PRINT_LEFT_MARGIN={$env}");
            $_ENV['STATION_PRINT_LEFT_MARGIN'] = $_SERVER['STATION_PRINT_LEFT_MARGIN'] = $env;
            $this->assertSame($expected, (require config_path('station_print.php'))['left_margin'], "env {$env}");
        }
        putenv('STATION_PRINT_LEFT_MARGIN');
        unset($_ENV['STATION_PRINT_LEFT_MARGIN'], $_SERVER['STATION_PRINT_LEFT_MARGIN']);
    }

    public function test_midtrans_paid_fnb_order_gets_station_ticket_from_payment_fulfillment(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FNB-MT1', 'cashier_id' => $this->cashier->id, 'order_type' => 'TAKE_AWAY',
            'subtotal' => 110000, 'grand_total' => 110000, 'payment_status' => 'UNPAID',
        ]);
        $order->items()->create([
            'item_type' => 'FNB', 'reference_id' => $this->toast->id, 'item_name' => $this->toast->name,
            'quantity' => 2, 'unit_price' => 55000, 'subtotal' => 110000,
        ]);
        Payment::create(['order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => 'ORD-FNB-MT1', 'amount' => 110000, 'payment_method' => 'QRIS', 'status' => 'PENDING']);

        $orchestrator = app(\App\Services\Payment\PaymentOrchestratorService::class);
        $orchestrator->markOrderAsPaid($order, ['payment_gateway' => 'MIDTRANS', 'transaction_id' => 'ORD-FNB-MT1', 'payment_method' => 'QRIS', 'amount' => 110000]);
        // Notifikasi Midtrans dobel → tetap satu slip.
        $orchestrator->markOrderAsPaid($order->fresh(), ['payment_gateway' => 'MIDTRANS', 'transaction_id' => 'ORD-FNB-MT1', 'payment_method' => 'QRIS', 'amount' => 110000]);

        $ticket = KitchenTicket::sole();
        $this->assertSame('Kitchen', $ticket->station_name);
        $this->assertSame([['name' => $this->toast->name, 'quantity' => 2, 'notes' => null]], $ticket->items);
    }

    public function test_inactive_station_menu_gets_no_slip(): void
    {
        $this->kitchen->update(['is_active' => false]);
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->toast->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789015')
            ->call('submitFnbCheckout')
            ->assertNotDispatched('club61-station-print');

        $this->assertSame(0, KitchenTicket::count());
    }

    public function test_cash_payment_is_rejected(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'CASH')
            ->call('submitFnbCheckout')
            ->assertSet('posStep', 'payment')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, '100% Cashless'));

        $this->assertSame(0, Order::count());
    }

    public function test_unrecognized_payment_method_is_rejected_not_silently_marked_paid(): void
    {
        // Simulasi request hasil rekayasa (bukan lewat UI <select>/tab pembayaran) yang mengisi
        // paymentMethod dengan nilai di luar daftar QRIS/EDC yang dikenali — harus tetap ditolak,
        // bukan lolos ke markOrderAsPaid() tanpa satu pun bukti bayar tersimpan.
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->set('paymentMethod', 'BITCOIN')
            ->call('submitFnbCheckout')
            ->assertSet('posStep', 'payment')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'tidak dikenali'));

        $this->assertSame(0, Order::count());
    }

    public function test_edc_payment_requires_complete_slip_fields(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'DEBIT_CARD')
            ->set('edcLast4', '12') // kurang dari 4 digit
            ->call('submitFnbCheckout')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, '4 digit'));

        $this->assertSame(0, Order::count());
    }

    public function test_menu_disabled_after_added_to_cart_is_skipped_at_checkout(): void
    {
        // Race sederhana: item sudah di keranjang kasir, tapi staf lain menonaktifkannya
        // (misal kehabisan stok) tepat sebelum tombol bayar diklik — checkout tidak boleh
        // menjual item yang sudah tidak tersedia.
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('addToCart', $this->toast->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012');

        $this->latte->update(['is_available' => false]);

        $component->call('submitFnbCheckout')->assertSet('showReceiptModal', true);

        $order = Order::firstOrFail();
        $this->assertCount(1, $order->items);
        $this->assertSame('Smashed Avocado Toast', $order->items->first()->item_name);
    }

    public function test_checkout_rejected_when_all_cart_items_became_unavailable(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012');

        $this->latte->update(['is_available' => false]);

        $component->call('submitFnbCheckout')
            ->assertSet('posStep', 'payment')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'tidak tersedia'));

        $this->assertSame(0, Order::count());
    }

    public function test_staff_without_process_fnb_order_permission_cannot_checkout(): void
    {
        $this->openFnbShift();

        $unauthorized = $this->cashierWithoutFnbPermission();
        $this->actingAs($unauthorized);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012')
            ->call('submitFnbCheckout');

        // Livewire menyerap HttpException 403 dari abort_unless() di level request, jadi yang
        // diverifikasi adalah AKIBATnya: tidak ada Order yang benar-benar tersimpan.
        $this->assertSame(0, Order::count());
    }

    public function test_staff_without_process_fnb_order_permission_cannot_view_history_or_reopen_receipts(): void
    {
        // Riwayat transaksi & buka-ulang struk HARUS pakai izin yang sama dengan yang boleh
        // memproses transaksinya — kalau tidak, staf kasir yang izin process_fnb_order-nya sudah
        // dicabut tetap bisa mengintip seluruh riwayat penjualan F&B (nama pelanggan, meja, detail
        // slip EDC/QRIS) meski tidak boleh transaksi sama sekali.
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->set('customerName', 'Rahasia Pelanggan')
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012')
            ->call('submitFnbCheckout');

        $order = Order::firstOrFail();

        $unauthorized = $this->cashierWithoutFnbPermission();
        $this->actingAs($unauthorized);

        $component = Livewire::test(FnbCashierTerminal::class)
            ->set('posStep', 'history');

        $this->assertCount(0, $component->get('orderHistory'));
        $component->assertDontSee('Rahasia Pelanggan')
            ->assertDontSee($order->order_number);

        // Livewire menyerap HttpException 403 di level request (pola sama seperti submitFnbCheckout
        // di atas) — verifikasi AKIBATnya: struk orang lain tidak pernah benar-benar terbuka.
        $component->call('viewOrderReceipt', $order->id)
            ->assertSet('showReceiptModal', false);
        $this->assertNull($component->get('completedOrderData'));
    }

    public function test_cashier_can_open_and_close_fnb_shift(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('openShiftModal')
            ->call('executeOpenShift')
            ->assertSet('showOpenShiftModal', false);

        $this->assertDatabaseHas('pos_cashier_shifts', [
            'counter' => 'FNB_COUNTER',
            'status' => 'OPEN',
        ]);

        Livewire::test(FnbCashierTerminal::class)
            ->call('prepareCloseShift')
            ->call('executeCloseShift')
            ->assertSet('showShiftReportModal', true);

        $this->assertDatabaseHas('pos_cashier_shifts', [
            'counter' => 'FNB_COUNTER',
            'status' => 'CLOSED',
        ]);
    }

    public function test_queue_number_counts_only_fnb_orders_of_the_current_wib_day(): void
    {
        // "Hari ini" = hari WIB, berapa pun zona waktu aplikasi — diuji dengan aplikasi ber-zona UTC (server yang
        // APP_TIMEZONE-nya belum diatur), tempat whereDate lama salah hitung untuk order 00:00–07:00 WIB.
        $timezone = [config('app.timezone'), date_default_timezone_get()];
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-21 09:00:00', 'Asia/Jakarta')->setTimezone('UTC'));
        try {
            $existing = function (string $wib, string $itemType) {
                $order = Order::create(['order_number' => 'ORD-Q-'.\Illuminate\Support\Str::random(6), 'order_type' => 'DINE_IN', 'subtotal' => 38000, 'grand_total' => 38000]);
                \App\Models\Pos\OrderItem::create(['order_id' => $order->id, 'item_type' => $itemType, 'item_name' => 'Item', 'quantity' => 1, 'unit_price' => 38000, 'subtotal' => 38000]);
                Order::whereKey($order->id)->update(['created_at' => \Carbon\Carbon::parse($wib, 'Asia/Jakarta')->setTimezone(config('app.timezone'))]);
            };
            $existing('2026-10-20 23:30:00', 'FNB');   // kemarin (WIB) → tidak dihitung
            $existing('2026-10-21 00:30:00', 'FNB');   // hari ini, lewat tengah malam WIB → dihitung
            $existing('2026-10-21 08:00:00', 'PADEL'); // bukan F&B → tidak dihitung

            $this->openFnbShift();
            $this->actingAs($this->cashier);
            Livewire::test(FnbCashierTerminal::class)
                ->set('customerName', 'Antrian Pagi')
                ->call('addToCart', $this->latte->id)
                ->call('proceedToPayment')
                ->call('setPaymentMethod', 'QRIS')
                ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789013')
                ->call('submitFnbCheckout');

            $this->assertSame(2, (int) Order::where('customer_name', 'Antrian Pagi')->value('queue_number'));
        } finally {
            \Carbon\Carbon::setTestNow();
            config(['app.timezone' => $timezone[0]]);
            date_default_timezone_set($timezone[1]);
        }
    }

    public function test_transaction_history_tab_lists_orders_with_status_and_can_reopen_receipt(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->set('customerName', 'Siti Aminah')
            ->set('tableNumber', 'Meja 10')
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012')
            ->call('submitFnbCheckout');

        $order = Order::where('customer_name', 'Siti Aminah')->firstOrFail();
        $this->assertNotNull($order->queue_number);

        $component = Livewire::test(FnbCashierTerminal::class)
            ->set('posStep', 'history');

        $history = $component->get('orderHistory');
        $this->assertCount(1, $history);
        $this->assertSame($order->id, $history->first()->id);

        $component->assertSee('Riwayat Transaksi F&B')
            ->assertSee('Siti Aminah')
            ->assertSee('Meja 10')
            ->assertSee('PAID')
            ->assertSee($order->order_number);

        $component->call('viewOrderReceipt', $order->id)
            ->assertSet('showReceiptModal', true)
            ->assertSee('Siti Aminah')
            ->assertSee('Meja 10')
            ->assertSee($order->order_number)
            ->assertSee(str_pad((string) $order->queue_number, 3, '0', STR_PAD_LEFT));
    }

    public function test_payment_step_uses_same_payment_form_as_walk_in_booking(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->assertSee('KARTU DEBIT')
            ->assertSee('KARTU KREDIT')
            // Bayar Otomatis = pilihan utama; QRIS manual (RRN) tetap ada sebagai cadangan.
            ->assertSee('Bayar Otomatis')
            ->set('qrisMode', 'MANUAL')
            ->assertSee('Retrieval Reference Number')
            ->call('setPaymentMethod', 'DEBIT_CARD')
            ->assertSee('Pembayaran Kartu Debit (Debit Card)')
            ->assertSee('Mesin EDC BCA')->assertDontSee('Mesin EDC Mandiri')->assertDontSee('Mesin EDC Lainnya')
            ->assertSee('Keamanan PCI-DSS');
    }

    public function test_receipt_popup_shows_resolved_payment_method_and_edc_slip_details(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'DEBIT_CARD')
            ->set('edcTerminal', 'EDC_BCA')
            ->set('edcLast4', '4242')
            ->set('edcApprovalCode', 'APR999')
            ->set('edcTraceNumber', 'TRC999')
            ->call('submitFnbCheckout')
            ->assertSet('showReceiptModal', true)
            ->assertSet('posStep', 'selection')
            ->assertSee('Mesin EDC BCA')
            ->assertSee('4242')
            ->assertSee('APR999')
            ->assertSee('TRC999')
            ->assertSee('Kartu Debit (EDC)');
    }

    public function test_receipt_popup_shows_qris_slip_details(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisProvider', 'GOPAY')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012')
            ->set('qrisSenderName', 'Budi Santoso')
            ->call('submitFnbCheckout')
            ->assertSet('showReceiptModal', true)
            ->assertSee('GoPay / Midtrans QRIS')
            ->assertSee('123456789012')
            ->assertSee('Budi Santoso')
            ->assertSee('QRIS Kasir Frontdesk');
    }

    /** Meeting 28 Okt 2026: mesin EDC fisik hanya BCA — terminal lain ditolak di server, data lama tetap terbaca. */
    public function test_only_the_bca_edc_machine_is_accepted_for_new_payments(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'DEBIT_CARD')
            ->set('edcTerminal', 'EDC_MANDIRI') // request hasil rekayasa: pilihannya sudah tidak ada di layar
            ->set('edcLast4', '1234')->set('edcApprovalCode', 'APR777')->set('edcTraceNumber', 'TRC777')
            ->call('submitFnbCheckout')
            ->assertSet('showReceiptModal', false)
            ->assertSet('errorMessage', fn ($m) => str_contains((string) $m, 'hanya BCA'));
        $this->assertSame(0, Payment::where('status', 'SUCCESS')->count());

        $this->assertSame(['QRIS', 'EDC_BCA', 'TRANSFER_BANK'], array_keys(\App\Services\Pos\PosPaymentProof::activeMethods()));
        $this->assertSame('Mesin EDC Mandiri', \App\Services\Pos\PosPaymentProof::METHODS['EDC_MANDIRI'], 'label data lama tetap ada');
        try {
            \App\Services\Pos\PosPaymentProof::validate('EDC_LAINNYA', ['card_last_4' => '1234', 'approval_code' => 'APR888', 'trace_number' => 'TRC888'], 25000);
            $this->fail('EDC Lainnya harus ditolak.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** Checkout 1 latte lewat metode tertentu (helper untuk skenario closing). */
    protected function checkoutLatte(string $method, array $fields): void
    {
        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', $method)
            ->set('qrisMode', 'MANUAL'); // QRIS di skenario closing = QRIS manual (RRN)

        foreach ($fields as $name => $value) {
            $component->set($name, $value);
        }

        $component->call('submitFnbCheckout')->assertSet('showReceiptModal', true);
    }

    /** Nominal pembayaran sukses shift ini per metode (dari database, bukan dari layar — layar tutup shift blind). */
    private function paidAmount(PosCashierShift $shift, string $method): int
    {
        return (int) Payment::where('pos_shift_id', $shift->id)->where('status', 'SUCCESS')->where('payment_method', $method)->sum('amount');
    }

    public function test_closing_is_blind_and_a_full_match_closes_the_shift_and_records_edc_settlement(): void
    {
        $shift = $this->openFnbShift();
        $this->actingAs($this->cashier);

        $this->checkoutLatte('QRIS', ['qrisProvider' => 'BCA_QRIS', 'qrisRrn' => '123456789012']);
        $this->checkoutLatte('DEBIT_CARD', ['edcTerminal' => 'EDC_BCA', 'edcLast4' => '1234', 'edcApprovalCode' => 'APR001', 'edcTraceNumber' => 'TRC001']);
        $this->checkoutLatte('CREDIT_CARD', ['edcTerminal' => 'EDC_BCA', 'edcLast4' => '5678', 'edcApprovalCode' => 'APR002', 'edcTraceNumber' => 'TRC002']);

        $component = Livewire::test(FnbCashierTerminal::class)->call('prepareCloseShift');
        $rows = collect($component->get('closingRows'))->keyBy('key');

        // Tiap mesin EDC: baris debit & kredit; tidak ada jumlah transaksi / nominal sistem di browser.
        $this->assertEqualsCanonicalizing(['QRIS_BCA_QRIS', 'EDC_BCA_DEBIT', 'EDC_BCA_CREDIT'], $rows->keys()->all());
        $rows->each(fn (array $row) => $this->assertArrayNotHasKey('system_amount', $row));
        $rows->each(fn (array $row) => $this->assertArrayNotHasKey('count', $row));
        $this->assertSame(['EDC_BCA' => ''], $component->get('edcModes'));

        $component->set('settlementInputs.QRIS_BCA_QRIS', number_format($this->paidAmount($shift, 'QRIS'), 0, ',', '.'))
            ->set('settlementInputs.EDC_BCA_DEBIT', (string) $this->paidAmount($shift, 'DEBIT_CARD'))
            ->set('settlementInputs.EDC_BCA_CREDIT', (string) $this->paidAmount($shift, 'CREDIT_CARD'))
            ->set('edcModes.EDC_BCA', 'SETTLE')
            ->call('executeCloseShift')
            ->assertSet('showShiftReportModal', true)
            ->assertSee("club61PrintReceipt('#printable-z-report')", false);

        $shift->refresh();
        $this->assertSame('CLOSED', $shift->status);
        $this->assertSame(PosCashierShift::REVIEW_MATCHED, $shift->review_status);
        $this->assertEquals(0, (float) $shift->settlement_difference);
        $this->assertCount(3, $shift->settlement_reconciliation);
        $this->assertSame(1, \App\Models\Pos\PosEdcSettlement::where('pos_shift_id', $shift->id)->where('source', 'CLOSING')->count());
    }

    public function test_a_difference_freezes_the_typed_numbers_requires_notes_and_needs_review(): void
    {
        $shift = $this->openFnbShift();
        $this->actingAs($this->cashier);

        $this->checkoutLatte('QRIS', ['qrisRrn' => '123456789012']);
        $paid = $this->paidAmount($shift, 'QRIS');

        $component = Livewire::test(FnbCashierTerminal::class)->call('prepareCloseShift');
        $component->set('settlementInputs.QRIS_BCA_QRIS', (string) ($paid - 5000))
            ->call('executeCloseShift')
            ->assertSet('errorMessage', fn ($m) => str_contains($m, 'tidak bisa diubah'));
        $this->assertSame('OPEN', $shift->fresh()->status, 'baru dicek, belum ditutup');
        $this->assertEquals($paid - 5000, $shift->fresh()->closing_inputs['first_attempt']['inputs']['QRIS_BCA_QRIS']);
        $this->assertEquals(-5000, $component->get('closingMismatch')['difference'], 'angka sistem baru terlihat setelah disimpan');

        // Setelah angka sistem terlihat, mengetik ulang tidak berpengaruh — tanpa penjelasan tetap tidak bisa tutup.
        $component->set('settlementInputs.QRIS_BCA_QRIS', (string) $paid)
            ->call('executeCloseShift')
            ->assertSet('errorMessage', fn ($m) => str_contains($m, 'Wajib isi penjelasan'));
        $this->assertSame('OPEN', $shift->fresh()->status);

        $component->set('closingNotes', 'Settlement QRIS kurang 5rb, menunggu konfirmasi bank.')
            ->call('executeCloseShift')
            ->assertSet('showShiftReportModal', true);

        $shift->refresh();
        $this->assertSame('CLOSED', $shift->status);
        $this->assertSame(PosCashierShift::REVIEW_NEEDS_REVIEW, $shift->review_status);
        $this->assertEquals(-5000, (float) $shift->settlement_difference, 'yang dipakai angka pertama, bukan ketikan ulang');
    }

    public function test_closing_rejects_empty_settlement_input(): void
    {
        $shift = $this->openFnbShift();
        $this->actingAs($this->cashier);

        $this->checkoutLatte('QRIS', ['qrisRrn' => '123456789012']);

        Livewire::test(FnbCashierTerminal::class)
            ->call('prepareCloseShift')
            ->call('executeCloseShift')
            ->assertSet('errorMessage', fn ($m) => str_contains($m, 'Isi nominal'));

        $this->assertSame('OPEN', $shift->fresh()->status);
        $this->assertNull($shift->fresh()->closing_inputs, 'input tidak lengkap tidak membekukan apa pun');
    }

    public function test_new_transaction_during_closing_forces_recheck(): void
    {
        $shift = $this->openFnbShift();
        $this->actingAs($this->cashier);

        $this->checkoutLatte('QRIS', ['qrisRrn' => '123456789012']);

        $component = Livewire::test(FnbCashierTerminal::class)->call('prepareCloseShift');
        $component->set('settlementInputs.QRIS_BCA_QRIS', (string) $this->paidAmount($shift, 'QRIS'));

        // Kasir lain menyelesaikan transaksi saat modal closing masih terbuka
        $this->checkoutLatte('QRIS', ['qrisRrn' => '999999999999']);

        $component->call('executeCloseShift')
            ->assertSet('errorMessage', fn ($m) => str_contains($m, 'transaksi baru'));

        $this->assertSame('OPEN', $shift->fresh()->status);
        $this->assertNull($shift->fresh()->closing_inputs, 'transaksi baru → cek ulang, bukan dibekukan');
    }

    public function test_tax_amount_matches_fnb_module_override_from_club_finance_setting(): void
    {
        $this->openFnbShift();
        $this->actingAs($this->cashier);

        Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', 'QRIS')
            ->set('qrisMode', 'MANUAL')->set('qrisRrn', '123456789012')
            ->call('submitFnbCheckout');

        $order = Order::firstOrFail();
        $settings = \App\Models\Pos\ClubFinanceSetting::getSettings();

        if ($settings->isTaxApplicable('POS_WALKIN', 'FNB')) {
            $this->assertGreaterThan(0, (float) $order->tax_amount);
        } else {
            $this->assertEquals(0, (float) $order->tax_amount);
        }

        $this->assertEqualsWithDelta(
            (float) $order->subtotal + (float) $order->tax_amount + (float) $order->service_charge,
            (float) $order->grand_total,
            0.01
        );
    }
}
