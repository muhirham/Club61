<?php

namespace Tests\Feature\Security;

use App\Models\Padel\PadelCourt;
use App\Models\Setting\CompanyProfileSetting;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Perbaikan audit keamanan pra-launch (10 Okt 2026): C3, H2, H3, H4, H5.
 */
class PreLaunchHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, array $attrs = []): User
    {
        static $seq = 0;
        $seq++;

        return User::create(array_merge([
            'name' => "User {$role} {$seq}",
            'email' => strtolower($role)."{$seq}@club61.test",
            'phone' => '0812990000'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
            'password' => bcrypt('Rahasia#2026'),
            'role' => $role,
            'is_active' => true,
        ], $attrs));
    }

    // ---------- C3: XSS layar check-in kasir ----------

    public function test_pos_checkin_result_escapes_server_values_before_innerhtml(): void
    {
        $cashier = $this->makeUser('CASHIER');

        $html = $this->actingAs($cashier)->get('/pos')->assertOk()->getContent();

        $this->assertStringContainsString('function escHtml(', $html);
        foreach (['res.player_name', 'res.court_name', 'res.booking_code', 'res.schedule', 'e.name', 'data.message', 'err.message'] as $value) {
            $this->assertStringNotContainsString('${'.$value, $html, "{$value} masuk innerHTML tanpa escape");
        }
    }

    // ---------- H2: akun nonaktif ----------

    public function test_inactive_user_cannot_log_in_on_web(): void
    {
        $user = $this->makeUser('CASHIER', ['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Rahasia#2026'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivating_logged_in_cashier_kicks_them_out_of_pos(): void
    {
        $cashier = $this->makeUser('CASHIER');
        $this->actingAs($cashier)->get('/pos')->assertOk();

        $cashier->update(['is_active' => false]);

        $this->get('/pos')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_check_in_tickets_via_pos(): void
    {
        $cashier = $this->makeUser('CASHIER');
        $cashier->update(['is_active' => false]);

        $this->actingAs($cashier)->postJson('/pos/check-in', ['code' => 'BK-PAD-XXXX'])->assertForbidden();
    }

    public function test_deactivation_revokes_api_tokens(): void
    {
        $user = $this->makeUser('CUSTOMER');
        $user->createToken('app');
        $this->assertSame(1, $user->tokens()->count());

        $user->update(['is_active' => false]);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_api_token_of_inactive_user_is_rejected(): void
    {
        $user = $this->makeUser('CUSTOMER');
        $token = $user->createToken('app')->plainTextToken;
        // Dinonaktifkan tanpa event model (mis. diubah langsung di DB) — middleware tetap menolak.
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_active_user_api_token_still_works(): void
    {
        $user = $this->makeUser('CUSTOMER');
        $token = $user->createToken('app')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    // ---------- H3: URL CMS ----------

    public function test_maps_embed_url_must_be_https_google_maps(): void
    {
        $this->assertFalse(CompanyProfileSetting::isSafeMapsEmbedUrl('javascript://x%0Aalert(document.domain)'));
        $this->assertFalse(CompanyProfileSetting::isSafeMapsEmbedUrl('http://www.google.com/maps/embed?pb=1'));
        $this->assertFalse(CompanyProfileSetting::isSafeMapsEmbedUrl('https://evil.example/maps/embed'));
        $this->assertFalse(CompanyProfileSetting::isSafeMapsEmbedUrl('data:text/html,<script>alert(1)</script>'));
        $this->assertTrue(CompanyProfileSetting::isSafeMapsEmbedUrl('https://www.google.com/maps/embed?pb=!1m18'));
    }

    public function test_social_links_must_be_http_or_https(): void
    {
        $this->assertFalse(CompanyProfileSetting::isSafeWebUrl('javascript:alert(1)'));
        $this->assertFalse(CompanyProfileSetting::isSafeWebUrl('javascript://x%0Aalert(1)'));
        $this->assertTrue(CompanyProfileSetting::isSafeWebUrl('https://instagram.com/club61'));
    }

    public function test_homepage_ignores_unsafe_cms_urls_already_stored(): void
    {
        $settings = CompanyProfileSetting::current();
        $settings->maps_embed_url = 'javascript://x%0Aalert(document.domain)';
        $settings->footer_social_links = ['instagram' => 'javascript:alert(document.cookie)', 'tiktok' => 'https://tiktok.com/@club61'];
        $settings->save();

        $this->get('/')->assertOk()
            ->assertDontSee('alert(document.domain)', false)
            ->assertDontSee('alert(document.cookie)', false)
            ->assertSee('https://tiktok.com/@club61', false)
            ->assertSee('https://www.google.com/maps?q=', false);
    }

    // ---------- H4: grid jam bulat & kunci lapangan ----------

    public function test_hold_rejects_off_grid_start_time(): void
    {
        $user = $this->makeUser('CUSTOMER');
        $court = PadelCourt::create([
            'name' => 'Court Uji', 'court_type' => 'INDOOR', 'surface_type' => 'MONDO_SUPERCOURT',
            'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 450000, 'is_active' => true,
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('jam bulat');

        app(PadelBookingService::class)->holdBatchSlots(
            [['court_id' => $court->id, 'start_time' => '19:30', 'end_time' => '20:30']],
            now()->addDay()->toDateString(),
            $user,
        );
    }

    // ---------- H5: brute force login web ----------

    public function test_identifier_variants_share_one_login_limit(): void
    {
        $user = $this->makeUser('CASHIER', ['phone' => '081234500001', 'email' => 'kasir.limit@club61.test']);

        // Email, nomor HP lokal, dan +62 untuk akun yang sama dulu dapat jatah masing-masing.
        foreach (['kasir.limit@club61.test', '081234500001', '+6281234500001', 'KASIR.LIMIT@club61.test', '6281234500001'] as $variant) {
            $this->post('/login', ['email' => $variant, 'password' => 'salah-password'])->assertSessionHasErrors('email');
        }

        // Percobaan ke-6 dengan password BENAR tetap ditahan.
        $this->post('/login', ['email' => '081234500001', 'password' => 'Rahasia#2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_rotating_ip_does_not_bypass_per_account_limit(): void
    {
        $user = $this->makeUser('CASHIER', ['email' => 'kasir.ip@club61.test']);

        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post('/login', ['email' => $user->email, 'password' => 'salah-password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.99'])
            ->post('/login', ['email' => $user->email, 'password' => 'Rahasia#2026'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_successful_login_still_works_after_a_few_typos(): void
    {
        $user = $this->makeUser('CASHIER', ['email' => 'kasir.ok@club61.test']);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'Rahasia#2026']);
        $this->assertAuthenticatedAs($user);
    }
}
