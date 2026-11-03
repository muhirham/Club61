<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\BookOfflineCourt;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelRecurringOccurrence;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Services\Padel\RecurringScheduleService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Batas booking menjelang Grand Opening (BOOKING_OPEN_UNTIL / BOOKING_CLOSED_DATES): harga lama hanya bisa dipesan
 * sampai tanggal main 25 Nov, 26 Nov tutup sehari penuh — untuk customer online, POS (termasuk super admin) dan
 * reschedule. Jadwal Tetap (kuota jam) hanya terkena tanggal tutup: minggu itu dilewati tanpa memotong kuota.
 */
class BookingWindowTest extends TestCase
{
    use RefreshDatabase;

    private PadelCourt $court;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        config([
            'booking_window.open_until' => '2026-11-25',
            'booking_window.closed_dates' => '2026-11-26',
            'booking_window.closed_reason' => 'Grand Opening',
        ]);
        $this->court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 100000, 'hourly_rate_prime' => 100000, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function slot(int $from, int $to): array
    {
        return ['court_id' => $this->court->id, 'start_time' => sprintf('%02d:00', $from), 'end_time' => sprintf('%02d:00', $to)];
    }

    public function test_online_customer_books_until_the_25th_and_sees_why_later_dates_are_closed(): void
    {
        $notOpen = 'Booking untuk tanggal main setelah Rabu, 25 November 2026 belum dibuka.';
        $closed = 'Club 61 tutup pada Kamis, 26 November 2026 (Grand Opening).';

        // Jadwal: slot kosong tampil Tutup + pesannya.
        $this->getJson('/api/v1/padel/schedule?date=2026-11-25')->assertOk()
            ->assertJsonPath('data.notice', null)->assertJsonPath('data.courts.0.slots.4.status', 'AVAILABLE');
        $day26 = $this->getJson('/api/v1/padel/schedule?date=2026-11-26')->assertOk();
        $this->assertStringStartsWith($closed, $day26->json('data.notice'));
        $this->assertSame(['CLOSED'], collect($day26->json('data.courts.0.slots'))->pluck('status')->unique()->values()->all());
        $this->assertStringStartsWith($notOpen, $this->getJson('/api/v1/padel/schedule?date=2026-11-27')->json('data.notice'));

        // Tahan slot ditolak di server.
        $customer = User::factory()->customer()->create();
        $hold = fn (string $date) => $this->actingAs($customer, 'sanctum')->postJson('/api/v1/padel/hold-slot', ['booking_date' => $date, 'slots' => [$this->slot(10, 11)]]);
        $this->assertStringStartsWith($notOpen, $hold('2026-11-27')->assertStatus(422)->json('message'));
        $this->assertStringStartsWith($closed, $hold('2026-11-26')->assertStatus(422)->json('message'));
        $this->assertSame(0, PadelBooking::count());
        $hold('2026-11-25')->assertStatus(201);
    }

    public function test_pos_and_reschedule_are_blocked_for_everyone_including_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create(['is_active' => true]);
        $service = app(PadelBookingService::class);

        // Server POS Walk-In (juga coaching) menolak sebelum apa pun dibuat.
        try {
            $service->processWalkInCheckout(customer: User::factory()->customer()->create(), slots: [$this->slot(10, 11)], bookingDate: '2026-11-27',
                equipments: [], paymentMethod: 'QRIS', cashier: $admin);
            $this->fail('Tanggal main setelah 25 Nov seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('belum dibuka', $e->getMessage());
        }

        // Layar POS: slot tidak bisa dipilih, banner tampil.
        $this->actingAs($admin);
        Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', '2026-11-26')
            ->assertSee('Club 61 tutup pada Kamis, 26 November 2026 (Grand Opening)')
            ->call('toggleSlot', $this->court->id, 'Court A', '10:00', '11:00', 100000)
            ->assertNotified('Tanggal Tidak Bisa Dibooking')
            ->assertSet('selectedSlots', []);

        // Modal reschedule Kelola Pemesanan: pilih tanggal 27 → alasan tampil, bukan daftar slot kosong tanpa penjelasan.
        Livewire::test(\App\Filament\Pages\KelolaPemesanan::class)
            ->set('selectedBookingId', 'x')
            ->set('rescheduleCourtId', $this->court->id)
            ->set('rescheduleDate', '2026-11-27')
            ->call('loadAvailableSlots')
            ->assertNotified('Tanggal Tidak Bisa Dibooking')
            ->assertSet('availableSlots', []);

        // Reschedule ke tanggal tutup / belum dibuka ditolak (daftar slot & simpan).
        foreach (['2026-11-26' => 'tutup', '2026-11-27' => 'belum dibuka'] as $date => $text) {
            try {
                $service->getAvailableRescheduleSlots('x', $this->court->id, $date);
                $this->fail("Reschedule ke {$date} seharusnya ditolak.");
            } catch (HttpException $e) {
                $this->assertStringContainsString($text, $e->getMessage());
            }
            try {
                $service->adminRescheduleBooking('x', $this->court->id, $date, '10:00', 'Pindah', $admin);
                $this->fail("Reschedule ke {$date} seharusnya ditolak.");
            } catch (HttpException $e) {
                $this->assertStringContainsString($text, $e->getMessage());
            }
        }
    }

