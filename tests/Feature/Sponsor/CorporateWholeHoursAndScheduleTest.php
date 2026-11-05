<?php

namespace Tests\Feature\Sponsor;

use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\Sponsor\SponsorMemberVoucher;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Dashboard Tim Corporate: jam voucher selalu bilangan bulat (lapangan dibooking per jam), Jadwal Tetap Mingguan
 * organisasi ikut tampil di kartu Jadwal Akses Main Tim, dan menu Tim Sponsor bisa dibuka dari HP.
 */
class CorporateWholeHoursAndScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected User $pic;

    protected SponsorOrganization $org;

    protected SponsorOrganizationMember $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pic = User::factory()->create();
        $plan = MembershipPlan::create([
            'code' => 'MBR-CORP-'.uniqid(), 'name' => 'IM3 Platinum', 'ownership_type' => 'ORGANIZATIONAL',
            'duration_days' => 365, 'price' => 50000000, 'is_active' => true,
        ]);
        $membership = UserMembership::create([
            'membership_code' => 'MBR-CORP-'.uniqid(), 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $this->pic->id,
            'plan_id' => $plan->id, 'status' => 'ACTIVE', 'start_date' => now(), 'end_date' => now()->addYear(),
        ]);
        $this->org = SponsorOrganization::create([
            'name' => 'Indosat Im3 Platinum', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $this->pic->id,
        ]);
        UserMembershipBalance::create([
            'user_membership_id' => $membership->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'initial_quota' => 200, 'remaining_quota' => 200,
        ]);
        $employee = User::factory()->create(['name' => 'Budi Karyawan', 'phone' => '081234500001']);
        $this->member = SponsorOrganizationMember::create(['sponsor_organization_id' => $this->org->id, 'user_id' => $employee->id, 'status' => 'ACTIVE']);
    }

    public function test_half_hours_are_rejected_on_every_path_and_whole_hours_still_work(): void
    {
        $this->actingAs($this->pic);

        $this->postJson("/api/v1/sponsor/organization/members/{$this->member->id}/release-voucher", ['hours' => 1.5])
            ->assertStatus(422)->assertJsonPath('message', 'Jam voucher harus bilangan bulat (1, 2, 3, ...) — tidak bisa setengah jam.');
        $this->postJson('/api/v1/sponsor/organization/members', ['name' => 'Siti Rahma', 'phone' => '081399887766', 'initial_hours' => 2.5])
            ->assertStatus(422);
        $this->assertFalse(User::where('phone', '081399887766')->exists(), 'tambah karyawan dibatalkan utuh');

        $bulk = $this->postJson('/api/v1/sponsor/organization/members/bulk-release-voucher', ['mode' => 'uniform', 'hours' => 0.5])->assertOk();
        $this->assertSame(0, $bulk->json('data.released'));
        $this->assertSame(0, SponsorMemberVoucher::count());

        $this->postJson("/api/v1/sponsor/organization/members/{$this->member->id}/release-voucher", ['hours' => 3])->assertStatus(201);
        $voucher = SponsorMemberVoucher::sole();
        $this->patchJson("/api/v1/sponsor/organization/vouchers/{$voucher->id}", ['hours_granted' => 2.5])->assertStatus(422);
        $this->patchJson("/api/v1/sponsor/organization/vouchers/{$voucher->id}", ['hours_granted' => 4])->assertOk();
        $this->assertEquals(4, (float) $voucher->fresh()->hours_granted);

        $csv = "name,phone,hours\nRina Satu,081211110001,1.5\nDodi Dua,081211110002,2\n";
        $data = $this->post('/api/v1/sponsor/organization/members/import-csv', [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv),
        ])->assertOk()->json('data');
        $this->assertSame(1, $data['vouchers_issued']);
        $this->assertCount(1, $data['failed_rows']);
        $this->assertSame(2, $data['failed_rows'][0]['row']);
        $this->assertStringContainsString('bilangan bulat', $data['failed_rows'][0]['reason']);
    }

    public function test_dashboard_shows_whole_numbers_and_whole_hour_steppers(): void
    {
        $this->actingAs($this->pic)->get('/corporate')->assertOk()
            ->assertSee('Kuota kontrak 200 jam')
            ->assertDontSee('200.0')
            ->assertSee('step="1"', false)
            ->assertDontSee('step="0.5"', false)
            ->assertDontSee("stepValue('initial_hours_input', 0.5", false);
    }

    public function test_weekly_fixed_schedule_of_the_contract_membership_is_shown(): void
    {
        $court = PadelCourt::create(['name' => 'Court IOH', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'open_time' => '06:00', 'close_time' => '24:00', 'is_active' => true]);
        $base = ['user_membership_id' => $this->org->user_membership_id, 'court_ids' => [$court->id], 'starts_on' => now()->toDateString(), 'weeks_ahead' => 4];
        PadelRecurringSchedule::create($base + ['name' => 'IOH Kamis', 'day_of_week' => 4, 'start_time' => '19:00', 'end_time' => '22:00', 'status' => PadelRecurringSchedule::STATUS_ACTIVE]);
        PadelRecurringSchedule::create($base + ['name' => 'Lama', 'day_of_week' => 2, 'start_time' => '07:00', 'end_time' => '08:00', 'status' => PadelRecurringSchedule::STATUS_STOPPED]);

        $this->actingAs($this->pic)->get('/corporate')->assertOk()
            ->assertSee('Jadwal Tetap Mingguan')
            ->assertSee('Setiap Kamis')
            ->assertSee('19:00')
            ->assertSee('Court IOH')
            ->assertDontSee('Setiap Selasa')
            ->assertDontSee('Jadwal akses untuk tim Anda belum diatur.');
    }

    public function test_quota_used_by_the_weekly_fixed_schedule_is_shown_in_the_summary(): void
    {
        $this->actingAs($this->pic)->get('/corporate')->assertOk()
            ->assertDontSee('data-recurring-hours', false)
            ->assertSee('Kuota kontrak 200 jam: 0 jam sudah diberikan ke 1 karyawan aktif dan 200 jam masih bisa diberikan.');

        $court = PadelCourt::create(['name' => 'Court IOH', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
        $schedule = PadelRecurringSchedule::create(['name' => 'IOH Minggu', 'user_membership_id' => $this->org->user_membership_id, 'court_ids' => [$court->id], 'starts_on' => now()->toDateString(), 'weeks_ahead' => 4, 'day_of_week' => 7, 'start_time' => '18:00', 'end_time' => '20:00', 'status' => PadelRecurringSchedule::STATUS_ACTIVE]);
        $balance = UserMembershipBalance::sole();
        $balance->update(['remaining_quota' => 194]);
        $booking = fn (string $code, float $hours, string $status = 'PAID', bool $recurring = true) => (new PadelBooking)->forceFill([
            'booking_code' => $code, 'user_id' => $this->pic->id, 'court_id' => $court->id, 'booking_date' => now()->toDateString(),
            'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHours(2), 'court_fee' => 0, 'total_amount' => 0, 'status' => $status,
            'recurring_schedule_id' => $recurring ? $schedule->id : null, 'membership_balance_id' => $balance->id, 'member_hours_consumed' => $hours,
        ])->save();
        $booking('BK-REC-1', 2);
        $booking('BK-REC-2', 2);
        $booking('BK-REC-3', 2, 'COMPLETED');
        $booking('BK-REC-4', 0, 'CANCELLED');
        $booking('BK-OWN-1', 1, 'PAID', false);

        $this->actingAs($this->pic)->get('/corporate')->assertOk()
            ->assertSee('data-recurring-hours', false)
            ->assertSee('Jam Jadwal Tetap')
            ->assertSee('6 jam dipakai Jadwal Tetap Mingguan, dan 194 jam masih bisa diberikan.')
            ->assertSee('dipotong saat booking mingguannya dibuat');
    }

    public function test_sponsor_team_menu_is_reachable_on_mobile_for_the_pic_only(): void
    {
        $this->actingAs($this->pic)->get('/corporate')->assertOk()->assertSee('data-nav-sponsor-mobile', false);
        $this->actingAs(User::factory()->create())->get('/corporate')->assertOk()->assertDontSee('data-nav-sponsor-mobile', false);
    }
}
