<?php

namespace Tests\Feature\Padel;

use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Booking lunas Rp0 (voucher corporate / kuota member) yang hangus karena tidak check-in harus terbaca "Tidak hadir" di
 * invoice — dulu terbaca "Batas Waktu Pembayaran Habis" karena halaman invoice hanya melihat nominal yang dibayar.
 */
class NoShowInvoiceLabelTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected PadelCourt $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create();
        $this->court = PadelCourt::create(['name' => 'Court Protelindo', 'type' => 'INDOOR', 'hourly_rate_regular' => 100000, 'hourly_rate_prime' => 100000, 'is_active' => true]);
    }

    private function expiredBooking(string $code, ?string $paymentStatus, float $amount = 0): PadelBooking
    {
        $order = Order::create([
            'order_number' => 'ORD-'.$code, 'user_id' => $this->customer->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => $amount, 'grand_total' => $amount, 'payment_status' => $paymentStatus === 'SUCCESS' ? 'PAID' : 'UNPAID',
        ]);
        if ($paymentStatus) {
            Payment::create([
                'order_id' => $order->id, 'payment_gateway' => 'SPONSOR_VOUCHER', 'transaction_id' => $order->order_number,
                'amount' => $amount, 'payment_method' => 'SPONSOR_VOUCHER', 'status' => $paymentStatus,
            ]);
        }

        return PadelBooking::create([
            'booking_code' => $code, 'user_id' => $this->customer->id, 'court_id' => $this->court->id, 'order_id' => $order->id,
            'booking_date' => now()->subDay()->toDateString(), 'start_time' => now()->subDay(), 'end_time' => now()->subDay()->addHours(2),
            'court_fee' => $amount, 'total_amount' => $amount, 'status' => 'EXPIRED', 'qr_code_hash' => 'hash-'.$code,
        ]);
    }

    public function test_paid_zero_rupiah_no_show_is_marked_paid_but_unpaid_expiry_is_not(): void
    {
        $voucherNoShow = $this->expiredBooking('BK-NOSHOW-0', 'SUCCESS');
        $unpaid = $this->expiredBooking('BK-UNPAID-1', 'PENDING', 200000);
        $paidNoShow = $this->expiredBooking('BK-NOSHOW-2', 'SUCCESS', 200000);
        $this->actingAs($this->customer);

        $this->getJson("/api/v1/padel/bookings/{$voucherNoShow->id}/ticket")->assertOk()
            ->assertJsonPath('data.was_paid', true);
        $this->getJson("/api/v1/padel/bookings/{$unpaid->id}/ticket")->assertOk()
            ->assertJsonPath('data.was_paid', false);
        $this->getJson("/api/v1/padel/bookings/{$paidNoShow->id}/ticket")->assertOk()
            ->assertJsonPath('data.was_paid', true);

        $list = collect($this->getJson('/api/v1/padel/my-bookings')->assertOk()->json('data'))->keyBy('booking_code');
        $this->assertTrue($list['BK-NOSHOW-0']['was_paid']);
        $this->assertFalse($list['BK-UNPAID-1']['was_paid']);
        $this->assertTrue($list['BK-NOSHOW-2']['was_paid']);
    }

    public function test_invoice_page_ships_the_no_show_label(): void
    {
        $this->actingAs($this->customer)->get('/invoice')->assertOk()
            ->assertSee('Tidak hadir')
            ->assertSee('wasPaid(t)', false);
    }
}