    public function test_recurring_schedule_skips_the_closed_day_without_using_quota_and_ignores_open_until(): void
    {
        Club61PermissionMatrix::syncAllPermissions('web');
        ClubFinanceSetting::getSettings()->update(['is_admin_fee_enabled' => false, 'is_tax_enabled' => false]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));

        $admin = User::factory()->admin()->create();
        $pic = User::factory()->customer()->create();
        $plan = MembershipPlan::create(['code' => 'CORP-IOH', 'name' => 'Corporate 100 Jam', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 365, 'price' => 0, 'is_active' => true]);
        $membership = UserMembership::create([
            'membership_code' => 'MBR-IOH', 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $pic->id, 'plan_id' => $plan->id,
            'status' => 'ACTIVE', 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'qr_pass_hash' => 'QR-IOH',
        ]);
        $balance = UserMembershipBalance::create(['user_membership_id' => $membership->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'initial_quota' => 100, 'remaining_quota' => 100]);
        $schedule = PadelRecurringSchedule::create([
            'name' => 'IOH Kamis malam', 'user_membership_id' => $membership->id, 'day_of_week' => 4, 'start_time' => '19:00', 'end_time' => '22:00',
            'court_ids' => [$this->court->id], 'starts_on' => '2026-11-20', 'weeks_ahead' => 2, 'status' => PadelRecurringSchedule::STATUS_ACTIVE, 'created_by_id' => $admin->id,
        ]);

        $result = app(RecurringScheduleService::class)->generate($schedule, $admin);

        // Kamis 26 Nov dilewati; Kamis 3 Des tetap dibuat walau setelah 25 Nov (dibayar kuota, bukan harga lapangan).
        $this->assertSame(1, $result['booked']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(['2026-12-03'], PadelBooking::pluck('booking_date')->map(fn ($d) => $d->toDateString())->all());
        $skipped = PadelRecurringOccurrence::whereDate('occurrence_date', '2026-11-26')->sole();
        $this->assertSame(PadelRecurringOccurrence::STATUS_SKIPPED, $skipped->status);
        $this->assertStringContainsString('Grand Opening', $skipped->note);
        $this->assertEquals(97, (float) $balance->fresh()->remaining_quota, 'hanya 3 jam (3 Des) yang dipotong');
    }

