<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\BookOfflineCourt;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Services\Payment\PaymentOrchestratorService;
use App\Services\Pos\PosMidtransQrisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** QRIS dinamis Midtrans di POS Walk-In: QR tampil, lunas dari Midtrans, uangnya tetap masuk shift kasir yang menerima. */
class MidtransQrisPosTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected PadelCourt $court;

    protected CourtEquipment $ball;

    protected PosCashierShift $shift;

    protected string $bookingDate;

    protected function setUp(): void
    {
        parent::setUp();
        // Hari kerja (Rabu) — akhir pekan memakai tarif prime, harga slot di tes ini tarif reguler.
        Carbon::setTestNow(Carbon::parse(now()->addDays(3)->next(Carbon::WEDNESDAY)->format('Y-m-d').' 10:00:00'));

        \App\Services\Permission\Club61PermissionMatrix::syncAllPermissions('web');
        $this->cashier = User::factory()->cashier()->create(['is_active' => true]);
        $this->cashier->givePermissionTo(['View:BookOfflineCourt', 'process_walkin_booking']);

        $this->court = PadelCourt::create(['name' => 'Court QR', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 450000, 'is_active' => true]);
        $this->ball = CourtEquipment::create(['name' => 'Bola', 'type' => 'BALL', 'rental_price' => 35000, 'stock_quantity' => 5]);
        $this->bookingDate = now()->format('Y-m-d');

        $this->shift = PosCashierShift::create([
            'shift_number' => 'SFT-QR-0001', 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkoutQr(array $equipments = []): array
    {
        $service = app(PadelBookingService::class);
        $customer = $service->findOrCreateWalkInCustomer(name: 'Budi QR', phone: '081234567890');

        return $service->processWalkInCheckout(
            customer: $customer,
            slots: [['court_id' => $this->court->id, 'start_time' => '14:00:00', 'end_time' => '15:00:00']],
            bookingDate: $this->bookingDate,
            equipments: $equipments,
            paymentMethod: 'QRIS_MIDTRANS',
            cashier: $this->cashier,
            paymentMeta: ['qris_provider' => PosMidtransQrisService::PROVIDER],
        );
    }

    private function midtransSettles(Payment $payment): void
    {
        // Sama seperti webhook Midtrans: gateway MIDTRANS, tanpa counter kasir.
        app(PaymentOrchestratorService::class)->markOrderAsPaid($payment->order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $payment->transaction_id, 'payment_method' => 'QRIS',
            'amount' => (float) $payment->amount, 'payload_log' => ['payment_type' => 'qris', 'transaction_status' => 'settlement'],
        ]);
    }

    public function test_checkout_holds_the_slot_and_opens_a_qr_bill_tied_to_the_cashier_shift(): void
    {
        $result = $this->checkoutQr();

        $this->assertNotEmpty($result['pending_qris']['snap_token']);
        $this->assertSame('QRIS', $result['pending_qris']['method']);
        $order = Order::where('order_number', $result['pending_qris']['order_number'])->firstOrFail();
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame($this->shift->id, $order->pos_shift_id);

        $payment = Payment::findOrFail($result['pending_qris']['payment_id']);
        $this->assertSame('PENDING', $payment->status);
        $this->assertSame('MIDTRANS', $payment->payment_gateway);
        $this->assertSame($this->shift->id, $payment->pos_shift_id);
        $this->assertStringStartsWith($order->order_number.'_', $payment->transaction_id);

        $this->assertSame('PENDING_PAYMENT', PadelBooking::where('order_id', $order->id)->value('status'));
    }

    public function test_midtrans_settlement_keeps_the_payment_in_the_cashier_shift(): void
    {
        $result = $this->checkoutQr();
        $payment = Payment::findOrFail($result['pending_qris']['payment_id']);

        $this->midtransSettles($payment);

        $payment->refresh();
        $this->assertSame('SUCCESS', $payment->status);
        $this->assertSame($this->shift->id, $payment->pos_shift_id);
        $this->assertSame('PAID', PadelBooking::where('order_id', $payment->order_id)->value('status'));
        $this->assertSame(PosMidtransQrisService::PAID, app(PosMidtransQrisService::class)->status($payment->id));

        $row = collect($this->shift->fresh()->settlementBreakdown())->firstWhere('key', 'QRIS_MIDTRANS_QRIS');
        $this->assertNotNull($row);
        $this->assertSame((float) $payment->amount, $row['system_amount']);
        $this->assertSame('QRIS Otomatis (Kasir)', app(PadelBookingService::class)->formatPaymentMethodLabel('QRIS', $payment->payload_log));
    }

    public function test_cancel_releases_the_slot_and_returns_ball_stock(): void
    {
        $result = $this->checkoutQr([['equipment_id' => $this->ball->id, 'quantity' => 2]]);
        $this->assertSame(3, (int) $this->ball->fresh()->stock_quantity);

        $status = app(PosMidtransQrisService::class)->cancel($result['pending_qris']['payment_id']);

        $this->assertSame(PosMidtransQrisService::CANCELLED, $status);
        $this->assertSame('CANCELLED', Order::find($result['pending_qris']['order_id'])->payment_status);
        $this->assertSame('CANCELLED', PadelBooking::where('order_id', $result['pending_qris']['order_id'])->value('status'));
        $this->assertSame('FAILED', Payment::find($result['pending_qris']['payment_id'])->status);
        $this->assertSame(5, (int) $this->ball->fresh()->stock_quantity);
    }

    public function test_expired_qr_is_cancelled_by_the_cashier_screen_poll(): void
    {
        $result = $this->checkoutQr();

        Carbon::setTestNow(now()->addMinutes(app(\App\Services\Padel\BookingTimeService::class)->paymentWindowMinutes() + 1));

        $this->assertSame(PosMidtransQrisService::EXPIRED, app(PosMidtransQrisService::class)->status($result['pending_qris']['payment_id']));
        $this->assertSame('CANCELLED', PadelBooking::where('order_id', $result['pending_qris']['order_id'])->value('status'));
    }

    public function test_cashier_screen_shows_qr_then_receipt_after_payment(): void
    {
        $this->actingAs($this->cashier);

        $component = Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', $this->bookingDate)
            ->set('selectedSlots', ["{$this->court->id}_14:00:00" => [
                'court_id' => $this->court->id, 'court_name' => $this->court->name, 'start_time' => '14:00:00',
                'end_time' => '15:00:00', 'time_label' => '14:00 - 15:00', 'price' => 300000.00,
            ]])
            ->set('customerMode', 'quick_create')
            ->set('walkInName', 'Budi QR')
            ->set('walkInPhone', '081299887766')
            ->set('paymentMethod', 'QRIS')
            ->assertSet('qrisMode', 'MIDTRANS')
            ->call('submitWalkInBooking')
            ->assertSee('Menunggu pembayaran customer');

        $pending = $component->get('pendingQris');
        $this->assertNotNull($pending);

        // Belum dibayar → popup tetap.
        $component->call('pollPendingQris')->assertSet('pendingQris.payment_id', $pending['payment_id']);

        $this->midtransSettles(Payment::findOrFail($pending['payment_id']));

        $component->call('pollPendingQris')
            ->assertSet('pendingQris', null)
            ->assertSet('posStep', 'receipt')
            ->assertSet('completedOrderData.order_number', $pending['order_number'])
            ->assertDispatched('club61-auto-print', fn ($event, $params) => $params['next'] === 'startNewTransaction' && str_contains($params['html'], 'printable-pos-receipt') && str_contains($params['html'], $pending['order_number']));

        // Tampil di Riwayat kasir walau gateway-nya MIDTRANS.
        $component->set('posStep', 'history');
        $this->assertTrue(collect($component->instance()->getTransactionHistoryProperty())->contains('order_number', $pending['order_number']));
    }

    public function test_fnb_counter_shows_qr_then_receipt_and_cancel_voids_the_order(): void
    {
        $this->cashier->givePermissionTo(['access_pos_terminal', 'process_fnb_order']);
        $fnbShift = PosCashierShift::create([
            'shift_number' => 'SFT-FNB-QR-0001', 'counter' => 'FNB_COUNTER', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $category = \App\Models\Fnb\FnbCategory::create(['name' => 'Coffee', 'sort_order' => 1]);
        $latte = \App\Models\Fnb\FnbMenu::create(['category_id' => $category->id, 'name' => 'Latte', 'base_price' => 38000, 'is_available' => true]);
        $this->actingAs($this->cashier);

        // Lunas.
        $component = Livewire::test(\App\Livewire\Pos\FnbCashierTerminal::class)
            ->call('addToCart', $latte->id)
            ->set('paymentMethod', 'QRIS')
            ->assertSet('qrisMode', 'MIDTRANS')
            ->call('submitFnbCheckout')
            ->assertSee('Menunggu pembayaran customer');
        $pending = $component->get('pendingQris');
        $payment = Payment::findOrFail($pending['payment_id']);
        $this->assertSame($fnbShift->id, $payment->pos_shift_id);
        $this->assertSame([], $component->get('cart'));

        $this->midtransSettles($payment);
        $component->call('pollPendingQris')
            ->assertSet('pendingQris', null)
            ->assertSet('showReceiptModal', true)
            ->assertSet('completedOrderData.order_number', $pending['order_number'])
            ->assertSet('completedOrderData.payment_method_label', 'QRIS Otomatis (Kasir)')
            ->assertDispatched('club61-auto-print', fn ($event, $params) => $params['key'] === $pending['order_id'] && $params['next'] === 'startNewTransaction' && str_contains($params['html'], 'fnbpos-receipt') && str_contains($params['html'], $pending['order_number']));

        // Sekali per order (orders.receipt_printed_at): layar ini mengklaim cetak sekali; klaim ulang, layar/tab lain,
        // atau halaman yang di-refresh tidak mencetak lagi.
        $this->assertSame(true, $component->call('claimAutoPrint', $pending['order_id'])->effects['returns'][0] ?? null);
        $this->assertNotNull(Order::find($pending['order_id'])->receipt_printed_at);
        $this->assertSame(false, $component->call('claimAutoPrint', $pending['order_id'])->effects['returns'][0] ?? null);
        $otherTab = Livewire::test(\App\Livewire\Pos\FnbCashierTerminal::class);
        $this->assertSame(false, $otherTab->call('claimAutoPrint', $pending['order_id'])->effects['returns'][0] ?? null);

        // Batal.
        $second = Livewire::test(\App\Livewire\Pos\FnbCashierTerminal::class)
            ->call('addToCart', $latte->id)
            ->set('paymentMethod', 'QRIS')
            ->call('submitFnbCheckout');
        $pendingTwo = $second->get('pendingQris');
        $second->call('cancelPendingQris')->assertSet('pendingQris', null);
        $this->assertSame('CANCELLED', Order::find($pendingTwo['order_id'])->payment_status);
    }

    public function test_membership_counter_activates_the_card_only_after_qr_payment(): void
    {
        $this->cashier->givePermissionTo(['View:JualMembership', 'sell_membership']);
        $plan = \App\Models\Membership\MembershipPlan::create([
            'code' => 'MBR-QR', 'name' => 'Gold QR', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 1000000, 'is_active' => true,
        ]);
        \App\Models\Membership\MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 10, 'discount_percent' => 10]);
        $this->actingAs($this->cashier);

        $component = Livewire::test(\App\Filament\Pages\JualMembership::class)
            ->set('walkInName', 'Siti QR')
            ->set('walkInPhone', '081299990011')
            ->call('selectPlan', $plan->id)
            ->set('paymentMethod', 'QRIS')
            ->assertSet('qrisMode', 'MIDTRANS')
            ->call('submitSale')
            ->assertSee('Menunggu pembayaran customer')
            ->assertSet('showSuccessModal', false);

        $pending = $component->get('pendingQris');
        $membership = \App\Models\Membership\UserMembership::where('order_id', $pending['order_id'])->firstOrFail();
        $this->assertSame('PENDING_PAYMENT', $membership->status);

        $this->midtransSettles(Payment::findOrFail($pending['payment_id']));
        $component->call('pollPendingQris')
            ->assertSet('pendingQris', null)
            ->assertSet('showSuccessModal', true)
            ->assertSee('Gold QR')
            ->assertDispatched('club61-auto-print', fn ($event, $params) => $params['next'] === 'closeReceipt' && str_contains($params['html'], 'printable-membership-receipt') && ! str_contains($params['html'], 'Cetak Struk'));

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertSame($this->shift->id, Payment::find($pending['payment_id'])->pos_shift_id);
    }

    public function test_membership_upgrade_is_refused_with_qr_because_the_old_card_is_drained_immediately(): void
    {
        $this->cashier->givePermissionTo(['View:JualMembership', 'sell_membership']);
        $old = \App\Models\Membership\MembershipPlan::create(['code' => 'MBR-OLD', 'name' => 'Silver', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 500000, 'is_active' => true]);
        $new = \App\Models\Membership\MembershipPlan::create(['code' => 'MBR-NEW', 'name' => 'Gold', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 1000000, 'is_active' => true]);
        $customer = User::factory()->create(['phone' => '081299990022']);
        \App\Models\Membership\UserMembership::create([
            'membership_code' => 'MBR-EXIST', 'owner_type' => 'INDIVIDUAL', 'user_id' => $customer->id, 'plan_id' => $old->id,
            'start_date' => now()->subDay(), 'end_date' => now()->addDays(20), 'status' => 'ACTIVE',
        ]);
        $this->actingAs($this->cashier);

        Livewire::test(\App\Filament\Pages\JualMembership::class)
            ->set('selectedCustomerId', $customer->id)
            ->call('selectPlan', $new->id)
            ->set('paymentMethod', 'QRIS')
            ->call('submitSale')
            ->assertSet('pendingQris', null);

        $this->assertSame(0, Payment::where('payment_gateway', 'MIDTRANS')->count());
        $this->assertSame('ACTIVE', \App\Models\Membership\UserMembership::where('membership_code', 'MBR-EXIST')->value('status'));
    }

    public function test_cashier_can_use_a_va_shown_at_pos_and_it_gets_its_own_shift_row(): void
    {
        \App\Models\Pos\OnlinePaymentMethod::where('code', 'BCA_VA')->update(['is_active' => true, 'show_at_pos' => true]);
        app(\App\Services\Payment\OnlinePaymentMethodService::class)->flush();

        $service = app(PadelBookingService::class);
        $result = $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Budi VA', phone: '081234567891'),
            slots: [['court_id' => $this->court->id, 'start_time' => '16:00:00', 'end_time' => '17:00:00']],
            bookingDate: $this->bookingDate,
            equipments: [],
            paymentMethod: 'QRIS_MIDTRANS',
            cashier: $this->cashier,
            paymentMeta: ['pos_online_method' => 'BCA_VA'],
        );

        $this->assertSame('BCA_VA', $result['pending_qris']['method']);
        $payment = Payment::findOrFail($result['pending_qris']['payment_id']);
        $this->assertArrayNotHasKey('qris_details', $payment->payload_log);

        app(PaymentOrchestratorService::class)->markOrderAsPaid($payment->order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $payment->transaction_id, 'payment_method' => 'BANK_TRANSFER',
            'amount' => (float) $payment->amount, 'payload_log' => ['payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca', 'va_number' => '123']]],
        ]);

        $row = collect($this->shift->fresh()->settlementBreakdown())->firstWhere('key', 'AUTO_BCA_VA');
        $this->assertNotNull($row);
        $this->assertStringEndsWith('(Kasir)', $row['label']);
        $this->assertStringEndsWith('(Kasir)', app(PadelBookingService::class)->formatPaymentMethodLabel('BANK_TRANSFER', $payment->fresh()->payload_log));
    }

    public function test_method_not_shown_at_pos_falls_back_and_none_means_manual_only(): void
    {
        // Pilihan kasir tidak dicentang "Tampil di Kasir" → metode pertama yang tampil (QRIS).
        $this->assertSame('QRIS', PosMidtransQrisService::resolveMethod('BCA_VA', 300000));

        \App\Models\Pos\OnlinePaymentMethod::query()->update(['show_at_pos' => false]);
        app(\App\Services\Payment\OnlinePaymentMethodService::class)->flush();
        $this->assertNull(PosMidtransQrisService::resolveMethod('QRIS', 300000));

        // Tidak ada metode otomatis: layar kasir hanya QRIS manual → tanpa RRN ditolak, tidak ada order.
        $this->actingAs($this->cashier);
        Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', $this->bookingDate)
            ->set('selectedSlots', ["{$this->court->id}_14:00:00" => [
                'court_id' => $this->court->id, 'court_name' => $this->court->name, 'start_time' => '14:00:00',
                'end_time' => '15:00:00', 'time_label' => '14:00 - 15:00', 'price' => 300000.00,
            ]])
            ->set('customerMode', 'quick_create')
            ->set('walkInName', 'Budi Manual')
            ->set('walkInPhone', '081299887700')
            ->set('paymentMethod', 'QRIS')
            ->call('submitWalkInBooking')
            ->assertSet('pendingQris', null);
        $this->assertSame(0, Order::count());
    }

    public function test_pending_payment_survives_a_page_refresh_and_blocks_new_sales_and_shift_closing(): void
    {
        $this->cashier->givePermissionTo('close_pos_shift');
        $this->actingAs($this->cashier);
        $slot = ["{$this->court->id}_14:00:00" => [
            'court_id' => $this->court->id, 'court_name' => $this->court->name, 'start_time' => '14:00:00',
            'end_time' => '15:00:00', 'time_label' => '14:00 - 15:00', 'price' => 300000.00,
        ]];

        $first = Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', $this->bookingDate)->set('selectedSlots', $slot)
            ->set('customerMode', 'quick_create')->set('walkInName', 'Budi Refresh')->set('walkInPhone', '081299887711')
            ->set('paymentMethod', 'QRIS')->call('submitWalkInBooking');
        $pending = $first->get('pendingQris');

        // Halaman dibuka lagi (refresh / tab tertutup) → kartu menunggu pembayaran muncul lagi.
        $reopened = Livewire::test(BookOfflineCourt::class)->assertSet('pendingQris.payment_id', $pending['payment_id']);

        // Transaksi baru ditolak selama masih menunggu.
        $reopened->set('selectedSlots', ["{$this->court->id}_16:00:00" => [
            'court_id' => $this->court->id, 'court_name' => $this->court->name, 'start_time' => '16:00:00',
            'end_time' => '17:00:00', 'time_label' => '16:00 - 17:00', 'price' => 300000.00,
        ]])->set('customerMode', 'quick_create')->set('walkInName', 'Orang Lain')->set('walkInPhone', '081299887722')
            ->call('submitWalkInBooking');
        $this->assertSame(1, Order::count());

        // Shift tidak bisa ditutup selama masih ada Bayar Otomatis yang menunggu.
        $this->assertSame(1, $this->shift->fresh()->pendingAutoPaymentCount());
        $reopened->call('prepareCloseShift')->assertSet('showCloseShiftModal', false);
        $reopened->call('executeCloseShift');
        $this->assertSame('OPEN', $this->shift->fresh()->status);

        // Kasir lain di loket yang sama tidak melihat tagihan kasir ini.
        $other = User::factory()->cashier()->create(['is_active' => true]);
        $other->givePermissionTo(['View:BookOfflineCourt', 'process_walkin_booking']);
        $this->actingAs($other);
        Livewire::test(BookOfflineCourt::class)->assertSet('pendingQris', null);
    }

    public function test_abandoned_auto_payment_is_swept_with_its_order_and_receipt_shows_the_real_status(): void
    {
        $this->cashier->givePermissionTo(['access_pos_terminal', 'process_fnb_order']);
        PosCashierShift::create([
            'shift_number' => 'SFT-FNB-QR-0002', 'counter' => 'FNB_COUNTER', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $category = \App\Models\Fnb\FnbCategory::create(['name' => 'Coffee', 'sort_order' => 1]);
        $latte = \App\Models\Fnb\FnbMenu::create(['category_id' => $category->id, 'name' => 'Latte', 'base_price' => 38000, 'is_available' => true]);
        $this->actingAs($this->cashier);

        $pending = Livewire::test(\App\Livewire\Pos\FnbCashierTerminal::class)
            ->call('addToCart', $latte->id)->set('paymentMethod', 'QRIS')->call('submitFnbCheckout')
            ->get('pendingQris');

        // Kasir menutup layar; QR kedaluwarsa → pembersih berkala membatalkan order (bukan UNPAID selamanya).
        $this->assertSame(0, app(PosMidtransQrisService::class)->sweepAbandoned());
        Carbon::setTestNow(now()->addMinutes(app(\App\Services\Padel\BookingTimeService::class)->paymentWindowMinutes() + 5));
        $this->artisan('pos:sweep-auto-payments')->assertSuccessful();
        $this->assertSame('CANCELLED', Order::find($pending['order_id'])->payment_status);

        // Struk dari Riwayat untuk order batal: bukan "Transaksi Lunas" & tidak bisa dicetak.
        Livewire::test(\App\Livewire\Pos\FnbCashierTerminal::class)
            ->set('posStep', 'history')
            ->call('viewOrderReceipt', $pending['order_id'])
            ->assertSet('completedOrderData.payment_status', 'CANCELLED')
            ->assertSee('Dibatalkan')
            ->assertDontSee('Transaksi Lunas')
            ->assertDontSee('Cetak Struk');
    }

    public function test_pending_qr_id_cannot_be_swapped_from_the_browser(): void
    {
        $this->actingAs($this->cashier);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(BookOfflineCourt::class)->set('pendingQris', ['payment_id' => 'x']);
    }
}
