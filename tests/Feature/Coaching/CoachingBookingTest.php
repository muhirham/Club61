<?php

namespace Tests\Feature\Coaching;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\JadwalCoaching;
use App\Filament\Resources\Coaching\CoachingAcademyResource;
use App\Filament\Resources\Coaching\CoachResource;
use App\Filament\Resources\Coaching\Pages\ManageCoaches;
use App\Filament\Resources\Coaching\Pages\ManageCoachingRates;
use App\Models\Audit\ActivityLog;
use App\Models\Coaching\Coach;
use App\Models\Coaching\CoachingAcademy;
use App\Models\Coaching\CoachingBooking;
use App\Models\Coaching\CoachingRate;
use App\Models\Coaching\CoachingSetting;
use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Coaching\CoachingPricingService;
use App\Services\Coaching\CoachingReminder;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 24 Opsi B Tahap 1 — booking coaching di POS Walk-In dengan tarif setoran (docs/PRD_MODUL_24_COACHING_OPSI_B_…).
 */
class CoachingBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private PadelCourt $courtA;

    private PadelCourt $courtB;

    private Coach $maimun;

    private Coach $nalda;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        app(CoachingPricingService::class)->flush();

        $this->receptionist = User::factory()->create(['name' => 'Resepsionis Sari', 'is_active' => true]);
        $this->receptionist->givePermissionTo(Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->courtA = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        $this->courtB = PadelCourt::create(['name' => 'Court B', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        $academy = CoachingAcademy::where('name', 'SR Academy')->firstOrFail();
        $this->maimun = Coach::create(['academy_id' => $academy->id, 'name' => 'Maimun', 'title' => 'HEAD_COACH', 'phone' => '081234500001', 'is_active' => true]);
        $this->nalda = Coach::create(['academy_id' => $academy->id, 'name' => 'Nalda', 'title' => 'COACH', 'phone' => '0812-3450-0002', 'is_active' => true]);
        $this->date = Carbon::parse('next Tuesday')->format('Y-m-d');

        PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->receptionist->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $this->actingAs($this->receptionist);
    }

    private function slot(PadelCourt $court, string $hour): array
    {
        return ["{$court->id}_{$hour}:00:00" => [
            'court_id' => $court->id, 'court_name' => $court->name, 'start_time' => "{$hour}:00:00",
            'end_time' => sprintf('%02d:00:00', (int) $hour + 1), 'time_label' => "{$hour}:00", 'price' => 300000,
        ]];
    }

    private function pos(array $slots)
    {
        return Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', $this->date)
            ->set('selectedSlots', $slots)
            ->set('customerMode', 'quick_create')
            ->set('walkInName', 'Norfita')
            ->set('walkInPhone', '081299887766');
    }

    private function payByEdc($page, string $approval = 'APPR01')
    {
        return $page->set('paymentMethod', 'EDC_BCA')->set('edcLast4', '1234')->set('edcApprovalCode', $approval)->set('edcTraceNumber', 'TRC'.$approval)
            ->call('submitWalkInBooking');
    }

    private function coachingCheckout(PadelCourt $court, string $hour, array $coaching, string $phone = '081299887700')
    {
        $service = app(PadelBookingService::class);

        return $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Murid Coaching', phone: $phone),
            slots: [['court_id' => $court->id, 'start_time' => "{$hour}:00", 'end_time' => sprintf('%02d:00', (int) $hour + 1)]],
            bookingDate: $this->date, equipments: [], paymentMethod: 'EDC_BCA', cashier: $this->receptionist,
            paymentMeta: ['terminal' => 'EDC_BCA', 'card_last_4' => '1234', 'approval_code' => 'A'.$hour.$court->name, 'trace_number' => 'T'.$hour.$court->name],
            coaching: $coaching,
        );
    }

    // ── Tarif ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_rates_follow_day_hour_priority_and_extra_coach_fee(): void
    {
        $pricing = app(CoachingPricingService::class);
        $tuesday = Carbon::parse($this->date);

        // Tarif awal migrasi: 06–17 Rp100rb, 17–24 Rp150rb setiap hari.
        $this->assertSame(100000.0, $pricing->feeFor($tuesday->copy()->setTime(16, 0), $tuesday->copy()->setTime(17, 0), 0)['fee']);
        $this->assertSame(250000.0, $pricing->feeFor($tuesday->copy()->setTime(16, 0), $tuesday->copy()->setTime(18, 0), 0)['fee'], 'dihitung per jam');
        $this->assertSame(150000.0, $pricing->feeFor($tuesday->copy()->setTime(23, 0), $tuesday->copy()->addDay()->startOfDay(), 0)['fee'], '23:00–24:00');

        // Tarif weekend khusus dengan prioritas lebih kecil menang di hari itu saja.
        CoachingRate::create(['name' => 'Weekend', 'days' => [6, 7], 'start_time' => '06:00', 'end_time' => '24:00', 'price_per_hour' => 175000, 'priority' => 1, 'is_active' => true]);
        $pricing = app(CoachingPricingService::class); // tarif dibaca ulang (cache dihapus saat tarif disimpan)
        $saturday = $tuesday->copy()->next(Carbon::SATURDAY);
        $this->assertSame(175000.0, $pricing->feeFor($saturday->copy()->setTime(9, 0), $saturday->copy()->setTime(10, 0), 0)['fee']);
        $this->assertSame(100000.0, $pricing->feeFor($tuesday->copy()->setTime(9, 0), $tuesday->copy()->setTime(10, 0), 0)['fee']);

        // Biaya coach tambahan default Rp0; kalau diisi ditambahkan per jam per coach tambahan.
        $this->assertSame(100000.0, $pricing->feeFor($tuesday->copy()->setTime(9, 0), $tuesday->copy()->setTime(10, 0), 2)['fee']);
        CoachingSetting::query()->firstOrFail()->update(['extra_coach_fee_per_hour' => 50000]);
        $pricing = app(CoachingPricingService::class);
        $this->assertSame(200000.0, $pricing->feeFor($tuesday->copy()->setTime(9, 0), $tuesday->copy()->setTime(10, 0), 2)['fee']);

        // Jam tanpa tarif ditolak, tidak pernah jatuh ke Rp0.
        $this->expectException(HttpException::class);
        $pricing->feeFor($tuesday->copy()->setTime(5, 0), $tuesday->copy()->setTime(6, 0), 0);
    }

    // ── POS Walk-In ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_receptionist_books_coaching_at_the_setoran_rate_and_it_is_booked_as_coaching(): void
    {
        // Tab ketiga sederet "Walk-In Baru | Cari Akun | Coaching".
        $page = $this->pos($this->slot($this->courtA, '18'))
            ->assertSee('data-coaching-tab', false)
            ->call('setCustomerMode', 'coaching')
            ->assertSet('bookingType', 'COACHING')
            ->assertSet('customerMode', 'quick_create')
            ->assertSee('Booking Coaching')
            ->assertSee('Atas nama *');

        // Pindah ke tab lain → kembali reguler; balik lagi ke Coaching.
        $page->call('setCustomerMode', 'search')->assertSet('bookingType', 'REGULAR')
            ->call('setCustomerMode', 'coaching')->assertSet('customerMode', 'quick_create')->assertSet('bookingType', 'COACHING');

        // Coach utama wajib sebelum ke pembayaran.
        $page->call('proceedToPayment')->assertSet('posStep', 'selection')->assertNotified('Coach Belum Dipilih');

        $page->set('coachingCoachId', $this->maimun->id)
            ->set('coachingExtraCoachIds', [$this->nalda->id])
            ->set('coachingStudents', ['Norfita', 'Andi'])
            ->set('coachingNotes', 'Ballboy 1')
            ->assertSee('Lapangan (tarif coaching)')
            ->assertDontSee('Kode voucher');
        $this->assertSame(150000.0, $page->instance()->courtTotal, 'tarif setoran malam, bukan harga lapangan reguler Rp300rb');

        $page->call('proceedToPayment')->assertSet('posStep', 'payment');
        $this->payByEdc($page)->assertSet('posStep', 'receipt');

        $booking = PadelBooking::sole();
        $this->assertSame('PAID', $booking->status);
        $this->assertEquals(150000, (float) $booking->court_fee);
        $coaching = CoachingBooking::with('coaches')->sole();
        $this->assertSame($coaching->id, $booking->coaching_booking_id);
        $this->assertSame($booking->order_id, $coaching->order_id);
        $this->assertSame('SR Academy', $coaching->academy_name);
        $this->assertSame(['Norfita', 'Andi'], $coaching->students);
        $this->assertSame('Ballboy 1', $coaching->notes);
        $this->assertEqualsCanonicalizing(['Maimun' => 'HEAD', 'Nalda' => 'EXTRA'], $coaching->coaches->mapWithKeys(fn ($c) => [$c->name => $c->pivot->role])->all());
        $this->assertStringStartsWith('Sewa Lapangan Coaching · Court A', $booking->order->items()->where('item_type', 'PADEL')->value('item_name'));

        // Buku Transaksi: kategori Sewa Lapangan Coaching (bukan Sewa Lapangan Padel).
        $this->assertSame(['COACHING'], LedgerEntry::where('order_id', $booking->order_id)->pluck('category')->unique()->values()->all());
        $this->assertSame('Sewa Lapangan Coaching', LedgerEntry::categoryLabel('COACHING'));
    }

    public function test_coaching_ignores_membership_and_vouchers(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('voucher');

        $service = app(PadelBookingService::class);
        $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Murid', phone: '081299887701'),
            slots: [['court_id' => $this->courtA->id, 'start_time' => '09:00', 'end_time' => '10:00']],
            bookingDate: $this->date, equipments: [], paymentMethod: 'EDC_BCA', cashier: $this->receptionist,
            voucherCode: 'PROMO10',
            coaching: ['head_coach_id' => $this->maimun->id],
        );
    }

    public function test_a_coach_cannot_be_in_two_sessions_at_the_same_time(): void
    {
        $this->coachingCheckout($this->courtA, '09', ['head_coach_id' => $this->maimun->id]);

        // Coach lain di lapangan lain jam yang sama: boleh.
        $this->coachingCheckout($this->courtB, '09', ['head_coach_id' => $this->nalda->id], '081299887701');
        $this->assertSame(2, CoachingBooking::count());

        // Maimun sebagai coach tambahan di jam yang sama (lapangan ketiga yang kosong): ditolak, slot dilepas lagi.
        $courtC = PadelCourt::create(['name' => 'Court C', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        $other = Coach::create(['name' => 'Budi', 'title' => 'COACH', 'is_active' => true]);
        try {
            $this->coachingCheckout($courtC, '09', ['head_coach_id' => $other->id, 'extra_coach_ids' => [$this->maimun->id]], '081299887702');
            $this->fail('coach bentrok harus ditolak');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Maimun', $e->getMessage());
        }
        $this->assertSame(2, CoachingBooking::count());
        $this->assertSame(2, PadelBooking::whereIn('status', ['PAID'])->count());

        // Coach nonaktif tidak bisa dipilih.
        $this->nalda->update(['is_active' => false]);
        $this->expectException(HttpException::class);
        $this->coachingCheckout($this->courtA, '11', ['head_coach_id' => $this->nalda->id], '081299887703');
    }

    public function test_one_session_cannot_use_two_courts_at_the_same_hour(): void
    {
        $service = app(PadelBookingService::class);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('dua lapangan');
        $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Murid', phone: '081299887704'),
            slots: [
                ['court_id' => $this->courtA->id, 'start_time' => '09:00', 'end_time' => '10:00'],
                ['court_id' => $this->courtB->id, 'start_time' => '09:00', 'end_time' => '10:00'],
            ],
            bookingDate: $this->date, equipments: [], paymentMethod: 'EDC_BCA', cashier: $this->receptionist,
            paymentMeta: ['terminal' => 'EDC_BCA', 'card_last_4' => '1234', 'approval_code' => 'A2C', 'trace_number' => 'T2C'],
            coaching: ['head_coach_id' => $this->maimun->id],
        );
    }

    public function test_only_staff_with_book_coaching_can_use_the_coaching_rate(): void
    {
        $cashier = User::factory()->cashier()->create(['is_active' => true]);
        $cashier->givePermissionTo(['View:BookOfflineCourt', 'process_walkin_booking']);
        $this->actingAs($cashier);

        Livewire::test(BookOfflineCourt::class)
            ->assertDontSee('data-coaching-tab', false)
            ->call('setCustomerMode', 'coaching')
            ->assertSet('bookingType', 'REGULAR')
            ->assertNotified('Akses Ditolak')
            ->call('setBookingType', 'COACHING')
            ->assertSet('bookingType', 'REGULAR')
            ->assertNotified('Akses Ditolak');

        // Dipaksa dari browser: tetap harga reguler, dan server menolak data coaching.
        $page = $this->pos($this->slot($this->courtA, '18'))->set('bookingType', 'COACHING')->set('coachingCoachId', $this->maimun->id);
        $this->assertFalse($page->instance()->isCoaching);
        $this->assertSame(300000.0, $page->instance()->courtTotal);

        $service = app(PadelBookingService::class);
        $this->expectException(HttpException::class);
        $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Murid', phone: '081299887705'),
            slots: [['court_id' => $this->courtA->id, 'start_time' => '09:00', 'end_time' => '10:00']],
            bookingDate: $this->date, equipments: [], paymentMethod: 'EDC_BCA', cashier: $cashier,
            coaching: ['head_coach_id' => $this->maimun->id],
        );
    }

    // ── Reschedule ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_reschedule_uses_the_coaching_rate_and_checks_the_coach(): void
    {
        $result = $this->coachingCheckout($this->courtA, '09', ['head_coach_id' => $this->maimun->id]);
        $booking = PadelBooking::where('order_id', $result['order']->id)->sole();
        $service = app(PadelBookingService::class);

        // Pindah ke 18:00 (tarif coaching malam Rp150rb) → selisih Rp50rb, bukan selisih harga reguler.
        $quote = $service->quoteReschedule($booking, $this->courtA, Carbon::parse("{$this->date} 18:00"), Carbon::parse("{$this->date} 19:00"));
        $this->assertSame(150000.0, $quote['gross_new']);
        $this->assertSame(50000.0, $quote['court_delta']);

        // Jam tanpa tarif coaching tidak ditawarkan.
        CoachingRate::where('name', 'Malam')->update(['is_active' => false]);
        app(CoachingPricingService::class)->flush();
        $slots = collect($service->getAvailableRescheduleSlots($booking->id, $this->courtA->id, $this->date)['slots'])->pluck('start_time');
        $this->assertContains('10:00', $slots);
        $this->assertNotContains('18:00', $slots);
        CoachingRate::where('name', 'Malam')->update(['is_active' => true]);
        app(CoachingPricingService::class)->flush();

        // Coach sudah punya sesi lain di jam tujuan → ditolak.
        $this->coachingCheckout($this->courtB, '11', ['head_coach_id' => $this->maimun->id], '081299887709');
        $admin = User::factory()->superAdmin()->create();
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Coach Maimun sudah punya sesi coaching');
        $service->adminRescheduleBooking($booking->id, $this->courtA->id, $this->date, '11:00', 'Pindah jam', $admin);
    }

    // ── Admin & jadwal ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_schedule_page_lists_sessions_and_builds_the_whatsapp_reminder(): void
    {
        $result = $this->coachingCheckout($this->courtA, '08', ['head_coach_id' => $this->maimun->id, 'extra_coach_ids' => [$this->nalda->id], 'students' => ['Norfita'], 'notes' => 'ballboy']);
        $booking = PadelBooking::with(['court', 'user', 'coachingBooking.coaches'])->where('order_id', $result['order']->id)->sole();

        Livewire::test(JadwalCoaching::class)
            ->filterTable('tanggal', ['date' => $this->date])
            ->assertCanSeeTableRecords([$booking])
            ->assertSee('Maimun')
            ->assertSee('Murid: Norfita')
            ->mountTableAction('remind', $booking)
            ->assertMountedActionModalSeeHtml('https://wa.me/6281234500001');

        // Jam lewat tanpa check-in (EXPIRED otomatis) tetap tampil di jadwal tanggal itu.
        $booking->update(['status' => 'EXPIRED']);
        Livewire::test(JadwalCoaching::class)
            ->filterTable('tanggal', ['date' => $this->date])
            ->assertCanSeeTableRecords([$booking])
            ->assertSee('Tidak check-in');

        $url = CoachingReminder::url($this->nalda, $booking);
        $this->assertStringStartsWith('https://wa.me/6281234500002?text=', $url, '0812-… dirapikan ke 62…');
        $message = rawurldecode(substr($url, strpos($url, '?text=') + 6));
        $this->assertStringContainsString('Halo Coach Nalda', $message);
        $this->assertStringContainsString('Jam: 08:00 - 09:00 WIB', $message);
        $this->assertStringContainsString('Coach: Maimun & Nalda', $message);
        $this->assertStringContainsString('Catatan: ballboy', $message);

        // Kasir biasa (tanpa izin) tidak bisa membuka Jadwal Coaching.
        $this->actingAs(User::factory()->cashier()->create(['is_active' => true]));
        $this->get(JadwalCoaching::getUrl())->assertDenied();
    }

    public function test_rates_and_settings_need_the_rates_permission(): void
    {
        // Admin (preset) mengelola data coach, tapi tarif = izin pintu belakang (super admin / diberikan manual).
        $admin = User::factory()->admin()->create();
        $this->assertTrue($admin->can('manage_coaching'));
        $this->assertFalse($admin->can('manage_coaching_rates'));
        $this->assertContains('manage_coaching_rates', Club61PermissionMatrix::BACKDOOR_PERMISSIONS);
        $this->assertContains('book_coaching', Club61PermissionMatrix::defaultRolePermissions('receptionist'));

        $this->actingAs($admin);
        try {
            Livewire::test(ManageCoachingRates::class)->instance()->saveSettings(['extra_coach_fee_per_hour' => 1]);
            $this->fail('tanpa izin tarif harus ditolak');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner);
        Livewire::test(ManageCoachingRates::class)
            ->callAction('coachingSettings', ['extra_coach_fee_per_hour' => 25000, 'admin_whatsapp' => '+62 812 3456 7890'])
            ->assertHasNoActionErrors();
        $this->assertEquals(25000, (float) CoachingSetting::current()->extra_coach_fee_per_hour);
        $this->assertSame('081234567890', CoachingSetting::current()->admin_whatsapp);
        $this->assertSame(25000.0, app(CoachingPricingService::class)->extraFeePerHour(), 'cache tarif ikut diperbarui');
        $this->assertTrue(ActivityLog::where('module', 'COACHING')->where('severity', 'CRITICAL')->exists());

        // Jam selesai harus setelah jam mulai.
        Livewire::test(ManageCoachingRates::class)
            ->callAction('create', ['name' => 'Salah', 'days' => [1], 'start_time' => '18:00', 'end_time' => '17:00', 'price_per_hour' => 1000, 'priority' => 5, 'is_active' => true])
            ->assertHasActionErrors(['end_time']);
    }

    public function test_admin_manages_academies_and_coaches(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $academy = CoachingAcademy::where('name', 'SR Academy')->firstOrFail();

        Livewire::test(ManageCoaches::class)
            ->assertCanSeeTableRecords([$this->maimun, $this->nalda])
            ->callAction('create', ['name' => 'Coach Baru', 'title' => 'COACH', 'academy_id' => $academy->id, 'phone' => '+62 812-1111-2222', 'sort_order' => 3, 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('081211112222', Coach::where('name', 'Coach Baru')->value('phone'));

        // Academy yang masih punya coach & coach yang sudah dipakai booking tidak bisa dihapus.
        $this->assertFalse(CoachingAcademyResource::canDelete($academy));
        $this->coachingCheckout($this->courtA, '09', ['head_coach_id' => $this->maimun->id]);
        $this->assertFalse(CoachResource::canDelete($this->maimun->fresh()));
        $this->assertTrue(CoachResource::canDelete($this->nalda->fresh()));

        // Staf tanpa izin tidak bisa membuka menu Coach.
        $this->actingAs(User::factory()->cashier()->create(['is_active' => true]));
        $this->get(CoachResource::getUrl())->assertDenied();
    }

    public function test_customer_hold_api_no_longer_accepts_a_coach(): void
    {
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer, 'sanctum');

        $this->postJson('/api/v1/padel/hold-slot', [
            'booking_date' => $this->date,
            'slots' => [['court_id' => $this->courtA->id, 'start_time' => '09:00', 'end_time' => '10:00']],
            'coach_id' => $this->maimun->id,
        ])->assertStatus(201);

        $booking = PadelBooking::sole();
        $this->assertNull($booking->coach_id);
        $this->assertNull($booking->coaching_booking_id);
        $this->assertEquals(300000, (float) $booking->court_fee, 'harga reguler — tarif coaching hanya dari POS');
    }
}
