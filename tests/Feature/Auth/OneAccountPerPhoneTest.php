<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\JualMembership;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Pos\PosCashierShift;
use App\Models\Setting\CompanyProfileSetting;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 33 Tahap 1 — satu orang = satu nomor HP = satu akun: daftar web wajib nomor HP (unik, dinormalisasi, anti klik
 * ganda), profil bisa melengkapi nomor, banner untuk akun tanpa nomor, kasir POS tidak lagi diam-diam membuat akun kedua,
 * kasir bisa mengisi nomor HP akun terpilih, dan customer tidak bisa menghapus akunnya sendiri.
 */
class OneAccountPerPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/register', $override + [
            'name' => 'Budi Santoso', 'email' => 'budi@example.com', 'phone' => '+62 812-3456-7000',
            'password' => 'password', 'password_confirmation' => 'password',
        ]);
    }

    private function walkIn(string $phone = '081234567000', array $extra = []): User
    {
        return app(PadelBookingService::class)->findOrCreateWalkInCustomer('Budi Kasir', $phone, $extra['email'] ?? null);
    }

    public function test_web_registration_requires_a_unique_normalized_phone_and_gets_the_customer_role(): void
    {
        $this->register(['phone' => ''])->assertSessionHasErrors('phone');
        $this->register(['phone' => '12ab'])->assertSessionHasErrors(['phone' => __('account.register.phone_invalid')]);
        $this->assertGuest();

        $this->register()->assertSessionHasNoErrors();
        $user = User::where('email', 'budi@example.com')->sole();
        $this->assertSame('081234567000', $user->phone);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertSame('ONLINE', $user->registration_source);
    }

    public function test_phone_already_owned_by_a_walk_in_account_is_rejected_with_login_and_whatsapp_help(): void
    {
        CompanyProfileSetting::current()->update(['whatsapp_number' => '08116028965']);
        Cache::forget(CompanyProfileSetting::CACHE_KEY);
        $walkIn = $this->walkIn();

        $this->from('/register')->register(['phone' => '0812 3456 7000'])
            ->assertSessionHasErrors(['phone' => __('account.register.phone_taken')]);
        $this->assertGuest();
        $this->assertSame(1, User::where('phone', '081234567000')->count(), 'tidak ada akun kedua');
        $this->assertFalse(User::where('email', 'budi@example.com')->exists());

        $this->get('/register')->assertOk()
            ->assertSee(__('account.register.phone_taken'))
            ->assertSee('data-register-wa', false)
            ->assertSee('wa.me/628116028965', false);

        // Akun kasir bisa dipakai masuk dengan nomor HP + 6 digit terakhir.
        $this->post('/login', ['email' => '081234567000', 'password' => '567000'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($walkIn);
    }

    /** Dulu: 20 digit tanpa awalan 0 → "0"+20 = 21 karakter → kolom phone(20) ditolak MySQL → error 500. */
    public function test_too_long_numbers_are_rejected_politely_on_every_path_instead_of_500(): void
    {
        $tooLong = '12345678901234567890';
        $this->assertNull(\App\Support\PhoneNumber::normalize($tooLong));
        $this->assertSame('0'.str_repeat('8', 19), \App\Support\PhoneNumber::normalize('0'.str_repeat('8', 19)), 'tepat 20 karakter tetap sah');

        $this->register(['phone' => $tooLong])->assertSessionHasErrors(['phone' => __('account.register.phone_invalid')]);
        $this->postJson('/api/v1/auth/register', ['name' => 'Siti', 'email' => 'siti@example.com', 'phone' => $tooLong, 'password' => 'Password123!'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $user = User::factory()->create(['name' => 'Rina Web', 'phone' => null]);
        $this->actingAs($user)->patch('/profile', ['name' => 'Rina Web', 'email' => $user->email, 'phone' => $tooLong])
            ->assertSessionHasErrors(['phone' => __('account.register.phone_invalid')]);

        try {
            app(PadelBookingService::class)->findOrCreateWalkInCustomer('Budi', $tooLong);
            $this->fail('nomor kepanjangan harus ditolak');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->expectException(\DomainException::class);
        app(\App\Services\Customer\CustomerPhoneService::class)->attachToCustomer($user, $tooLong);
    }

    public function test_double_submit_is_refused_while_the_first_registration_holds_the_lock(): void
    {
        $lock = Cache::lock('register:email:budi@example.com', 15);
        $this->assertTrue($lock->get());

        $this->register()->assertSessionHasErrors(['email' => __('account.register.in_progress')]);
        $this->assertFalse(User::where('email', 'budi@example.com')->exists());

        $lock->release();
        $this->register()->assertSessionHasNoErrors();
        $this->assertSame(1, User::where('email', 'budi@example.com')->count());
    }

    public function test_app_registration_double_submit_is_refused_too(): void
    {
        $lock = Cache::lock('register:phone:081299990000', 15);
        $this->assertTrue($lock->get());

        $this->postJson('/api/v1/auth/register', ['name' => 'Siti', 'email' => 'siti@example.com', 'phone' => '081299990000', 'password' => 'Password123!'])
            ->assertStatus(422);
        $this->assertFalse(User::where('email', 'siti@example.com')->exists());
        $lock->release();
    }

    public function test_profile_can_add_a_phone_but_not_take_someone_elses_or_clear_it(): void
    {
        $this->walkIn('081200000001');
        // Nama tetap: nama acak faker (mis. "O'Keefe") bisa ditolak aturan nama dan membuat test ini acak-gagal.
        $user = User::factory()->create(['name' => 'Rina Web', 'email' => 'web@example.com', 'phone' => null]);
        $this->actingAs($user);

        $this->patch('/profile', ['name' => $user->name, 'email' => $user->email, 'phone' => '0812-0000-0001'])
            ->assertSessionHasErrors(['phone' => __('account.profile.phone_taken')]);
        $this->assertNull($user->fresh()->phone);

        $this->patch('/profile', ['name' => $user->name, 'email' => $user->email, 'phone' => '+62 812 0000 0002'])->assertSessionHasNoErrors();
        $this->assertSame('081200000002', $user->fresh()->phone);

        $this->patch('/profile', ['name' => $user->name, 'email' => $user->email, 'phone' => ''])
            ->assertSessionHasErrors(['phone' => __('account.profile.phone_required')]);
        $this->patch('/profile', ['name' => 'Nama Baru', 'email' => $user->email])->assertSessionHasNoErrors();
        $this->assertSame('081200000002', $user->fresh()->phone, 'field tidak dikirim → nomor lama tetap');
        $this->assertSame('Nama Baru', $user->fresh()->name);
    }

    public function test_customer_pages_show_the_phone_banner_until_the_phone_is_added(): void
    {
        $user = User::factory()->create(['phone' => null]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('data-phone-banner', false);
        $this->actingAs($user)->get('/invoice')->assertOk()->assertSee('data-phone-banner', false);
        $this->actingAs($user)->get('/profile')->assertOk()->assertDontSee('data-phone-banner', false);

        $user->forceFill(['phone' => '081200000003'])->save();
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertDontSee('data-phone-banner', false);

        $staff = User::factory()->cashier()->create(['phone' => null]);
        $this->actingAs($staff)->get('/profile/vouchers')->assertDontSee('data-phone-banner', false);
    }

    public function test_walk_in_with_an_email_of_another_account_is_not_silently_duplicated(): void
    {
        $web = User::factory()->create(['name' => 'Budi Web', 'email' => 'budi@example.com', 'phone' => null]);
        $service = app(PadelBookingService::class);

        try {
            $service->findOrCreateWalkInCustomer('Budi', '081277770000', 'budi@example.com', rejectTakenEmail: true);
            $this->fail('email milik akun lain harus ditolak di kasir');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Budi Web', $e->getMessage());
        }
        $this->assertFalse(User::where('phone', '081277770000')->exists());

        // Nomor HP yang sudah dikenal selalu menang (akun itu yang dipakai), email diabaikan.
        $known = $this->walkIn('081277770001');
        $this->assertTrue($known->is($service->findOrCreateWalkInCustomer('X', '0812-7777-0001', 'budi@example.com', rejectTakenEmail: true)));

        // Impor penjualan (tanpa flag) tetap seperti dulu.
        $this->assertNotSame($web->id, $service->findOrCreateWalkInCustomer('Budi', '081277770002', 'budi@example.com')->id);
    }

    private function membershipCashier(): User
    {
        \App\Services\Permission\Club61PermissionMatrix::syncAllPermissions('web');
        $cashier = User::factory()->cashier()->create(['is_active' => true]);
        $cashier->givePermissionTo(['View:JualMembership', 'sell_membership', 'View:BookOfflineCourt', 'process_walkin_booking']);
        PosCashierShift::create([
            'shift_number' => 'SFT-PADEL-'.now()->format('Ymd').'-0001', 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);

        return $cashier;
    }

    private function plan(): MembershipPlan
    {
        $plan = MembershipPlan::create(['code' => 'MBR-T33', 'name' => 'Silver', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 1000000, 'is_active' => true]);
        MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 10, 'discount_percent' => 20]);

        return $plan;
    }

    public function test_membership_cashier_blocks_a_taken_email_and_reuses_the_account_by_normalized_phone(): void
    {
        $this->actingAs($this->membershipCashier());
        $plan = $this->plan();
        User::factory()->create(['name' => 'Budi Web', 'email' => 'budi@example.com', 'phone' => null]);

        Livewire::test(JualMembership::class)
            ->set('selectedPlanId', $plan->id)->set('customerMode', 'quick_create')
            ->set('walkInName', 'Budi')->set('walkInPhone', '081277771111')->set('walkInEmail', 'budi@example.com')
            ->call('proceedToPayment')
            ->assertSet('posStep', 'selection')
            ->assertNotified('Email Sudah Terdaftar');

        // Akun walk-in lama dengan nomor yang sama (diketik dengan format lain) dipakai lagi — tidak dobel.
        $existing = $this->walkIn('081277772222');
        Livewire::test(JualMembership::class)
            ->set('walkInName', 'Budi Lagi')->set('walkInPhone', '+62 812-7777-2222')
            ->call('selectPlan', $plan->id)
            ->set('paymentMethod', 'QRIS')->set('qrisMode', 'MANUAL')->set('qrisRrn', 'RRN33A')
            ->call('submitSale')
            ->assertSet('showSuccessModal', true);
        $this->assertSame(1, User::where('phone', '081277772222')->count());
        $this->assertSame($existing->id, \App\Models\Membership\UserMembership::latest('id')->value('user_id'));

        // Akun baru dari kasir membership = jalur yang sama dengan kasir lapangan (nomor dinormalisasi, password 6 digit).
        Livewire::test(JualMembership::class)
            ->set('walkInName', 'Member Baru')->set('walkInPhone', '0812 7777 3333')
            ->call('selectPlan', $plan->id)
            ->set('paymentMethod', 'QRIS')->set('qrisMode', 'MANUAL')->set('qrisRrn', 'RRN33B')
            ->call('submitSale')
            ->assertSet('showSuccessModal', true);
        $new = User::where('phone', '081277773333')->sole();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('773333', $new->password));
        $this->assertTrue($new->hasRole('customer'));
    }

    public function test_cashier_adds_a_phone_to_a_selected_account_without_one(): void
    {
        $this->actingAs($this->membershipCashier());
        $web = User::factory()->create(['name' => 'Budi Web', 'phone' => null]);
        $this->walkIn('081288880000');

        $page = Livewire::test(BookOfflineCourt::class)
            ->set('customerMode', 'search')
            ->call('selectCustomer', $web->id)
            ->assertSee('data-pos-add-phone', false);

        $page->set('selectedCustomerNewPhone', '0812-8888-0000')->call('saveSelectedCustomerPhone')
            ->assertNotified('Nomor HP Tidak Disimpan');
        $this->assertNull($web->fresh()->phone);

        $page->set('selectedCustomerNewPhone', '+62 812 8888 0001')->call('saveSelectedCustomerPhone')
            ->assertNotified('Nomor HP Disimpan')
            ->assertSet('selectedCustomerPhone', '081288880001')
            ->assertDontSee('data-pos-add-phone', false);
        $this->assertSame('081288880001', $web->fresh()->phone);

        // Akun yang sudah punya nomor / akun staf tidak bisa diisi lewat kasir.
        $staff = User::factory()->cashier()->create(['phone' => null]);
        Livewire::test(JualMembership::class)->call('selectCustomer', $staff->id)
            ->set('selectedCustomerNewPhone', '081288880002')->call('saveSelectedCustomerPhone')
            ->assertNotified('Nomor HP Tidak Disimpan');
        $this->assertNull($staff->fresh()->phone);
    }
}
