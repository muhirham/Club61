<?php

namespace Tests\Feature\Membership;

use App\Filament\Resources\Membership\Pages\ListMembershipPlans;
use App\Models\Audit\ActivityLog;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Membership\MembershipUsageLog;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\User;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Membership\MembershipPlanQuotaService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * "Terapkan Kuota & Masa Aktif" (izin apply_membership_plan_quota, bawaan super admin saja): kartu yang sudah terjual
 * disamakan dengan kuota & masa aktif paket terbaru setelah pratinjau. Kuota terpakai tetap dihitung, sisa tidak pernah
 * minus, tanggal berakhir yang sudah lewat tidak dipakai, tercatat di Audit Log Mutasi Kuota & Log Aktivitas.
 */
class MembershipPlanQuotaTest extends TestCase
{
    use RefreshDatabase;

    private MembershipPlan $plan;

    private MembershipPlanBenefit $padel;

    private MembershipBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->balances = app(MembershipBalanceService::class);
        $this->plan = MembershipPlan::create(['code' => 'IM3-PLAT', 'name' => 'IM3 Platinum', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 90, 'price' => 50000000, 'is_active' => true]);
        $this->padel = MembershipPlanBenefit::create(['plan_id' => $this->plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 200]);
        MembershipPlanBenefit::create(['plan_id' => $this->plan->id, 'facility' => 'GYM', 'quota_type' => 'VISITS', 'quota_value' => null]);
    }

    /** Kartu aktif sejak $startedDaysAgo hari lalu yang sudah memakai $used jam. */
    private function activeCard(int $startedDaysAgo, float $used): UserMembership
    {
        $card = $this->balances->purchasePlan(User::factory()->customer()->create(), $this->plan->fresh('benefits'));
        $card = $this->balances->activateMembership($card, Carbon::today()->subDays($startedDaysAgo));
        if ($used > 0) {
            $this->balances->adjustQuota($card->balances->firstWhere('facility', 'PADEL')->id, 'DECREMENT', $used, 'Main');
        }

        return $card->fresh('balances');
    }

    public function test_super_admin_applies_a_bigger_quota_and_longer_period_after_preview(): void
    {
        $active = $this->activeCard(2, 50);
        $pending = $this->balances->purchasePlan(User::factory()->customer()->create(), $this->plan->fresh('benefits'));
        $this->padel->update(['quota_value' => 300]);
        $this->plan->update(['duration_days' => 120]);

        // Paket diubah: kartu lama belum berubah.
        $this->assertEquals(150, (float) $active->balances->firstWhere('facility', 'PADEL')->fresh()->remaining_quota);

        // Pratinjau per kartu.
        $preview = collect(app(MembershipPlanQuotaService::class)->preview($this->plan));
        $row = $preview->firstWhere('code', $active->membership_code);
        $this->assertSame([200.0, 300.0, 150.0, 250.0], array_values(array_intersect_key($row['quotas'][0], array_flip(['old_initial', 'new_initial', 'old_remaining', 'new_remaining']))));
        $this->assertSame(Carbon::today()->subDays(2)->addDays(120)->toDateString(), $row['end_date']['new']);
        $html = view('filament.resources.membership.plan-quota-preview', ['rows' => $preview->all()])->render();
        foreach ([$active->membership_code, $pending->membership_code, 'sisa 150', '2 dari 2 kartu akan berubah', 'Dihitung saat aktivasi'] as $text) {
            $this->assertStringContainsString($text, strip_tags(str_replace(['&rarr;', '&middot;'], ['→', '·'], $html)));
        }

        // Admin biasa tidak melihat tombolnya; super admin menerapkan.
        $this->assertNotContains('apply_membership_plan_quota', Club61PermissionMatrix::defaultRolePermissions('admin'));
        Livewire::actingAs(User::factory()->admin()->create(['is_active' => true]))->test(ListMembershipPlans::class)
            ->assertTableActionHidden('applyQuota', $this->plan);
        Livewire::actingAs(User::factory()->superAdmin()->create(['is_active' => true]))->test(ListMembershipPlans::class)
            ->callTableAction('applyQuota', $this->plan)->assertNotified('Kuota & masa aktif diterapkan');

        $padel = $active->balances->firstWhere('facility', 'PADEL')->fresh();
        $this->assertEquals([300, 250], [(float) $padel->initial_quota, (float) $padel->remaining_quota], 'jam yang sudah terpakai tetap dihitung');
        $this->assertSame(Carbon::today()->subDays(2)->addDays(120)->toDateString(), $active->fresh()->end_date->toDateString());
        $log = MembershipUsageLog::where('balance_id', $padel->id)->where('change_type', 'MANUAL_ADJUSTMENT')->sole();
        $this->assertEquals(100, (float) $log->quantity);
        $this->assertStringContainsString('Penyesuaian paket IM3 Platinum', $log->notes);
        $this->assertNull($active->balances->firstWhere('facility', 'GYM')->fresh()->initial_quota, 'kunjungan unlimited tidak disentuh');
        $this->assertTrue(ActivityLog::where('event', 'membership_plan.quota_applied')->exists());

        // Kartu menunggu bayar: kuota awal ikut, diisi saat aktivasi; masa aktif mengikuti paket saat aktivasi.
        $pendingPadel = $pending->balances->firstWhere('facility', 'PADEL')->fresh();
        $this->assertEquals([300, 0], [(float) $pendingPadel->initial_quota, (float) $pendingPadel->remaining_quota]);
        $activated = $this->balances->activateMembership($pending->fresh(), Carbon::today());
        $this->assertEquals(300, (float) $activated->balances->firstWhere('facility', 'PADEL')->remaining_quota);

        // Diterapkan lagi → tidak ada perubahan.
        $this->assertSame(0, app(MembershipPlanQuotaService::class)->apply($this->plan, User::factory()->superAdmin()->create())['memberships']);
    }

    public function test_smaller_quota_never_goes_below_zero_and_past_end_date_is_skipped(): void
    {
        $card = $this->activeCard(60, 170);
        $this->padel->update(['quota_value' => 100]);
        $this->plan->update(['duration_days' => 30]);

        $row = collect(app(MembershipPlanQuotaService::class)->preview($this->plan))->sole();
        $this->assertTrue($row['quotas'][0]['clamped']);
        $this->assertFalse($row['end_date']['apply']);
        $this->assertStringContainsString('dikurangi sampai 0', implode(' ', $row['notes']));
        $this->assertStringContainsString('tanggal berakhir tidak diubah', implode(' ', $row['notes']));

        // Tanpa izin → ditolak di server.
        try {
            app(MembershipPlanQuotaService::class)->apply($this->plan, User::factory()->admin()->create());
            $this->fail('Tanpa izin seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $endBefore = $card->end_date->toDateString();
        app(MembershipPlanQuotaService::class)->apply($this->plan, User::factory()->superAdmin()->create());
        $padel = $card->balances->firstWhere('facility', 'PADEL')->fresh();
        $this->assertEquals([100, 0], [(float) $padel->initial_quota, (float) $padel->remaining_quota]);
        $this->assertSame($endBefore, $card->fresh()->end_date->toDateString(), 'kartu yang sedang dipakai tidak tiba-tiba berakhir');
    }

    public function test_renewed_card_keeps_its_end_date_and_actions_show_only_for_sold_plans(): void
    {
        // Kartu 90 hari diperpanjang 90 hari: berakhir = tanggal berakhir lama + 90, bukan tanggal mulai + masa aktif.
        $card = $this->activeCard(10, 50);
        $renewal = $this->balances->renewMembership($card, $this->plan->fresh('benefits'));
        $this->balances->fulfillRenewal($renewal->fresh());
        $renewedEnd = $card->fresh()->end_date->toDateString();
        $this->assertSame(Carbon::today()->subDays(10)->addDays(180)->toDateString(), $renewedEnd);
        $this->assertSame('MERGED', $renewal->fresh()->status);

        $this->padel->update(['quota_value' => 250]);
        $this->plan->update(['duration_days' => 120]);

        $row = collect(app(MembershipPlanQuotaService::class)->preview($this->plan))->sole();
        $this->assertFalse($row['end_date']['apply']);
        $this->assertStringContainsString('Kartu sudah diperpanjang', implode(' ', $row['notes']));

        app(MembershipPlanQuotaService::class)->apply($this->plan, User::factory()->superAdmin()->create());
        $this->assertSame($renewedEnd, $card->fresh()->end_date->toDateString(), 'masa perpanjangan yang sudah dibayar tidak terpotong');
        $padel = $card->balances->firstWhere('facility', 'PADEL')->fresh();
        $this->assertEquals([250, 400], [(float) $padel->initial_quota, (float) $padel->remaining_quota], 'kuota tetap disamakan: 150 + 200 perpanjangan + 50');

        // Tombol Terapkan hanya untuk paket yang sudah terjual; Edit selalu tampil.
        $unsold = MembershipPlan::create(['code' => 'BARU', 'name' => 'Paket Baru', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 100000, 'is_active' => true]);
        Livewire::actingAs(User::factory()->superAdmin()->create(['is_active' => true]))->test(ListMembershipPlans::class)
            ->assertTableActionVisible('applyQuota', $this->plan)->assertTableActionVisible('applyRules', $this->plan)
            ->assertTableActionHidden('applyQuota', $unsold)->assertTableActionHidden('applyRules', $unsold)
            ->assertTableActionVisible('edit', $unsold);
    }

    public function test_quota_used_while_applying_rolls_everything_back_with_a_clear_message(): void
    {
        $card = $this->activeCard(2, 50);
        $this->padel->update(['quota_value' => 100]);
        $this->plan->update(['duration_days' => 120]);
        $endBefore = $card->end_date->toDateString();

        // Member memakai kuota tepat saat diterapkan → sisa jadi minus di tengah proses.
        $this->mock(MembershipBalanceService::class, fn ($mock) => $mock->shouldReceive('adjustQuota')
            ->andThrow(new \DomainException('Penyesuaian manual menyebabkan saldo kuota minus')));

        Livewire::actingAs(User::factory()->superAdmin()->create(['is_active' => true]))->test(ListMembershipPlans::class)
            ->callTableAction('applyQuota', $this->plan)->assertNotified('Belum diterapkan');

        $padel = $card->balances->firstWhere('facility', 'PADEL')->fresh();
        $this->assertEquals([200, 150], [(float) $padel->initial_quota, (float) $padel->remaining_quota], 'tidak ada yang tersimpan');
        $this->assertSame($endBefore, $card->fresh()->end_date->toDateString());
        $this->assertFalse(ActivityLog::where('event', 'membership_plan.quota_applied')->exists());
    }

    public function test_card_changed_by_a_concurrent_apply_is_not_topped_up_twice(): void
    {
        $card = $this->activeCard(2, 50);
        $this->padel->update(['quota_value' => 300]);
        $balanceId = $card->balances->firstWhere('facility', 'PADEL')->id;

        // Penerapan lain selesai tepat setelah kartu terbaca (salinan lama 200 / 150 di memori, database sudah 300 / 250).
        $fired = false;
        UserMembershipBalance::retrieved(function (UserMembershipBalance $balance) use ($balanceId, &$fired) {
            if (! $fired && $balance->id === $balanceId) {
                $fired = true;
                UserMembershipBalance::whereKey($balanceId)->toBase()->update(['initial_quota' => 300, 'remaining_quota' => 250]);
            }
        });

        try {
            app(MembershipPlanQuotaService::class)->apply($this->plan, User::factory()->superAdmin()->create());
            $this->fail('Kartu yang berubah seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // Tambahan 100 jam tidak dihitung dari salinan lama (di pengujian, simulasi di atas ikut dibatalkan karena satu transaksi).
        $this->assertFalse(MembershipUsageLog::where('balance_id', $balanceId)->where('change_type', 'MANUAL_ADJUSTMENT')->exists());
        $this->assertFalse(ActivityLog::where('event', 'membership_plan.quota_applied')->exists());
    }

    public function test_shorter_period_also_caps_the_weekly_schedules_of_the_card(): void
    {
        $card = $this->activeCard(10, 0);
        $oldEnd = Carbon::today()->subDays(10)->addDays(90)->toDateString();
        $newEnd = Carbon::today()->subDays(10)->addDays(60)->toDateString();
        $admin = User::factory()->superAdmin()->create();
        $court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        $schedule = fn (string $status) => PadelRecurringSchedule::create([
            'name' => 'Kamis malam', 'user_membership_id' => $card->id, 'day_of_week' => 4, 'start_time' => '19:00', 'end_time' => '21:00',
            'court_ids' => [$court->id], 'starts_on' => Carbon::today()->subDays(10)->toDateString(), 'ends_on' => $oldEnd, 'weeks_ahead' => 2,
            'status' => $status, 'created_by_id' => $admin->id,
        ]);
        $active = $schedule(PadelRecurringSchedule::STATUS_ACTIVE);
        $stopped = $schedule(PadelRecurringSchedule::STATUS_STOPPED);
        $lateDate = Carbon::today()->subDays(10)->addDays(70);
        $late = PadelBooking::create([
            'booking_code' => 'BK-JT-LATE', 'user_id' => $card->user_id, 'court_id' => $court->id, 'booking_date' => $lateDate->toDateString(),
            'start_time' => $lateDate->copy()->setTime(19, 0), 'end_time' => $lateDate->copy()->setTime(21, 0),
            'court_fee' => 0, 'coach_fee' => 0, 'equipment_fee' => 0, 'total_amount' => 0, 'status' => 'PAID',
        ]);
        $late->forceFill(['recurring_schedule_id' => $active->id])->save();
        $this->plan->update(['duration_days' => 60]);

        $notes = implode(' ', collect(app(MembershipPlanQuotaService::class)->preview($this->plan))->sole()['notes']);
        $this->assertStringContainsString('Booking Mingguan: batas akhir 1 jadwal ikut dipotong', $notes);
        $this->assertStringContainsString('1 booking jadwal tetap setelah', $notes);

        $result = app(MembershipPlanQuotaService::class)->apply($this->plan, $admin);

        $this->assertSame(1, $result['schedules']);
        $this->assertSame($newEnd, $card->fresh()->end_date->toDateString());
        $this->assertSame($newEnd, $active->fresh()->ends_on->toDateString(), 'jadwal tidak membuat booking lewat masa aktif kartu');
        $this->assertSame($oldEnd, $stopped->fresh()->ends_on->toDateString(), 'riwayat jadwal yang dihentikan tidak diubah');
        $this->assertSame('PAID', $late->fresh()->status, 'booking yang sudah dibuat tidak dibatalkan otomatis');
    }
}
