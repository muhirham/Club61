<?php

namespace Tests\Feature\Membership;

use App\Filament\Pages\Kustomer;
use App\Models\Audit\ActivityLog;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Membership\MembershipUsageLog;
use App\Models\Membership\UserMembership;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\Refund;
use App\Models\Pos\Voucher;
use App\Models\User;
use App\Models\Wellness\WellnessBooking;
use App\Models\Wellness\WellnessFacility;
use App\Models\Wellness\WellnessSlot;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Membership\MembershipCancellationService;
use App\Services\Membership\MonthlyBilling;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Batalkan Membership (izin cancel_membership, pintu belakang): kartu individu dihentikan permanen, sisa kuota hangus,
 * uang tidak disentuh. Ditolak selama kartu masih dipakai (booking mendatang, Jadwal Tetap, perpanjangan menunggu bayar).
 * Juga: sinkronisasi kartu habis tidak meng-EXPIRED-kan kartu yang jamnya terpotong booking yang belum dimainkan, dan
 * Saluran Beli di Data Member memakai gateway pembayaran yang sebenarnya.
 */
class MembershipCancellationTest extends TestCase
{
    use RefreshDatabase;

    private MembershipBalanceService $balances;

    private MembershipCancellationService $service;

    private MembershipPlan $plan;

