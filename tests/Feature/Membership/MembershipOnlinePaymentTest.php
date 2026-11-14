<?php

namespace Tests\Feature\Membership;

use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\User;
use App\Services\Payment\PaymentManager;
use App\Services\Payment\PaymentOrchestratorService;
use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pembelian membership online yang belum dibayar: lanjutkan bayar order yang sama, tidak ada order dobel,
 * bisa dibatalkan, dan kartu PENDING tidak tertinggal "Menunggu Pembayaran" selamanya.
 */
class MembershipOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    /** @var list<array> sesi yang dikirim ke "Midtrans" */
    public array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->customer = User::factory()->customer()->create();

        // Midtrans sungguhan (bukan simulator) — order tetap menunggu pembayaran.
        $test = $this;
        $this->app->instance(PaymentManager::class, new class($test) extends PaymentManager
        {
            public function __construct(private $test) {}

            public function createPayment(array $params): array
            {
                $this->test->sessions[] = $params;

                return ['snap_token' => 'SNAP-'.Str::random(8), 'redirect_url' => 'https://snap.test/'.$params['order_id'], 'is_mock' => false];
            }
        });
    }

    private function plan(string $name = 'Silver', float $price = 1_500_000): MembershipPlan
    {
        return MembershipPlan::create(['code' => 'MBR-'.Str::random(5), 'name' => $name, 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => $price, 'is_active' => true]);
    }

    private function checkout(MembershipPlan $plan, string $method = 'QRIS')
    {
        return $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/membership/checkout', ['plan_id' => $plan->id, 'payment_method' => $method]);
    }

    public function test_buying_the_same_plan_again_resumes_the_pending_order_instead_of_creating_another(): void
    {
        $plan = $this->plan();

        $this->checkout($plan)->assertCreated();
        $second = $this->checkout($plan, 'BCA_VA')->assertOk()->assertJsonPath('data.resumed', true);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, UserMembership::count());
        $this->assertSame(1, Payment::count());

        $order = Order::first();
        $this->assertCount(2, $this->sessions);
        $this->assertSame($order->order_number, $this->sessions[0]['order_id']);
        $this->assertStringStartsWith($order->order_number.'_', $this->sessions[1]['order_id']);
        $this->assertSame((int) $order->grand_total, $this->sessions[1]['gross_amount']);
        $this->assertSame((int) $order->grand_total, array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $this->sessions[1]['item_details'])));

        $bill = Payment::first();
        $this->assertSame('BCA_VA', $bill->payment_method);
        $this->assertContains($this->sessions[1]['order_id'], $bill->payload_log['midtrans_order_ids']);
        $this->assertNotNull($second->json('data.payment.snap_token'));
    }

    public function test_choosing_another_plan_while_one_is_unpaid_is_refused_with_the_pending_order(): void
    {
        $silver = $this->plan('Silver');
        $gold = $this->plan('Gold', 3_000_000);
        $this->checkout($silver)->assertCreated();
        $pendingId = UserMembership::value('id');

        $this->checkout($gold)
            ->assertStatus(409)
            ->assertJsonPath('data.pending_purchase.id', $pendingId)
            ->assertJsonPath('data.pending_purchase.plan_name', 'Silver');

        $this->assertSame(1, Order::count());
    }

    public function test_partial_payment_never_activates_the_membership(): void
    {
        $this->checkout($this->plan())->assertCreated();
        $membership = UserMembership::first();
        $order = Order::first();

        // Pelunasan kurang (jalur mana pun) → order PARTIALLY_PAID → kartu tetap menunggu pembayaran.
        app(PaymentOrchestratorService::class)->markOrderAsPaid($order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number, 'payment_method' => 'QRIS', 'amount' => (float) $order->grand_total - 100000,
        ]);

        $this->assertSame('PARTIALLY_PAID', $order->fresh()->payment_status);
        $this->assertSame('PENDING_PAYMENT', $membership->fresh()->status);
    }

    public function test_paying_the_new_session_activates_the_membership_once(): void
    {
        $plan = $this->plan();
        $this->checkout($plan)->assertCreated();
        $membership = UserMembership::first();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/membership/my-purchases')
            ->assertJsonPath('data.0.can_pay_online', true);

        $this->postJson("/api/v1/membership/purchases/{$membership->id}/pay", ['payment_method' => 'MANDIRI_VA'])->assertOk();
        $session = end($this->sessions)['order_id'];
        $order = Order::first();

        app(PaymentOrchestratorService::class)->markOrderAsPaid($order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $session, 'payment_method' => 'MANDIRI_VA', 'amount' => (float) $order->grand_total,
        ]);

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertSame('PAID', $order->fresh()->payment_status);
        $this->assertSame(1, Payment::where('status', 'SUCCESS')->count());

        $this->getJson('/api/v1/membership/my-purchases')->assertJsonPath('data.0.can_pay_online', false);
        $this->postJson("/api/v1/membership/purchases/{$membership->id}/pay", ['payment_method' => 'QRIS'])->assertStatus(409);
    }

    public function test_cancel_closes_the_order_and_allows_choosing_another_plan(): void
    {
        $silver = $this->plan('Silver');
        $gold = $this->plan('Gold', 3_000_000);
        $this->checkout($silver)->assertCreated();
        $membership = UserMembership::first();

        $this->postJson("/api/v1/membership/purchases/{$membership->id}/cancel")->assertOk();

        $this->assertSame('CANCELLED', $membership->fresh()->status);
        $this->assertSame('CANCELLED', Order::first()->payment_status);
        $this->assertSame('FAILED', Payment::first()->status);
        $this->assertSame('CUSTOMER_CANCELLED', Payment::first()->payload_log['closed_by']);

        $this->postJson("/api/v1/membership/purchases/{$membership->id}/pay", ['payment_method' => 'QRIS'])->assertStatus(422);
        $this->checkout($gold)->assertCreated();
        $this->assertSame(2, Order::count());
    }

    public function test_other_customers_cannot_pay_or_cancel_someone_elses_order(): void
    {
        $this->checkout($this->plan())->assertCreated();
        $membership = UserMembership::first();
        $other = User::factory()->customer()->create();

        $this->actingAs($other, 'sanctum')->postJson("/api/v1/membership/purchases/{$membership->id}/pay", ['payment_method' => 'QRIS'])->assertNotFound();
        $this->actingAs($other, 'sanctum')->postJson("/api/v1/membership/purchases/{$membership->id}/cancel")->assertNotFound();

        $this->assertSame('PENDING_PAYMENT', $membership->fresh()->status);
    }

    public function test_cashier_sales_are_not_payable_online(): void
    {
        $this->checkout($this->plan())->assertCreated();
        $membership = UserMembership::first();
        $membership->update(['sold_by_admin_id' => User::factory()->create()->id]);

        $this->getJson('/api/v1/membership/my-purchases')->assertJsonPath('data.0.can_pay_online', false);
        $this->postJson("/api/v1/membership/purchases/{$membership->id}/pay", ['payment_method' => 'QRIS'])->assertStatus(422);
    }

    public function test_midtrans_expire_notification_cancels_the_pending_card(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-TEST-KEY']);
        $this->checkout($this->plan())->assertCreated();
        $order = Order::first();
        $gross = number_format((float) $order->grand_total, 2, '.', '');

        $this->postJson('/api/v1/padel/webhook/midtrans', [
            'order_id' => $order->order_number,
            'status_code' => '202',
            'gross_amount' => $gross,
            'signature_key' => hash('sha512', $order->order_number.'202'.$gross.'SB-Mid-server-TEST-KEY'),
            'transaction_status' => 'expire',
        ])->assertOk();

        $this->assertSame('CANCELLED', $order->fresh()->payment_status);
        $this->assertSame('CANCELLED', UserMembership::first()->status);
    }

    public function test_invoice_check_activates_a_membership_paid_without_webhook(): void
    {
        // Kasus nyata: bayar VA di Midtrans, webhook tidak sampai (server lokal) → halaman invoice tetap "pending".
        $this->checkout($this->plan())->assertCreated();
        $order = Order::first();
        config(['services.midtrans.server_key' => 'SB-Mid-server-TEST-KEY']);
        \Illuminate\Support\Facades\Http::fake([
            '*/v2/'.$order->order_number.'/status' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '200', 'transaction_status' => 'settlement', 'order_id' => $order->order_number,
                'gross_amount' => number_format((float) $order->grand_total, 2, '.', ''), 'payment_type' => 'bank_transfer',
                'va_numbers' => [['bank' => 'bca', 'va_number' => '1234567890']],
            ]),
            '*' => \Illuminate\Support\Facades\Http::response(['status_code' => '404'], 404),
        ]);
        $this->app->forgetInstance(\App\Services\Payment\MidtransService::class);

        $this->getJson('/api/v1/membership/my-purchases?verify_payment=1')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'ACTIVE')
            ->assertJsonPath('data.0.can_pay_online', false);

        $this->assertSame('PAID', $order->fresh()->payment_status);
    }

    public function test_abandoned_orders_are_cancelled_by_the_daily_job(): void
    {
        $this->checkout($this->plan())->assertCreated();
        $fresh = UserMembership::first();

        $this->travel(25)->hours();
        $this->checkout($this->plan('Gold', 3_000_000))->assertStatus(409); // masih dianggap pending sebelum job jalan

        $this->artisan('membership:sync-expired')->expectsOutputToContain('1 pesanan membership online')->assertSuccessful();

        $this->assertSame('CANCELLED', $fresh->fresh()->status);
        $this->assertSame('CANCELLED', Order::first()->payment_status);

        // Batal otomatis tetap tercatat di Log Aktivitas (pelaku Sistem), walau tidak tampil di Data Member.
        $log = \App\Models\Audit\ActivityLog::where('event', 'membership.online_order_expired')->sole();
        $this->assertSame(['SYSTEM', Order::first()->order_number], [$log->actor_type, $log->meta['order_number']]);
        $this->assertStringContainsString('tidak dibayar dalam 24 jam', $log->description);
    }

    public function test_simulator_resume_marks_the_order_paid_like_checkout(): void
    {
        $this->checkout($this->plan())->assertCreated();
        $membership = UserMembership::first();
        $this->app->forgetInstance(PaymentManager::class); // kembali ke driver bawaan (simulator di testing)

        // Metode lain: metode yang sama memakai sesi yang sudah ada (SnapSessionGuard), bukan sesi simulator baru.
        $this->postJson("/api/v1/membership/purchases/{$membership->id}/pay", ['payment_method' => 'BCA_VA'])
            ->assertOk()
            ->assertJsonPath('data.payment.is_mock', true);

        $this->assertSame('ACTIVE', $membership->fresh()->status);
    }
}