    public function test_closing_some_hours_blocks_only_those_hours_on_every_path(): void
    {
        config(['booking_window.closed_dates' => '2026-11-04 06:00-13:00, 2026-11-26', 'booking_window.closed_reason' => 'Acara Khusus']);
        $this->assertSame(['2026-11-26'], \App\Support\BookingWindow::closedDates(), '26 Nov tetap tutup sehari penuh');
        $this->assertSame([['start' => '06:00', 'end' => '13:00']], \App\Support\BookingWindow::closedHours('2026-11-04'));
        $this->assertNull(\App\Support\BookingWindow::blockedReason('2026-11-04'), 'tanggalnya sendiri tidak tutup');
        $closed = 'Club 61 tutup pada Rabu, 4 November 2026 pukul 06:00–13:00 (Acara Khusus).';

        // Jadwal customer: 06:00–12:00 Tutup, 13:00 ke atas tetap bisa; banner menyebut jamnya.
        $day = $this->getJson('/api/v1/padel/schedule?date=2026-11-04')->assertOk();
        $this->assertStringContainsString('Jam lain tetap bisa dibooking', $day->json('data.notice'));
        $status = collect($day->json('data.courts.0.slots'))->pluck('status', 'local_start');
        $this->assertSame('CLOSED', $status['06:00']);
        $this->assertSame('CLOSED', $status['12:00']);
        $this->assertSame('AVAILABLE', $status['13:00']);
        $this->assertSame('AVAILABLE', $this->getJson('/api/v1/padel/schedule?date=2026-11-05')->json('data.courts.0.slots.0.status'));

        // Tahan slot online: jam yang menyentuh jam tutup ditolak, 13:00 boleh.
        $customer = User::factory()->customer()->create();
        $hold = fn (int $from, int $to) => $this->actingAs($customer, 'sanctum')->postJson('/api/v1/padel/hold-slot', ['booking_date' => '2026-11-04', 'slots' => [$this->slot($from, $to)]]);
        $this->assertStringStartsWith($closed, $hold(12, 13)->assertStatus(422)->json('message'));
        $hold(12, 14)->assertStatus(422);
        $this->assertSame(0, PadelBooking::count());
        $hold(13, 14)->assertStatus(201);

        // POS Walk-In: layar & server.
        $admin = User::factory()->superAdmin()->create(['is_active' => true]);
        $this->actingAs($admin);
        Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', '2026-11-04')
            ->assertSee('pukul 06:00–13:00')
            ->call('toggleSlot', $this->court->id, 'Court A', '12:00', '13:00', 100000)
            ->assertNotified('Jam Tidak Bisa Dibooking')
            ->assertSet('selectedSlots', []);
        try {
            app(PadelBookingService::class)->processWalkInCheckout(customer: $customer, slots: [$this->slot(10, 11)], bookingDate: '2026-11-04',
                equipments: [], paymentMethod: 'QRIS', cashier: $admin);
            $this->fail('Jam tutup seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertStringStartsWith($closed, $e->getMessage());
        }

        // Reschedule: jam tutup tidak ditawarkan & ditolak bila dipaksa.
        $booking = PadelBooking::create([
            'booking_code' => 'BK-RSC01', 'user_id' => $customer->id, 'court_id' => $this->court->id, 'booking_date' => '2026-11-05',
            'start_time' => '2026-11-05 10:00:00', 'end_time' => '2026-11-05 11:00:00', 'court_fee' => 100000, 'total_amount' => 100000, 'status' => 'PAID',
        ]);
        $service = app(PadelBookingService::class);
        $starts = collect($service->getAvailableRescheduleSlots($booking->id, $this->court->id, '2026-11-04')['slots'])->pluck('start_time');
        $this->assertSame('14:00', $starts->first(), 'jam tutup tidak ditawarkan; 13:00 sudah ditahan customer di atas');
        try {
            $service->adminRescheduleBooking($booking->id, $this->court->id, '2026-11-04', '12:00', 'Pindah', $admin);
            $this->fail('Reschedule ke jam tutup seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertStringStartsWith($closed, $e->getMessage());
        }
        $this->assertSame('2026-11-05', $booking->fresh()->booking_date->toDateString());

        // Perintah cek menampilkan jam tutup.
        $this->artisan('booking:window')->expectsOutputToContain('Rabu, 4 November 2026 06:00–13:00')->assertSuccessful();
    }

    public function test_recurring_schedule_skips_the_week_only_when_its_hours_hit_the_closed_hours(): void
    {
        Club61PermissionMatrix::syncAllPermissions('web');
        ClubFinanceSetting::getSettings()->update(['is_admin_fee_enabled' => false, 'is_tax_enabled' => false]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);
        config(['booking_window.closed_dates' => '2026-11-04 06:00-13:00']);

        $admin = User::factory()->admin()->create();
        $pic = User::factory()->customer()->create();
        $plan = MembershipPlan::create(['code' => 'CORP-X', 'name' => 'Corporate 100 Jam', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 365, 'price' => 0, 'is_active' => true]);
        $membership = UserMembership::create([
            'membership_code' => 'MBR-X', 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $pic->id, 'plan_id' => $plan->id,
            'status' => 'ACTIVE', 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'qr_pass_hash' => 'QR-X',
        ]);
        $balance = UserMembershipBalance::create(['user_membership_id' => $membership->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'initial_quota' => 100, 'remaining_quota' => 100]);
        $make = fn (string $from, string $to) => PadelRecurringSchedule::create([
            'name' => "Rabu {$from}", 'user_membership_id' => $membership->id, 'day_of_week' => 3, 'start_time' => $from, 'end_time' => $to,
            'court_ids' => [$this->court->id], 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'weeks_ahead' => 1,
            'status' => PadelRecurringSchedule::STATUS_ACTIVE, 'created_by_id' => $admin->id,
        ]);
        $morning = $make('10:00', '12:00');
        $evening = $make('18:00', '20:00');

        $service = app(RecurringScheduleService::class);
        $this->assertSame(0, $service->generate($morning, $admin)['booked']);
        $this->assertSame(1, $service->generate($evening, $admin)['booked']);

        $this->assertSame(PadelRecurringOccurrence::STATUS_SKIPPED, $morning->occurrences()->sole()->status);
        $this->assertSame(['18:00'], PadelBooking::get()->map(fn ($b) => $b->start_time->format('H:i'))->all());
        $this->assertEquals(98, (float) $balance->fresh()->remaining_quota, 'hanya 2 jam malam yang dipotong');
    }

    public function test_without_settings_nothing_is_blocked_and_the_check_command_reports_invalid_values(): void
    {
        config(['booking_window.open_until' => null, 'booking_window.closed_dates' => null, 'booking_window.closed_reason' => null]);
        $this->assertNull(\App\Support\BookingWindow::blockedReason('2026-11-26'));
        $this->assertNull(\App\Support\BookingWindow::blockedReason('2026-12-31'));
        $this->getJson('/api/v1/padel/schedule?date=2026-11-26')->assertJsonPath('data.notice', null);

        config(['booking_window.open_until' => '25-11-2026', 'booking_window.closed_dates' => '2026-11-26, 2026-02-30']);
        $this->assertNull(\App\Support\BookingWindow::openUntil());
        $this->assertSame(['2026-11-26'], \App\Support\BookingWindow::closedDates());
        $this->artisan('booking:window')
            ->expectsOutputToContain('tanpa batas')
            ->expectsOutputToContain('tidak valid')
            ->expectsOutputToContain('Kamis, 26 November 2026')
            ->assertSuccessful();
    }
}