    private PadelCourt $court;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->balances = app(MembershipBalanceService::class);
        $this->service = app(MembershipCancellationService::class);
        $this->plan = MembershipPlan::create(['code' => 'PRIME-50', 'name' => '61 Prime 50 Hours', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 250000, 'is_active' => true]);
        MembershipPlanBenefit::create(['plan_id' => $this->plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 50]);
        MembershipPlanBenefit::create(['plan_id' => $this->plan->id, 'facility' => 'SAUNA', 'quota_type' => 'VISITS', 'quota_value' => 4]);
        $this->court = PadelCourt::create(['name' => 'Court Uji', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
        $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Owner Uji']);
    }

    /** Kartu aktif yang sudah memakai $used jam padel. */
    private function card(float $used = 1, ?User $customer = null, ?MembershipPlan $plan = null): UserMembership
    {
        $card = $this->balances->purchasePlan($customer ?? User::factory()->customer()->create(['name' => 'Customer Uji']), ($plan ?? $this->plan)->fresh('benefits'));
        $card = $this->balances->activateMembership($card);
        if ($used > 0) {
            $this->balances->adjustQuota($card->balanceFor('PADEL')->id, 'DECREMENT', $used, 'Main');
        }

        return $card->fresh('balances');
    }

    private function quotaBooking(UserMembership $card, Carbon $start, string $status = 'PAID', ?string $code = null): PadelBooking
    {
        return PadelBooking::create([
            'booking_code' => $code ?? 'BK-'.strtoupper(Str::random(6)), 'user_id' => $card->user_id, 'court_id' => $this->court->id,
            'membership_balance_id' => $card->balanceFor('PADEL')->id, 'member_hours_consumed' => 2,
            'booking_date' => $start->toDateString(), 'start_time' => $start, 'end_time' => $start->copy()->addHours(2),
            'court_fee' => 0, 'total_amount' => 0, 'status' => $status,
        ]);
    }

    private function assertRefused(UserMembership $card, string $message): void
    {
        try {
            $this->service->cancel($card, 'Harga paket salah input', $this->superAdmin);
            $this->fail('Pembatalan seharusnya ditolak: '.$message);
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame('ACTIVE', $card->fresh()->status);
    }

    public function test_super_admin_cancels_a_card_quota_is_forfeited_and_money_stays_recorded(): void
    {
        $card = $this->card(used: 1);
        $padel = $card->balanceFor('PADEL');
        $this->quotaBooking($card, Carbon::now()->subDays(2)->setTime(19, 0), 'COMPLETED');

        Livewire::actingAs($this->superAdmin)->test(Kustomer::class)
            ->call('openDetail', $card->id)
            ->assertSee('Batalkan Membership')
            ->call('openCancelMembership')
            ->assertSet('showCancelMembership', true)
            ->assertSee('Padel 49 jam, Wellness 4 sesi')
            ->assertSee('tidak dikembalikan dan tetap tercatat di Buku Transaksi')
            ->set('cancelReason', 'Harga paket salah input')
            ->call('confirmCancelMembership')
            ->assertSet('showCancelMembership', false)
            ->assertSee('DIBATALKAN ADMIN')
            ->assertSee('Owner Uji')
            ->assertSee('Harga paket salah input')
            ->assertDontSee('wire:click="openCancelMembership"', false);

        $card->refresh();
        $this->assertSame('CANCELLED', $card->status);
        $this->assertEquals(0, (float) $padel->fresh()->remaining_quota);
        $this->assertEquals(0, (float) $card->balanceFor('SAUNA')->remaining_quota);
        $this->assertEquals(50, (float) $padel->fresh()->initial_quota, 'kuota awal tidak diubah');

        $log = MembershipUsageLog::where('balance_id', $padel->id)->where('related_type', MembershipCancellationService::LOG_MARKER)->sole();
        $this->assertSame(['MANUAL_ADJUSTMENT', -49.0, $this->superAdmin->id, 'Kartu dibatalkan: Harga paket salah input'], [$log->change_type, (float) $log->quantity, $log->performed_by, $log->notes]);
        $info = MembershipCancellationService::cancellationInfo($card);
        $this->assertSame(['Owner Uji', 'Harga paket salah input'], [$info['by'], $info['reason']]);

        $activity = ActivityLog::where('event', 'membership.cancelled')->sole();
        $this->assertSame(['WARNING', $card->id, 'Owner Uji'], [$activity->severity, $activity->subject_id, $activity->causer_name]);
        $this->assertSame('PADEL 49, SAUNA 4', $activity->meta['sisa kuota hangus']);

        // Uang tidak disentuh: tanpa refund & tanpa voucher saldo.
        $this->assertSame(0, Refund::count());
        $this->assertSame(0, Voucher::count());

        // Daftar member: label & filter "Dibatalkan".
        Livewire::actingAs($this->superAdmin)->test(Kustomer::class)
            ->set('statusFilter', 'CANCELLED')
            ->assertSee($card->membership_code)
            ->assertSee('DIBATALKAN');
    }

    public function test_cancelled_card_can_no_longer_be_used_and_does_not_come_back(): void
    {
        $card = $this->card(used: 0);
        $this->service->cancel($card, 'Harga paket salah input', $this->superAdmin);
        $card = $card->fresh('balances');
        $this->assertFalse($card->isActive());

        // Checkout: kartu dipilih atau dideteksi otomatis → tanpa benefit, harga normal.
        $start = Carbon::tomorrow()->setTime(10, 0);
        $locked = PadelBooking::create([
            'booking_code' => 'BK-LOCK', 'user_id' => $card->user_id, 'court_id' => $this->court->id, 'booking_date' => $start->toDateString(),
            'start_time' => $start, 'end_time' => $start->copy()->addHour(), 'court_fee' => 200000, 'total_amount' => 200000, 'status' => 'LOCKED',
        ]);
        $padelService = app(PadelBookingService::class);
        $this->assertFalse($padelService->previewMembershipBenefit([$locked->id], $card->user, $card->balanceFor('PADEL')->id)['has_benefit']);
        $this->assertFalse($padelService->previewMembershipBenefit([$locked->id], $card->user)['has_benefit']);

        // Check-in fasilitas ditolak.
        try {
            $this->balances->recordCheckin($card->balanceFor('SAUNA')->id, $card->user_id);
            $this->fail('Check-in kartu batal harus ditolak.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('tidak aktif', $e->getMessage());
        }

        // Sinkronisasi harian & pembatalan kedua tidak mengubah apa pun.
        $this->artisan('membership:sync-expired')->assertSuccessful();
        $this->assertSame('CANCELLED', $card->fresh()->status);
        $this->expectException(HttpException::class);
        $this->service->cancel($card, 'Harga paket salah input', $this->superAdmin);
    }

    public function test_cancellation_is_refused_while_the_card_is_still_in_use(): void
    {
        // Booking padel mendatang yang memakai kuota (termasuk yang masih ditahan).
        $card = $this->card();
        $paid = $this->quotaBooking($card, Carbon::tomorrow()->setTime(19, 0), 'PAID', 'BK-NEXT');
        $this->assertRefused($card, 'BK-NEXT');
        $paid->update(['status' => 'CANCELLED']);
        $held = $this->quotaBooking($card, Carbon::tomorrow()->setTime(21, 0), 'LOCKED', 'BK-HOLD');
        $this->assertRefused($card, 'BK-HOLD');
        $held->update(['status' => 'EXPIRED']);

        // Booking wellness mendatang yang memakai kuota.
        $facility = WellnessFacility::create(['name' => 'Sauna', 'max_capacity_per_slot' => 8, 'duration_minutes' => 45, 'price_per_person' => 150000]);
        $slot = WellnessSlot::create(['facility_id' => $facility->id, 'session_date' => Carbon::tomorrow()->toDateString(), 'start_time' => Carbon::tomorrow()->setTime(16, 0),
            'end_time' => Carbon::tomorrow()->setTime(16, 45), 'max_capacity' => 8, 'booked_count' => 1, 'status' => 'AVAILABLE']);
        $wellness = WellnessBooking::create(['booking_code' => 'WB-NEXT', 'user_id' => $card->user_id, 'slot_id' => $slot->id, 'num_persons' => 1, 'total_amount' => 0,
            'status' => 'CONFIRMED', 'membership_balance_id' => $card->balanceFor('SAUNA')->id, 'member_sessions_consumed' => 1]);
        $this->assertRefused($card, 'WB-NEXT');
        $wellness->update(['status' => 'CANCELLED']);

        // Jadwal Tetap yang belum dihentikan (aktif / dijeda).
        $schedule = PadelRecurringSchedule::create([
            'name' => 'Kamis malam', 'user_membership_id' => $card->id, 'day_of_week' => 4, 'start_time' => '19:00', 'end_time' => '21:00',
            'court_ids' => [$this->court->id], 'starts_on' => Carbon::today()->toDateString(), 'weeks_ahead' => 2,
            'status' => PadelRecurringSchedule::STATUS_PAUSED, 'created_by_id' => $this->superAdmin->id,
        ]);
        $this->assertRefused($card, 'Jadwal Tetap Mingguan');
        $schedule->update(['status' => PadelRecurringSchedule::STATUS_STOPPED]);

        // Perpanjangan yang menunggu bayar — kalau lunas setelah batal, kartu induk aktif kembali.
        $renewal = $this->balances->renewMembership($card, $this->plan->fresh('benefits'));
        $this->assertRefused($card, $renewal->membership_code);
        $renewal->update(['status' => 'CANCELLED']);

        // Semua pemakaian sudah selesai → boleh dibatalkan.
        $this->service->cancel($card, 'Harga paket salah input', $this->superAdmin);
        $this->assertSame('CANCELLED', $card->fresh()->status);
        $this->assertSame([], MembershipCancellationService::upcomingQuotaBookings($card));
    }

    public function test_only_active_individual_cards_can_be_cancelled_here(): void
    {
        $corporatePlan = MembershipPlan::create(['code' => 'CORP', 'name' => 'Corporate', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 30, 'price' => 1000000, 'is_active' => true]);
        MembershipPlanBenefit::create(['plan_id' => $corporatePlan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 9]);
        $corporate = $this->card(used: 0, plan: $corporatePlan);
        $this->assertRefused($corporate, 'Kelola Sponsor');

        $monthly = $this->card(used: 0);
        $monthly->balanceFor('PADEL')->update(['extra_benefits' => [MonthlyBilling::KEY => ['enabled' => true]]]);
        $this->assertRefused($monthly->fresh('balances'), 'Tagihan Bulanan');

        $expired = $this->card(used: 0);
        $expired->update(['status' => 'EXPIRED']);
        try {
            $this->service->cancel($expired, 'Harga paket salah input', $this->superAdmin);
            $this->fail('Kartu EXPIRED tidak bisa dibatalkan.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Hanya kartu berstatus ACTIVE', $e->getMessage());
        }

        // Alasan wajib.
        $card = $this->card(used: 0);
        try {
            $this->service->cancel($card, '  ', $this->superAdmin);
            $this->fail('Alasan wajib diisi.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('ACTIVE', $card->fresh()->status);
    }

    public function test_cancelling_needs_the_backdoor_permission(): void
    {
        $this->assertContains('cancel_membership', Club61PermissionMatrix::BACKDOOR_PERMISSIONS);
        $this->assertNotContains('cancel_membership', Club61PermissionMatrix::defaultRolePermissions('admin'));

        $card = $this->card(used: 0);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('View:Kustomer');

        Livewire::actingAs($viewer)->test(Kustomer::class)
            ->call('openDetail', $card->id)
            ->assertDontSee('wire:click="openCancelMembership"', false)
            ->call('openCancelMembership')
            ->assertForbidden();

        try {
            $this->service->cancel($card, 'Harga paket salah input', $viewer);
            $this->fail('Tanpa izin harus ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('ACTIVE', $card->fresh()->status);
    }

    /** Dulu kartu langsung EXPIRED begitu Jadwal Tetap memotong seluruh jamnya, padahal bookingnya belum dimainkan. */
    public function test_sync_keeps_a_card_whose_hours_are_booked_ahead_until_the_last_booking_has_passed(): void
    {
        $card = $this->card(used: 50);
        $card->balanceFor('SAUNA')->update(['remaining_quota' => 0]);
        $booking = $this->quotaBooking($card, Carbon::now()->addDays(8)->setTime(19, 0));

        $this->artisan('membership:sync-expired')->assertSuccessful();
        $this->assertSame('ACTIVE', $card->fresh()->status);

        $booking->update(['status' => 'COMPLETED']);
        $this->artisan('membership:sync-expired')->assertSuccessful();
        $this->assertSame('EXPIRED', $card->fresh()->status);
    }

    public function test_sync_expired_does_not_overwrite_a_card_changed_while_the_job_runs(): void
    {
        // Dua kartu lewat masa aktif. Tepat setelah job mengambil daftar kartu ACTIVE, admin membatalkan kartu pertama
        // dan kartu kedua diperpanjang — job tidak boleh menimpa keduanya jadi EXPIRED dari data lama.
        $cancelled = $this->card();
        $renewed = $this->card();
        UserMembership::whereKey([$cancelled->id, $renewed->id])->update(['end_date' => Carbon::yesterday()->toDateString()]);

        $fired = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$fired, $cancelled, $renewed) {
            if ($fired || ! str_contains($query->sql, 'from "user_memberships"') || ! in_array('ACTIVE', $query->bindings, true)) {
                return;
            }
            $fired = true;
            UserMembership::whereKey($cancelled->id)->update(['status' => 'CANCELLED']);
            UserMembership::whereKey($renewed->id)->update(['end_date' => Carbon::today()->addDays(30)->toDateString()]);
        });

        $this->artisan('membership:sync-expired')->assertSuccessful();

        $this->assertTrue($fired);
        $this->assertSame('CANCELLED', $cancelled->fresh()->status);
        $this->assertSame('ACTIVE', $renewed->fresh()->status);
    }

    public function test_customer_list_shows_the_gateway_that_actually_received_the_payment(): void
    {
        $doku = $this->card(used: 0, customer: User::factory()->customer()->create(['name' => 'Pembeli Doku']));
        $order = Order::create(['order_number' => 'ORD-MBR-DOKU', 'user_id' => $doku->user_id, 'order_type' => 'MEMBERSHIP', 'subtotal' => 250000, 'grand_total' => 250000, 'payment_status' => 'PAID']);
        Payment::create(['order_id' => $order->id, 'payment_gateway' => 'DOKU', 'transaction_id' => 'ORD-MBR-DOKU-1', 'amount' => 250000, 'payment_method' => 'QRIS', 'status' => 'SUCCESS']);
        $doku->update(['order_id' => $order->id]);

        Livewire::actingAs($this->superAdmin)->test(Kustomer::class)
            ->assertSee('Online · DOKU')
            ->assertSee('Web / Aplikasi')
            ->assertDontSee('Online Midtrans');
    }
}
