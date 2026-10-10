<?php

namespace Tests\Feature\Finance;

use App\Models\Audit\ActivityLog;
use App\Models\Finance\LedgerEntry;
use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\Pos\Refund;
use App\Models\User;
use App\Services\Finance\LedgerWriter;
use App\Services\Finance\TaxAndFeeService;
use App\Services\Fnb\FnbPosService;
use App\Services\Padel\PadelBookingService;
use App\Services\Payment\PaymentOrchestratorService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Modul 17 Fase 1 — Buku Transaksi: setiap uang masuk / refund tercatat sekali, dipecah per kategori, dengan
 * total baris = payments.amount persis (PRD §3.4 & §8).
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $customer;

    protected PadelCourt $court;

    protected string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');

        // Contoh PRD §2: pajak PB1 10% + biaya layanan 3%, dihitung dari penjualan setelah diskon.
        ClubFinanceSetting::getSettings()->update([
            'is_tax_enabled' => true, 'tax_name' => 'PB1', 'tax_type' => 'PERCENTAGE', 'tax_rate' => 10, 'tax_channels' => 'ALL',
            'is_admin_fee_enabled' => true, 'admin_fee_name' => 'Biaya Layanan', 'admin_fee_type' => 'PERCENTAGE',
            'admin_fee_amount' => 3, 'admin_fee_channels' => 'ALL',
        ]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Rina Kasir']);
        $this->customer = User::factory()->customer()->create(['name' => 'Budi']);
        $this->court = PadelCourt::create([
            'name' => 'Court Ledger', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true,
        ]);
        $this->date = now()->next(Carbon::WEDNESDAY)->format('Y-m-d');
        $this->openShift('PADEL_FRONTDESK');
    }

    private function openShift(string $counter): PosCashierShift
    {
        return PosCashierShift::create([
            'shift_number' => 'SFT-LEDGER-'.Str::random(6),
            'counter' => $counter,
            'status' => 'OPEN',
            'opened_by_id' => $this->admin->id,
            'opened_at' => now(),
            'starting_cash' => 0,
            'expected_cash' => 0,
        ]);
    }

    private function orchestrator(): PaymentOrchestratorService
    {
        return app(PaymentOrchestratorService::class);
    }

    /** @param  array<int, array{0: string, 1: float}>  $items  [item_type, subtotal] */
    private function makeOrder(array $items, float $discount = 0, string $orderType = 'WALK_IN', string $channel = 'POS_WALKIN'): Order
    {
        $subtotal = array_sum(array_column($items, 1));
        $calc = app(TaxAndFeeService::class)->calculate($subtotal, $discount, $channel, 'PADEL');

        $order = Order::create([
            'order_number' => 'ORD-LG-'.strtoupper(Str::random(8)),
            'user_id' => $this->customer->id,
            'cashier_id' => $orderType === 'WALK_IN' ? $this->admin->id : null,
            'order_type' => $orderType,
            'subtotal' => $calc['subtotal'],
            'discount_amount' => $calc['discount_amount'],
            'tax_amount' => $calc['tax_amount'],
            'service_charge' => $calc['admin_fee_amount'],
            'grand_total' => $calc['grand_total'],
            'payment_status' => 'UNPAID',
        ]);
        foreach ($items as [$type, $amount]) {
            $order->items()->create([
                'item_type' => $type, 'item_name' => $type.' item', 'quantity' => 1, 'unit_price' => $amount, 'subtotal' => $amount,
            ]);
        }

        return $order;
    }

    private function payAtCashier(Order $order, ?float $amount = null, array $payload = []): void
    {
        $this->orchestrator()->markOrderAsPaid($order, [
            'payment_gateway' => 'CASHIER_POS',
            'counter' => 'PADEL_FRONTDESK',
            'payment_method' => 'QRIS',
            'amount' => $amount ?? (float) $order->grand_total,
            'payload_log' => $payload + ['qris_details' => ['provider' => 'BCA_QRIS', 'rrn' => 'RRN'.random_int(100000, 999999)]],
        ]);
    }

    private function paidOnlineBooking(string $start = '10:00', float $courtFee = 200000, string $code = 'BK-LG-001', float $memberDiscount = 0): PadelBooking
    {
        $order = $this->makeOrder([['PADEL', $courtFee]], 0, 'ONLINE_BOOKING', 'ONLINE');
        Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number,
            'amount' => $order->grand_total, 'payment_method' => 'BCA_VA', 'status' => 'PENDING',
        ]);
        $startAt = Carbon::parse("{$this->date} {$start}");
        $booking = PadelBooking::create([
            'booking_code' => $code, 'order_id' => $order->id, 'user_id' => $this->customer->id, 'court_id' => $this->court->id,
            'booking_date' => $this->date, 'start_time' => $startAt, 'end_time' => $startAt->copy()->addHour(),
            'court_fee' => $courtFee, 'member_discount_court' => $memberDiscount, 'total_amount' => $order->grand_total,
            'status' => 'PENDING_PAYMENT',
        ]);

        $this->orchestrator()->markOrderAsPaid($order, [
            'payment_gateway' => 'MIDTRANS',
            'transaction_id' => $order->order_number,
            'payment_method' => 'BCA_VA',
            'amount' => (float) $order->grand_total,
            'payload_log' => ['payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca', 'va_number' => '7001234']]],
        ]);

        return $booking->fresh();
    }

    private function rows(Order $order, ?string $type = null)
    {
        return LedgerEntry::where('order_id', $order->id)->when($type, fn ($q) => $q->where('entry_type', $type))->get()->keyBy('category');
    }

    public function test_walk_in_court_and_racket_with_discount_split_proportionally(): void
    {
        // Contoh PRD §2: lapangan 300.000 + raket 50.000, diskon 35.000 → bersih 315.000, layanan 9.450, pajak 31.500.
        $order = $this->makeOrder([['PADEL', 300000], ['EQUIPMENT', 50000]], 35000);
        $this->assertEquals(355950, (float) $order->grand_total);

        $this->payAtCashier($order);

        $rows = $this->rows($order);
        $this->assertCount(2, $rows);

        $court = $rows['SEWA_LAPANGAN'];
        $this->assertSame('POS_WALKIN_PADEL', $court->source);
        $this->assertSame(LedgerEntry::TYPE_PAYMENT, $court->entry_type);
        $this->assertEquals([300000, 30000, 270000, 8100, 27000, 305100], [
            (float) $court->gross_amount, (float) $court->discount_amount, (float) $court->net_amount,
            (float) $court->service_amount, (float) $court->tax_amount, (float) $court->total_amount,
        ]);

        $addon = $rows['ADDON_PADEL'];
        $this->assertEquals([50000, 5000, 45000, 1350, 4500, 50850], [
            (float) $addon->gross_amount, (float) $addon->discount_amount, (float) $addon->net_amount,
            (float) $addon->service_amount, (float) $addon->tax_amount, (float) $addon->total_amount,
        ]);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals((float) $payment->amount, (float) $rows->sum('total_amount'));
        $this->assertNotNull($payment->paid_at);
        $this->assertSame($payment->pos_shift_id, $court->pos_shift_id);
        $this->assertSame('Rina Kasir', $court->cashier_name);
        $this->assertStringStartsWith('RRN', (string) $court->payment_reference);
    }

    public function test_real_walk_in_checkout_with_racket_records_addon_and_still_activates_booking(): void
    {
        Carbon::setTestNow(Carbon::parse(now()->addDays(3)->format('Y-m-d').' 08:00:00'));
        $racket = CourtEquipment::create(['name' => 'Raket Ledger', 'type' => 'RACKET', 'rental_price' => 50000, 'stock_quantity' => 5]);
        $service = app(PadelBookingService::class);
        $walkIn = $service->findOrCreateWalkInCustomer(name: 'Penyewa', phone: '0813'.random_int(1000000, 9999999));

        $result = $service->processWalkInCheckout(
            customer: $walkIn,
            slots: [['court_id' => $this->court->id, 'start_time' => '10:00:00', 'end_time' => '11:00:00']],
            bookingDate: now()->format('Y-m-d'),
            equipments: [['equipment_id' => $racket->id, 'quantity' => 1]],
            paymentMethod: 'QRIS',
            cashier: $this->admin,
            paymentMeta: ['qris_provider' => 'BCA_QRIS', 'qris_rrn' => 'RRN555001'],
        );

        $order = $result['order']->fresh('items');
        $this->assertSame(['EQUIPMENT', 'PADEL'], $order->items->pluck('item_type')->sort()->values()->all());
        $this->assertSame('PAID', PadelBooking::where('order_id', $order->id)->value('status'));

        $rows = $this->rows($order);
        $this->assertEquals(50000, (float) $rows['ADDON_PADEL']->gross_amount);
        $this->assertEquals(
            (float) Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->sum('amount'),
            (float) $rows->sum('total_amount')
        );
        Carbon::setTestNow();
    }

    public function test_repeated_webhook_and_reconciliation_settle_records_the_payment_once(): void
    {
        $booking = $this->paidOnlineBooking();
        $order = $booking->order;

        // Notifikasi ulang Midtrans + rekonsiliasi untuk transaksi yang sama.
        foreach (['webhook', 'reconcile'] as $via) {
            $this->orchestrator()->markOrderAsPaid($order, [
                'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number, 'payment_method' => 'BCA_VA',
                'amount' => (float) $order->grand_total, 'payload_log' => ['reconciled_via' => $via],
            ]);
        }

        $rows = $this->rows($order);
        $this->assertCount(1, $rows);
        $this->assertSame('ONLINE_PADEL', $rows['SEWA_LAPANGAN']->source);
        $this->assertSame('BCA Virtual Account', $rows['SEWA_LAPANGAN']->payment_method_label);
        $this->assertSame('VA 7001234', $rows['SEWA_LAPANGAN']->payment_reference);
        $this->assertNull($rows['SEWA_LAPANGAN']->cashier_id, 'Pembayaran online tidak punya kasir');
    }

    private function reschedule(PadelBooking $booking, string $start, bool $payNow, array $proof = []): array
    {
        return app(PadelBookingService::class)->adminRescheduleBooking(
            bookingId: $booking->id,
            newCourtId: $this->court->id,
            newDate: $this->date,
            newStartTimeStr: $start,
            reason: 'Permintaan customer',
            adminUser: $this->admin,
            paymentMethod: 'QRIS',
            isDeltaPaid: $payNow,
            paymentProof: $proof,
        );
    }

    public function test_reschedule_delta_paid_at_cashier_uses_its_own_breakdown_and_leaves_original_rows_untouched(): void
    {
        $booking = $this->paidOnlineBooking('10:00');
        $order = $booking->order;
        $before = $this->rows($order)->map(fn ($r) => $r->only(['net_amount', 'tax_amount', 'service_amount', 'total_amount']))->all();

        $this->reschedule($booking, '18:00', payNow: true, proof: ['qris_provider' => 'BCA_QRIS', 'qris_rrn' => 'RRN880011']);

        $delta = Payment::where('order_id', $order->id)->where('transaction_id', 'like', 'SUPP-%')->firstOrFail();
        $this->assertSame('SUCCESS', $delta->status);
        $log = $delta->payload_log;

        $deltaRows = LedgerEntry::where('payment_id', $delta->id)->get();
        $this->assertCount(1, $deltaRows);
        $row = $deltaRows->first();
        $this->assertSame('RESCHEDULE_DELTA_POS', $row->source);
        $this->assertSame('SEWA_LAPANGAN', $row->category);
        $this->assertEquals((float) $log['court_delta'], (float) $row->net_amount);
        $this->assertEquals((float) $log['tax_delta'], (float) $row->tax_amount);
        $this->assertEquals((float) $log['admin_fee_delta'], (float) $row->service_amount);
        $this->assertEquals((float) $delta->amount, (float) $row->total_amount);

        // Order sudah berubah (grand_total naik), tapi baris pembayaran awal tetap.
        $after = LedgerEntry::where('order_id', $order->id)->where('payment_id', '!=', $delta->id)->get()->keyBy('category')
            ->map(fn ($r) => $r->only(['net_amount', 'tax_amount', 'service_amount', 'total_amount']))->all();
        $this->assertEquals($before, $after);
    }

    public function test_reschedule_delta_paid_online_is_recorded_as_online_settlement(): void
    {
        $booking = $this->paidOnlineBooking('10:00');
        $this->reschedule($booking, '18:00', payNow: false);

        $bill = Payment::where('order_id', $booking->order_id)->where('status', 'PENDING')->firstOrFail();
        $this->orchestrator()->markOrderAsPaid($booking->order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $bill->transaction_id, 'payment_method' => 'QRIS',
            'amount' => (float) $bill->amount, 'payload_log' => ['payment_type' => 'qris'],
        ]);

        $row = LedgerEntry::where('payment_id', $bill->id)->sole();
        $this->assertSame('RESCHEDULE_DELTA_ONLINE', $row->source);
        $this->assertEquals((float) $bill->amount, (float) $row->total_amount);
        $this->assertEquals((float) $bill->fresh()->payload_log['court_delta'], (float) $row->net_amount);
    }

    public function test_moving_to_a_cheaper_slot_adds_nothing_to_the_ledger(): void
    {
        $booking = $this->paidOnlineBooking('18:00', 300000);
        $count = LedgerEntry::count();

        $this->reschedule($booking, '10:00', payNow: false);

        $this->assertGreaterThan(0, (float) $booking->fresh()->reschedule_forfeited_amount);
        $this->assertSame($count, LedgerEntry::count());
    }

    public function test_refund_is_negative_and_split_with_the_same_proportions_as_the_payment(): void
    {
        $order = $this->makeOrder([['PADEL', 300000], ['EQUIPMENT', 50000]], 35000);
        $this->payAtCashier($order);
        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        $refund = Refund::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'refund_amount' => 100001,
            'reason' => '[TRANSFER_BANK] uji refund', 'status' => 'PROCESSED',
        ]);

        $rows = LedgerEntry::where('refund_id', $refund->id)->get()->keyBy('category');
        $this->assertCount(2, $rows);
        $this->assertEquals(-100001, (float) $rows->sum('total_amount'));
        $this->assertTrue($rows->every(fn ($r) => (float) $r->total_amount < 0 && $r->entry_type === LedgerEntry::TYPE_REFUND));
        // Proporsi baris pembayaran: lapangan 305.100 : add-on 50.850.
        $this->assertEqualsWithDelta(100001 * 305100 / 355950, -(float) $rows['SEWA_LAPANGAN']->total_amount, 1);
        foreach ($rows as $row) {
            $this->assertEquals((float) $row->total_amount, (float) $row->net_amount + (float) $row->service_amount + (float) $row->tax_amount);
        }

        // Disimpan ulang → tidak tercatat dua kali.
        $refund->update(['reason' => 'ubah catatan']);
        $this->assertCount(2, LedgerEntry::where('refund_id', $refund->id)->get());
    }

    public function test_refund_request_writes_refund_rows_only_when_approved(): void
    {
        $booking = $this->paidOnlineBooking('10:00');
        $grand = (float) $booking->order->grand_total;

        app(PadelBookingService::class)->requestCancelAndRefund($booking->id, 'CUSTOMER_REQUEST', 'Batal', $this->admin);
        $this->assertSame(0, LedgerEntry::where('order_id', $booking->order_id)->where('entry_type', 'REFUND')->count(), 'pengajuan belum mengeluarkan uang');

        $refund = \App\Models\Pos\Refund::where('padel_booking_id', $booking->id)->sole();
        app(\App\Services\Finance\RefundQueueService::class)->process($refund, User::factory()->superAdmin()->create(), 'TRANSFER_BANK', 'TRF-001');

        $this->assertSame('REFUNDED', $booking->fresh()->status);
        $this->assertEquals(-$grand, (float) LedgerEntry::where('order_id', $booking->order_id)->where('entry_type', 'REFUND')->sum('total_amount'));
        $this->assertEquals(0, (float) LedgerEntry::where('order_id', $booking->order_id)->sum('total_amount'));
    }

    public function test_overpayment_is_recorded_as_money_in_and_netted_out_once_refunded(): void
    {
        $booking = $this->paidOnlineBooking('10:00');
        $order = $booking->order;
        $grand = (float) $order->grand_total;

        // Customer juga membayar di kasir padahal sudah lunas online (sesi berbeda).
        $this->orchestrator()->markOrderAsPaid($order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number.'-2', 'payment_method' => 'QRIS',
            'amount' => $grand,
        ]);

        $extra = Payment::where('transaction_id', $order->order_number.'-2')->firstOrFail();
        $this->assertSame(LedgerEntry::TYPE_OVERPAYMENT, LedgerEntry::where('payment_id', $extra->id)->value('entry_type'));
        $refund = Refund::where('payment_id', $extra->id)->where('status', 'PENDING')->firstOrFail();
        $this->assertEquals(2 * $grand, (float) LedgerEntry::where('order_id', $order->id)->sum('total_amount'));

        $refund->update(['status' => 'PROCESSED']);

        $this->assertEquals($grand, (float) LedgerEntry::where('order_id', $order->id)->sum('total_amount'));
        $this->assertNotNull($refund->fresh()->processed_at);
    }

    public function test_second_snap_session_payment_is_recorded_as_overpayment(): void
    {
        $order = $this->makeOrder([['PADEL', 200000]], 0, 'ONLINE_BOOKING', 'ONLINE');
        Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number,
            'amount' => $order->grand_total, 'payment_method' => 'QRIS', 'status' => 'PENDING',
            'payload_log' => ['midtrans_order_ids' => [$order->order_number.'_S1', $order->order_number.'_S2']],
        ]);
        foreach (['_S1', '_S2'] as $session) {
            $this->orchestrator()->markOrderAsPaid($order, [
                'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number.$session, 'payment_method' => 'QRIS',
                'amount' => (float) $order->grand_total,
            ]);
        }

        $duplicate = Payment::where('order_id', $order->id)->where('status', PaymentOrchestratorService::DUPLICATE_STATUS)->sole();
        $this->assertSame(LedgerEntry::TYPE_OVERPAYMENT, LedgerEntry::where('payment_id', $duplicate->id)->value('entry_type'));
        $this->assertEquals(2 * (float) $order->grand_total, (float) LedgerEntry::where('order_id', $order->id)->sum('total_amount'));
    }

    public function test_fully_quota_covered_booking_records_zero_with_benefit_value(): void
    {
        $order = $this->makeOrder([['PADEL', 0]], 0, 'ONLINE_BOOKING', 'ONLINE');
        $startAt = Carbon::parse("{$this->date} 10:00");
        PadelBooking::create([
            'booking_code' => 'BK-LG-Q', 'order_id' => $order->id, 'user_id' => $this->customer->id, 'court_id' => $this->court->id,
            'booking_date' => $this->date, 'start_time' => $startAt, 'end_time' => $startAt->copy()->addHour(),
            'court_fee' => 0, 'member_discount_court' => 200000, 'total_amount' => 0, 'status' => 'PENDING_PAYMENT',
        ]);

        $this->orchestrator()->markOrderAsPaid($order, [
            'payment_gateway' => 'MEMBERSHIP_QUOTA', 'transaction_id' => $order->order_number,
            'payment_method' => 'MEMBERSHIP_QUOTA', 'amount' => 0,
        ]);

        $row = LedgerEntry::where('order_id', $order->id)->sole();
        $this->assertEquals(0, (float) $row->total_amount);
        $this->assertEquals(0, (float) $row->net_amount);
        $this->assertEquals(200000, (float) $row->benefit_amount);
    }

    public function test_fnb_pos_sale_is_recorded_under_fnb(): void
    {
        $this->openShift('FNB_COUNTER');
        $category = FnbCategory::create(['name' => 'Coffee', 'sort_order' => 1]);
        $latte = FnbMenu::create(['category_id' => $category->id, 'name' => 'Latte', 'base_price' => 38000, 'is_available' => true]);

        $result = app(FnbPosService::class)->checkout(
            items: [['menu_id' => $latte->id, 'quantity' => 2]],
            orderType: 'DINE_IN',
            tableNumber: 'T1',
            cashier: $this->admin,
            paymentMethod: 'QRIS',
            paymentMeta: ['qris_provider' => 'BCA_QRIS', 'qris_rrn' => 'RRN330022'],
        );

        $order = $result['order'];
        $row = LedgerEntry::where('order_id', $order->id)->sole();
        $this->assertSame('FNB', $row->category);
        $this->assertSame('POS_FNB', $row->source);
        $this->assertEquals(76000, (float) $row->gross_amount);
        $this->assertEquals((float) $order->fresh()->grand_total, (float) $row->total_amount);
    }

    public function test_membership_source_depends_on_where_it_was_paid(): void
    {
        $writer = app(LedgerWriter::class);

        foreach (['MIDTRANS' => 'ONLINE_MEMBERSHIP', 'CASHIER_POS' => 'POS_MEMBERSHIP'] as $gateway => $expected) {
            $order = $this->makeOrder([['MEMBERSHIP', 1500000]], 0, 'MEMBERSHIP', 'ONLINE');
            $payment = Payment::create([
                'order_id' => $order->id, 'payment_gateway' => $gateway, 'transaction_id' => 'TX-'.Str::random(8),
                'amount' => $order->grand_total, 'payment_method' => 'QRIS', 'status' => 'SUCCESS',
            ]);

            $row = $writer->recordPayment($payment)->sole();
            $this->assertSame('MEMBERSHIP', $row->category);
            $this->assertSame($expected, $row->source);
        }
    }

    public function test_odd_amounts_still_sum_exactly_to_the_payment(): void
    {
        $order = $this->makeOrder([['PADEL', 100001], ['EQUIPMENT', 33333], ['EQUIPMENT', 7]], 7777);
        $this->payAtCashier($order);

        $rows = $this->rows($order);
        $this->assertEquals((float) $order->grand_total, (float) $rows->sum('total_amount'));
        foreach ($rows as $row) {
            foreach (['gross_amount', 'discount_amount', 'net_amount', 'service_amount', 'tax_amount', 'total_amount'] as $col) {
                $this->assertEquals(round((float) $row->$col), (float) $row->$col, "{$col} harus rupiah bulat");
            }
        }

        // Pembayaran sebagian dengan nominal ganjil (order dibayar dua kali).
        $order2 = $this->makeOrder([['PADEL', 200000], ['EQUIPMENT', 35000]]);
        $this->payAtCashier($order2, 100003);
        $first = Payment::where('order_id', $order2->id)->firstOrFail();
        $this->assertEquals(100003, (float) LedgerEntry::where('payment_id', $first->id)->sum('total_amount'));

        $this->assertSame(['A' => 33300, 'B' => 66700], LedgerWriter::split(100000, ['A' => 1, 'B' => 2]));
    }

    public function test_simulated_payments_are_not_recorded(): void
    {
        $order = $this->makeOrder([['PADEL', 200000]], 0, 'ONLINE_BOOKING', 'ONLINE');
        $this->orchestrator()->markOrderAsPaid($order, [
            'payment_gateway' => 'MOCK', 'transaction_id' => $order->order_number, 'payment_method' => 'QRIS', 'amount' => (float) $order->grand_total,
        ]);

        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->count());
    }

    public function test_ledger_failure_rolls_back_the_settlement(): void
    {
        $order = $this->makeOrder([['PADEL', 200000]], 0, 'ONLINE_BOOKING', 'ONLINE');
        Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number,
            'amount' => $order->grand_total, 'payment_method' => 'QRIS', 'status' => 'PENDING',
        ]);
        $this->app->instance(LedgerWriter::class, new class extends LedgerWriter
        {
            public function recordPayment(Payment $payment, string $entryType = LedgerEntry::TYPE_PAYMENT, ?\Carbon\CarbonInterface $occurredAt = null): \Illuminate\Support\Collection
            {
                throw new RuntimeException('disk penuh');
            }
        });

        try {
            $this->orchestrator()->markOrderAsPaid($order, [
                'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number, 'payment_method' => 'QRIS', 'amount' => (float) $order->grand_total,
            ]);
            $this->fail('Pelunasan harus gagal kalau buku gagal ditulis');
        } catch (RuntimeException $e) {
            $this->assertSame('disk penuh', $e->getMessage());
        }

        $this->assertSame('PENDING', Payment::where('order_id', $order->id)->value('status'));
        $this->assertSame('UNPAID', $order->fresh()->payment_status);
    }

    public function test_ledger_rows_cannot_be_changed_or_deleted(): void
    {
        $order = $this->makeOrder([['PADEL', 200000]]);
        $this->payAtCashier($order);
        $row = LedgerEntry::where('order_id', $order->id)->firstOrFail();

        try {
            $row->update(['total_amount' => 1]);
            $this->fail('Baris buku tidak boleh diubah');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $row->delete();
    }

    public function test_backfill_is_idempotent_and_verify_detects_unrecorded_payments(): void
    {
        // Pembayaran lama dari sebelum buku ada (tercatat langsung, tanpa lewat orchestrator).
        $order = $this->makeOrder([['PADEL', 300000], ['EQUIPMENT', 50000]], 35000);
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'CASHIER_POS', 'transaction_id' => 'POS-OLD-1',
            'amount' => $order->grand_total, 'payment_method' => 'QRIS', 'status' => 'SUCCESS',
        ]);
        Refund::withoutEvents(fn () => Refund::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'refund_amount' => 50000, 'reason' => 'lama',
            'status' => 'PROCESSED', 'processed_at' => now(),
        ]));
        $today = now('Asia/Jakarta')->toDateString();

        $this->artisan('ledger:verify', ['--date' => $today])->assertExitCode(1);
        $this->assertTrue(ActivityLog::where('event', 'ledger.verify_mismatch')->exists());

        $this->artisan('ledger:backfill', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, LedgerEntry::count());

        $this->artisan('ledger:backfill')->assertExitCode(0);
        $count = LedgerEntry::count();
        $this->assertSame(4, $count, '2 kategori pembayaran + 2 kategori refund');

        $this->artisan('ledger:backfill')->assertExitCode(0);
        $this->assertSame($count, LedgerEntry::count());

        $this->artisan('ledger:verify', ['--date' => $today])->assertExitCode(0);
        $this->assertEquals((float) $order->grand_total - 50000, (float) LedgerEntry::sum('total_amount'));
    }
}
