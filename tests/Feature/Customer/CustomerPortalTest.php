<?php

namespace Tests\Feature\Customer;

use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Pos\Voucher;
use App\Models\User;
use App\Services\Customer\CustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Modul 23: navigasi, Profile (kartu member) & Voucher Saya — tab Tersedia / Riwayat. */
class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->customer()->create(['name' => 'Budi Santoso']);
    }

    private function credit(array $attrs, ?User $owner = null): Voucher
    {
        return Voucher::create(array_merge([
            'user_id' => ($owner ?? $this->customer)->id,
            'code' => 'KR-'.strtoupper(\Illuminate\Support\Str::random(8)),
            'discount_type' => Voucher::TYPE_CREDIT,
            'discount_value' => 150000,
            'balance' => 150000,
            'valid_until' => now()->addMonths(6),
            'is_active' => true,
        ], $attrs));
    }

    private function membership(): UserMembership
    {
        $plan = MembershipPlan::create(['code' => 'MBR-GOLD', 'name' => 'Gold Club', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 1000000, 'is_active' => true]);

        return UserMembership::create([
            'membership_code' => 'MBR-0001', 'owner_type' => 'INDIVIDUAL', 'user_id' => $this->customer->id, 'plan_id' => $plan->id,
            'status' => 'ACTIVE', 'start_date' => now()->subDay(), 'end_date' => now()->addDays(20), 'qr_pass_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_my_vouchers_requires_login(): void
    {
        $this->get('/profile/vouchers')->assertRedirect('/login');
    }

    public function test_available_and_history_tabs_show_only_the_customers_own_vouchers(): void
    {
        $usable = $this->credit(['code' => 'KR-USABLE01']);
        $soon = $this->credit(['code' => 'KR-SOON0001', 'valid_until' => now()->addDays(3)]);
        $this->credit(['code' => 'KR-USEDUP01', 'balance' => 0]);
        $this->credit(['code' => 'KR-EXPIRED1', 'valid_until' => now()->subDay()]);
        $this->credit(['code' => 'KR-OFF00001', 'is_active' => false]);
        $this->credit(['code' => 'KR-OTHER001'], User::factory()->customer()->create());

        $available = $this->actingAs($this->customer)->get('/profile/vouchers')->assertOk();
        $available->assertSeeInOrder(['KR-SOON0001', 'KR-USABLE01'])  // paling cepat berakhir dulu
            ->assertSee('Berakhir dalam 3 hari')
            ->assertDontSee('KR-USEDUP01')->assertDontSee('KR-OTHER001');

        $history = $this->get('/profile/vouchers?tab=history')->assertOk();
        $history->assertSee('KR-USEDUP01')->assertSee('Sudah habis')
            ->assertSee('KR-EXPIRED1')->assertSee('Kedaluwarsa')
            ->assertSee('KR-OFF00001')->assertSee('Dinonaktifkan')
            ->assertDontSee('KR-USABLE01')->assertDontSee('KR-OTHER001');

        $this->assertSame(2, app(CustomerAccountService::class)->availableVoucherCount($this->customer));
        $this->assertNotNull($usable->id && $soon->id);
    }

    public function test_booking_calendar_input_can_be_tapped_directly_on_ios(): void
    {
        // iOS (semua browser = WebKit) hanya membuka pemilih tanggal bila input tanggal disentuh langsung — dulu input
        // disembunyikan (pointer-events-none) di dalam tombol, jadi kalender tidak pernah terbuka di iPhone.
        $html = $this->actingAs($this->customer)->get(route('customer.booking'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div[^>]*data-calendar-picker/', $html);
        preg_match('/<input type="date" x-ref="calendarInput"[^>]*>/', $html, $input);
        $this->assertNotEmpty($input, 'input tanggal kalender ada');
        $this->assertStringNotContainsString('pointer-events-none', $input[0]);
        $this->assertStringNotContainsString('aria-hidden', $input[0]);
        $this->assertStringContainsString('@click="openCalendarPicker()"', $input[0]);
        $this->assertStringContainsString('font-size:16px', $input[0], 'iPhone tidak zoom saat kalender dibuka');
    }

    public function test_empty_voucher_page_offers_booking(): void
    {
        $this->actingAs($this->customer)->get('/profile/vouchers')->assertOk()
            ->assertSee('Belum ada voucher')->assertSee(route('customer.booking'), false);
    }

    public function test_profile_shows_member_pass_and_voucher_count(): void
    {
        $this->membership();
        $this->credit([]);

        $this->actingAs($this->customer)->get('/profile')->assertOk()
            ->assertSee('Member Pass Digital')->assertSee('MBR-0001')
            ->assertSee('data-member-qr="'.str_repeat('a', 64).'"', false)
            ->assertSee('1 tersedia')
            ->assertSee(route('profile.vouchers'), false);
    }

    public function test_profile_invites_non_members_to_join(): void
    {
        $this->actingAs($this->customer)->get('/profile')->assertOk()
            ->assertSee('Jadi member Club 61')->assertDontSee('Member Pass Digital');
    }

    public function test_navigation_has_membership_tab_and_account_dropdown(): void
    {
        $this->credit([]);
        $page = $this->actingAs($this->customer)->get('/my-club')->assertOk();

        // Navigasi bawah HP: Membership menggantikan Profile; dropdown akun berisi Profile, Voucher Saya (angka), Logout.
        $page->assertSee(route('customer.membership'), false)
            ->assertSee('Voucher Saya')->assertSee('Keluar')
            ->assertSee('Belum menjadi member');
    }

    public function test_my_club_only_shows_a_membership_status_line(): void
    {
        $this->membership();
        $this->credit(['code' => 'KR-NOTHERE1']);

        $this->actingAs($this->customer)->get('/my-club')->assertOk()
            ->assertSee('member')->assertSee('Lihat di Profil')
            ->assertDontSee('Member Pass Digital')
            ->assertDontSee('KR-NOTHERE1')
            ->assertDontSee('My Credit Vouchers');
    }

    /** Meeting 28 Okt 2026: nama fasilitas di portal customer "Wellness", bukan "Sauna". */
    public function test_customer_pages_say_wellness_instead_of_sauna(): void
    {
        foreach (['/my-club', '/dashboard'] as $url) {
            $page = $this->actingAs($this->customer)->get($url)->assertOk();
            $this->assertStringNotContainsStringIgnoringCase('sauna', $page->getContent(), $url);
        }
        $this->actingAs($this->customer)->get('/my-club')->assertSee('Protokol Wellness');
    }
}
