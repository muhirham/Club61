<?php

namespace Tests\Feature\I18n;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Situs customer dua bahasa — Tahap A: navbar, halaman masuk / daftar, beranda customer, profil, Voucher Saya.
 * Default Bahasa Indonesia; pilihan EN (cookie / session) mengganti teksnya; tombol ID / EN ada di navbar & halaman masuk.
 */
class AccountPagesLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function enCookie(): static
    {
        return $this->withCookie(SetLocale::COOKIE, 'en');
    }

    public function test_login_page_defaults_to_indonesian_and_switches_to_english(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Masuk ke Club 61')
            ->assertSee('Lupa kata sandi?')
            ->assertSee('Belum punya akun?')
            ->assertSee('c61-lang-switch', false)
            ->assertSee(route('lang.switch', 'en'), false)
            ->assertDontSee('Sign in to Club 61');

        $this->enCookie()->get('/login')->assertOk()
            ->assertSee('Sign in to Club 61')
            ->assertSee('Forgot password?')
            ->assertSee('Don&#039;t have an account?', false)
            ->assertDontSee('Masuk ke Club 61');
    }

    public function test_register_page_defaults_to_indonesian_and_switches_to_english(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Bergabung ke Club 61 Padel &amp; Wellness', false)
            ->assertSee('Daftar Akun Sekarang')
            ->assertSee('c61-lang-switch', false);

        $this->withSession(['site_locale' => 'en'])->get('/register')->assertOk()
            ->assertSee('Join Club 61 Padel &amp; Wellness', false)
            ->assertSee('Create My Account')
            ->assertDontSee('Daftar Akun Sekarang');
    }

    public function test_customer_dashboard_and_navbar_follow_the_selected_language(): void
    {
        $this->actingAs(User::factory()->customer()->create(['name' => 'Budi Santoso']));

        $this->get('/dashboard')->assertOk()
            ->assertSee('Selamat datang kembali,')
            ->assertSee('Pesan Lapangan')
            ->assertSee('Akses cepat')
            ->assertSee('Booking terbaru')
            ->assertSee('Voucher Saya')        // menu akun di navbar
            ->assertSee('Keluar')
            ->assertSee('c61-lang-switch', false)
            ->assertSee(route('lang.switch', 'en'), false)
            ->assertSee("'id-ID'", false)       // format tanggal JS ikut bahasa
            ->assertDontSee('Welcome back,');

        $this->enCookie()->get('/dashboard')->assertOk()
            ->assertSee('Welcome back,')
            ->assertSee('Book a Court')
            ->assertSee('Quick access')
            ->assertSee('Recent bookings')
            ->assertSee('My Vouchers')
            ->assertSee('Log Out')
            ->assertSee("'en-GB'", false)
            ->assertDontSee('Selamat datang kembali,');
    }

    public function test_profile_and_vouchers_pages_follow_the_selected_language(): void
    {
        $this->actingAs(User::factory()->customer()->create());

        $this->get('/profile')->assertOk()
            ->assertSee('Pengaturan Akun')
            ->assertSee('Informasi Profil')
            ->assertSee('Ubah Kata Sandi')
            ->assertSee('No. WhatsApp')
            ->assertDontSee('Hapus Akun')
            ->assertDontSee('Account Settings');

        $this->get('/profile/vouchers')->assertOk()->assertSee('Belum ada voucher')->assertSee('Tersedia');

        $this->enCookie()->get('/profile')->assertOk()
            ->assertSee('Account Settings')
            ->assertSee('Profile Information')
            ->assertSee('Update Password')
            ->assertSee('WhatsApp Number')
            ->assertDontSee('Delete Account')
            ->assertDontSee('Pengaturan Akun');

        $this->get('/profile/vouchers')->assertOk()->assertSee('No vouchers yet')->assertSee('Available');
    }

    public function test_forgot_password_page_follows_the_selected_language(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Lupa Kata Sandi')->assertSee('c61-lang-switch', false);
        $this->enCookie()->get('/forgot-password')->assertOk()->assertSee('Forgot Password')->assertSee('Send Reset Link');
    }
}
