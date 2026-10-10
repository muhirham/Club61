<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\BookOfflineCourt;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Membership\UserMembership;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\Sponsor\SponsorMemberVoucher;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Models\User;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Padel\PadelBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tagihan Rp0 (kuota member / voucher jam corporate): karyawan corporate yang datang walk-in dapat jam
 * corporate-nya, tagihan Rp0 lunas tanpa metode bayar / Midtrans, dan struknya tetap merinci harga normal
 * + potongan benefit.
 */
class WalkInBenefitRp0Test extends TestCase
{
    use RefreshDatabase;

    protected PadelBookingService $service;
    protected User $cashier;
    protected PadelCourt $court;
    protected string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PadelBookingService::class);
        \App\Services\Permission\Club61PermissionMatrix::syncAllPermissions('web');

        $this->cashier = User::factory()->cashier()->create(['name' => 'Kasir Rp0', 'is_active' => true]);
        $this->cashier->givePermissionTo(['View:BookOfflineCourt', 'process_walkin_booking']);

        $this->court = PadelCourt::create([
            'name' => 'Court Rp0', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 450000, 'is_active' => true,
        ]);
        $this->date = now()->addDays(3)->format('Y-m-d');

        PosCashierShift::create([
            'shift_number' => 'SFT-RP0-0001', 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
    }

    /** Karyawan corporate + voucher jam aktif. */
    protected function sponsorEmployee(float $hours, string $phone = '081277700011'): array
    {
        $pic = User::factory()->create();
        $plan = MembershipPlan::create(['code' => 'MBR-CORP-RP0', 'name' => 'Corporate B2B', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 365, 'price' => 1000000, 'is_active' => true]);
        $membership = UserMembership::create([
            'membership_code' => 'MBR-CORP-RP0-1', 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $pic->id, 'plan_id' => $plan->id,
            'status' => 'ACTIVE', 'start_date' => now(), 'end_date' => now()->addYear(),
        ]);
        $org = SponsorOrganization::create(['name' => 'PT Rp0 Sejahtera', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $pic->id]);

        $employee = User::factory()->customer()->create(['name' => 'Karyawan Corp', 'phone' => $phone, 'is_active' => true]);
        $member = SponsorOrganizationMember::create(['sponsor_organization_id' => $org->id, 'user_id' => $employee->id, 'status' => 'ACTIVE']);
        $voucher = SponsorMemberVoucher::create([
            'sponsor_organization_member_id' => $member->id, 'hours_granted' => $hours, 'hours_used' => 0,
            'issued_at' => now(), 'expires_at' => now()->addMonth(),
        ]);

        return [$employee, $voucher, $org];
    }

    /** Member pribadi dengan kuota jam padel. */
    protected function hourMember(float $hours, ?User $user = null): array
    {
        $user ??= User::factory()->customer()->create(['name' => 'Member Jam', 'phone' => '081277700099', 'is_active' => true]);
        $plan = MembershipPlan::create(['code' => 'MBR-H-'.uniqid(), 'name' => 'Padel Jam', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 1000000, 'is_active' => true]);
        MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => $hours, 'discount_percent' => 0]);
        $balances = app(MembershipBalanceService::class);
        $membership = $balances->activateMembership($balances->purchasePlan($user, $plan))->fresh('balances');

        return [$user, $membership->balanceFor('PADEL')];
    }

    protected function slots(array $starts): array
    {
        return array_map(fn (int $h) => [
            'court_id' => $this->court->id,
            'start_time' => sprintf('%02d:00:00', $h),
            'end_time' => sprintf('%02d:00:00', $h + 1),
        ], $starts);
    }

    protected function walkIn(User $customer, array $slots, string $method = 'VOUCHER', ?string $balanceId = null, ?string $sponsorVoucherId = null): array
    {
        return $this->service->processWalkInCheckout(
            customer: $customer, slots: $slots, bookingDate: $this->date, equipments: [], paymentMethod: $method,
            cashier: $this->cashier, membershipBalanceId: $balanceId, sponsorVoucherId: $sponsorVoucherId,
        );
    }

    protected function assertQuoteMatches(array $quote, $bookings): void
    {
        $this->assertEqualsWithDelta((float) $bookings->sum('member_discount_court'), $quote['membership_discount'], 0.01, 'potongan member di layar = yang tercatat');
        $this->assertEqualsWithDelta((float) $bookings->sum('sponsor_discount_court'), $quote['sponsor_discount'], 0.01, 'potongan corporate di layar = yang tercatat');
        $this->assertEqualsWithDelta((float) $bookings->sum('court_fee'), $quote['court_total_after'], 0.01, 'sewa lapangan setelah benefit di layar = yang tercatat');
    }

    public function test_corporate_employee_walk_in_is_fully_covered_by_corporate_hours_without_payment_proof(): void
    {
        [$employee, $voucher] = $this->sponsorEmployee(5);
        $slots = $this->slots([10, 11]);

        $quote = $this->service->quoteWalkInBenefits($slots, $this->date, $employee);
        $this->assertTrue($quote['sponsor']['applies']);
        $this->assertEquals(2.0, $quote['sponsor']['hours']);
        $this->assertEquals(0.0, $quote['court_total_after']);

        $result = $this->walkIn($employee, $slots);

        $this->assertTrue($result['success']);
        $this->assertSame('SPONSOR_VOUCHER', $result['payment_method']);
        $this->assertEquals(0.0, $result['grand_total']);
        $this->assertSame('PAID', $result['order']->payment_status);
        $this->assertQuoteMatches($quote, $result['bookings']);

        $payment = Payment::where('order_id', $result['order']->id)->where('status', 'SUCCESS')->sole();
        $this->assertSame('SPONSOR_VOUCHER', $payment->payment_method);
        $this->assertEquals(0.0, (float) $payment->amount);
        $this->assertEquals(2.0, (float) $voucher->fresh()->hours_used);
        $this->assertTrue($result['bookings']->every(fn ($b) => $b->status === 'PAID' && $b->qr_code_hash));
    }

    public function test_corporate_hours_cover_part_of_the_bill_and_the_rest_is_paid(): void
    {
        [$employee, $voucher] = $this->sponsorEmployee(1);
        $slots = $this->slots([10, 11]);

        $quote = $this->service->quoteWalkInBenefits($slots, $this->date, $employee);
        $this->assertEquals(1.0, $quote['sponsor']['hours']);
        $this->assertGreaterThan(0, $quote['court_total_after']);

        $result = $this->walkIn($employee, $slots, 'QRIS');

        $this->assertSame('QRIS', $result['payment_method']);
        $this->assertGreaterThan(0, $result['grand_total']);
        $this->assertQuoteMatches($quote, $result['bookings']);
        $this->assertEquals(1.0, (float) $voucher->fresh()->hours_used);
    }

    public function test_cashier_can_switch_corporate_hours_off(): void
    {
        [$employee, $voucher] = $this->sponsorEmployee(5);
        $slots = $this->slots([10]);

        $quote = $this->service->quoteWalkInBenefits($slots, $this->date, $employee, null, 'NONE');
        $this->assertFalse($quote['sponsor']['applies']);
        $this->assertEquals(0.0, $quote['sponsor_discount']);

        $result = $this->walkIn($employee, $slots, 'QRIS', null, 'NONE');

        $this->assertGreaterThan(0, $result['grand_total']);
        $this->assertEquals(0.0, (float) $voucher->fresh()->hours_used);
    }

    public function test_member_hours_quote_follows_the_merged_booking_and_rp0_needs_no_payment_method(): void
    {
        [$member, $balance] = $this->hourMember(10);
        $slots = $this->slots([10, 11, 12]); // 3 slot berurutan = 1 booking 3 jam

        $quote = $this->service->quoteWalkInBenefits($slots, $this->date, $member);
        $this->assertTrue($quote['membership']['applies']);
        $this->assertEquals(3.0, $quote['membership']['hours']);
        $this->assertCount(1, $quote['lines'], 'slot berurutan digabung jadi satu booking seperti saat commit');

        // Metode di layar (QRIS) diabaikan server karena tidak ada uang yang ditagih.
        $result = $this->walkIn($member, $slots, 'QRIS', $balance->id);

        $this->assertSame('MEMBERSHIP_QUOTA', $result['payment_method']);
        $this->assertEquals(0.0, $result['grand_total']);
        $this->assertQuoteMatches($quote, $result['bookings']);
        $this->assertEquals(7.0, (float) $balance->fresh()->remaining_quota);
    }

    public function test_member_hours_are_all_or_nothing_and_corporate_hours_cover_the_booking_instead(): void
    {
        [$employee, $voucher] = $this->sponsorEmployee(5);
        [, $balance] = $this->hourMember(1, $employee); // kuota member 1 jam < booking 2 jam
        $slots = $this->slots([10, 11]);

        $quote = $this->service->quoteWalkInBenefits($slots, $this->date, $employee, $balance->id);
        $this->assertEquals(0.0, $quote['membership_discount'], 'kuota member tidak dipecah per jam');
        $this->assertEquals(2.0, $quote['sponsor']['hours']);

        $result = $this->walkIn($employee, $slots, 'VOUCHER', $balance->id);

        $this->assertSame('SPONSOR_VOUCHER', $result['payment_method']);
        $this->assertQuoteMatches($quote, $result['bookings']);
        $this->assertEquals(1.0, (float) $balance->fresh()->remaining_quota);
        $this->assertEquals(2.0, (float) $voucher->fresh()->hours_used);
    }

    public function test_pos_screen_applies_corporate_hours_and_receipt_shows_full_details(): void
    {
        [$employee, $voucher, $org] = $this->sponsorEmployee(5);
        $this->actingAs($this->cashier);

        $slotKey = "{$this->court->id}_10:00:00";
        $component = Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', $this->date)
            ->set('selectedSlots', [$slotKey => [
                'court_id' => $this->court->id, 'court_name' => $this->court->name,
                'start_time' => '10:00:00', 'end_time' => '11:00:00', 'time_label' => '10:00 - 11:00', 'price' => 300000.00,
            ]])
            ->call('setCustomerMode', 'search')
            ->call('selectCustomer', $employee->id)
            ->assertSet('sponsorVoucherInfo.organization_name', 'PT Rp0 Sejahtera')
            ->assertSee('Jam Corporate Dipakai')
            ->set('paymentMethod', 'EDC_BCA') // tanpa bukti EDC — tetap lolos karena tagihan Rp0
            ->call('submitWalkInBooking');

        $order = Order::where('order_type', 'WALK_IN')->sole();
        $this->assertSame('PAID', $order->payment_status);
        $this->assertEquals(0.0, (float) $order->grand_total);
        $this->assertSame('SPONSOR_VOUCHER', Payment::where('order_id', $order->id)->sole()->payment_method);
        $this->assertEquals(1.0, (float) $voucher->fresh()->hours_used);

        $receipt = $component->get('completedOrderData');
        $line = $receipt['bookings'][0];
        $this->assertEquals(300000.0, $line['normal_fee'], 'struk menulis harga normal, bukan Rp0');
        $this->assertEquals(300000.0, $line['sponsor_discount']);
        $this->assertEquals(1.0, $line['sponsor_hours']);
        $this->assertSame($org->name, $line['sponsor_org']);
        $this->assertContains('Sisa jam corporate: 4 jam', $receipt['benefit_notes']);
        $this->assertStringNotContainsString('EDC', $receipt['payment_method']);

        $html = view('filament.partials.walkin-receipt', ['receipt' => $receipt])->render();
        $this->assertStringContainsString('Jam Corporate PT Rp0 Sejahtera (1 jam)', $html);
        $this->assertStringContainsString('Rp 300.000', $html);
    }

    public function test_reprinted_rp0_receipt_keeps_the_benefit_breakdown(): void
    {
        [$member, $balance] = $this->hourMember(10);
        $result = $this->walkIn($member, $this->slots([10]), 'VOUCHER', $balance->id);
        $payment = Payment::where('order_id', $result['order']->id)->sole();

        $this->actingAs($this->cashier);
        $receipt = Livewire::test(BookOfflineCourt::class)->instance()->buildWalkInReceipt($payment, reprint: true);

        $this->assertEquals(300000.0, $receipt['bookings'][0]['normal_fee']);
        $this->assertEquals(300000.0, $receipt['bookings'][0]['member_discount']);
        $this->assertEquals(1.0, $receipt['bookings'][0]['member_hours']);
        $this->assertEquals(0.0, (float) $receipt['grand_total']);
        $this->assertStringContainsString('Kuota Member (1 jam)', view('filament.partials.walkin-receipt', ['receipt' => $receipt])->render());
    }

    public function test_online_rp0_bill_is_settled_without_opening_midtrans(): void
    {
        Http::fake();
        [$employee, $voucher, $org] = $this->sponsorEmployee(5);

        $order = Order::create([
            'order_number' => 'ORD-RP0-ONLINE', 'user_id' => $employee->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 0, 'grand_total' => 0, 'payment_status' => 'UNPAID',
        ]);
        $start = Carbon::parse("{$this->date} 10:00");
        $booking = PadelBooking::create([
            'booking_code' => 'BK-RP0-ON', 'order_id' => $order->id, 'user_id' => $employee->id, 'court_id' => $this->court->id,
            'booking_date' => $this->date, 'start_time' => $start, 'end_time' => $start->copy()->addHour(),
            'court_fee' => 0, 'total_amount' => 0, 'status' => 'PENDING_PAYMENT', 'expires_at' => now()->addMinutes(10),
            'sponsor_organization_id' => $org->id, 'sponsor_member_voucher_id' => $voucher->id,
            'sponsor_hours_consumed' => 1, 'sponsor_discount_court' => 300000,
        ]);
        Payment::create(['order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => 'ORD-RP0-ONLINE', 'amount' => 0, 'payment_method' => 'QRIS', 'status' => 'PENDING']);

        $result = $this->service->retryPayment($order->order_number, 'QRIS', $employee);

        $this->assertTrue($result['is_paid']);
        $this->assertNull($result['snap_token']);
        $this->assertSame('SPONSOR_VOUCHER', $result['payment_method']);
        $this->assertSame('PAID', $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->qr_code_hash);
        $this->assertSame('PAID', $order->fresh()->payment_status);
        $this->assertSame('SPONSOR_VOUCHER', Payment::where('order_id', $order->id)->sole()->payment_method);
        Http::assertNothingSent();
    }
}
