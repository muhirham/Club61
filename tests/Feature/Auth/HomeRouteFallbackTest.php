<?php

namespace Tests\Feature\Auth;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Permission\HomeRouteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** HOME ROUTE kosong / tidak terdaftar → login mendarat di halaman pertama yang dicentang di matriks izin. */
class HomeRouteFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
    }

    private function staffWithRole(string $roleName, ?string $homeRoute, array $permissions): User
    {
        $role = Role::create(['name' => $roleName, 'guard_name' => 'web', 'home_route' => $homeRoute]);
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['password' => bcrypt('password123'), 'role' => 'ADMIN']);
        $user->syncRoles([$role]);

        return $user;
    }

    private function login(User $user)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password123']);
    }

    public function test_role_without_home_route_lands_on_its_only_permitted_page(): void
    {
        $user = $this->staffWithRole('staf_keuangan', null, ['View:BukuTransaksi']);

        $this->login($user)->assertRedirect('/admin/buku-transaksi');
        $this->get('/admin/buku-transaksi')->assertOk();
    }

    public function test_unregistered_home_route_from_old_seeder_falls_back_to_first_menu_page(): void
    {
        // Sisa seeder lama: "/admin/book-offline-court" tidak ada di pilihan HOME ROUTE → diabaikan.
        $user = $this->staffWithRole('resepsionis_lama', '/admin/book-offline-court', ['View:KelolaPemesanan', 'View:BookOfflineCourt']);

        // Urutan menu "Operasional Harian": POS Walk-In Booking (2) sebelum Kelola Pemesanan (3).
        $this->login($user)->assertRedirect('/admin/book-offline-court');
    }

    public function test_first_permitted_page_follows_sidebar_order_across_groups(): void
    {
        // Kelola Pemesanan (Operasional Harian) tampil sebelum Buku Transaksi (Keuangan) di sidebar.
        $user = $this->staffWithRole('staf_campur', null, ['View:BukuTransaksi', 'View:KelolaPemesanan']);

        $this->login($user)->assertRedirect('/admin/kelola-pemesanan');
    }

    public function test_admin_home_route_without_dashboard_permission_goes_to_first_permitted_page(): void
    {
        $user = $this->staffWithRole('admin_keuangan', '/admin', ['View:BukuTransaksi']);

        $this->login($user)->assertRedirect('/admin/buku-transaksi');
    }

    public function test_admin_home_route_with_dashboard_permission_stays_on_dashboard(): void
    {
        $user = $this->staffWithRole('admin_lengkap', null, ['View:Dashboard', 'View:BukuTransaksi']);

        $this->login($user)->assertRedirect('/admin');
    }

    public function test_registered_home_route_is_still_respected(): void
    {
        $user = $this->staffWithRole('kasir_custom', '/pos', ['View:BukuTransaksi', 'access_pos_terminal']);

        $this->login($user)->assertRedirect('/pos');
    }

    public function test_default_receptionist_role_from_seeder_has_no_hard_coded_home_route(): void
    {
        $this->seed(\Database\Seeders\DemoAccessSeeder::class);

        $receptionist = User::where('email', 'resepsionis@club61.com')->firstOrFail();
        $this->assertNull(Role::findByName('receptionist', 'web')->home_route);
        $this->assertNull(HomeRouteResolver::configured($receptionist));

        // Preset resepsionis: Monitoring Lapangan = menu pertama yang dicentang.
        $this->post('/login', ['email' => 'resepsionis@club61.com', 'password' => \Database\Seeders\DemoAccessSeeder::DEFAULT_PASSWORD])
            ->assertRedirect('/admin/booking-system');
    }

    public function test_staff_opening_customer_portal_is_sent_to_the_automatic_home(): void
    {
        $user = $this->staffWithRole('staf_keuangan', null, ['View:BukuTransaksi']);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/admin/buku-transaksi');
    }

    public function test_role_form_saves_an_empty_home_route_as_automatic(): void
    {
        $admin = User::factory()->superAdmin()->create();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\Roles\Pages\CreateRole::class)
            ->fillForm(['name' => 'staf_otomatis', 'description' => 'Staf Otomatis', 'home_route' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Role::findByName('staf_otomatis', 'web')->home_route);
    }
}
