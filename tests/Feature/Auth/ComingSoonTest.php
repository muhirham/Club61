<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\ComingSoon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComingSoonTest extends TestCase
{
    use RefreshDatabase;

    private function openingAt(string $until = '2026-11-26 00:00'): void
    {
        config(['coming_soon.until' => $until]);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('app.timezone')));
    }

    public function test_mode_is_off_by_default_and_login_page_is_normal(): void
    {
        config(['coming_soon.until' => null]);

        $this->assertFalse(ComingSoon::active());
        $this->get('/login')->assertOk()->assertSee('Masuk ke Club 61')->assertDontSee('Portal member segera dibuka');
    }

    public function test_invalid_date_keeps_mode_off(): void
    {
        config(['coming_soon.until' => 'bukan-tanggal']);

        $this->assertFalse(ComingSoon::active());
        $this->get('/login')->assertOk()->assertSee('Masuk ke Club 61');
    }

    public function test_login_page_shows_countdown_and_staff_login_before_opening(): void
    {
        $this->openingAt();

        $this->assertTrue(ComingSoon::active());

        $this->get('/login')
            ->assertOk()
            ->assertSee('Portal member segera dibuka')
            ->assertSee('Kamis, 26 November 2026')
            ->assertSee('Login Staf')
            ->assertSee('data-seconds="'.ComingSoon::secondsLeft().'"', false)
            ->assertDontSee('Daftar membership');
    }

    public function test_countdown_uses_server_time_until_opening(): void
    {
        $this->openingAt();

        // 26 Okt 10:00 → 26 Nov 00:00 = 30 hari 14 jam.
        $this->assertSame(30 * 86400 + 14 * 3600, ComingSoon::secondsLeft());
    }

    public function test_customer_is_rejected_before_opening(): void
    {
        $this->openingAt();
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Akun member belum bisa masuk. Portal dibuka Kamis, 26 November 2026.']);

        $this->assertGuest();
    }

    public function test_staff_can_still_log_in_before_opening(): void
    {
        $this->openingAt();
        $admin = User::factory()->create(['role' => 'SUPER_ADMIN']);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_cashier_can_still_log_in_before_opening(): void
    {
        $this->openingAt();
        $cashier = User::factory()->create(['role' => 'CASHIER']);

        $this->post('/login', ['email' => $cashier->email, 'password' => 'password'])->assertRedirect('/pos');
        $this->assertAuthenticatedAs($cashier);
    }

    public function test_wrong_password_shows_the_staff_form_with_the_normal_error(): void
    {
        $this->openingAt();
        $admin = User::factory()->create(['role' => 'SUPER_ADMIN']);

        $this->from('/login')->post('/login', ['email' => $admin->email, 'password' => 'salah'])->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/login')->assertSee(trans('auth.failed'));
    }

    public function test_registration_is_closed_before_opening(): void
    {
        $this->openingAt();

        $this->get('/register')->assertRedirect(route('login'));
        $this->post('/register', [
            'name' => 'Calon Member',
            'email' => 'calon@example.com',
            'phone' => '081234567891',
            'password' => 'password-kuat-123',
            'password_confirmation' => 'password-kuat-123',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'calon@example.com']);
    }

    public function test_everything_is_normal_again_after_opening(): void
    {
        config(['coming_soon.until' => '2026-11-26 00:00']);
        $this->travelTo(CarbonImmutable::parse('2026-11-26 00:00:01', config('app.timezone')));
        $user = User::factory()->create();

        $this->assertFalse(ComingSoon::active());
        $this->get('/login')->assertSee('Masuk ke Club 61');
        $this->get('/register')->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }
}
