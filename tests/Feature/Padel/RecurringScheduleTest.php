<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\MetodePembayaranOnline;
use App\Filament\Resources\Padel\Pages\ManagePadelRecurringSchedules;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\BookingTimeSetting;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelRecurringOccurrence;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Payment;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\User;
use App\Services\Padel\BookingTimeService;
use App\Services\Padel\PadelBookingService;
use App\Services\Padel\RecurringScheduleService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Jadwal Tetap Mingguan: booking rutin corporate / member dibuat otomatis dari kuota jam membership, tanpa uang masuk.
 * Batas reschedule = satu pengaturan global admin (mis. H-3) yang berlaku untuk SEMUA booking (bawaan 2 jam).
 */
class RecurringScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pic;

    private UserMembershipBalance $balance;

    /** @var array<int, PadelCourt> */
    private array $courts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        ClubFinanceSetting::getSettings()->update(['is_admin_fee_enabled' => false, 'is_tax_enabled' => false]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);

        // Senin 2 Nov 2026 10:00 — Kamis berikutnya 5 Nov.
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));

        $this->admin = User::factory()->admin()->create();
        $this->pic = User::factory()->customer()->create(['name' => 'PIC Indosat']);
        foreach (['A', 'B', 'C'] as $name) {
            $this->courts[] = PadelCourt::create(['name' => 'Court '.$name, 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        }

        $plan = MembershipPlan::create(['code' => 'CORP-IOH', 'name' => 'Corporate 100 Jam', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 365, 'price' => 0, 'is_active' => true]);
        $membership = UserMembership::create([
            'membership_code' => 'MBR-IOH', 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $this->pic->id, 'plan_id' => $plan->id,
            'status' => 'ACTIVE', 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'qr_pass_hash' => 'QR-IOH',
        ]);
        $this->balance = UserMembershipBalance::create([
            'user_membership_id' => $membership->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'initial_quota' => 100, 'remaining_quota' => 100,
        ]);
        SponsorOrganization::create(['name' => 'IOH', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $this->pic->id, 'status' => 'ACTIVE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(array $overrides = []): PadelRecurringSchedule
    {
        return PadelRecurringSchedule::create($overrides + [
            'name' => 'IOH Kamis malam',
            'user_membership_id' => $this->balance->user_membership_id,
            'day_of_week' => 4,
            'start_time' => '19:00',
            'end_time' => '22:00',
            'court_ids' => collect($this->courts)->pluck('id')->all(),
            'starts_on' => '2026-11-02',
            'weeks_ahead' => 2,
            'status' => PadelRecurringSchedule::STATUS_ACTIVE,
            'created_by_id' => $this->admin->id,
        ]);
    }

    private function service(): RecurringScheduleService
    {
        return app(RecurringScheduleService::class);
    }

    public function test_generates_weekly_bookings_paid_by_quota_without_a_cashier_shift(): void
    {
        $schedule = $this->schedule();

        $result = $this->service()->generate($schedule, $this->admin);

        // 2 Kamis (5 & 12 Nov) × 3 lapangan, 3 jam per booking = 18 jam.
        $this->assertSame(6, $result['booked']);
        $this->assertSame([], $result['failed']);
        $this->assertEquals(82, (float) $this->balance->fresh()->remaining_quota);

        $bookings = PadelBooking::where('recurring_schedule_id', $schedule->id)->get();
        $this->assertCount(6, $bookings);
        $this->assertEqualsCanonicalizing(['2026-11-05', '2026-11-12'], $bookings->map(fn ($b) => $b->booking_date->toDateString())->unique()->values()->all());
        foreach ($bookings as $b) {
            $this->assertSame('PAID', $b->status);
            $this->assertSame($this->pic->id, $b->user_id);
            $this->assertEquals(0, (float) $b->court_fee);
            $this->assertEquals(3, (float) $b->member_hours_consumed);
            $this->assertSame('19:00', $b->start_time->format('H:i'));
            $this->assertSame('22:00', $b->end_time->format('H:i'));
            $this->assertSame('PAID', $b->order->payment_status);
            $this->assertEquals(0, (float) $b->order->grand_total);
        }
        $this->assertSame(6, Payment::where('payment_method', 'MEMBERSHIP_QUOTA')->where('amount', 0)->where('status', 'SUCCESS')->count());

        // Dijalankan lagi (tombol + perintah malam): tidak ada booking / potongan ganda.
        $again = $this->service()->generate($schedule->fresh(), $this->admin);
        $this->assertSame(0, $again['booked']);
        $this->assertSame(6, PadelBooking::where('recurring_schedule_id', $schedule->id)->count());
        $this->assertEquals(82, (float) $this->balance->fresh()->remaining_quota);

        // Minggu berikutnya masuk jangkauan → dibuat oleh perintah terjadwal.
        Carbon::setTestNow(Carbon::parse('2026-11-09 01:00:00'));
        $this->artisan('padel:generate-recurring')->assertSuccessful();
        $this->assertSame(9, PadelBooking::where('recurring_schedule_id', $schedule->id)->count());
        $this->assertEquals(73, (float) $this->balance->fresh()->remaining_quota);
    }

    public function test_a_taken_slot_fails_only_that_court_and_is_retried(): void
    {
        $customer = User::factory()->customer()->create();
        $taken = PadelBooking::create([
            'booking_code' => 'BK-'.strtoupper(Str::random(6)), 'user_id' => $customer->id, 'court_id' => $this->courts[1]->id,
            'booking_date' => '2026-11-05', 'start_time' => Carbon::parse('2026-11-05 20:00'), 'end_time' => Carbon::parse('2026-11-05 21:00'),
            'court_fee' => 300000, 'coach_fee' => 0, 'equipment_fee' => 0, 'total_amount' => 300000, 'status' => 'PAID',
        ]);
        $schedule = $this->schedule();

        $result = $this->service()->generate($schedule, $this->admin);

        $this->assertSame(5, $result['booked']);
        $this->assertCount(1, $result['failed']);
        $failed = PadelRecurringOccurrence::where('status', 'FAILED')->sole();
        $this->assertSame($this->courts[1]->id, $failed->court_id);
        $this->assertSame('2026-11-05', $failed->occurrence_date->toDateString());
        $this->assertEquals(85, (float) $this->balance->fresh()->remaining_quota);
        // Hold yang gagal dilepas — tidak ada slot nyangkut.
        $this->assertSame(0, PadelBooking::where('user_id', $this->pic->id)->where('status', 'LOCKED')->count());

        $taken->update(['status' => 'CANCELLED']);
        $this->assertSame(1, $this->service()->generate($schedule->fresh(), $this->admin)['booked']);
        $this->assertSame('BOOKED', $failed->fresh()->status);
        $this->assertEquals(82, (float) $this->balance->fresh()->remaining_quota);
    }

    public function test_insufficient_quota_never_becomes_a_bill(): void
    {
        $this->balance->update(['remaining_quota' => 7]);
        $schedule = $this->schedule(['weeks_ahead' => 1]);

        $result = $this->service()->generate($schedule, $this->admin);

        // 7 jam = 2 lapangan × 3 jam; lapangan ke-3 gagal, sisa 1 jam tidak dipakai.
        $this->assertSame(2, $result['booked']);
        $this->assertStringContainsString('Sisa kuota jam tidak cukup', $result['failed'][0]);
        $this->assertEquals(1, (float) $this->balance->fresh()->remaining_quota);
        $this->assertSame(2, PadelBooking::where('user_id', $this->pic->id)->where('status', 'PAID')->count());
        $this->assertSame(0, PadelBooking::where('user_id', $this->pic->id)->whereIn('status', ['LOCKED', 'PENDING_PAYMENT'])->count());
        $this->assertSame(0, Payment::where('amount', '>', 0)->count());
    }

    private function setRescheduleCutoff(int $hours): void
    {
        BookingTimeSetting::query()->update(['reschedule_cutoff_hours' => $hours]);
        app(BookingTimeService::class)->flush();
    }

    public function test_reschedule_cutoff_is_one_global_setting_for_every_booking(): void
    {
        $schedule = $this->schedule();
        $this->service()->generate($schedule, $this->admin);
        $booking = PadelBooking::where('recurring_schedule_id', $schedule->id)->whereDate('booking_date', '2026-11-05')->where('court_id', $this->courts[0]->id)->sole();
        $later = PadelBooking::where('recurring_schedule_id', $schedule->id)->whereDate('booking_date', '2026-11-12')->where('court_id', $this->courts[0]->id)->sole();
        $regular = new PadelBooking(['start_time' => Carbon::parse('2026-11-05 19:00')]);

        // Bawaan tetap aturan lama: 2 jam, untuk semua booking.
        $this->assertSame(2, PadelBookingService::rescheduleCutoffHours($booking));
        $this->assertSame(2, PadelBookingService::rescheduleCutoffHours($regular));

        // Admin mengatur H-3 → booking jadwal tetap DAN booking biasa ikut.
        $this->setRescheduleCutoff(72);
        $this->assertSame(72, PadelBookingService::rescheduleCutoffHours($booking));
        $this->assertSame(72, PadelBookingService::rescheduleCutoffHours($regular));
        $this->assertSame('3 hari', PadelBookingService::rescheduleCutoffLabel($regular));
        $this->assertSame('2026-11-02 19:00', PadelBookingService::rescheduleDeadline($booking)->format('Y-m-d H:i'));

        // Kamis 5 Nov masih > H-3 pada Senin 10:00 → boleh; kuota ikut pindah (tidak ditagih).
        $service = app(PadelBookingService::class);
        $service->adminRescheduleBooking($booking->id, $this->courts[0]->id, '2026-11-06', '19:00', 'Minta geser', $this->admin);
        $this->assertSame('2026-11-06', $booking->fresh()->booking_date->toDateString());
        $this->assertEquals(0, (float) $booking->fresh()->court_fee);
        $this->assertEquals(82, (float) $this->balance->fresh()->remaining_quota);

        // Kamis 12 Nov dilihat dari Selasa 10 Nov → lewat H-3 → ditolak.
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00'));
        try {
            $service->adminRescheduleBooking($later->id, $this->courts[0]->id, '2026-11-13', '19:00', 'Telat', $this->admin);
            $this->fail('Reschedule setelah H-3 harus ditolak.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('3 hari sebelum jam main', $e->getMessage());
        }

        // Booking biasa (customer online) juga ditolak lewat H-3.
        $customer = User::factory()->customer()->create();
        $online = PadelBooking::create([
            'booking_code' => 'BK-'.strtoupper(Str::random(6)), 'user_id' => $customer->id, 'court_id' => $this->courts[1]->id,
            'booking_date' => '2026-11-12', 'start_time' => Carbon::parse('2026-11-12 10:00'), 'end_time' => Carbon::parse('2026-11-12 11:00'),
            'court_fee' => 300000, 'coach_fee' => 0, 'equipment_fee' => 0, 'total_amount' => 300000, 'status' => 'PAID',
        ]);
        try {
            $service->adminRescheduleBooking($online->id, $this->courts[1]->id, '2026-11-13', '10:00', 'Telat', $this->admin);
            $this->fail('Booking biasa juga harus ikut batas global.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('3 hari sebelum jam main', $e->getMessage());
        }

        // Admin mengubah batas jadi 1 hari → langsung berlaku.
        $this->setRescheduleCutoff(24);
        $service->adminRescheduleBooking($later->id, $this->courts[0]->id, '2026-11-13', '19:00', 'Batas diubah', $this->admin);
        $this->assertSame('2026-11-13', $later->fresh()->booking_date->toDateString());

        // Generator tidak membuat ulang tanggal asli yang bookingnya sudah dipindah.
        $this->service()->generate($schedule->fresh(), $this->admin);
        $this->assertSame(0, PadelBooking::where('recurring_schedule_id', $schedule->id)->whereDate('booking_date', '2026-11-12')->where('court_id', $this->courts[0]->id)->count());
    }

    public function test_skipping_a_date_cancels_its_bookings_and_returns_the_hours(): void
    {
        $schedule = $this->schedule();
        $this->service()->generate($schedule, $this->admin);
        $this->assertEquals(82, (float) $this->balance->fresh()->remaining_quota);

        $cancelled = $this->service()->skip($schedule, '2026-11-12', 'Grand Opening', $this->admin);

        $this->assertSame(3, $cancelled);
        $this->assertEquals(91, (float) $this->balance->fresh()->remaining_quota);
        $this->assertSame(3, PadelBooking::where('recurring_schedule_id', $schedule->id)->whereDate('booking_date', '2026-11-12')->where('status', 'CANCELLED')->count());
        $this->assertSame(3, PadelRecurringOccurrence::where('status', 'SKIPPED')->count());

        // Pilihan "Lewati tanggal" tidak menampilkan tanggal yang sudah dilewati; muncul lagi setelah "Batal lewati".
        $options = fn () => array_keys(\App\Filament\Resources\Padel\PadelRecurringScheduleResource::upcomingDateOptions($schedule->fresh()));
        $this->assertSame(['2026-11-05', '2026-11-19'], array_slice($options(), 0, 2));
        $labels = \App\Filament\Resources\Padel\PadelRecurringScheduleResource::upcomingDateOptions($schedule->fresh());
        $this->assertSame('Kamis, 05 Nov 2026 — sudah dibooking, 9 jam kuota dikembalikan', $labels['2026-11-05'], '3 jam × 3 lapangan');
        $this->assertSame('Kamis, 19 Nov 2026 — belum dibooking, kuota belum terpotong', $labels['2026-11-19']);

        // Tidak dibuat ulang selama masih dilewati; dibuat lagi setelah "batal lewati".
        $this->assertSame(0, $this->service()->generate($schedule->fresh(), $this->admin)['booked']);
        $this->service()->unskip($schedule, '2026-11-12', $this->admin);
        $this->assertSame(['2026-11-05', '2026-11-12', '2026-11-19'], array_slice($options(), 0, 3));

        // Hari ini yang jam mainnya sudah dimulai tidak ditawarkan.
        $this->travelTo(Carbon::parse('2026-11-05 19:30'));
        $this->assertSame('2026-11-12', $options()[0]);
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));
        $this->assertSame(3, $this->service()->generate($schedule->fresh(), $this->admin)['booked']);
        $this->assertEquals(82, (float) $this->balance->fresh()->remaining_quota);

        // Hari yang bukan Kamis ditolak.
        $this->expectException(HttpException::class);
        $this->service()->skip($schedule, '2026-11-13', 'Salah hari', $this->admin);
    }

    public function test_table_shows_until_which_date_the_quota_covers_the_schedule(): void
    {
        // Kamis 5 Nov – 3 Des (5 minggu), 3 lapangan × 3 jam = 9 jam/minggu; 5 & 12 Nov sudah dibuat.
        $schedule = $this->schedule(['ends_on' => '2026-12-03']);
        $this->service()->generate($schedule, $this->admin);
        $this->balance->update(['remaining_quota' => 12]);

        $forecast = $this->service()->quotaForecast($schedule->fresh());
        $this->assertEquals(15, $forecast['shortfall'], '26 Nov kurang 2 lapangan (6 jam) + 3 Des penuh (9 jam)');
        $this->assertSame('2026-11-19', $forecast['covered_until']->toDateString());
        $this->assertSame(['2026-11-26', 1, 3], [$forecast['partial']['date']->toDateString(), $forecast['partial']['courts'], $forecast['partial']['of']]);
        $this->assertSame('2026-12-03', $forecast['first_missing']->toDateString());

        $this->actingAs($this->admin);
        Livewire::test(ManagePadelRecurringSchedules::class)
            ->assertSee('Kuota kurang 15 jam untuk sampai Kam, 03 Des 2026.')
            ->assertSee('Cukup sampai Kam, 19 Nov 2026.')
            ->assertSee('Kam, 26 Nov 2026: hanya 1 dari 3 lapangan.')
            ->assertSee('Kam, 03 Des 2026 dst. tidak akan dibuat.')
            ->assertSee('booking dibuat s/d 12 Nov 2026');

        // Tanggal yang dilewati tidak butuh kuota; kuota cukup → keterangan hijau.
        $this->service()->skip($schedule, '2026-12-03', 'Libur', $this->admin);
        $this->balance->update(['remaining_quota' => 18]);
        $this->assertEquals(0, $this->service()->quotaForecast($schedule->fresh())['shortfall']);
        Livewire::test(ManagePadelRecurringSchedules::class)
            ->assertSee('Kuota cukup sampai akhir periode (Kam, 03 Des 2026)')
            ->assertDontSee('Kuota kurang');

        // Jadwal lain dari membership yang sama memakai kuota yang sama.
        $this->schedule(['name' => 'Jumat pagi', 'day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '09:00', 'court_ids' => [$this->courts[0]->id], 'ends_on' => '2026-11-27']);
        $this->assertGreaterThan(0, $this->service()->quotaForecast($schedule->fresh())['shortfall']);
    }

    public function test_skip_before_generation_and_paused_schedule_create_nothing(): void
    {
        $schedule = $this->schedule();
        $this->service()->skip($schedule, '2026-11-05', 'Lapangan dipakai acara', $this->admin);
        $this->assertSame(3, $this->service()->generate($schedule, $this->admin)['booked']);
        $this->assertSame(0, PadelBooking::whereDate('booking_date', '2026-11-05')->count());

        $paused = $this->schedule(['name' => 'Dijeda', 'day_of_week' => 5, 'status' => PadelRecurringSchedule::STATUS_PAUSED]);
        $this->assertSame(0, $this->service()->generate($paused, $this->admin)['booked']);
    }

    public function test_form_input_is_checked_on_the_server(): void
    {
        $base = [
            'name' => 'IOH', 'user_membership_id' => $this->balance->user_membership_id, 'day_of_week' => 4,
            'start_time' => '19:00', 'end_time' => '22:00', 'court_ids' => [$this->courts[0]->id],
            'starts_on' => '2026-11-05', 'weeks_ahead' => 5,
        ];

        $this->assertSame([$this->courts[0]->id], $this->service()->normalize($base)['court_ids']);

        $bad = [
            ['start_time' => '19:30'],
            ['end_time' => '19:00'],
            ['day_of_week' => 9],
            ['court_ids' => [(string) Str::ulid()]],
            ['weeks_ahead' => 40],
            ['user_membership_id' => (string) Str::ulid()],
            ['ends_on' => '2026-11-01'],
        ];
        foreach ($bad as $override) {
            try {
                $this->service()->normalize($override + $base);
                $this->fail('Isian harus ditolak: '.json_encode($override));
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }

        // Membership yang sudah tidak aktif tidak bisa dipilih.
        UserMembership::whereKey($this->balance->user_membership_id)->update(['status' => 'EXPIRED']);
        $this->expectException(HttpException::class);
        $this->service()->normalize($base);
    }

    public function test_admin_creates_a_schedule_from_the_panel_and_bookings_are_made_immediately(): void
    {
        $this->assertTrue($this->admin->can('manage_recurring_bookings'));
        $this->actingAs($this->admin);

        Livewire::test(ManagePadelRecurringSchedules::class)
            ->callAction('create', [
                'name' => 'IOH Kamis malam', 'user_membership_id' => $this->balance->user_membership_id, 'day_of_week' => 4,
                'start_time' => '19:00', 'end_time' => '22:00', 'court_ids' => collect($this->courts)->pluck('id')->all(),
                'starts_on' => '2026-11-05', 'weeks_ahead' => 1,
            ])
            ->assertHasNoActionErrors();

        $schedule = PadelRecurringSchedule::sole();
        $this->assertSame($this->admin->id, $schedule->created_by_id);
        $this->assertSame('ACTIVE', $schedule->status);
        $this->assertSame(3, PadelBooking::where('recurring_schedule_id', $schedule->id)->where('status', 'PAID')->count());
        $this->assertSame('IOH', $schedule->ownerLabel());
        $this->assertSame('2027-10-31', $schedule->ends_on->toDateString(), 'bawaan: sampai membership habis');

        // "Selama 2 minggu" → berakhir 13 hari setelah mulai (2 kali hari jadwal), bukan sampai membership habis.
        Livewire::test(ManagePadelRecurringSchedules::class)
            ->callAction('create', [
                'name' => 'Turnamen 2 minggu', 'user_membership_id' => $this->balance->user_membership_id, 'day_of_week' => 6,
                'start_time' => '08:00', 'end_time' => '10:00', 'court_ids' => [$this->courts[0]->id],
                'starts_on' => '2026-11-05', 'period_mode' => 'WEEKS', 'period_weeks' => 2, 'weeks_ahead' => 5,
            ])
            ->assertHasNoActionErrors();
        $twoWeeks = PadelRecurringSchedule::where('name', 'Turnamen 2 minggu')->sole();
        $this->assertSame('2026-11-18', $twoWeeks->ends_on->toDateString());
        $this->assertSame(['2026-11-07', '2026-11-14'], PadelBooking::where('recurring_schedule_id', $twoWeeks->id)
            ->orderBy('booking_date')->get()->map(fn ($b) => $b->booking_date->toDateString())->all(), 'kunci 5 minggu tetap hanya 2 minggu jadwal');
        $this->assertSame(['2026-11-07', '2026-11-14'], array_keys(\App\Filament\Resources\Padel\PadelRecurringScheduleResource::upcomingDateOptions($twoWeeks)));

        // Form edit membaca kembali pilihan "Lama jadwal".
        $this->assertSame(['period_mode' => 'MEMBERSHIP', 'ends_on' => null], \App\Filament\Resources\Padel\PadelRecurringScheduleResource::periodState($schedule));
        $this->assertSame(['period_mode' => 'DATE', 'ends_on' => '2026-11-18'], \App\Filament\Resources\Padel\PadelRecurringScheduleResource::periodState($twoWeeks));
    }

    public function test_owner_sets_the_global_reschedule_cutoff_with_its_own_button(): void
    {
        $owner = User::factory()->superAdmin()->create();

        Livewire::actingAs($owner)->test(ManagePadelRecurringSchedules::class)
            ->assertActionVisible('rescheduleCutoff')
            ->callAction('rescheduleCutoff', ['value' => 3, 'unit' => 'DAYS'])
            ->assertHasNoActionErrors();
        $this->assertSame(72, app(BookingTimeService::class)->rescheduleCutoffHours());

        // Lebih dari 30 hari ditolak, nilai lama tetap.
        Livewire::actingAs($owner)->test(ManagePadelRecurringSchedules::class)
            ->callAction('rescheduleCutoff', ['value' => 31, 'unit' => 'DAYS']);
        $this->assertSame(72, app(BookingTimeService::class)->rescheduleCutoffHours());

        // Modal Atur Batas Waktu (tahan slot & batas bayar) terpisah dan tidak mengubah batas reschedule.
        Livewire::actingAs($owner)->test(MetodePembayaranOnline::class)
            ->callAction('bookingTimes', ['slot_hold_minutes' => 12, 'payment_window_minutes' => 20])
            ->assertHasNoActionErrors();
        $this->app->forgetInstance(BookingTimeService::class);
        $this->assertSame(72, app(BookingTimeService::class)->rescheduleCutoffHours());

        // Admin biasa (tanpa manage_booking_time_limits) tidak melihat tombolnya.
        Livewire::actingAs($this->admin)->test(ManagePadelRecurringSchedules::class)
            ->assertActionHidden('rescheduleCutoff');
    }

    // ---- Edit jadwal → booking yang sudah dibuat ikut dipindah (aturan reschedule global) ----

    /** Isian edit = isian sekarang + perubahan, lewat normalize (sama dengan form). */
    private function edit(PadelRecurringSchedule $schedule, array $changes): array
    {
        $data = app(RecurringScheduleService::class)->normalize($changes + RecurringScheduleService::formState($schedule), $schedule);

        return $this->service()->updateSchedule($schedule, $data, $this->admin);
    }

    private function codes(PadelRecurringSchedule $schedule): array
    {
        return PadelBooking::where('recurring_schedule_id', $schedule->id)->where('status', 'PAID')->orderBy('start_time')->get()
            ->map(fn ($b) => $b->booking_date->format('D Y-m-d').' '.$b->start_time->format('H:i').'-'.$b->end_time->format('H:i').' '.$b->court->name)->all();
    }

    public function test_changing_the_day_moves_existing_bookings_without_cancelling_them(): void
    {
        $schedule = $this->schedule(['court_ids' => [$this->courts[0]->id], 'end_time' => '21:00']);
        $this->service()->generate($schedule, $this->admin);
        $before = PadelBooking::where('recurring_schedule_id', $schedule->id)->orderBy('start_time')->pluck('booking_code')->all();
        $this->assertSame(['Thu 2026-11-05 19:00-21:00 Court A', 'Thu 2026-11-12 19:00-21:00 Court A'], $this->codes($schedule));

        $summary = $this->edit($schedule, ['day_of_week' => 7]);

        $this->assertSame(2, $summary['moved']);
        $this->assertSame(0, $summary['cancelled']);
        $this->assertSame(0, $summary['generated']['booked']);
        $this->assertSame(['Sun 2026-11-08 19:00-21:00 Court A', 'Sun 2026-11-15 19:00-21:00 Court A'], $this->codes($schedule));
        // Booking yang sama (kode sama), tidak ada yang dibatalkan, kuota tidak berubah.
        $this->assertSame($before, PadelBooking::where('recurring_schedule_id', $schedule->id)->orderBy('start_time')->pluck('booking_code')->all());
        $this->assertSame(0, PadelBooking::where('status', 'CANCELLED')->count());
        $this->assertEquals(96, (float) $this->balance->fresh()->remaining_quota);
        $this->assertSame(1, (int) PadelBooking::where('booking_code', $before[0])->value('reschedule_count'));
    }

    public function test_booking_inside_the_reschedule_cutoff_stays_and_that_week_is_not_doubled(): void
    {
        $schedule = $this->schedule(['court_ids' => [$this->courts[0]->id], 'end_time' => '21:00']);
        $this->service()->generate($schedule, $this->admin);
        // H-4: Kamis 5 Nov 19:00 batasnya Minggu 1 Nov 19:00 (sudah lewat); Kamis 12 Nov masih bisa.
        $this->setRescheduleCutoff(96);

        $summary = $this->edit($schedule, ['day_of_week' => 7]);

        $this->assertSame(1, $summary['moved']);
        $this->assertCount(1, $summary['kept']);
        $this->assertStringContainsString('Lewat batas reschedule', $summary['kept'][0]);
        $this->assertSame(['Thu 2026-11-05 19:00-21:00 Court A', 'Sun 2026-11-15 19:00-21:00 Court A'], $this->codes($schedule));
        // Minggu 8 Nov tidak dibuatkan booking kedua (minggu itu tetap main Kamis).
        $this->assertSame('SKIPPED', PadelRecurringOccurrence::whereDate('occurrence_date', '2026-11-08')->value('status'));
        $this->assertEquals(96, (float) $this->balance->fresh()->remaining_quota);
    }

    public function test_longer_duration_is_applied_when_the_extra_hour_is_free_and_quota_allows(): void
    {
        $schedule = $this->schedule(['court_ids' => [$this->courts[0]->id], 'end_time' => '21:00', 'weeks_ahead' => 1]);
        $this->service()->generate($schedule, $this->admin);
        $this->assertEquals(98, (float) $this->balance->fresh()->remaining_quota);

        $this->edit($schedule, ['end_time' => '22:00']);

        $this->assertSame(['Thu 2026-11-05 19:00-22:00 Court A'], $this->codes($schedule));
        $this->assertEquals(97, (float) $this->balance->fresh()->remaining_quota);
        $this->assertEquals(3, (float) PadelBooking::where('recurring_schedule_id', $schedule->id)->value('member_hours_consumed'));

        // Dipersingkat lagi → jamnya kembali.
        $this->edit($schedule->fresh(), ['end_time' => '20:00']);
        $this->assertSame(['Thu 2026-11-05 19:00-20:00 Court A'], $this->codes($schedule));
        $this->assertEquals(99, (float) $this->balance->fresh()->remaining_quota);
    }

    public function test_longer_duration_keeps_the_old_booking_when_the_extra_hour_is_taken_or_quota_is_short(): void
    {
        $schedule = $this->schedule(['court_ids' => [$this->courts[0]->id], 'end_time' => '21:00', 'weeks_ahead' => 2]);
        $this->service()->generate($schedule, $this->admin);
        $customer = User::factory()->customer()->create();
        PadelBooking::create([
            'booking_code' => 'BK-LAIN', 'user_id' => $customer->id, 'court_id' => $this->courts[0]->id,
            'booking_date' => '2026-11-05', 'start_time' => Carbon::parse('2026-11-05 21:00'), 'end_time' => Carbon::parse('2026-11-05 22:00'),
            'court_fee' => 300000, 'coach_fee' => 0, 'equipment_fee' => 0, 'total_amount' => 300000, 'status' => 'PAID',
        ]);

        $summary = $this->edit($schedule, ['end_time' => '22:00']);

        // 5 Nov: jam 21-22 sudah diisi orang → tetap 19-21. 12 Nov: kosong → 19-22.
        $this->assertSame(['Thu 2026-11-05 19:00-21:00 Court A', 'Thu 2026-11-12 19:00-22:00 Court A'], $this->codes($schedule));
        $this->assertStringContainsString('sudah terisi (BK-LAIN)', implode(' ', $summary['kept']));
        $this->assertEquals(95, (float) $this->balance->fresh()->remaining_quota);

        // Kuota habis → jam mulai tetap ikut pindah, durasi lama dipertahankan.
        $this->balance->fresh()->update(['remaining_quota' => 0]);
        $summary = $this->edit($schedule->fresh(), ['start_time' => '18:00', 'end_time' => '22:00']);
        $this->assertSame(['Thu 2026-11-05 18:00-20:00 Court A', 'Thu 2026-11-12 18:00-21:00 Court A'], $this->codes($schedule));
        $this->assertStringContainsString('durasi lama dipertahankan', implode(' ', $summary['kept']));
        $this->assertEquals(0, (float) $this->balance->fresh()->remaining_quota);
    }

    public function test_court_changes_move_cancel_or_add_bookings(): void
    {
        $courtD = PadelCourt::create(['name' => 'Court D', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        [$a, $b, $c] = $this->courts;
        $schedule = $this->schedule(['weeks_ahead' => 1]); // Kamis 5 Nov, A B C, 3 jam
        $this->service()->generate($schedule, $this->admin);
        $this->assertEquals(91, (float) $this->balance->fresh()->remaining_quota);

        // Ganti C → D: booking C pindah ke D (bukan batal + baru).
        $codeC = PadelBooking::where('court_id', $c->id)->value('booking_code');
        $summary = $this->edit($schedule, ['court_ids' => [$a->id, $b->id, $courtD->id]]);
        $this->assertSame(1, $summary['moved']);
        $this->assertSame($courtD->id, PadelBooking::where('booking_code', $codeC)->value('court_id'));
        $this->assertEquals(91, (float) $this->balance->fresh()->remaining_quota);

        // Kurangi jadi A B → booking D dibatalkan, jamnya kembali, slot bisa dijual lagi.
        $summary = $this->edit($schedule->fresh(), ['court_ids' => [$a->id, $b->id]]);
        $this->assertSame(1, $summary['cancelled']);
        $this->assertSame('CANCELLED', PadelBooking::where('booking_code', $codeC)->value('status'));
        $this->assertEquals(94, (float) $this->balance->fresh()->remaining_quota);
        $this->assertSame(2, PadelBooking::where('recurring_schedule_id', $schedule->id)->where('status', 'PAID')->count());

        // Tambah C lagi → langsung dibuatkan booking baru.
        $summary = $this->edit($schedule->fresh(), ['court_ids' => [$a->id, $b->id, $c->id]]);
        $this->assertSame(1, $summary['generated']['booked']);
        $this->assertSame(3, PadelBooking::where('recurring_schedule_id', $schedule->id)->where('status', 'PAID')->count());
        $this->assertEquals(91, (float) $this->balance->fresh()->remaining_quota);
    }

    public function test_dates_outside_the_period_keep_the_old_booking(): void
    {
        $schedule = $this->schedule(['court_ids' => [$this->courts[0]->id], 'end_time' => '21:00']);
        $this->service()->generate($schedule, $this->admin);

        // Periode sampai Sabtu 14 Nov: Minggu 8 Nov masih masuk, Minggu 15 Nov tidak.
        $summary = $this->edit($schedule, ['day_of_week' => 7, 'ends_on' => '2026-11-14']);

        $this->assertSame(1, $summary['moved']);
        $this->assertStringContainsString('di luar periode', implode(' ', $summary['kept']));
        $this->assertSame(['Sun 2026-11-08 19:00-21:00 Court A', 'Thu 2026-11-12 19:00-21:00 Court A'], $this->codes($schedule));
    }

    public function test_period_follows_the_membership_end_date(): void
    {
        $base = [
            'name' => 'IOH', 'user_membership_id' => $this->balance->user_membership_id, 'day_of_week' => 4,
            'start_time' => '19:00', 'end_time' => '22:00', 'court_ids' => [$this->courts[0]->id], 'starts_on' => '2026-11-05', 'weeks_ahead' => 5,
        ];
        $this->assertSame('2027-10-31', $this->service()->normalize($base)['ends_on']);

        $this->expectException(HttpException::class);
        $this->service()->normalize(['ends_on' => '2027-12-31'] + $base);
    }

    public function test_editing_from_the_panel_moves_bookings(): void
    {
        $schedule = $this->schedule(['court_ids' => [$this->courts[0]->id], 'end_time' => '21:00', 'weeks_ahead' => 1]);
        $this->service()->generate($schedule, $this->admin);

        Livewire::actingAs($this->admin)->test(ManagePadelRecurringSchedules::class)
            ->callTableAction('edit', $schedule, ['day_of_week' => 7])
            ->assertHasNoTableActionErrors();

        $this->assertSame(7, $schedule->fresh()->day_of_week);
        $this->assertSame(['Sun 2026-11-08 19:00-21:00 Court A'], $this->codes($schedule));
    }

    // ── Hapus jadwal: patokan jam main — belum mulai = batal & jam kembali utuh, sudah mulai / lewat = jam terpakai ──

    public function test_removing_a_schedule_before_anything_is_played_returns_every_hour_and_deletes_it(): void
    {
        $schedule = $this->schedule();
        $this->service()->generate($schedule, $this->admin);
        $this->assertEquals(82, (float) $this->balance->fresh()->remaining_quota);
        $this->assertSame(['cancel' => 6, 'hours' => 18.0, 'played' => 0], $this->service()->removalPreview($schedule));

        $result = $this->service()->remove($schedule, $this->admin);

        $this->assertSame([false, 6, 18.0, 0, true], [$result['busy'], $result['cancelled'], $result['hours_returned'], $result['played'], $result['deleted']]);
        $this->assertEquals(100, (float) $this->balance->fresh()->remaining_quota, 'kuota balik utuh');
        $this->assertNull(PadelRecurringSchedule::find($schedule->id));
        $this->assertSame(0, PadelRecurringOccurrence::count());
        $this->assertSame(6, PadelBooking::where('status', 'CANCELLED')->count(), 'booking tetap ada sebagai CANCELLED');
        $this->assertSame(0, PadelBooking::whereIn('status', ['PAID', 'LOCKED'])->count(), 'lapangan kosong lagi');
        $this->assertTrue(\App\Models\Audit\ActivityLog::where('event', 'recurring.removed')->exists());

        // Proses malam tidak membuat apa pun lagi.
        $this->artisan('padel:generate-recurring')->assertSuccessful();
        $this->assertSame(0, PadelBooking::whereIn('status', ['PAID', 'LOCKED'])->count());
    }

    public function test_removing_after_a_session_started_keeps_its_hours_and_stops_the_schedule(): void
    {
        $schedule = $this->schedule();
        $this->service()->generate($schedule, $this->admin);

        // Kamis 5 Nov 19:30 — sesi minggu ini sedang berjalan (dianggap sudah main), minggu 12 Nov belum.
        Carbon::setTestNow(Carbon::parse('2026-11-05 19:30:00'));
        $this->assertSame(['cancel' => 3, 'hours' => 9.0, 'played' => 3], $this->service()->removalPreview($schedule));

        $result = $this->service()->remove($schedule, $this->admin);

        $this->assertSame([3, 9.0, 3, false], [$result['cancelled'], $result['hours_returned'], $result['played'], $result['deleted']]);
        $this->assertEquals(91, (float) $this->balance->fresh()->remaining_quota, 'hanya jam yang belum dimainkan yang kembali');
        $this->assertSame(3, PadelBooking::whereDate('booking_date', '2026-11-05')->where('status', 'PAID')->count(), 'sesi yang sudah mulai tidak disentuh');
        $this->assertSame(3, PadelBooking::whereDate('booking_date', '2026-11-12')->where('status', 'CANCELLED')->count());

        $schedule->refresh();
        $this->assertSame(PadelRecurringSchedule::STATUS_STOPPED, $schedule->status);
        $this->assertSame(0, PadelRecurringOccurrence::where('recurring_schedule_id', $schedule->id)->whereIn('status', ['PENDING', 'FAILED'])->count());
        $this->assertSame(3, PadelRecurringOccurrence::where('recurring_schedule_id', $schedule->id)->whereDate('occurrence_date', '2026-11-12')->where('status', 'SKIPPED')->count());

        // Jadwal yang dihentikan tidak membuat booking lagi & tidak bisa diubah.
        Carbon::setTestNow(Carbon::parse('2026-11-09 01:00:00'));
        $this->artisan('padel:generate-recurring')->assertSuccessful();
        $this->assertSame(0, PadelBooking::where('recurring_schedule_id', $schedule->id)->whereIn('status', ['PAID', 'LOCKED'])->where('start_time', '>', now())->count());
        $this->expectException(HttpException::class);
        $this->service()->skip($schedule, '2026-11-19', 'Coba', $this->admin);
    }

    public function test_remove_waits_for_a_running_generation(): void
    {
        $schedule = $this->schedule();
        $lock = Cache::lock('padel-recurring:'.$schedule->id, 300);
        $lock->get();

        $this->assertTrue($this->service()->remove($schedule, $this->admin)['busy']);
        $this->assertNotNull(PadelRecurringSchedule::find($schedule->id));
        $lock->release();
    }

    public function test_admin_removes_a_schedule_from_the_panel_after_seeing_the_summary(): void
    {
        $schedule = $this->schedule(['court_ids' => [$this->courts[0]->id]]);
        $this->service()->generate($schedule, $this->admin);

        Livewire::actingAs($this->admin)->test(ManagePadelRecurringSchedules::class)
            ->mountTableAction('remove', $schedule)
            ->assertMountedActionModalSee('2 booking yang jam mainnya belum mulai dibatalkan, 6 jam kembali utuh ke kuota')
            ->callMountedTableAction()
            ->assertNotified('Jadwal dihapus');

        $this->assertNull(PadelRecurringSchedule::find($schedule->id));
        $this->assertEquals(100, (float) $this->balance->fresh()->remaining_quota);
    }

    public function test_staff_without_permission_cannot_open_the_page(): void
    {
        $receptionist = User::factory()->receptionist()->create();
        $this->assertFalse($receptionist->can('manage_recurring_bookings'));

        $this->actingAs($receptionist)->get('/admin/jadwal-tetap')->assertDenied();
        $this->actingAs($this->admin)->get('/admin/jadwal-tetap')->assertOk();
    }

    public function test_pages_show_the_schedule_and_its_bookings(): void
    {
        $schedule = $this->schedule();
        $this->service()->generate($schedule, $this->admin);
        $this->service()->skip($schedule, '2026-11-12', 'Grand Opening', $this->admin);
        $this->actingAs($this->admin);

        $this->get('/admin/jadwal-tetap')->assertOk()
            ->assertSee('IOH Kamis malam')->assertSee('Kamis, 19:00–22:00')->assertSee('91 jam');

        Livewire::test(ManagePadelRecurringSchedules::class)
            ->mountTableAction('history', $schedule)
            ->assertMountedActionModalSee('Dilewati')->assertMountedActionModalSee('Grand Opening')->assertMountedActionModalSee('Dibuat');

        $this->get('/admin/kelola-pemesanan')->assertOk()->assertSee('Jadwal Tetap · IOH Kamis malam');
    }
}
