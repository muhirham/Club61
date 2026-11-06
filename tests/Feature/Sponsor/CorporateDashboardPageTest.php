<?php

namespace Tests\Feature\Sponsor;

use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Sponsor\SponsorAccessSchedule;
use App\Models\Sponsor\SponsorMemberVoucher;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorporateDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOrganization(User $pic): SponsorOrganization
    {
        $plan = MembershipPlan::create([
            'code' => 'MBR-CORP-'.uniqid(),
            'name' => 'Corporate B2B Padel',
            'ownership_type' => 'ORGANIZATIONAL',
            'duration_days' => 365,
            'price' => 50000000.00,
            'is_active' => true,
        ]);
        $membership = UserMembership::create([
            'membership_code' => 'MBR-CORP-'.uniqid(),
            'owner_type' => 'ORGANIZATIONAL',
            'user_id' => $pic->id,
            'plan_id' => $plan->id,
            'status' => 'ACTIVE',
            'start_date' => now(),
            'end_date' => now()->addYear(),
        ]);

        return SponsorOrganization::create([
            'name' => 'PT Dashboard Test',
            'user_membership_id' => $membership->id,
            'sponsor_admin_user_id' => $pic->id,
        ]);
    }

    /**
     * Kuota kontrak diambil dari benefit paket membership (facility PADEL, quota_type HOURS),
     * bukan field terpisah di SponsorOrganization — simulasikan itu lewat UserMembershipBalance.
     */
    protected function givePadelHourQuota(SponsorOrganization $org, float $hours): UserMembershipBalance
    {
        return UserMembershipBalance::create([
            'user_membership_id' => $org->user_membership_id,
            'facility' => 'PADEL',
            'quota_type' => 'HOURS',
            'initial_quota' => $hours,
            'remaining_quota' => $hours,
        ]);
    }

    public function test_non_pic_user_sees_empty_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/corporate');

        $response->assertStatus(200)
            ->assertSee('Anda belum terdaftar sebagai PIC akun corporate mana pun.');
    }

    public function test_pic_sees_organization_summary_roster_and_schedule(): void
    {
        $pic = User::factory()->create();
        $org = $this->makeOrganization($pic);

        $employee = User::factory()->create(['name' => 'Budi Karyawan', 'phone' => '081234500001']);
        $member = SponsorOrganizationMember::create([
            'sponsor_organization_id' => $org->id,
            'user_id' => $employee->id,
            'status' => 'ACTIVE',
        ]);
        SponsorMemberVoucher::create([
            'sponsor_organization_member_id' => $member->id,
            'hours_granted' => 8,
            'hours_used' => 3,
            'issued_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        SponsorAccessSchedule::create([
            'sponsor_organization_id' => $org->id,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
            'time_start' => '08:00',
            'time_end' => '16:00',
            'max_concurrent_courts' => 1,
        ]);

        $response = $this->actingAs($pic)->get('/corporate');

        $response->assertStatus(200)
            ->assertSee('PT Dashboard Test')
            ->assertSee('Budi Karyawan')
            ->assertSee('081234500001')
            ->assertSee('5 jam') // 8 granted - 3 used (jam bulat, tanpa ".0")
            ->assertSee('Maks. 1 lapangan sekaligus');
    }

    public function test_bulk_release_modal_lists_active_members_and_excludes_revoked(): void
    {
        $pic = User::factory()->create();
        $org = $this->makeOrganization($pic);

        $active = User::factory()->create(['name' => 'Aktif Karyawan', 'phone' => '081234500010']);
        $activeMember = SponsorOrganizationMember::create([
            'sponsor_organization_id' => $org->id, 'user_id' => $active->id, 'status' => 'ACTIVE',
        ]);
        $revoked = User::factory()->create(['name' => 'Sudah Keluar', 'phone' => '081234500011']);
        $revokedMember = SponsorOrganizationMember::create([
            'sponsor_organization_id' => $org->id, 'user_id' => $revoked->id, 'status' => 'REVOKED',
        ]);

        $response = $this->actingAs($pic)->get('/corporate');

        $response->assertStatus(200)
            ->assertSee('Beri Jam ke Beberapa Karyawan')
            ->assertSee('bulk-hours-'.$activeMember->id, false)
            ->assertDontSee('bulk-hours-'.$revokedMember->id, false);
    }

    public function test_recent_manual_release_is_embedded_for_double_click_warning(): void
    {
        $pic = User::factory()->create();
        $org = $this->makeOrganization($pic);

        $employee = User::factory()->create(['name' => 'Rifki', 'phone' => '081234500012']);
        $member = SponsorOrganizationMember::create([
            'sponsor_organization_id' => $org->id, 'user_id' => $employee->id, 'status' => 'ACTIVE',
        ]);
        SponsorMemberVoucher::create([
            'sponsor_organization_member_id' => $member->id,
            'hours_granted' => 5, 'hours_used' => 0,
            'issued_at' => now()->subMinutes(10), 'expires_at' => now()->addMonth(),
            'source' => 'MANUAL_RELEASE',
        ]);

        $response = $this->actingAs($pic)->get('/corporate');

        $response->assertStatus(200)->assertSee('data-recent-release="5 jam pukul', false);
    }

    public function test_dashboard_shows_contract_quota_breakdown_when_quota_is_set(): void
    {
        $pic = User::factory()->create();
        $org = $this->makeOrganization($pic);
        // Kuota kontrak 200 jam, sudah 41 jam dirilis lewat voucher di bawah (sisa 159).
        UserMembershipBalance::create([
            'user_membership_id' => $org->user_membership_id,
            'facility' => 'PADEL',
            'quota_type' => 'HOURS',
            'initial_quota' => 200,
            'remaining_quota' => 159,
        ]);

        $employee = User::factory()->create(['name' => 'Andi', 'phone' => '081234500050']);
        $member = SponsorOrganizationMember::create([
            'sponsor_organization_id' => $org->id, 'user_id' => $employee->id, 'status' => 'ACTIVE',
        ]);
        SponsorMemberVoucher::create([
            'sponsor_organization_member_id' => $member->id,
            'hours_granted' => 41, 'hours_used' => 0,
            'issued_at' => now(), 'expires_at' => now()->addMonth(),
        ]);

        $response = $this->actingAs($pic)->get('/corporate');

        $response->assertStatus(200)
            ->assertSee('Kuota Kontrak')
            ->assertSee('Kuota kontrak 200 jam')
            ->assertSee('Sisa Kuota')
            ->assertSee('159 jam masih bisa diberikan')
            ->assertSee('Jam Diberikan')
            ->assertSee('41 jam sudah diberikan')->assertDontSee('200.0');
    }

    public function test_dashboard_explains_plan_without_hour_quota_cannot_release(): void
    {
        $pic = User::factory()->create();
        $this->makeOrganization($pic); // no PADEL/HOURS balance configured on the plan

        $response = $this->actingAs($pic)->get('/corporate');

        // Dulu tertulis "Unlimited" — sekarang paket tanpa kuota jam tidak bisa merilis jam sama sekali.
        $response->assertStatus(200)->assertSeeInOrder(['Kuota Kontrak', 'Tanpa kuota jam'])
            ->assertSee('jam belum bisa diberikan', false)->assertDontSee('Unlimited');
    }

    public function test_roster_is_paginated_when_team_is_large(): void
    {
        $pic = User::factory()->create();
        $org = $this->makeOrganization($pic);

        for ($i = 0; $i < 15; $i++) {
            $employee = User::factory()->create(['name' => 'Employee '.$i, 'phone' => '08123450'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
            SponsorOrganizationMember::create([
                'sponsor_organization_id' => $org->id, 'user_id' => $employee->id, 'status' => 'ACTIVE',
            ]);
        }

        $page1 = $this->actingAs($pic)->get('/corporate');
        $page1->assertStatus(200)
            ->assertSee('Halaman 1 dari 2', false)
            ->assertSee('Menampilkan 1–10 dari 15 karyawan', false);

        $page2 = $this->actingAs($pic)->get('/corporate?page=2');
        $page2->assertStatus(200)->assertSee('Employee 14');
    }

    public function test_roster_search_filters_by_name_or_phone_and_does_not_affect_summary_counts(): void
    {
        $pic = User::factory()->create();
        $org = $this->makeOrganization($pic);

        $rifki = User::factory()->create(['name' => 'Rifki Ganteng', 'phone' => '081700112235']);
        SponsorOrganizationMember::create(['sponsor_organization_id' => $org->id, 'user_id' => $rifki->id, 'status' => 'ACTIVE']);
        $rudito = User::factory()->create(['name' => 'Rudito Keren', 'phone' => '081700112236']);
        SponsorOrganizationMember::create(['sponsor_organization_id' => $org->id, 'user_id' => $rudito->id, 'status' => 'ACTIVE']);

        $response = $this->actingAs($pic)->get('/corporate?search=Rifki');

        // The bulk-release modal legitimately still lists Rudito by name (it always covers the
        // WHOLE active roster, independent of search/pagination), so scope the "filtered out"
        // check to his phone number, which only ever renders inside the roster table/cards.
        $response->assertStatus(200)
            ->assertSee('Rifki Ganteng')
            ->assertSee('081700112235')
            ->assertDontSee('081700112236')
            ->assertSee('1 karyawan ditemukan', false)
            // Summary card / bulk modal must still count the WHOLE active roster, not just the filtered page.
            ->assertSee('bulk-hours-'.$org->members()->where('user_id', $rudito->id)->first()->id, false);
    }

    public function test_nav_link_only_visible_for_pic_users(): void
    {
        $pic = User::factory()->create();
        $this->makeOrganization($pic);
        $regular = User::factory()->create();

        $this->actingAs($pic)->get('/dashboard')->assertSee('Tim Sponsor');
        $this->actingAs($regular)->get('/dashboard')->assertDontSee('Tim Sponsor');
    }

    public function test_sample_csv_can_be_downloaded(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/corporate/sample-csv');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertSee('name,phone,hours', false);
    }
}
