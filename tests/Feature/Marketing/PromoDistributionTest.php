<?php

namespace Tests\Feature\Marketing;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\DaftarVoucher;
use App\Models\Audit\ActivityLog;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\Voucher;
use App\Models\Pos\VoucherClaim;
use App\Models\User;
use App\Services\Finance\PromoVoucherService;
use App\Services\Finance\VoucherClaimService;
use App\Services\Finance\VoucherService;
use App\Services\Permission\Club61PermissionMatrix;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 22 tahap 3 — cara sebar B (kode unik massal + CSV) dan C (klaim ke akun → Voucher Saya), memakai mesin
 * VoucherService::resolve() yang sama dengan cara A.
 */
class PromoDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $customer;

    protected User $other;

    protected PadelCourt $court;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        ClubFinanceSetting::getSettings()->update(['is_admin_fee_enabled' => false, 'is_tax_enabled' => false]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Owner']);
        $this->customer = User::factory()->customer()->create(['name' => 'Budi']);
        $this->other = User::factory()->customer()->create(['name' => 'Siti']);
        $this->court = PadelCourt::create(['name' => 'Court PD', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 200000, 'is_active' => true]);
    }

    private function base(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Hadiah Turnamen', 'discount_type' => Voucher::TYPE_FIXED, 'discount_value' => 50000,
            'categories' => ['PADEL'], 'channel' => Voucher::CHANNEL_ALL, 'valid_until' => now()->addDays(30)->toDateString(), 'is_active' => true,
        ];
    }

    private function campaign(int $count = 5): Voucher
    {
        return app(PromoVoucherService::class)->create($this->base(['distribution' => 'UNIQUE', 'prefix' => 'trn', 'code_count' => $count]), $this->admin);
    }

    private function claimPromo(array $overrides = []): Voucher
    {
        return app(PromoVoucherService::class)->create($this->base($overrides + [
            'name' => 'Klaim Diskon 15%', 'distribution' => 'CLAIM', 'code' => 'KLAIM15', 'discount_type' => Voucher::TYPE_PERCENT, 'discount_value' => 15,
        ]), $this->admin);
    }

    private function resolve(string $code, User $user, string $channel = 'ONLINE'): array
    {
        return app(VoucherService::class)->resolve($code, $user, 200000, context: ['channel' => $channel, 'amounts' => ['PADEL' => 200000]]);
    }

    private function order(string $code, string $status, User $user, float $discount = 50000): Order
    {
        return Order::create([
            'order_number' => 'ORD-PD-'.Str::random(6), 'user_id' => $user->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 200000, 'discount_amount' => $discount, 'grand_total' => 200000 - $discount, 'payment_status' => $status, 'voucher_code' => $code,
        ]);
    }

    private function paidOrder(string $code, User $user, float $discount = 50000): Order
    {
        $order = $this->order($code, 'PAID', $user, $discount);
        Payment::create(['order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number, 'amount' => 200000 - $discount, 'payment_method' => 'QRIS', 'status' => 'SUCCESS']);
        app(VoucherService::class)->consume($order);

        return $order;
    }

    private function cancel(Order $order): void
    {
        PadelBooking::create([
            'booking_code' => 'BK-PD-'.Str::random(5), 'order_id' => $order->id, 'user_id' => $order->user_id, 'court_id' => $this->court->id,
            'booking_date' => now()->addDay()->toDateString(), 'start_time' => now()->addDay()->setTime(10, 0), 'end_time' => now()->addDay()->setTime(11, 0),
            'court_fee' => 150000, 'total_amount' => 150000, 'status' => 'CANCELLED',
        ]);
        app(VoucherService::class)->restoreCreditForCancelledOrder($order);
    }

    // ---------- B. Kode unik massal ----------

    public function test_admin_creates_a_unique_code_campaign_and_the_list_shows_one_row(): void
    {
        Livewire::actingAs($this->admin)->test(DaftarVoucher::class)
            ->callAction(TestAction::make('create')->table(), $this->base([
                'distribution' => 'UNIQUE', 'prefix' => 'trn', 'code_count' => 5, 'stack_with_membership' => false, 'min_order_amount' => 100000,
            ]))
            ->assertHasNoActionErrors()
            ->assertNotified('Promo 5 kode TRN-… dibuat.');

        $campaign = Voucher::where('distribution', 'UNIQUE')->whereNull('campaign_id')->sole();
        $codes = Voucher::where('campaign_id', $campaign->id)->get();
        $this->assertCount(5, $codes);
        $this->assertCount(5, $codes->pluck('code')->unique());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^TRN-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6}$/', $code->code);
            $this->assertSame(1, $code->quota);
            $this->assertSame('Hadiah Turnamen', $code->name, 'aturan kampanye disalin ke tiap kode');
            $this->assertEquals(50000, (float) $code->discount_value);
            $this->assertEquals(100000, (float) $code->min_order_amount);
            $this->assertSame(['PADEL'], $code->categories);
        }
        $this->assertSame(1, ActivityLog::where('event', 'promo.created')->count(), 'satu baris log, bukan per kode');

        // Tabel: satu baris kampanye; mencari salah satu kode menemukan kampanyenya.
        Livewire::actingAs($this->admin)->test(DaftarVoucher::class)
            ->assertCanSeeTableRecords([$campaign])
            ->assertCanNotSeeTableRecords($codes)
            ->assertSee('5 kode unik')
            ->assertSee('Kode unik massal')
            ->searchTable($codes->first()->code)
            ->assertCanSeeTableRecords([$campaign]);
    }

    public function test_each_unique_code_works_once_and_one_account_gets_one_code_per_campaign(): void
    {
        $campaign = $this->campaign(3);
        [$a, $b, $c] = Voucher::where('campaign_id', $campaign->id)->orderBy('code')->pluck('code')->all();

        // Kode baris kampanye sendiri tidak bisa dipakai.
        $this->assertStringContainsString('tidak ditemukan', (string) $this->resolve($campaign->code, $this->customer)['error']);

        $this->assertNull($this->resolve($a, $this->customer)['error']);
        $this->assertEquals(50000, $this->resolve($a, $this->customer)['discount']);
        $this->assertStringContainsString('1 kode per akun', implode(' ', app(PromoVoucherService::class)->terms(Voucher::where('code', $a)->sole())['rules']));

        $order = $this->paidOrder($a, $this->customer);
        $this->assertSame(0, Voucher::where('code', $a)->value('quota'));

        // Akun yang sama tidak bisa memakai kode lain dari kampanye ini; akun lain bisa.
        $this->assertStringContainsString('satu kode dari kampanye ini', (string) $this->resolve($b, $this->customer)['error']);
        $this->assertNull($this->resolve($b, $this->other)['error']);
        // Kode yang sudah dipakai mati untuk siapa pun.
        $this->assertStringContainsString('Kode ini sudah dipakai', (string) $this->resolve($a, $this->other)['error']);

        // Kode yang sedang dipesan order belum lunas juga tidak bisa dipakai orang lain.
        $this->order($c, 'UNPAID', $this->other);
        $third = User::factory()->customer()->create();
        $this->assertStringContainsString('Kode ini sudah dipakai', (string) $this->resolve($c, $third)['error']);

        // Order lunas dibatalkan → kode hidup lagi & jatah akun kembali (T2).
        $this->cancel($order);
        $this->assertSame(1, Voucher::where('code', $a)->value('quota'));
        $this->assertNull($this->resolve($b, $this->customer)['error']);

        $stats = app(PromoVoucherService::class)->campaignStats($campaign);
        $this->assertSame(['total' => 3, 'used' => 0, 'held' => 1, 'free' => 2], $stats);
    }

    public function test_campaign_changes_apply_to_every_code_and_the_discount_locks_after_use(): void
    {
        $campaign = $this->campaign(3);
        $service = app(PromoVoucherService::class);
        $until = now()->addDays(60)->toDateString();

        $service->update($campaign, $this->base(['valid_until' => $until, 'channel' => 'POS']), $this->admin);
        $service->setActive($campaign->fresh(), false, $this->admin);

        foreach (Voucher::where('campaign_id', $campaign->id)->get() as $code) {
            $this->assertFalse($code->is_active);
            $this->assertSame('POS', $code->channel);
            $this->assertSame($campaign->fresh()->valid_until->toDateTimeString(), $code->valid_until->toDateTimeString());
            $this->assertStringContainsString('tidak aktif', (string) $this->resolve($code->code, $this->customer, 'POS')['error']);
        }

        // Kode unik tidak bisa diubah sendiri-sendiri.
        try {
            $service->setActive(Voucher::where('campaign_id', $campaign->id)->first(), true, $this->admin);
            $this->fail('kode unik diatur lewat kampanye');
        } catch (HttpException $e) {
            $this->assertStringContainsString('kampanye', $e->getMessage());
        }

        $service->setActive($campaign->fresh(), true, $this->admin);
        $this->paidOrder(Voucher::where('campaign_id', $campaign->id)->value('code'), $this->customer);
        $this->assertTrue($service->hasBeenUsed($campaign->fresh()));
        try {
            $service->update($campaign->fresh(), $this->base(['discount_value' => 75000, 'channel' => 'POS', 'valid_until' => $until]), $this->admin);
            $this->fail('potongan kampanye yang sudah dipakai dikunci');
        } catch (HttpException $e) {
            $this->assertStringContainsString('sudah dipakai', $e->getMessage());
        }
    }

    public function test_codes_csv_lists_status_and_is_limited_to_promo_managers(): void
    {
        $campaign = $this->campaign(3);
        $used = Voucher::where('campaign_id', $campaign->id)->orderBy('code')->value('code');
        $this->paidOrder($used, $this->customer);

        $response = $this->actingAs($this->admin)->get(route('admin.voucher-codes.export', $campaign));
        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(4, $lines, 'header + 3 kode');
        $this->assertStringContainsString('Kode,Status', $lines[0]);
        $this->assertMatchesRegularExpression('/'.preg_quote($used, '/').',"?Sudah dipakai"?,Budi,ORD-PD-/', $csv);
        $this->assertSame(2, substr_count($csv, 'Belum dipakai'));
        $this->assertTrue(ActivityLog::where('event', 'promo.codes_exported')->exists());

        // Bukan kampanye → 404; staf tanpa manage_vouchers → 403.
        $this->get(route('admin.voucher-codes.export', Voucher::where('campaign_id', $campaign->id)->first()))->assertNotFound();
        $this->actingAs(User::factory()->cashier()->create(['is_active' => true]))
            ->get(route('admin.voucher-codes.export', $campaign))->assertForbidden();
    }

    // ---------- C. Klaim ke akun ----------

    public function test_customer_claims_a_promo_and_quota_counts_claims(): void
    {
        $promo = $this->claimPromo(['quota' => 2]);

        // Belum klaim → tidak bisa dipakai walau tahu kodenya.
        $this->assertStringContainsString('harus diklaim', (string) $this->resolve('KLAIM15', $this->customer)['error']);

        // Halaman promo publik: tamu melihat promonya dengan tombol masuk (klaim tetap wajib login).
        $this->get(route('promo.claim', 'KLAIM15'))->assertOk()->assertSee('Klaim Diskon 15%')->assertSee('Masuk untuk klaim')->assertDontSee('Klaim promo ini');
        $this->post(route('promo.claim.store', 'KLAIM15'))->assertRedirect(route('login'));

        $this->actingAs($this->customer)->get(route('promo.claim', 'klaim15'))
            ->assertOk()->assertSee('Klaim Diskon 15%')->assertSee('Klaim promo ini')->assertSee('Sisa 2 dari 2 klaim');
        $this->post(route('promo.claim.store', 'KLAIM15'))->assertRedirect(route('promo.claim', 'KLAIM15'));
        $this->get(route('promo.claim', 'KLAIM15'))->assertSee('Promo ini sudah ada di voucher Anda.')->assertDontSee('Klaim promo ini');
        $this->assertSame(1, $promo->fresh()->quota);

        // Klik ganda: tidak dobel.
        $this->post(route('promo.claim.store', 'KLAIM15'))->assertSessionHas('promo_error');
        $this->assertSame(1, VoucherClaim::where('voucher_id', $promo->id)->count());

        $this->assertNull($this->resolve('KLAIM15', $this->customer)['error']);
        $this->assertEquals(30000, $this->resolve('KLAIM15', $this->customer)['discount']);

        // Kuota klaim habis.
        app(VoucherClaimService::class)->claim($promo, $this->other);
        $this->assertSame(0, $promo->fresh()->quota);
        $late = User::factory()->customer()->create();
        $this->actingAs($late)->get(route('promo.claim', 'KLAIM15'))->assertSee('Kuota klaim promo ini sudah habis.');
        try {
            app(VoucherClaimService::class)->claim($promo->fresh(), $late);
            $this->fail('kuota klaim habis');
        } catch (HttpException $e) {
            $this->assertStringContainsString('habis', $e->getMessage());
        }

        // Kode promo kode umum (A) / tidak dikenal tidak punya halaman klaim.
        app(PromoVoucherService::class)->create($this->base(['code' => 'OPEN20']), $this->admin);
        $this->get(route('promo.claim', 'OPEN20'))->assertNotFound();
    }

    public function test_guest_logs_in_from_the_promo_link_and_lands_back_on_it(): void
    {
        $this->claimPromo(['quota' => 5]);

        // Tamu buka link → "Log in to claim" → login → kembali ke halaman promo (dulu dilempar ke Dashboard).
        $this->get(route('promo.claim.auth', ['KLAIM15', 'masuk']))->assertRedirect(route('login'));
        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])
            ->assertRedirect('/promo/KLAIM15');
        $this->get('/promo/KLAIM15')->assertSee('Klaim promo ini');
        $this->post(route('promo.claim.store', 'KLAIM15'))->assertSessionHas('promo_claimed');
        auth()->logout();

        // Daftar akun baru dari link promo juga kembali ke promo.
        $this->get(route('promo.claim.auth', ['KLAIM15', 'daftar']))->assertRedirect(route('register'));
        $this->post('/register', ['name' => 'Pelanggan Baru', 'email' => 'baru@contoh.test', 'phone' => '081234567893', 'password' => 'Password-12345', 'password_confirmation' => 'Password-12345'])
            ->assertRedirect('/promo/KLAIM15');
        auth()->logout();

        // Login biasa tanpa link tetap ke Dashboard; tujuan halaman staf tidak diikuti untuk customer.
        $this->post('/login', ['email' => $this->other->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        auth()->logout();
        $this->withSession(['url.intended' => url('/admin/daftar-voucher')])
            ->post('/login', ['email' => $this->other->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        auth()->logout();
        // Tujuan ke domain lain dipotong jadi path relatif.
        $this->withSession(['url.intended' => 'https://evil.example//coaching'])
            ->post('/login', ['email' => $this->other->email, 'password' => 'password'])->assertRedirect('/coaching');
    }

    public function test_claimed_promo_shows_in_my_vouchers_checkout_api_and_pos_and_returns_after_cancel(): void
    {
        $promo = $this->claimPromo(['quota' => 10]);

        // API aplikasi: klaim + vouchers/mine (key claimed, data lama tetap).
        $this->actingAs($this->customer)->postJson('/api/v1/padel/vouchers/claim', ['code' => 'klaim15'])
            ->assertOk()->assertJsonPath('data.code', 'KLAIM15');
        $this->getJson('/api/v1/padel/vouchers/mine')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('claimed.0.code', 'KLAIM15')
            ->assertJsonPath('claimed.0.name', 'Klaim Diskon 15%');
        $this->postJson('/api/v1/padel/vouchers/claim', ['code' => 'KLAIM15'])->assertStatus(422);
        $this->postJson('/api/v1/padel/vouchers/claim', ['code' => 'TIDAKADA'])->assertNotFound();

        // Voucher Saya: kartu PROMO di Tersedia.
        $this->actingAs($this->customer, 'web')->get(route('profile.vouchers'))->assertOk()->assertSee('Klaim Diskon 15%')->assertSee('data-voucher-kind="PROMO"', false);

        // Kasir: promo klaim customer terpilih muncul untuk dipakai.
        $pos = Livewire::actingAs($this->admin)->test(BookOfflineCourt::class)->set('selectedCustomerId', $this->customer->id);
        $this->assertSame(['KLAIM15'], array_column($pos->instance()->customerClaimedPromos, 'code'));

        // Dipakai & lunas → kuota klaim tidak berubah, hilang dari daftar yang bisa dipakai.
        $order = $this->paidOrder('KLAIM15', $this->customer, 30000);
        $this->assertSame(9, $promo->fresh()->quota, 'kuota C = klaim, tidak dipotong lagi saat lunas');
        $this->assertSame(1, $promo->fresh()->used_count);
        $this->assertSame([], app(VoucherClaimService::class)->usableForApi($this->customer));
        $this->assertStringContainsString('sudah pernah kamu pakai', (string) $this->resolve('KLAIM15', $this->customer)['error']);

        // Booking dibatalkan → promo kembali ke Voucher Saya, kuota klaim tetap (T2).
        $this->cancel($order);
        $this->assertSame(9, $promo->fresh()->quota);
        $this->assertSame(0, $promo->fresh()->used_count);
        $this->assertSame(['KLAIM15'], array_column(app(VoucherClaimService::class)->usableForApi($this->customer), 'code'));
        $this->assertNull($this->resolve('KLAIM15', $this->customer)['error']);
    }

    public function test_claim_quota_edit_counts_existing_claims_and_link_is_shown_to_admin(): void
    {
        $promo = $this->claimPromo(['quota' => 5]);
        app(VoucherClaimService::class)->claim($promo, $this->customer);
        app(VoucherClaimService::class)->claim($promo->fresh(), $this->other);
        $service = app(PromoVoucherService::class);
        $base = $this->base(['name' => 'Klaim Diskon 15%', 'code' => 'KLAIM15', 'discount_type' => 'PERCENT', 'discount_value' => 15]);

        try {
            $service->update($promo->fresh(), ['quota' => 1] + $base, $this->admin);
            $this->fail('kuota klaim tidak boleh di bawah yang sudah diklaim');
        } catch (HttpException $e) {
            $this->assertStringContainsString('diklaim', $e->getMessage());
        }
        // Sudah diklaim → potongan dikunci (customer sudah melihat syaratnya).
        try {
            $service->update($promo->fresh(), ['quota' => 5, 'discount_value' => 50] + $base, $this->admin);
            $this->fail('potongan promo yang sudah diklaim dikunci');
        } catch (HttpException $e) {
            $this->assertStringContainsString('diklaim', $e->getMessage());
        }
        $service->update($promo->fresh(), ['quota' => 10] + $base, $this->admin);
        $this->assertSame(8, $promo->fresh()->quota, 'total 10 − 2 klaim');

        Livewire::actingAs($this->admin)->test(DaftarVoucher::class)
            ->assertSee('Klaim ke akun')
            ->assertSee('Diklaim 2 akun')
            ->mountTableAction('claimLink', $promo)
            ->assertMountedActionModalSeeHtml(route('promo.claim', 'KLAIM15'));
    }
}
