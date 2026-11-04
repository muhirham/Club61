<?php

namespace Tests\Feature;

use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Invarian stock alat sewa: BALL adalah consumable (dipotong seketika saat checkout, tidak pernah
 * di-restock), sedangkan RACKET/TOWEL adalah alat pinjam (baru dipotong saat check-in/serah-terima
 * fisik, dan bisa di-restock manual lewat aksi retur alat).
 */
class EquipmentStockTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected PadelCourt $court;
    protected CourtEquipment $racket;
    protected CourtEquipment $ball;
    protected PadelBookingService $service;
    protected string $bookingDate;

    protected function setUp(): void
    {
        parent::setUp();

        // Waktu dibekukan ke jam tetap (bukan wall-clock asli saat test dijalankan) supaya validasi
        // window check-in (-45 menit dari start_time, dan end_time > now) selalu konsisten lolos,
        // berapa pun jam sungguhan saat CI/lokal menjalankan test ini.
        Carbon::setTestNow(Carbon::parse(now()->addDays(3)->format('Y-m-d') . ' 10:00:00'));

        $this->service = app(PadelBookingService::class);

        \App\Services\Permission\Club61PermissionMatrix::syncAllPermissions('web');

        $this->cashier = User::factory()->cashier()->create(['is_active' => true]);
        $this->cashier->givePermissionTo(['View:BookOfflineCourt', 'process_walkin_booking']);

        $this->court = PadelCourt::create([
            'name' => 'Court Stock Test',
            'type' => 'INDOOR',
            'hourly_rate_regular' => 300000.00,
            'hourly_rate_prime' => 450000.00,
            'is_active' => true,
        ]);

        $this->racket = CourtEquipment::create([
            'name' => 'Raket Test',
            'type' => 'RACKET',
            'rental_price' => 50000.00,
            'stock_quantity' => 5,
        ]);

        $this->ball = CourtEquipment::create([
            'name' => 'Bola Test',
            'type' => 'BALL',
            'rental_price' => 35000.00,
            'stock_quantity' => 5,
        ]);

        $this->bookingDate = now()->format('Y-m-d');

        PosCashierShift::create([
            'shift_number' => 'SFT-STOCK-' . now()->format('Ymd') . '-0001',
            'counter' => 'PADEL_FRONTDESK',
            'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id,
            'opened_at' => now(),
            'starting_cash' => 500000,
            'expected_cash' => 500000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function checkoutWith(array $equipments, string $start = '10:00:00', string $end = '11:00:00')
    {
        $customer = $this->service->findOrCreateWalkInCustomer(
            name: 'Penyewa Alat',
            phone: '0813' . random_int(1000000, 9999999)
        );

        return $this->service->processWalkInCheckout(
            customer: $customer,
            slots: [['court_id' => $this->court->id, 'start_time' => $start, 'end_time' => $end]],
            bookingDate: $this->bookingDate,
            equipments: $equipments,
            paymentMethod: 'QRIS',
            cashier: $this->cashier,
            autoCheckIn: false,
        );
    }

    /** BALL: consumable, stock dipotong SEKETIKA saat checkout (bukan ditunda ke check-in). */
    public function test_ball_stock_is_deducted_immediately_at_checkout(): void
    {
        $result = $this->checkoutWith([
            ['equipment_id' => $this->ball->id, 'quantity' => 2],
        ]);

        $this->assertEquals(3, $this->ball->fresh()->stock_quantity);

        $line = PadelBookingEquipment::where('order_id', $result['order']->id)->firstOrFail();
        $this->assertNotNull($line->stock_deducted_at);
        $this->assertNull($line->returned_at);
    }

    /** RACKET: stock TIDAK dipotong saat checkout — baru dipotong nanti saat check-in. */
    public function test_racket_stock_is_not_deducted_until_check_in(): void
    {
        $result = $this->checkoutWith([
            ['equipment_id' => $this->racket->id, 'quantity' => 2],
        ]);

        // Belum check-in -> stock masih utuh.
        $this->assertEquals(5, $this->racket->fresh()->stock_quantity);

        $line = PadelBookingEquipment::where('order_id', $result['order']->id)->firstOrFail();
        $this->assertNull($line->stock_deducted_at);

        $booking = $result['bookings']->first();
        $this->service->checkIn($booking->qr_code_hash, $this->cashier);

        // Sesudah check-in (serah-terima fisik) -> stock baru terpotong.
        $this->assertEquals(3, $this->racket->fresh()->stock_quantity);
        $this->assertNotNull($line->fresh()->stock_deducted_at);
    }

    /** Double-scan check-in tidak boleh memotong stock racket dua kali (idempotent). */
    public function test_double_scan_check_in_does_not_double_deduct_racket_stock(): void
    {
        $result = $this->checkoutWith([
            ['equipment_id' => $this->racket->id, 'quantity' => 2],
        ]);
        $booking = $result['bookings']->first();

        $this->service->checkIn($booking->qr_code_hash, $this->cashier);
        $this->service->checkIn($booking->qr_code_hash, $this->cashier); // scan kedua (double-scan)

        $this->assertEquals(3, $this->racket->fresh()->stock_quantity);
    }

    /** Retur alat sewa (RACKET/TOWEL) mengembalikan stock; BALL tidak pernah ikut diretur. */
    public function test_return_equipment_restocks_racket_but_not_ball(): void
    {
        $result = $this->checkoutWith([
            ['equipment_id' => $this->racket->id, 'quantity' => 2],
            ['equipment_id' => $this->ball->id, 'quantity' => 1],
        ]);
        $booking = $result['bookings']->first();
        $this->service->checkIn($booking->qr_code_hash, $this->cashier);

        $this->assertEquals(3, $this->racket->fresh()->stock_quantity);
        $this->assertEquals(4, $this->ball->fresh()->stock_quantity);

        // Retur cepat ini untuk alat sewa dari sebelum Peminjaman Alat ada (tanpa catatan peminjaman).
        \App\Models\Padel\EquipmentLoan::query()->delete();
        $returnResult = $this->service->returnEquipment($booking->id, $this->cashier);

        // Racket balik ke stok, ball TIDAK ikut diretur (consumable).
        $this->assertEquals(5, $this->racket->fresh()->stock_quantity);
        $this->assertEquals(4, $this->ball->fresh()->stock_quantity);
        $this->assertEquals(1, $returnResult['restored_count']);
        $this->assertEquals('Raket Test', $returnResult['items'][0]['name']);
    }

    /** Retur dua kali untuk booking yang sama harus ditolak (idempotency guard, bukan restock dobel). */
    public function test_returning_equipment_twice_is_rejected(): void
    {
        $result = $this->checkoutWith([
            ['equipment_id' => $this->racket->id, 'quantity' => 2],
        ]);
        $booking = $result['bookings']->first();
        $this->service->checkIn($booking->qr_code_hash, $this->cashier);
        \App\Models\Padel\EquipmentLoan::query()->delete(); // alat sewa dari sebelum Peminjaman Alat ada

        $this->service->returnEquipment($booking->id, $this->cashier);
        $this->assertEquals(5, $this->racket->fresh()->stock_quantity);

        $this->expectException(HttpException::class);
        $this->service->returnEquipment($booking->id, $this->cashier);
    }

    /** Checkout ditolak kalau qty bola yang diminta melebihi sisa stok, dan stock tidak berubah sama sekali. */
    public function test_checkout_rejected_when_ball_stock_insufficient(): void
    {
        $this->expectException(HttpException::class);

        try {
            $this->checkoutWith([
                ['equipment_id' => $this->ball->id, 'quantity' => 99],
            ]);
        } finally {
            $this->assertEquals(5, $this->ball->fresh()->stock_quantity);
        }
    }
}
