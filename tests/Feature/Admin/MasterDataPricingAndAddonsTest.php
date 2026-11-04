<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\BookingSystem;
use App\Filament\Pages\MasterData;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Padel\PadelCourt;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterDataPricingAndAddonsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected PadelCourt $court;
    protected CourtEquipment $activeEquipment;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->adminUser = User::factory()->create([
            'email' => 'admin_master@club61.test',
        ]);
        $this->adminUser->assignRole($role);

        $this->court = PadelCourt::create([
            'name' => 'Court 1 - Test Panoramic',
            'type' => 'INDOOR',
            'hourly_rate_regular' => 250000.00,
            'hourly_rate_prime' => 400000.00,
            'is_active' => true,
        ]);

        $this->activeEquipment = CourtEquipment::create([
            'name' => 'Raket Babolat Pro',
            'type' => 'RACKET',
            'rental_price' => 50000.00,
            'stock_quantity' => 15,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_access_master_data_page(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(MasterData::class)
            ->assertSuccessful()
            ->assertSee('Master Data, Tarif Lapangan & Add-ons');
    }

    public function test_admin_can_update_court_rates(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(MasterData::class)
            ->call('openEditCourtModal', $this->court->id)
            ->set('courtName', 'Court 1 - Updated VIP')
            ->set('courtDescription', 'Indoor • High Ceiling AC')
            ->set('hourlyRateRegular', 320000)
            ->set('hourlyRatePrime', 480000)
            ->call('saveCourt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('padel_courts', [
            'id' => $this->court->id,
            'name' => 'Court 1 - Updated VIP',
            'description' => 'Indoor • High Ceiling AC',
            'hourly_rate_regular' => 320000.00,
            'hourly_rate_prime' => 480000.00,
        ]);
    }

    public function test_admin_can_create_new_court(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(MasterData::class)
            ->call('openCreateCourtModal')
            ->set('courtName', 'Court 4 - Center Stadium')
            ->set('courtDescription', 'Indoor • Championship Stadium')
            ->set('hourlyRateRegular', 280000)
            ->set('hourlyRatePrime', 420000)
            ->set('courtIsActive', true)
            ->call('saveCourt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('padel_courts', [
            'name' => 'Court 4 - Center Stadium',
            'type' => 'INDOOR',
            'description' => 'Indoor • Championship Stadium',
            'hourly_rate_regular' => 280000.00,
            'hourly_rate_prime' => 420000.00,
            'is_active' => true,
        ]);
    }

    public function test_outdoor_is_no_longer_offered_and_old_outdoor_court_becomes_indoor_on_save(): void
    {
        $this->actingAs($this->adminUser);
        $old = PadelCourt::create(['name' => 'Court Iforte', 'type' => 'OUTDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true]);

        Livewire::test(MasterData::class)
            ->assertDontSee('Outdoor Court')
            ->call('openEditCourtModal', $old->id)
            ->assertSet('courtType', 'INDOOR')
            ->set('courtName', 'Court Protelindo')
            ->call('saveCourt')
            ->assertHasNoErrors();
        $this->assertSame(['Court Protelindo', 'INDOOR'], [$old->fresh()->name, $old->fresh()->type]);

        Livewire::test(MasterData::class)->call('openCreateCourtModal')->set('courtName', 'X')->set('courtType', 'OUTDOOR')
            ->call('saveCourt')->assertHasErrors(['courtType']);
    }

    public function test_court_display_order_follows_sort_order_then_name(): void
    {
        $this->actingAs($this->adminUser);
        $this->court->delete();
        $bca = PadelCourt::create(['name' => 'Court BCA', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
        $im3 = PadelCourt::create(['name' => 'Court IM3 Platinum', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
        $pro = PadelCourt::create(['name' => 'Court Protelindo', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
        $names = fn () => collect(app(PadelBookingService::class)->getScheduleMatrix(now('Asia/Jakarta')->addDay()->toDateString())['courts'])->pluck('court_name')->all();

        // Belum diatur (semua 0) → tetap abjad seperti sebelumnya.
        $this->assertSame(['Court BCA', 'Court IM3 Platinum', 'Court Protelindo'], $names());

        foreach ([[$bca, 1], [$pro, 2], [$im3, 3]] as [$court, $order]) {
            Livewire::test(MasterData::class)
                ->call('openEditCourtModal', $court->id)
                ->assertSet('courtSortOrder', 0)
                ->set('courtSortOrder', $order)
                ->call('saveCourt')
                ->assertHasNoErrors();
        }

        $this->assertSame(['Court BCA', 'Court Protelindo', 'Court IM3 Platinum'], $names());
        $this->assertSame(['Court BCA', 'Court Protelindo', 'Court IM3 Platinum'], PadelCourt::ordered()->pluck('name')->all());

        // Lapangan baru otomatis di urutan terakhir; angka tidak valid ditolak.
        Livewire::test(MasterData::class)->call('openCreateCourtModal')->assertSet('courtSortOrder', 4)
            ->set('courtSortOrder', -1)->set('courtName', 'X')->call('saveCourt')->assertHasErrors(['courtSortOrder']);
    }

    public function test_admin_can_toggle_court_status(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(MasterData::class)
            ->call('toggleCourtStatus', $this->court->id);

        $this->assertDatabaseHas('padel_courts', [
            'id' => $this->court->id,
            'is_active' => false,
        ]);

        Livewire::test(MasterData::class)
            ->call('toggleCourtStatus', $this->court->id);

        $this->assertDatabaseHas('padel_courts', [
            'id' => $this->court->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_and_update_equipment_addon(): void
    {
        $this->actingAs($this->adminUser);

        // Create Add-on
        Livewire::test(MasterData::class)
            ->call('openCreateEquipmentModal')
            ->set('equipmentName', 'Bola Padel Pro Can 3 Pcs')
            ->set('equipmentType', 'BALL')
            ->set('equipmentRentalPrice', 35000)
            ->set('equipmentStock', 50)
            ->set('equipmentIsActive', true)
            ->call('saveEquipment')
            ->assertHasNoErrors();

        $ball = CourtEquipment::where('name', 'Bola Padel Pro Can 3 Pcs')->first();
        $this->assertNotNull($ball);
        $this->assertEquals(35000.00, (float) $ball->rental_price);

        // Update Add-on
        Livewire::test(MasterData::class)
            ->call('openEditEquipmentModal', $ball->id)
            ->set('equipmentRentalPrice', 40000)
            ->set('equipmentStock', 45)
            ->call('saveEquipment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('court_equipments', [
            'id' => $ball->id,
            'rental_price' => 40000.00,
            'stock_quantity' => 45,
        ]);
    }

    public function test_equipment_without_rental_history_can_be_hard_deleted(): void
    {
        $this->actingAs($this->adminUser);

        $freshEquipment = CourtEquipment::create([
            'name' => 'Handuk Olahraga Baru',
            'type' => 'TOWEL',
            'rental_price' => 15000.00,
            'stock_quantity' => 30,
            'is_active' => true,
        ]);

        Livewire::test(MasterData::class)
            ->call('deleteEquipment', $freshEquipment->id);

        $this->assertDatabaseMissing('court_equipments', [
            'id' => $freshEquipment->id,
        ]);
    }

    public function test_equipment_with_rental_history_is_protected_and_only_deactivated(): void
    {
        $this->actingAs($this->adminUser);

        // Buat booking dengan riwayat rental equipment
        $booking = PadelBooking::create([
            'booking_code' => 'BK-HIST-001',
            'user_id' => $this->adminUser->id,
            'court_id' => $this->court->id,
            'booking_date' => now()->toDateString(),
            'start_time' => now()->startOfHour(),
            'end_time' => now()->startOfHour()->addHour(),
            'court_fee' => 250000.00,
            'equipment_fee' => 50000.00,
            'total_amount' => 300000.00,
            'status' => 'COMPLETED',
        ]);

        $historyItem = PadelBookingEquipment::create([
            'booking_id' => $booking->id,
            'equipment_id' => $this->activeEquipment->id,
            'quantity' => 1,
            'unit_price' => 50000.00,
            'subtotal' => 50000.00,
        ]);

        // Coba hapus item yang sudah punya riwayat rental
        Livewire::test(MasterData::class)
            ->call('deleteEquipment', $this->activeEquipment->id);

        // Item HARUS TETAP ADA di database (bukan hard deleted) tetapi statusnya menjadi is_active = false
        $this->assertDatabaseHas('court_equipments', [
            'id' => $this->activeEquipment->id,
            'is_active' => false,
        ]);

        // Riwayat rental di booking equipments harus tetap utuh 100%
        $this->assertDatabaseHas('padel_booking_equipments', [
            'id' => $historyItem->id,
            'equipment_id' => $this->activeEquipment->id,
        ]);
    }

    /** Alat yang hanya pernah dijual langsung di Kasir Pro Shop (tanpa booking) tetap dilindungi dari hapus permanen. */
    public function test_equipment_sold_only_at_pro_shop_is_protected_and_only_deactivated(): void
    {
        $this->actingAs($this->adminUser);
        $ball = CourtEquipment::create(['name' => 'Bola Head Pro', 'type' => 'BALL', 'rental_price' => 35000, 'stock_quantity' => 10, 'is_active' => true]);
        $this->equipmentOrder($ball, 2, 'PAID');

        Livewire::test(MasterData::class)
            ->call('setActiveTab', 'equipments')
            ->assertSee('Terjual 2 unit')
            ->call('deleteEquipment', $ball->id);

        $this->assertDatabaseHas('court_equipments', ['id' => $ball->id, 'is_active' => false]);
    }

    public function test_transaction_history_counts_paid_units_and_follows_usage(): void
    {
        $this->actingAs($this->adminUser);
        $ball = CourtEquipment::create(['name' => 'Bola Head Pro', 'type' => 'BALL', 'rental_price' => 35000, 'stock_quantity' => 10, 'is_active' => true]);
        $towel = CourtEquipment::create(['name' => 'Handuk Club 61', 'type' => 'TOWEL', 'rental_price' => 15000, 'stock_quantity' => 10, 'is_active' => true]);
        $fresh = CourtEquipment::create(['name' => 'Overgrip Baru', 'type' => 'OTHER', 'usage' => CourtEquipment::USAGE_SALE, 'rental_price' => 25000, 'stock_quantity' => 10, 'is_active' => true]);

        // Bola: 3 + 2 unit lunas, 10 unit belum lunas tidak dihitung.
        $this->equipmentOrder($ball, 3, 'PAID');
        $this->equipmentOrder($ball, 2, 'PAID');
        $this->equipmentOrder($ball, 10, 'UNPAID');
        // Raket: 2 unit lunas lewat tambah alat ke booking (baris booking + item order yang sama — tidak dihitung dobel).
        $booking = PadelBooking::create([
            'booking_code' => 'BK-HIST-002', 'user_id' => $this->adminUser->id, 'court_id' => $this->court->id,
            'booking_date' => now()->toDateString(), 'start_time' => now()->startOfHour(), 'end_time' => now()->startOfHour()->addHour(),
            'court_fee' => 250000, 'equipment_fee' => 100000, 'total_amount' => 350000, 'status' => 'COMPLETED',
        ]);
        $order = $this->equipmentOrder($this->activeEquipment, 2, 'PAID');
        PadelBookingEquipment::create(['order_id' => $order->id, 'booking_id' => $booking->id, 'equipment_id' => $this->activeEquipment->id, 'quantity' => 2, 'unit_price' => 50000, 'subtotal' => 100000]);
        // Handuk: hanya transaksi yang belum lunas.
        $this->equipmentOrder($towel, 1, 'UNPAID');

        Livewire::test(MasterData::class)
            ->call('setActiveTab', 'equipments')
            ->assertSee('Perlengkapan Padel')
            ->assertSee('Riwayat Transaksi')
            ->assertSee('Terjual 5 unit')
            ->assertSee('Disewa 2 kali')
            ->assertSee('Ada riwayat transaksi')
            ->assertSee('Belum ada transaksi')
            ->assertSee('per unit')
            ->assertDontSee('Riwayat Rental')
            ->assertDontSee('Terkunci Historis');

        // Yang belum pernah dipakai transaksi apa pun tetap bisa dihapus permanen.
        Livewire::test(MasterData::class)->call('deleteEquipment', $fresh->id);
        $this->assertDatabaseMissing('court_equipments', ['id' => $fresh->id]);
    }

    private function equipmentOrder(CourtEquipment $equipment, int $quantity, string $paymentStatus): \App\Models\Pos\Order
    {
        $subtotal = (float) $equipment->rental_price * $quantity;
        $order = \App\Models\Pos\Order::create([
            'order_number' => 'ORD-PAD-'.strtoupper(\Illuminate\Support\Str::random(10)),
            'user_id' => $this->adminUser->id,
            'order_type' => 'WALK_IN',
            'subtotal' => $subtotal,
            'grand_total' => $subtotal,
            'payment_status' => $paymentStatus,
        ]);
        $order->items()->create([
            'item_type' => 'EQUIPMENT', 'reference_id' => $equipment->id, 'item_name' => $equipment->name,
            'quantity' => $quantity, 'unit_price' => $equipment->rental_price, 'subtotal' => $subtotal,
        ]);

        return $order;
    }

    public function test_inactive_equipment_is_rejected_in_checkout_server_side(): void
    {
        $inactiveEquipment = CourtEquipment::create([
            'name' => 'Raket Rusak / Nonaktif',
            'type' => 'RACKET',
            'rental_price' => 75000.00,
            'stock_quantity' => 0,
            'is_active' => false,
        ]);

        $service = app(PadelBookingService::class);

        // Buat booking hold
        $holdResult = $service->holdBatchSlots([
            [
                'court_id' => $this->court->id,
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
            ],
        ], now()->addDay()->toDateString(), $this->adminUser);

        $bookingIds = collect($holdResult['bookings'])->pluck('id')->toArray();

        // Coba checkout dengan mengirimkan equipment_id yang is_active = false
        $checkoutResult = $service->checkout(
            $bookingIds,
            [
                ['equipment_id' => $inactiveEquipment->id, 'quantity' => 2],
            ],
            null,
            'BCA_VA',
            'IDEMPOTENCY-' . uniqid(),
            $this->adminUser
        );

        // Equipment nonaktif harus diabaikan, biaya peralatan 0
        $this->assertEquals(0.00, (float) $checkoutResult['data']['equipment_fee']);

        $primaryBooking = PadelBooking::find($bookingIds[0]);
        $this->assertEquals(0.00, (float) $primaryBooking->equipment_fee);
    }

    public function test_booking_system_reflects_prime_time_rates_dynamically(): void
    {
        $this->actingAs($this->adminUser);

        // Uji pada hari kerja (Senin, 21 September 2026)
        $mondayDate = '2026-09-21';

        $testComponent = Livewire::test(BookingSystem::class)
            ->set('selectedDate', $mondayDate);

        // Jam 10 hari kerja (Reguler): harus menggunakan hourly_rate_regular = 250000
        $testComponent->call('inspectEmptySlot', $this->court->id, '10');
        $inspectData = $testComponent->get('inspectData');

        $this->assertNotNull($inspectData);
        $this->assertEquals(250000.00, $inspectData['rate']);
        $this->assertFalse($inspectData['is_prime_time']);

        // Jam 18 hari kerja (Prime Time): harus menggunakan hourly_rate_prime = 400000
        $testComponent->call('inspectEmptySlot', $this->court->id, '18');
        $inspectPrime = $testComponent->get('inspectData');

        $this->assertNotNull($inspectPrime);
        $this->assertEquals(400000.00, $inspectPrime['rate']);
        $this->assertTrue($inspectPrime['is_prime_time']);
    }

    public function test_schedule_matrix_includes_court_description(): void
    {
        $this->court->update(['description' => 'Indoor • VIP Central AC']);

        $service = app(PadelBookingService::class);
        // Tanggal masa depan — tanggal lampau kini ditandai PAST (sama seperti POS Walk-In).
        $matrix = $service->getScheduleMatrix(now()->addDays(3)->format('Y-m-d'));

        $courtData = collect($matrix['courts'])->firstWhere('court_id', $this->court->id);
        $this->assertNotNull($courtData);
        $this->assertEquals('Indoor • VIP Central AC', $courtData['description']);
    }

    public function test_admin_can_update_court_operating_hours(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(MasterData::class)
            ->call('openEditCourtModal', $this->court->id)
            ->set('courtOpenTime', '11:00')
            ->set('courtCloseTime', '22:00')
            ->call('saveCourt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('padel_courts', [
            'id' => $this->court->id,
            'open_time' => '11:00',
            'close_time' => '22:00',
        ]);
    }

    public function test_admin_can_bulk_update_operating_hours_all_courts(): void
    {
        $this->actingAs($this->adminUser);

        $court2 = PadelCourt::create([
            'name' => 'Court 2 - Second',
            'type' => 'OUTDOOR',
            'hourly_rate_regular' => 200000.00,
            'hourly_rate_prime' => 300000.00,
            'open_time' => '06:00',
            'close_time' => '23:00',
            'is_active' => true,
        ]);

        Livewire::test(MasterData::class)
            ->call('openOperatingHoursModal')
            ->set('bulkOpenTime', '11:00')
            ->set('bulkCloseTime', '23:00')
            ->call('saveOperatingHoursAllCourts')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('padel_courts', [
            'id' => $this->court->id,
            'open_time' => '11:00',
            'close_time' => '23:00',
        ]);

        $this->assertDatabaseHas('padel_courts', [
            'id' => $court2->id,
            'open_time' => '11:00',
            'close_time' => '23:00',
        ]);
    }

    public function test_court_operating_hours_validation_rejects_close_before_open(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(MasterData::class)
            ->call('openEditCourtModal', $this->court->id)
            ->set('courtOpenTime', '15:00')
            ->set('courtCloseTime', '10:00')
            ->call('saveCourt')
            ->assertHasErrors(['courtCloseTime']);
    }

    public function test_schedule_matrix_starts_from_court_open_time(): void
    {
        // Set seluruh lapangan buka jam 11:00 dan tutup jam 22:00
        PadelCourt::query()->update([
            'open_time' => '11:00',
            'close_time' => '22:00',
        ]);

        $service = app(PadelBookingService::class);
        // Tanggal masa depan — tanggal lampau kini ditandai PAST (sama seperti POS Walk-In).
        $matrix = $service->getScheduleMatrix(now()->addDays(3)->format('Y-m-d'));

        $courtData = collect($matrix['courts'])->firstWhere('court_id', $this->court->id);
        $this->assertNotNull($courtData);
        $this->assertNotEmpty($courtData['slots']);

        // Baris slot pertama HARUS dimulai dari jam buka (11:00), bukan hardcoded 06:00
        $firstSlot = $courtData['slots'][0];
        $this->assertEquals('11:00 - 12:00', $firstSlot['time']);
        $this->assertEquals('11:00', $firstSlot['local_start']);
        $this->assertEquals('AVAILABLE', $firstSlot['status']);

        // Slot jam 06:00 s/d 10:00 tidak boleh ada di matriks
        $hasBeforeOpen = collect($courtData['slots'])->contains(function ($s) {
            return $s['time'] === '06:00 - 07:00' || $s['time'] === '10:00 - 11:00';
        });
        $this->assertFalse($hasBeforeOpen);

        // Baris slot terakhir adalah 21:00 - 22:00
        $lastSlot = end($courtData['slots']);
        $this->assertEquals('21:00 - 22:00', $lastSlot['time']);
    }

    public function test_hold_batch_slots_rejects_booking_outside_operating_hours(): void
    {
        $this->court->update([
            'open_time' => '11:00',
            'close_time' => '22:00',
        ]);

        $service = app(PadelBookingService::class);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('berada di luar jam operasional');

        // Coba hold slot jam 09:00 - 10:00 (sebelum jam 11:00 buka)
        $bookingDate = \Carbon\Carbon::tomorrow()->toDateString();
        $service->holdBatchSlots([
            [
                'court_id' => $this->court->id,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
            ],
        ], $bookingDate, $this->adminUser);
    }
}
