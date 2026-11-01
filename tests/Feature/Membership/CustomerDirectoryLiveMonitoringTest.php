<?php

namespace Tests\Feature\Membership;

use App\Filament\Pages\Kustomer;
use App\Models\Membership\FacilityCheckin;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Membership\UserMembership;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerDirectoryLiveMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\Permission\Club61PermissionMatrix::syncAllPermissions('web');
        \App\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions(
            \App\Services\Permission\Club61PermissionMatrix::getAllPermissionSlugs()
        );
    }

    public function test_kustomer_page_renders_live_members_and_monitoring_logs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $plan = MembershipPlan::create([
            'code' => 'MBR-TEST',
            'name' => 'Test Plan',
            'ownership_type' => 'INDIVIDUAL',
            'duration_days' => 30,
            'price' => 1000000.00,
            'is_active' => true,
        ]);
        MembershipPlanBenefit::create([
            'plan_id' => $plan->id,
            'facility' => 'PADEL',
            'quota_type' => 'HOURS',
            'quota_value' => 10.00,
            'discount_percent' => 10.00,
        ]);

        $customer = User::factory()->create(['name' => 'Budi Testing']);

        $service = app(\App\Services\Membership\MembershipBalanceService::class);
        $membership = $service->purchasePlan($customer, $plan, ['status' => 'ACTIVE']);
        $service->activateMembership($membership);

        Livewire::actingAs($admin)
            ->test(Kustomer::class)
            ->assertSee('Budi Testing')
            ->assertSee($membership->membership_code)
            ->set('activeTab', 'live_monitoring')
            ->assertSee('Aktivasi membership paket');
    }

    public function test_individual_member_detail_displays_habit_analysis_and_padel_schedule(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $court = PadelCourt::create([
            'name' => 'Court 1 - Panoramic Indoor',
            'type' => 'INDOOR',
            'hourly_rate_regular' => 300000.00,
            'hourly_rate_prime' => 450000.00,
        ]);

        $plan = MembershipPlan::create([
            'code' => 'MBR-SILVER-TEST',
            'name' => 'Silver Padel Addict',
            'ownership_type' => 'INDIVIDUAL',
            'duration_days' => 30,
            'price' => 3500000.00,
            'is_active' => true,
        ]);

        $customer = User::factory()->create(['name' => 'Budi Habit Test', 'phone' => '081234567890']);

        $service = app(\App\Services\Membership\MembershipBalanceService::class);
        $membership = $service->purchasePlan($customer, $plan, ['status' => 'ACTIVE']);
        $service->activateMembership($membership);

        PadelBooking::create([
            'booking_code' => 'BK-TEST-001',
            'user_id' => $customer->id,
            'court_id' => $court->id,
            'booking_date' => now()->toDateString(),
            'start_time' => now()->setTime(17, 0),
            'end_time' => now()->setTime(19, 0),
            'court_fee' => 0.00,
            'total_amount' => 0.00,
            'status' => 'CHECKED_IN',
            'checked_in_at' => now()->setTime(16, 55),
            'member_hours_consumed' => 2.00,
        ]);

        Livewire::actingAs($admin)
            ->test(Kustomer::class)
            ->call('openDetail', $membership->id)
            ->assertSee('Analisis Kebiasaan & Pola Bermain')
            ->assertSee('Jadwal & Riwayat Reservasi Lapangan Padel')
            ->assertSee('Court 1 - Panoramic Indoor')
            ->assertSee('BK-TEST-001')
            ->assertSee('CHECKED IN')
            ->assertSee('-2 Jam Kuota')
            ->assertSee('17:00 - 18:00 WIB (1x booking)', false)
            ->assertDontSee('Sore & Malam (16:00 - 20:00 WIB)');
    }

    public function test_corporate_member_detail_displays_employee_roster_and_idle_status(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $plan = MembershipPlan::create([
            'code' => 'MBR-CORP-TEST',
            'name' => 'Corporate Sponsor Plan',
            'ownership_type' => 'ORGANIZATIONAL',
            'duration_days' => 90,
            'price' => 25000000.00,
            'is_active' => true,
        ]);
        MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 100]);

        $corpUser = User::factory()->create(['name' => 'PT Maju Bersama', 'phone' => '081311112222']);

        $service = app(\App\Services\Membership\MembershipBalanceService::class);
        $membership = $service->purchasePlan($corpUser, $plan->fresh('benefits'), [
            'owner_type' => 'ORGANIZATIONAL',
            'status' => 'ACTIVE',
        ]);
        $membership = $service->activateMembership($membership);

        // Belum ada anggota & jadwal tetap → pesan kosong, tanpa data contoh; kuota kartu tetap tampil.
        Livewire::actingAs($admin)
            ->test(Kustomer::class)
            ->call('openDetail', $membership->id)
            ->assertSee('Corporate Sponsor Pool System')
            ->assertSee('100,0 Jam')
            ->assertSee('Kartu ini belum memiliki Jadwal Tetap Mingguan')
            ->assertSee('Paket ini belum memakai daftar anggota karyawan')
            ->assertDontSee('sinarharapan')
            ->assertDontSee('Budi Pratama')
            ->assertDontSee('Kirim Pengingat via WhatsApp');

        // Sponsor tanpa anggota (mis. IM3): kuota dipakai lewat Jadwal Tetap Mingguan atas nama kartu.
        $court = PadelCourt::create(['name' => 'Court BCA', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000]);
        $padel = $membership->balances()->where('facility', 'PADEL')->sole();
        $recurring = \App\Models\Padel\PadelRecurringSchedule::create([
            'name' => 'Corporate Kamis Malam', 'user_membership_id' => $membership->id, 'day_of_week' => 4, 'start_time' => '19:00', 'end_time' => '21:00',
            'court_ids' => [$court->id], 'starts_on' => now()->toDateString(), 'weeks_ahead' => 2,
            'status' => \App\Models\Padel\PadelRecurringSchedule::STATUS_ACTIVE, 'created_by_id' => $admin->id,
        ]);
        $nextThursday = now()->next(\Carbon\Carbon::THURSDAY)->setTime(19, 0);
        PadelBooking::create([
            'booking_code' => 'BK-JT-1', 'user_id' => $corpUser->id, 'court_id' => $court->id,
            'membership_balance_id' => $padel->id, 'member_hours_consumed' => 2,
            'booking_date' => $nextThursday->toDateString(), 'start_time' => $nextThursday, 'end_time' => $nextThursday->copy()->addHours(2),
            'court_fee' => 0, 'total_amount' => 0, 'status' => 'PAID',
        ])->forceFill(['recurring_schedule_id' => $recurring->id])->save();
        $service->adjustQuota($padel->id, 'DECREMENT', 2, 'Jadwal Tetap');

        Livewire::actingAs($admin)
            ->test(Kustomer::class)
            ->call('openDetail', $membership->id)
            ->assertSee('Jadwal Tetap Mingguan (1)')
            ->assertSee('Corporate Kamis Malam')
            ->assertSee('Kamis, 19:00–21:00 WIB')
            ->assertSee($nextThursday->format('d M Y').', 19:00 - 21:00 WIB (Court BCA)')
            ->assertSee('2,0 Jam')
            ->assertSee('98,0 Jam')
            ->assertSee('Paket ini belum memakai daftar anggota karyawan')
            ->assertDontSee('Kirim Pengingat via WhatsApp');

        // Data asli dari Kelola Sponsor: 2 anggota, 1 sudah main memakai jatah perusahaan.
        $sponsor = app(\App\Services\Sponsor\SponsorOrganizationService::class);
        $organization = $sponsor->ensureOrganizationForMembership($membership->fresh());
        $rina = $sponsor->addMemberManually($organization, 'Rina Karyawan', '081277778888', 10, $admin);
        $sponsor->addMemberManually($organization, 'Doni Karyawan', '081299990000', 5, $admin);
        $rina->vouchers()->first()->update(['hours_used' => 2]);

        foreach ([['BK-RINA-1', now()->subDays(3)->setTime(19, 0), 'COMPLETED'], ['BK-RINA-2', now()->addDays(2)->setTime(8, 0), 'PAID']] as [$code, $start, $status]) {
            PadelBooking::create([
                'booking_code' => $code, 'user_id' => $rina->user_id, 'court_id' => $court->id, 'sponsor_organization_id' => $organization->id,
                'booking_date' => $start->toDateString(), 'start_time' => $start, 'end_time' => $start->copy()->addHours(2),
                'court_fee' => 0, 'total_amount' => 0, 'status' => $status,
            ]);
        }

        Livewire::actingAs($admin)
            ->test(Kustomer::class)
            ->call('openDetail', $membership->id)
            ->assertSee('PT Maju Bersama')
            ->assertSee('100,0 Jam')
            ->assertSee('15,0 Jam')
            ->assertSee('83,0 Jam')
            ->assertSee('Daftar Karyawan Terdaftar (2 Orang)')
            ->assertSee('Status: 1 Aktif Bermain')
            ->assertSee('Rina Karyawan')
            ->assertSee('AKTIF BERMAIN')
            ->assertSee(now()->subDays(3)->format('d M Y').', 19:00 - 21:00 WIB (Court BCA)')
            ->assertSee(now()->addDays(2)->format('d M Y').', 08:00 - 10:00 WIB (Court BCA)')
            ->assertSee('Doni Karyawan')
            ->assertSee('BELUM PERNAH MAIN')
            ->assertSee('Ada 1 Anggota Tim Belum Memakai Jatah Kuota')
            ->assertSee('wa.me/6281311112222', false)
            ->assertDontSee('sinarharapan');
    }

    public function test_online_membership_checkout_api_works_for_web_customer(): void
    {
        $plan = MembershipPlan::create([
            'code' => 'MBR-ONLINE',
            'name' => 'Online Gold Plan',
            'ownership_type' => 'INDIVIDUAL',
            'duration_days' => 30,
            'price' => 5000000.00,
            'is_active' => true,
        ]);
        MembershipPlanBenefit::create([
            'plan_id' => $plan->id,
            'facility' => 'PADEL',
            'quota_type' => 'HOURS',
            'quota_value' => 20.00,
            'discount_percent' => 20.00,
        ]);

        $customer = User::factory()->create(['name' => 'Online Customer']);

        $response = $this->actingAs($customer)
            ->postJson('/api/v1/membership/checkout', [
                'plan_id' => $plan->id,
                'payment_method' => 'QRIS',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // In mock simulator mode, it activates immediately
        $this->assertDatabaseHas('user_memberships', [
            'user_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_customer_my_club_page_renders_plans_and_links_to_membership(): void
    {
        $plan = MembershipPlan::create([
            'code' => 'MBR-VIP-01',
            'name' => 'Silver Padel Addict VIP',
            'ownership_type' => 'INDIVIDUAL',
            'duration_days' => 30,
            'price' => 3500000.00,
            'is_active' => true,
        ]);
        MembershipPlanBenefit::create([
            'plan_id' => $plan->id,
            'facility' => 'PADEL',
            'quota_type' => 'HOURS',
            'quota_value' => 10.00,
            'discount_percent' => 20.00,
        ]);

        $customer = User::factory()->create(['name' => 'Andi Wijaya VIP']);

        $response = $this->actingAs($customer)->get(route('customer.my-club'));

        $response->assertStatus(200)
            ->assertSee('Silver Padel Addict VIP')
            ->assertSee('Lihat Detail &amp; Beli Online', false);
    }

    public function test_customer_membership_page_renders_interactive_blade_without_modal(): void
    {
        $plan = MembershipPlan::create([
            'code' => 'MBR-VIP-02',
            'name' => 'Gold Ultimate VIP',
            'ownership_type' => 'INDIVIDUAL',
            'duration_days' => 30,
            'price' => 6000000.00,
            'is_active' => true,
        ]);
        MembershipPlanBenefit::create([
            'plan_id' => $plan->id,
            'facility' => 'PADEL',
            'quota_type' => 'HOURS',
            'quota_value' => 25.00,
            'discount_percent' => 30.00,
        ]);

        $customer = User::factory()->create(['name' => 'Budi Gold VIP']);

        $response = $this->actingAs($customer)->get(route('customer.membership', ['plan' => $plan->id]));

        $response->assertStatus(200)
            ->assertSee('Pilih &amp; Pembelian Paket Keanggotaan', false)
            ->assertSee('Gold Ultimate VIP')
            ->assertSee('Rincian Hak Akses &amp; Benefit yang Didapat', false)
            ->assertSee('Bayar Sekarang (Cashless)', false)
            ->assertSee('QRIS (GoPay / OVO / DANA / ShopeePay / m-Banking)', false)
            ->assertSee('BCA Virtual Account', false)
            ->assertDontSee('MIDTRANS_SNAP', false);
    }
}
