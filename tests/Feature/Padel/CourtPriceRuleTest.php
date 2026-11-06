<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\BookingSystem;
use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\MasterData;
use App\Models\Audit\ActivityLog;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelHoliday;
use App\Models\Padel\PadelPeakHourRule;
use App\Models\Padel\PadelPriceRule;
use App\Models\Role;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Services\Padel\PeakHourService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Modul 32 — Harga Khusus per Periode di atas Harga Dasar (tarif lapangan + jam peak). Harga mengikuti TANGGAL MAIN;
 * semua jalur harga (jadwal customer, hold, reschedule, POS, Booking System) lewat PeakHourService::rateFor().
 */
class CourtPriceRuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected PadelCourt $court;

    protected PadelCourt $otherCourt;

    /** Hari main terakhir harga promo (Rabu). */
    protected Carbon $promoLastDay;

    /** Hari main harga normal (Jumat sesudahnya). */
    protected Carbon $normalDay;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->admin = User::factory()->superAdmin()->create();

        // Harga Dasar sesudah Grand Opening: reguler 200k, prime 300k, jam peak Senin–Jumat 17–24 saja.
        $this->court = PadelCourt::create([
            'name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000,
            'open_time' => '06:00', 'close_time' => '24:00', 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->otherCourt = PadelCourt::create([
            'name' => 'Court B', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000,
            'open_time' => '06:00', 'close_time' => '24:00', 'is_active' => true, 'sort_order' => 2,
        ]);
        PadelPeakHourRule::query()->delete();
        foreach ([1, 2, 3, 4, 5] as $day) {
            PadelPeakHourRule::create(['day_of_week' => $day, 'start_hour' => 17, 'end_hour' => 24]);
        }
        $this->peak()->flush();

        $this->promoLastDay = now()->addWeek()->next(Carbon::WEDNESDAY)->startOfDay();
        $this->normalDay = $this->promoLastDay->copy()->next(Carbon::FRIDAY);
    }

    private function peak(): PeakHourService
    {
        return app(PeakHourService::class);
    }

    private function at(Carbon $day, int $hour): Carbon
    {
        return $day->copy()->setTime($hour, 0);
    }

    private function rule(array $overrides = []): PadelPriceRule
    {
        $rule = PadelPriceRule::create($overrides + [
            'name' => 'Promo Pra-Grand Opening',
            'court_ids' => null,
            'valid_from' => now()->toDateString(),
            'valid_until' => $this->promoLastDay->toDateString(),
            'days' => [0, 1, 2, 3, 4, 5, 6],
            'include_holidays' => true,
            'start_hour' => 0,
            'end_hour' => 24,
            'price_per_hour' => 100000,
            'is_prime' => false,
            'priority' => 10,
            'is_active' => true,
        ]);
        $this->peak()->flush();

        return $rule;
    }

    private function matrixSlot(Carbon $day, string $hour, ?PadelCourt $court = null): array
    {
        $matrix = app(PadelBookingService::class)->getScheduleMatrix($day->format('Y-m-d'));
        $row = collect($matrix['courts'])->firstWhere('court_id', ($court ?? $this->court)->id);

        return collect($row['slots'])->firstWhere('local_start', $hour);
    }

    /** Form Harga Khusus di Master Data, diisi seperti admin. */
    private function form(array $fields): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($this->admin);
        $component = Livewire::test(MasterData::class)->call('setActiveTab', 'price_rules')->call('openCreatePriceRuleModal');
        foreach ($fields as $key => $value) {
            $component->set($key, $value);
        }

        return $component->call('savePriceRule');
    }

    public function test_without_price_rules_every_price_equals_the_base_price(): void
    {
        $this->assertSame(['price' => 200000.0, 'prime' => false, 'rule' => null], $this->peak()->rateFor($this->court, $this->at($this->normalDay, 10)));
        $this->assertSame(['price' => 300000.0, 'prime' => true, 'rule' => null], $this->peak()->rateFor($this->court, $this->at($this->normalDay, 18)));
        $this->assertEquals(200000, $this->matrixSlot($this->normalDay, '10:00')['price']);
        $this->assertEquals(300000, $this->matrixSlot($this->normalDay, '18:00')['price']);
        $this->assertEquals(500000, $this->peak()->courtFee($this->court, $this->at($this->normalDay, 16), $this->at($this->normalDay, 18))['fee']);
    }

    public function test_grand_opening_promo_applies_by_play_date_on_every_price_path(): void
    {
        $this->rule();

        // Jadwal customer / API: sampai hari terakhir promo 100k (termasuk jam peak), sesudahnya Harga Dasar.
        $promo = $this->matrixSlot($this->promoLastDay, '18:00');
        $this->assertEquals(100000, $promo['price']);
        $this->assertFalse($promo['is_prime_time'], 'label ikut Harga Khusus (Reguler)');
        $this->assertEquals(200000, $this->matrixSlot($this->normalDay, '10:00')['price']);
        $normalPeak = $this->matrixSlot($this->normalDay, '18:00');
        $this->assertEquals(300000, $normalPeak['price']);
        $this->assertTrue($normalPeak['is_prime_time']);
        $this->assertEquals(200000, $this->matrixSlot($this->normalDay->copy()->next(Carbon::SATURDAY), '18:00')['price'], 'weekend = reguler');

        // Hold (harga yang ditagih) memakai tanggal main, bukan tanggal pesan.
        $customer = User::factory()->customer()->create();
        $promoHold = app(PadelBookingService::class)->holdBatchSlots([
            ['court_id' => $this->court->id, 'start_time' => '18:00', 'end_time' => '20:00'],
        ], $this->promoLastDay->format('Y-m-d'), $customer);
        $this->assertEquals(200000, (float) $promoHold['subtotal']);

        $normalHold = app(PadelBookingService::class)->holdBatchSlots([
            ['court_id' => $this->otherCourt->id, 'start_time' => '16:00', 'end_time' => '18:00'],
        ], $this->normalDay->format('Y-m-d'), User::factory()->customer()->create());
        $this->assertEquals(200000 + 300000, (float) $normalHold['subtotal'], '16:00 reguler + 17:00 prime');

        // Pindah jadwal dari tanggal promo ke tanggal normal: harga baru mengikuti tanggal main baru.
        $quote = app(PadelBookingService::class)->quoteReschedule($promoHold['bookings'][0], $this->court, $this->at($this->normalDay, 18), $this->at($this->normalDay, 20));
        $this->assertEquals(600000, $quote['gross_new']);
    }

    public function test_pos_and_booking_system_grids_follow_the_price_rule(): void
    {
        $this->rule();
        $this->actingAs($this->admin);

        $grid = Livewire::test(BookOfflineCourt::class)->set('bookingDate', $this->promoLastDay->format('Y-m-d'))->viewData('gridData');
        $row = collect($grid)->first(fn ($r) => $r['court']->id === $this->court->id);
        $this->assertEquals(100000, collect($row['slots'])->firstWhere('hour', 18)['rate']);
        $this->assertEquals(100000, $row['from_rate']);

        $grid = Livewire::test(BookOfflineCourt::class)->set('bookingDate', $this->normalDay->format('Y-m-d'))->viewData('gridData');
        $row = collect($grid)->first(fn ($r) => $r['court']->id === $this->court->id);
        $this->assertEquals(300000, collect($row['slots'])->firstWhere('hour', 18)['rate']);
        $this->assertEquals(200000, $row['from_rate']);

        $matrix = Livewire::test(BookingSystem::class)->set('selectedDate', $this->promoLastDay->format('Y-m-d'))->viewData('matrix');
        $row = collect($matrix)->first(fn ($r) => $r['court']->id === $this->court->id);
        $this->assertEquals(100000, $row['slots'][18]['price']);
        $this->assertFalse($row['slots'][18]['is_prime_time']);
        $this->assertEquals(100000, $row['from_rate']);
    }

    public function test_hours_outside_the_rule_and_other_courts_keep_the_base_price(): void
    {
        $this->rule(['name' => 'Happy Hour Court A', 'court_ids' => [$this->court->id], 'valid_until' => null, 'days' => [1, 2, 3, 4, 5], 'include_holidays' => false, 'start_hour' => 8, 'end_hour' => 10, 'price_per_hour' => 150000]);

        $this->assertSame(['price' => 150000.0, 'prime' => false, 'rule' => 'Happy Hour Court A'], $this->peak()->rateFor($this->court, $this->at($this->normalDay, 9)));
        $this->assertEquals(200000, $this->peak()->hourlyRate($this->court, $this->at($this->normalDay, 10)), 'jam selesai eksklusif');
        $this->assertEquals(200000, $this->peak()->hourlyRate($this->otherCourt, $this->at($this->normalDay, 9)), 'lapangan lain');
        $this->assertEquals(200000, $this->peak()->hourlyRate($this->court, $this->at($this->normalDay->copy()->next(Carbon::SATURDAY), 9)), 'hari lain');
        $this->assertEquals(150000 + 200000, $this->peak()->courtFee($this->court, $this->at($this->normalDay, 9), $this->at($this->normalDay, 11))['fee'], 'dihitung per jam');
    }

    public function test_smaller_priority_wins_and_inactive_rules_are_ignored(): void
    {
        $this->rule(['name' => 'Tarif Umum', 'valid_until' => null, 'price_per_hour' => 250000, 'priority' => 20]);
        $event = $this->rule(['name' => 'Event Turnamen', 'valid_from' => $this->normalDay->toDateString(), 'valid_until' => $this->normalDay->toDateString(), 'price_per_hour' => 400000, 'is_prime' => true, 'priority' => 5]);

        $this->assertSame(['price' => 400000.0, 'prime' => true, 'rule' => 'Event Turnamen'], $this->peak()->rateFor($this->court, $this->at($this->normalDay, 10)));
        $this->assertEquals(250000, $this->peak()->hourlyRate($this->court, $this->at($this->promoLastDay, 10)));

        $event->update(['is_active' => false]);
        $this->peak()->flush();
        $this->assertEquals(250000, $this->peak()->hourlyRate($this->court, $this->at($this->normalDay, 10)));
    }

    public function test_holiday_uses_a_holiday_rule_otherwise_the_sunday_rule(): void
    {
        $holiday = $this->normalDay->copy()->addWeek(); // Jumat
        PadelHoliday::create(['date' => $holiday->toDateString(), 'name' => 'Libur Tes']);
        $this->rule(['name' => 'Minggu Murah', 'valid_until' => null, 'days' => [0], 'include_holidays' => false, 'price_per_hour' => 120000, 'priority' => 20]);

        $this->assertSame(['price' => 120000.0, 'prime' => false, 'rule' => 'Minggu Murah'], $this->peak()->rateFor($this->court, $this->at($holiday, 18)), 'tanggal merah ikut hari Minggu');
        $this->assertEquals(300000, $this->peak()->hourlyRate($this->court, $this->at($holiday->copy()->subDay(), 18)), 'Kamis biasa tetap Harga Dasar');

        $this->rule(['name' => 'Harga Tanggal Merah', 'valid_until' => null, 'days' => [], 'include_holidays' => true, 'price_per_hour' => 350000, 'is_prime' => true, 'priority' => 30]);
        $this->assertSame(['price' => 350000.0, 'prime' => true, 'rule' => 'Harga Tanggal Merah'], $this->peak()->rateFor($this->court, $this->at($holiday, 18)), 'aturan Tanggal Merah dicek lebih dulu');
        $this->assertEquals(120000, $this->peak()->hourlyRate($this->court, $this->at($holiday->copy()->next(Carbon::SUNDAY), 18)), 'Minggu biasa tidak terkena aturan khusus tanggal merah');
    }

    public function test_admin_creates_a_price_rule_from_master_data_and_it_is_audited(): void
    {
        $this->form([
            'priceRuleName' => 'Promo Pra-Grand Opening',
            'priceRuleFrom' => now()->toDateString(),
            'priceRuleUntil' => $this->promoLastDay->toDateString(),
            'priceRuleStartHour' => 6,
            'priceRuleEndHour' => 24,
            'priceRulePrice' => 100000,
        ])->assertHasNoErrors()->assertSet('showPriceRuleModal', false);

        $rule = PadelPriceRule::sole();
        $this->assertNull($rule->court_ids);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $rule->days);
        $this->assertTrue($rule->include_holidays);
        $this->assertEquals(100000, $this->peak()->hourlyRate($this->court, $this->at($this->promoLastDay, 18)), 'cache langsung di-flush');

        $log = ActivityLog::where('event', 'price_rule.created')->sole();
        $this->assertSame('MASTER_DATA', $log->module);
        $this->assertStringContainsString('Rp100.000', str_replace(' ', '', $log->description));

        Livewire::test(MasterData::class)->call('setActiveTab', 'price_rules')
            ->assertSee('Promo Pra-Grand Opening')
            ->assertSee('Setiap hari + Tanggal Merah')
            ->set('simDate', $this->promoLastDay->format('Y-m-d'))
            ->set('simCourtId', $this->court->id)
            ->assertSee('Harga Khusus: Promo Pra-Grand Opening')
            ->set('simDate', $this->normalDay->format('Y-m-d'))
            ->assertSee('Harga Dasar')
            ->assertDontSee('Harga Khusus: Promo Pra-Grand Opening');
    }

    public function test_form_rejects_invalid_input_and_same_priority_overlap(): void
    {
        $base = [
            'priceRuleName' => 'Promo', 'priceRuleFrom' => now()->toDateString(), 'priceRuleUntil' => $this->promoLastDay->toDateString(),
            'priceRuleStartHour' => 6, 'priceRuleEndHour' => 24, 'priceRulePrice' => 100000,
        ];

        $this->form(['priceRulePrice' => 0] + $base)->assertHasErrors('priceRulePrice');
        $this->form(['priceRuleEndHour' => 6] + $base)->assertHasErrors('priceRuleEndHour');
        $this->form(['priceRuleUntil' => now()->subDay()->toDateString()] + $base)->assertHasErrors('priceRuleUntil');
        $this->form(['priceRuleDays' => [], 'priceRuleHolidays' => false] + $base)->assertHasErrors('priceRuleDays');
        $this->form(['priceRuleAllCourts' => false, 'priceRuleCourtIds' => []] + $base)->assertHasErrors('priceRuleCourtIds');
        $this->assertSame(0, PadelPriceRule::count());

        $this->form($base)->assertHasNoErrors();
        // Prioritas sama & beririsan → ditolak; prioritas beda → boleh.
        $this->form(['priceRuleName' => 'Promo Kembar', 'priceRuleStartHour' => 17] + $base)->assertHasErrors('priceRulePriority');
        $this->form(['priceRuleName' => 'Promo Kembar', 'priceRuleStartHour' => 17, 'priceRulePriority' => 5] + $base)->assertHasNoErrors();
        // Tidak beririsan (lapangan lain saja, tapi aturan pertama untuk semua lapangan) tetap bentrok; periode lain → boleh.
        $this->form(['priceRuleName' => 'Court B', 'priceRuleAllCourts' => false, 'priceRuleCourtIds' => [$this->otherCourt->id]] + $base)->assertHasErrors('priceRulePriority');
        $this->form(['priceRuleName' => 'Sesudah Promo', 'priceRuleFrom' => $this->normalDay->toDateString(), 'priceRuleUntil' => ''] + $base)->assertHasNoErrors();

        $this->assertSame(3, PadelPriceRule::count());
    }

    public function test_reactivating_a_rule_that_would_overlap_is_refused(): void
    {
        $this->rule(['name' => 'Lama', 'is_active' => false]);
        $this->rule(['name' => 'Baru']);
        $this->actingAs($this->admin);

        Livewire::test(MasterData::class)->call('togglePriceRule', PadelPriceRule::where('name', 'Lama')->value('id'));
        $this->assertFalse(PadelPriceRule::where('name', 'Lama')->value('is_active'));

        Livewire::test(MasterData::class)->call('togglePriceRule', PadelPriceRule::where('name', 'Baru')->value('id'));
        $this->assertFalse((bool) PadelPriceRule::where('name', 'Baru')->value('is_active'));
        $this->assertEquals(300000, $this->peak()->hourlyRate($this->court, $this->at($this->promoLastDay, 18)), 'nonaktif → kembali ke Harga Dasar');
        $this->assertTrue(ActivityLog::where('event', 'price_rule.deactivated')->exists());
    }

    public function test_invalid_simulation_date_warns_and_falls_back_to_today_instead_of_500(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        foreach (['2026-13-01', '2026-02-31', 'bukan-tanggal'] as $invalid) {
            Livewire::test(MasterData::class)->call('setActiveTab', 'price_rules')
                ->set('simDate', $invalid)
                ->assertOk()
                ->assertSet('simDate', $today)
                ->assertNotified('Tanggal Tidak Valid')
                ->assertSee('Simulasi Harga');
        }

        // Dikosongkan: kembali ke hari ini tanpa peringatan.
        Livewire::test(MasterData::class)->call('setActiveTab', 'price_rules')
            ->set('simDate', '')
            ->assertOk()
            ->assertSet('simDate', $today)
            ->assertNotNotified('Tanggal Tidak Valid');
    }

    public function test_staff_without_pricing_permission_cannot_manage_price_rules(): void
    {
        $rule = $this->rule();
        $kitchen = User::factory()->kitchen()->create();
        Role::findByName('kitchen', 'web')->givePermissionTo('View:MasterData');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($kitchen);

        Livewire::test(MasterData::class)->call('setActiveTab', 'price_rules')
            ->assertSee('Promo Pra-Grand Opening')
            ->assertDontSee('+ Tambah Harga Khusus')
            ->call('openCreatePriceRuleModal')->assertForbidden();
        Livewire::test(MasterData::class)->call('savePriceRule')->assertForbidden();
        Livewire::test(MasterData::class)->call('togglePriceRule', $rule->id)->assertForbidden();
        Livewire::test(MasterData::class)->call('openEditPriceRuleModal', $rule->id)->assertForbidden();

        $this->assertTrue($rule->fresh()->is_active);
        $this->assertSame(1, PadelPriceRule::count());
    }
}
