<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_cashier_is_redirected_to_pos_screen(): void
    {
        $user = User::factory()->create(['role' => 'CASHIER']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/pos');
    }

    public function test_kitchen_is_redirected_to_kitchen_kds(): void
    {
        $user = User::factory()->create(['role' => 'KITCHEN']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/kitchen');
    }

    public function test_admin_is_redirected_to_admin_panel(): void
    {
        $user = User::factory()->create(['role' => 'SUPER_ADMIN']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/admin');
    }

    public function test_users_can_authenticate_using_phone_number_in_various_formats(): void
    {
        $user = User::factory()->create(['phone' => '081234567890']);

        $response = $this->post('/login', [
            'email' => '+62 812-3456-7890',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_login_using_admin_shortcut_and_password123(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@club61.com',
            'role' => 'SUPER_ADMIN',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'admin',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/admin');
    }

    public function test_admin_login_rejects_wrong_password_and_does_not_overwrite_hash(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@club61.com',
            'role' => 'SUPER_ADMIN',
            'password' => bcrypt('Password123!'),
        ]);

        $response = $this->post('/login', [
            'email' => 'admin',
            'password' => 'wrongpassword',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');

        // Pastikan hash password di database tidak pernah tertimpa
        $freshUser = $user->fresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Password123!', $freshUser->password));
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('wrongpassword', $freshUser->password));
    }

    public function test_guest_accessing_admin_panel_is_redirected_to_the_single_unified_login_page(): void
    {
        // Filament tidak lagi punya login page terpisah (/admin/login) — cuma ada SATU pintu
        // login untuk semua orang (staf maupun customer), yaitu /login.
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/admin/login')->assertStatus(404);
    }

    public function test_logging_out_from_admin_panel_redirects_to_the_unified_login_page(): void
    {
        $admin = User::factory()->create(['role' => 'SUPER_ADMIN']);
        \App\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super_admin']);

        $this->actingAs($admin)->get('/admin')->assertStatus(200);

        $response = $this->post('/admin/logout');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_custom_shield_role_like_admin12_is_redirected_to_admin_panel(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin12', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'email' => 'custom.admin@club61.com',
            'password' => bcrypt('password123'),
        ]);
        $user->syncRoles(['admin12']);

        $this->assertTrue($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
        $this->assertFalse($user->isCustomer());
        $this->assertTrue($user->isAdmin());

        $response = $this->post('/login', [
            'email' => 'custom.admin@club61.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/admin');
    }

    public function test_custom_shield_role_respects_hidden_page_permissions(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin12', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'View:Dashboard', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'View:BookingSystem', 'guard_name' => 'web']);

        // Skenario: User hanya diberi izin View:Dashboard, sedangkan View:BookingSystem di-hide
        $role->syncPermissions(['View:Dashboard']);

        $user = User::factory()->create([
            'email' => 'staff.terbatas@club61.com',
            'is_active' => true,
        ]);
        $user->syncRoles(['admin12']);

        $this->actingAs($user);

        $this->assertTrue(\App\Filament\Pages\Dashboard::canAccess());
        $this->assertFalse(\App\Filament\Pages\BookingSystem::canAccess());

        $this->get('/admin')->assertStatus(200);
        $this->get('/admin/booking-system')->assertDenied();

        // Setelah View:BookingSystem dicentang/diberikan
        $role->givePermissionTo('View:BookingSystem');
        $this->assertTrue(\App\Filament\Pages\BookingSystem::canAccess());
        $this->get('/admin/booking-system')->assertStatus(200);
    }

    public function test_custom_role_with_home_route_redirects_to_configured_destination(): void
    {
        // 1. Role kasir custom dengan home_route /pos
        $posRole = \App\Models\Role::firstOrCreate(
            ['name' => 'kasir_vip', 'guard_name' => 'web'],
            ['description' => 'Kasir VIP Lounge', 'home_route' => '/pos']
        );
        $userPos = User::factory()->create(['password' => bcrypt('password123')]);
        $userPos->syncRoles([$posRole]);

        $response = $this->post('/login', [
            'email' => $userPos->email,
            'password' => 'password123',
        ]);
        $response->assertRedirect('/pos');

        $this->post('/logout');

        // 2. Role dapur custom dengan home_route /kitchen
        $kitchenRole = \App\Models\Role::firstOrCreate(
            ['name' => 'koki_pastry', 'guard_name' => 'web'],
            ['description' => 'Koki Pastry Cafe', 'home_route' => '/kitchen']
        );
        $userKitchen = User::factory()->create(['password' => bcrypt('password123')]);
        $userKitchen->syncRoles([$kitchenRole]);

        $response = $this->post('/login', [
            'email' => $userKitchen->email,
            'password' => 'password123',
        ]);
        $response->assertRedirect('/kitchen');
    }

    public function test_role_matrix_service_and_admin_roles_page(): void
    {
        \App\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['password' => bcrypt('password123')]);
        $admin->syncRoles(['super_admin']);

        $this->actingAs($admin);

        // Akses halaman tabel roles backoffice
        $response = $this->get('/admin/roles');
        $response->assertStatus(200);

        // Validasi matriks izin Club 61
        $matrixSlugs = \App\Services\Permission\Club61PermissionMatrix::getAllPermissionSlugs();
        $this->assertGreaterThanOrEqual(60, count($matrixSlugs));
        $this->assertContains('View:BookingSystem', $matrixSlugs);
        $this->assertContains('View:KelolaPemesanan', $matrixSlugs);
        $this->assertContains('access_pos_terminal', $matrixSlugs);
        $this->assertContains('view_kitchen_kds', $matrixSlugs);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_failed_login_messages_are_in_indonesian_and_warn_before_lockout(): void
    {
        $user = User::factory()->create();
        $wrong = fn () => $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'salah-terus']);

        $wrong()->assertSessionHasErrors(['email' => 'Email / nomor HP atau kata sandi salah. Periksa lagi penulisannya, lalu coba lagi.']);
        $wrong();
        $wrong();
        $this->assertStringContainsString('Sisa 2 kali percobaan lagi', session('errors')->first('email'));
        $wrong();
        $this->assertStringContainsString('Sisa 1 kali percobaan lagi', session('errors')->first('email'));
        $wrong();
        $wrong()->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak percobaan masuk yang gagal', session('errors')->first('email'));

        // Form kosong: pesan validasi juga bahasa Indonesia.
        $this->from('/login')->post('/login', ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email' => 'Email wajib diisi.', 'password' => 'Kata sandi wajib diisi.']);
    }

    public function test_failed_login_message_floats_above_the_form_so_the_card_keeps_its_size(): void
    {
        $user = User::factory()->create();

        $page = $this->followingRedirects()->from('/login')->post('/login', ['email' => $user->email, 'password' => 'salah-terus'])->assertOk();

        // Pesan tampil di kotak melayang (#login-alert), kolom email ditandai merah — tidak ada baris error di bawah kolom.
        $page->assertSee('id="login-alert"', false)
            ->assertSee('Email / nomor HP atau kata sandi salah.')
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('mt-1.5 text-sm text-rose-700', false);

        $this->get('/login')->assertOk()->assertDontSee('id="login-alert"', false);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}