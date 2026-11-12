<?php

namespace Tests\Feature\Membership;

use App\Filament\Pages\KelolaPemesanan;
use App\Filament\Resources\Membership\Pages\ListMembershipPlans;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelPeakHourRule;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Payment;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Models\User;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Membership\MonthlyBilling;
use App\Services\Padel\PadelBookingService;
use App\Services\Padel\PeakHourService;
use App\Services\Padel\RecurringScheduleService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 34 — paket membership "Tagihan Bulanan" (docs/PRD_MODUL_34_TAGIHAN_BULANAN_MEMBERSHIP.md): booking PIC aktif
 * (Jadwal Tetap, booking online, dicatat resepsionis) langsung terkonfirmasi Rp0 tanpa kuota dan direkap per bulan;
 * harga ditagihkan Finance di luar sistem. Jam tidak dapat dibagikan ke karyawan.
 */
class MonthlyBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pic;

    private PadelCourt $court;

    private MembershipPlan $plan;

    private MembershipPlanBenefit $benefit;

    private UserMembershipBalance $balance;

    private SponsorOrganization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        ClubFinanceSetting::getSettings()->update(['is_admin_fee_enabled' => false, 'is_tax_enabled' => false]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);

        // Senin 2 Nov 2026 10:00; jam peak Senin–Jumat 17–24 (akhir pekan reguler).
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        PadelPeakHourRule::query()->delete();
        foreach (range(1, 5) as $day) {
            PadelPeakHourRule::create(['day_of_week' => $day, 'start_hour' => 17, 'end_hour' => 24]);
        }
        app(PeakHourService::class)->flush();

        $this->admin = User::factory()->admin()->create(['is_active' => true]);
        $this->court = PadelCourt::create([
            'name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000,
            'open_time' => '06:00', 'close_time' => '24:00', 'is_active' => true,
        ]);

        $this->plan = MembershipPlan::create([
            'code' => 'CORP-HUAWEI', 'name' => 'Huawei Jumat Malam', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 90,
            'price' => 0, 'is_active' => true, 'sales_channel' => MembershipPlan::SALES_POS_ONLY,
        ]);
        $this->benefit = MembershipPlanBenefit::create([
            'plan_id' => $this->plan->id, 'facility' => 'PADEL', 'quota_type' => 'NONE',
            'extra_benefits' => [MonthlyBilling::KEY => ['enabled' => true]],
        ]);

        // Kartu dibeli PIC lama; PIC kemudian diganti lewat Edit Sponsor.
        $buyer = User::factory()->customer()->create(['name' => 'PIC Lama']);
        $this->pic = User::factory()->customer()->create(['name' => 'PIC Huawei']);
        $membership = app(MembershipBalanceService::class)->purchasePlan($buyer, $this->plan, ['status' => 'ACTIVE']);
        $membership->update(['start_date' => '2026-11-01', 'end_date' => '2027-01-30']);
        $this->balance = $membership->balances->firstWhere('facility', 'PADEL');
        $this->org = SponsorOrganization::create(['name' => 'Huawei', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $this->pic->id, 'status' => 'ACTIVE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(array $overrides = []): PadelRecurringSchedule
    {
        return PadelRecurringSchedule::create($overrides + [
            'name' => 'Huawei Jumat malam', 'user_membership_id' => $this->balance->user_membership_id,
            'day_of_week' => 5, 'start_time' => '19:00', 'end_time' => '22:00', 'court_ids' => [$this->court->id],
            'starts_on' => '2026-11-02', 'weeks_ahead' => 2, 'status' => PadelRecurringSchedule::STATUS_ACTIVE, 'created_by_id' => $this->admin->id,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, PadelBooking> */
    private function generate(?PadelRecurringSchedule $schedule = null)
    {
        $schedule ??= $this->schedule();
        $result = app(RecurringScheduleService::class)->generate($schedule, $this->admin);
        $this->assertSame([], $result['failed']);

        return PadelBooking::where('recurring_schedule_id', $schedule->id)->orderBy('start_time')->get();
    }

    private function expectRejected(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("Seharusnya ditolak: {$message}");
        } catch (HttpException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /** @return list<string> booking id yang ditahan atas nama $user (keranjang checkout / slot kasir). */
    private function hold(User $user, string $date, string $start, string $end): array
    {
        $hold = app(PadelBookingService::class)->holdBatchSlots([['court_id' => $this->court->id, 'start_time' => $start, 'end_time' => $end]], $date, $user);

        return collect($hold['bookings'])->pluck('id')->all();
    }

    public function test_weekly_schedule_confirms_bookings_for_the_current_pic_at_rp0_without_using_hours(): void
    {
        $options = app(RecurringScheduleService::class)->membershipOptions();
        $this->assertStringContainsString('Tagihan Bulanan', $options[$this->balance->user_membership_id]);

        $bookings = $this->generate();

        // 6 & 13 Nov, Jumat 19–22.
        $this->assertCount(2, $bookings);
        foreach ($bookings as $booking) {
            $this->assertSame('PAID', $booking->status, 'langsung terkonfirmasi');
            $this->assertNotNull($booking->qr_code_hash);
            $this->assertSame($this->pic->id, $booking->user_id, 'atas nama PIC aktif, bukan pembeli kartu');
            $this->assertSame($this->balance->id, $booking->membership_balance_id);
            $this->assertEquals(0, (float) $booking->court_fee);
            $this->assertEquals(0, (float) $booking->member_hours_consumed);
            $this->assertEquals(0, (float) $booking->member_discount_court);
            $this->assertSame('PAID', $booking->order->payment_status);
            $this->assertEquals(0, (float) $booking->order->grand_total);
            $this->assertStringContainsString('Jadwal Tetap', $booking->order->items->first()->item_name);
            $this->assertTrue(MonthlyBilling::isTabBooking($booking));
        }
        $payment = Payment::where('order_id', $bookings->first()->order_id)->sole();
        $this->assertSame([MonthlyBilling::METHOD, 0.0, null], [$payment->payment_method, (float) $payment->amount, $payment->pos_shift_id]);
        $this->assertSame(MonthlyBilling::METHOD_LABEL, app(PadelBookingService::class)->formatPaymentMethodLabel($payment->payment_method, $payment->payload_log));
        $this->assertEquals(0, (float) \App\Models\Finance\LedgerEntry::sum('total_amount'), 'tidak ada uang masuk');
        $this->assertEquals(0, (float) $this->balance->fresh()->remaining_quota);
        $this->assertNull(app(RecurringScheduleService::class)->weeksLeft($this->schedule()->fresh()));
    }

    public function test_monthly_bookings_check_in_normally_and_add_ons_are_paid_separately(): void
    {
        $booking = $this->generate()->first();
        $service = app(PadelBookingService::class);

        // Tambah alat di Kasir Pro Shop → transaksi baru di kasir (tidak masuk Tagihan Bulanan).
        $this->assertSame(\App\Services\Padel\BookingAddonService::MODE_PAID, app(\App\Services\Padel\BookingAddonService::class)->mode($booking));

        $this->travel(3)->hours();
        $service->releaseExpiredLocks();
        $this->assertSame('PAID', $booking->fresh()->status);

        $this->travelTo($booking->start_time->copy()->subMinutes(30));
        $service->checkIn($booking->booking_code, $this->admin);
        $this->assertSame('CHECKED_IN', $booking->fresh()->status);
    }

    public function test_kelola_pemesanan_labels_the_booking_and_cancels_only_before_the_deadline(): void
    {
        [$first, $second] = $this->generate()->all();
        $this->actingAs($this->admin);

        $page = Livewire::test(KelolaPemesanan::class)->assertSee('Tagihan Bulanan · Huawei');
        $this->assertFalse($page->instance()->canRequestRefundFor($first), 'tanpa tombol refund — tidak ada uang');
        $this->assertTrue($page->instance()->canCancelTabFor($first));

        $page->call('openCancelTabModal', $first->id)->assertSet('showCancelTabModal', true)
            ->assertSee('Batalkan Booking Tagihan Bulanan')
            ->set('cancelTabReason', 'Huawei libur kantor')
            ->call('executeCancelTab')->assertSet('showCancelTabModal', false);

        $this->assertSame('CANCELLED', $first->fresh()->status);
        $this->assertSame(0, \App\Models\Pos\Refund::count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'monthly_bill.cancelled']);

        // Lewat batas waktu reschedule (bawaan 2 jam) → tetap tercatat.
        $this->travelTo($second->start_time->copy()->subHour());
        $this->expectRejected(fn () => app(MonthlyBilling::class)->cancel($second, 'Batal mendadak', $this->admin), 'tetap tercatat pada Tagihan Bulanan');
        $this->assertSame('PAID', $second->fresh()->status);
    }

    public function test_skipping_a_date_and_removing_the_schedule_cancel_the_bookings(): void
    {
        $schedule = $this->schedule();
        [$first, $second] = $this->generate($schedule)->all();
        $service = app(RecurringScheduleService::class);

        // Lewati tanggal = keputusan Club 61 → keluar dari rekap walau sudah lewat batas waktu reschedule.
        $this->travelTo($first->start_time->copy()->subHour());
        $this->assertSame(1, $service->skip($schedule, '2026-11-06', 'Lapangan dipakai turnamen', $this->admin));
        $this->assertSame('CANCELLED', $first->fresh()->status);

        $result = $service->remove($schedule->fresh(), $this->admin);
        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('CANCELLED', $second->fresh()->status);
        $this->assertSame(0, \App\Models\Pos\Refund::count());
    }

    public function test_rescheduling_keeps_the_booking_at_rp0_without_any_difference(): void
    {
        $booking = $this->generate()->first();

        // Kelola Pemesanan: Jumat malam (peak) → Sabtu pagi (reguler), tanpa selisih yang ditagih / hangus.
        $result = app(PadelBookingService::class)->adminRescheduleBooking($booking->id, $this->court->id, '2026-11-07', '09:00', 'Pindah ke Sabtu', $this->admin);
        $this->assertEquals(0, $result['total_charge']);
        $booking->refresh();
        $this->assertSame('PAID', $booking->status);
        $this->assertSame('2026-11-07 09:00', $booking->start_time->format('Y-m-d H:i'));
        $this->assertEquals(0, (float) $booking->court_fee);
        $this->assertSame(1, Payment::where('order_id', $booking->order_id)->count(), 'tanpa tagihan selisih');

        // Jadwal Tetap diubah ke Kamis 18–20 → booking minggu depan ikut pindah, tetap Rp0.
        $schedule = PadelRecurringSchedule::sole();
        $second = PadelBooking::where('recurring_schedule_id', $schedule->id)->where('id', '!=', $booking->id)->sole();
        $data = app(RecurringScheduleService::class)->normalize(['day_of_week' => 4, 'start_time' => '18:00', 'end_time' => '20:00'] + RecurringScheduleService::formState($schedule), $schedule);
        app(RecurringScheduleService::class)->updateSchedule($schedule, $data, $this->admin);
        $second->refresh();
        $this->assertSame('PAID', $second->status);
        $this->assertSame('2026-11-12 18:00', $second->start_time->format('Y-m-d H:i'));
        $this->assertEquals(0, (float) $second->court_fee);
    }

    public function test_pic_books_online_and_the_member_card_covers_the_court_fee(): void
    {
        $this->actingAs($this->pic);
        $checkout = fn (array $ids, array $extra = []) => $this->withHeaders(['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])
            ->postJson('/api/v1/padel/checkout', ['booking_ids' => $ids, 'payment_method' => 'QRIS'] + $extra)->assertOk();

        // Sabtu 09–11 (reguler 2 × 200k): kartu Huawei tampil seperti benefit member — sewa lapangan ditanggung penuh.
        $ids = $this->hold($this->pic, '2026-11-07', '09:00', '11:00');
        $preview = $this->postJson('/api/v1/padel/preview-membership-benefit', ['booking_ids' => $ids])->assertOk()->json('data');
        $this->assertSame([true, MonthlyBilling::METHOD, 'Huawei'], [$preview['has_benefit'], $preview['benefit_type'], $preview['plan_name']]);
        $this->assertEquals([400000, 0], [$preview['court_discount_amount'], $preview['projected_court_total']]);

        $response = $checkout($ids);
        $this->assertSame('PAID', $response->json('data.payment_status'));
        $this->assertEquals(0, $response->json('data.grand_total'));
        $booking = PadelBooking::findOrFail($ids[0]);
        $this->assertSame(['PAID', $this->balance->id, 'ONLINE_BOOKING'], [$booking->status, $booking->membership_balance_id, $booking->order->order_type]);
        $this->assertEquals([0, 0], [(float) $booking->court_fee, (float) $booking->member_discount_court]);
        $this->assertSame(MonthlyBilling::METHOD, Payment::where('order_id', $booking->order_id)->value('payment_method'));

        // Tambah raket: sewa lapangan tetap masuk tagihan bulanan, raket dibayar online biasa.
        $equipment = \App\Models\Padel\CourtEquipment::create(['name' => 'Raket', 'type' => 'RACKET', 'rental_price' => 50000, 'stock_quantity' => 5, 'is_active' => true]);
        $withRacket = $this->hold($this->pic, '2026-11-07', '12:00', '13:00');
        $response = $checkout($withRacket, ['equipments' => [['equipment_id' => $equipment->id, 'quantity' => 1]]]);
        $this->assertEquals([0, 50000, 50000], [$response->json('data.court_fee'), $response->json('data.equipment_fee'), $response->json('data.grand_total')]);
        $this->assertSame($this->balance->id, PadelBooking::findOrFail($withRacket[0])->membership_balance_id);

        // Kartu dimatikan di checkout → harga normal, tidak masuk rekap.
        $off = $this->hold($this->pic, '2026-11-07', '14:00', '15:00');
        $response = $checkout($off, ['membership_balance_id' => 'NONE']);
        $this->assertEquals(200000, $response->json('data.grand_total'));
        $this->assertNull(PadelBooking::findOrFail($off[0])->membership_balance_id);

        $recap = MonthlyBilling::recap($this->balance, '2026-11');
        $this->assertSame(['Booking Online PIC'], $recap['rows']->pluck('source')->unique()->values()->all());
        $this->assertEquals(3, $recap['totals']['hours']);

        // Karyawan / customer lain (bukan PIC) tidak mendapat Tagihan Bulanan.
        $other = User::factory()->customer()->create(['name' => 'Karyawan Huawei']);
        $this->actingAs($other);
        $otherIds = $this->hold($other, '2026-11-07', '16:00', '17:00');
        $this->assertNotSame(MonthlyBilling::METHOD, $this->postJson('/api/v1/padel/preview-membership-benefit', ['booking_ids' => $otherIds])->json('data.benefit_type'));
        $this->assertEquals(200000, $checkout($otherIds)->json('data.grand_total'));
    }

    public function test_receptionist_sees_the_pic_card_as_a_member_benefit_at_the_walk_in_cashier(): void
    {
        $receptionist = User::factory()->create(['name' => 'Resepsionis Sari', 'is_active' => true]);
        $receptionist->givePermissionTo(Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        \App\Models\Pos\PosCashierShift::create([
            'shift_number' => \App\Models\Pos\PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $receptionist->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $service = app(PadelBookingService::class);
        $slots = [['court_id' => $this->court->id, 'start_time' => '15:00', 'end_time' => '17:00']];

        // Layar kasir: kartu Tagihan Bulanan PIC menanggung seluruh sewa lapangan.
        $this->actingAs($receptionist);
        $pos = Livewire::test(\App\Filament\Pages\BookOfflineCourt::class)->call('selectCustomer', $this->pic->id);
        $this->assertSame([MonthlyBilling::METHOD, 'Tagihan Bulanan Huawei'], [$pos->instance()->activeMembershipInfo['quota_type'], $pos->instance()->activeMembershipInfo['plan_name']]);
        $quote = $service->quoteWalkInBenefits($slots, '2026-11-04', $this->pic, $this->balance->id);
        $this->assertSame([true, true, $this->balance->id], [$quote['membership']['applies'], $quote['membership']['monthly'], $quote['membership']['balance_id']]);
        $this->assertEquals([400000, 0], [$quote['membership_discount'], $quote['court_total_after']]);

        $result = $service->processWalkInCheckout(
            customer: $this->pic, slots: $slots, bookingDate: '2026-11-04', equipments: [], paymentMethod: 'VOUCHER', cashier: $receptionist,
            membershipBalanceId: $this->balance->id, expectedGrandTotal: 0.0,
        );
        $booking = $result['bookings']->first();
        $this->assertSame(['PAID', 'WALK_IN', $this->balance->id, MonthlyBilling::METHOD], [$booking->status, $booking->order->order_type, $booking->membership_balance_id, $result['payment_method']]);
        $this->assertSame('Walk-in Resepsionis', MonthlyBilling::recap($this->balance, '2026-11')['rows'][0]['source']);

        // Customer bukan PIC → tidak ada Tagihan Bulanan.
        $stranger = User::factory()->customer()->create();
        $this->assertFalse(($service->quoteWalkInBenefits($slots, '2026-11-05', $stranger)['membership']['monthly'] ?? false));
    }

    public function test_pic_of_a_monthly_billing_company_cannot_share_hours(): void
    {
        $employee = User::factory()->customer()->create(['name' => 'Karyawan Huawei', 'phone' => '081234500099']);
        $member = SponsorOrganizationMember::create(['sponsor_organization_id' => $this->org->id, 'user_id' => $employee->id, 'status' => 'ACTIVE']);

        $this->assertTrue($this->org->usesMonthlyBilling());
        foreach (SponsorOrganization::PIC_HOUR_ACTIONS as $action) {
            $this->assertFalse($this->org->picAllows($action));
        }
        $this->assertTrue($this->org->picAllows(SponsorOrganization::PIC_MANAGE_MEMBERS));

        $this->actingAs($this->pic);
        $this->postJson("/api/v1/sponsor/organization/members/{$member->id}/release-voucher", ['hours' => 2])->assertForbidden();
        $this->postJson('/api/v1/sponsor/organization/members/bulk-release-voucher', ['mode' => 'uniform', 'hours' => 2])->assertForbidden();
        $this->assertSame(0, \App\Models\Sponsor\SponsorMemberVoucher::count());

        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->actingAs($this->pic, 'web')->get('/corporate')->assertOk()->assertSee('Paket Tagihan Bulanan.');
    }

    public function test_plan_form_saves_monthly_billing_only_for_organizational_front_desk_plans(): void
    {
        $plan = MembershipPlan::create(['code' => 'CORP-NEW', 'name' => 'Corporate Baru', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 0, 'is_active' => true]);
        $padel = MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 10, 'discount_percent' => 10]);
        $field = fn (string $name) => "data.benefits.record-{$padel->id}.{$name}";
        $edit = fn () => Livewire::actingAs(User::factory()->admin()->create(['is_active' => true]))
            ->test(\App\Filament\Resources\Membership\Pages\EditMembershipPlan::class, ['record' => $plan->getRouteKey()]);

        // Dicentang → tipe kuota otomatis "Tanpa Kuota"; paket perorangan & dijual online → ditolak.
        $edit()->assertSet($field('monthly_billing_enabled'), false)
            ->set($field('monthly_billing_enabled'), true)
            ->assertSet($field('quota_type'), 'NONE')
            ->assertDontSee('Dasar Harga Sewa')->assertDontSee('Tanggal Jatuh Tempo')
            ->call('save')
            ->assertHasFormErrors(['sales_channel', 'ownership_type']);

        // Selama dicentang, tipe kuota jam tidak dapat dipilih kembali.
        $edit()->set('data.sales_channel', MembershipPlan::SALES_POS_ONLY)->set('data.ownership_type', 'ORGANIZATIONAL')
            ->set($field('monthly_billing_enabled'), true)
            ->set($field('quota_type'), 'HOURS')
            ->call('save')
            ->assertHasFormErrors(["benefits.record-{$padel->id}.quota_type"]);

        $edit()->set('data.sales_channel', MembershipPlan::SALES_POS_ONLY)->set('data.ownership_type', 'ORGANIZATIONAL')
            ->set($field('monthly_billing_enabled'), true)
            ->call('save')
            ->assertHasNoFormErrors();

        $padel->refresh();
        $this->assertSame('NONE', $padel->quota_type);
        $this->assertEquals(0, (float) $padel->discount_percent);
        $this->assertSame(['enabled' => true], $padel->extra_benefits[MonthlyBilling::KEY]);

        // Dimatikan lagi → penanda dihapus.
        $edit()->assertSet($field('monthly_billing_enabled'), true)
            ->set($field('monthly_billing_enabled'), false)
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull(MonthlyBilling::settings($padel->fresh()->extra_benefits));
    }

    public function test_regular_padel_plans_are_saved_unchanged_without_monthly_billing(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        // Paket lama (data produksi) disimpan ulang tanpa menyentuh Tagihan Bulanan → kuota, diskon & catatan tetap.
        $plan = MembershipPlan::create(['code' => 'SILVER-OLD', 'name' => 'Silver Lama', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 500000, 'is_active' => true]);
        $padel = MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 10, 'discount_percent' => 10, 'extra_benefits' => ['note' => 'Termasuk bola']]);
        Livewire::actingAs($admin)->test(\App\Filament\Resources\Membership\Pages\EditMembershipPlan::class, ['record' => $plan->getRouteKey()])
            ->assertSet("data.benefits.record-{$padel->id}.monthly_billing_enabled", false)
            ->call('save')->assertHasNoFormErrors();

        $padel->refresh();
        $this->assertSame('HOURS', $padel->quota_type);
        $this->assertEquals(10, (float) $padel->quota_value);
        $this->assertEquals(10, (float) $padel->discount_percent);
        $this->assertSame('Termasuk bola', $padel->extra_benefits['note'] ?? null);
        $this->assertArrayNotHasKey(MonthlyBilling::KEY, (array) $padel->extra_benefits);

        // Paket baru biasa → penanda Tagihan Bulanan tidak ikut tersimpan.
        Livewire::actingAs($admin)->test(\App\Filament\Resources\Membership\Pages\CreateMembershipPlan::class)
            ->fillForm([
                'code' => 'GOLD-NEW', 'name' => 'Gold Baru', 'ownership_type' => 'INDIVIDUAL', 'duration_days' => 30, 'price' => 750000, 'is_active' => true,
                'benefits' => [['facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 8, 'discount_percent' => 15]],
            ])
            ->call('create')->assertHasNoFormErrors();

        $created = MembershipPlan::where('code', 'GOLD-NEW')->firstOrFail()->benefits()->where('facility', 'PADEL')->firstOrFail();
        $this->assertSame('HOURS', $created->quota_type);
        $this->assertEquals(15, (float) $created->discount_percent);
        $this->assertArrayNotHasKey(MonthlyBilling::KEY, (array) $created->extra_benefits);
    }

    public function test_apply_rules_keeps_cards_with_upcoming_monthly_bookings(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['is_active' => true]);
        $booking = $this->generate()->first();

        Livewire::actingAs($superAdmin)->test(ListMembershipPlans::class)
            ->callTableAction('applyRules', $this->plan)->assertNotified('Aturan paket diterapkan');
        $this->assertNotNull(MonthlyBilling::forBalance($this->balance->fresh()));

        // Tagihan Bulanan dimatikan di paket → kartu yang masih punya booking belum dimainkan tidak diubah.
        $this->benefit->update(['extra_benefits' => []]);
        $result = app(MembershipBalanceService::class)->applyPlanAccessRules($this->plan->fresh(), $superAdmin);
        $this->assertCount(1, $result['monthly_skipped']);
        $this->assertNotNull(MonthlyBilling::forBalance($this->balance->fresh()));
        $this->assertSame('PAID', $booking->fresh()->status);
    }
}
