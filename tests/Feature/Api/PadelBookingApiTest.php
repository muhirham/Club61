<?php

namespace Tests\Feature\Api;

use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class PadelBookingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;
    protected User $customerB;
    protected User $cashier;
    protected PadelCourt $court1;
    protected PadelCourt $court2;
    protected CourtEquipment $racket;
    protected string $customerToken;
    protected string $customerBToken;
    protected string $cashierToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create([
            'role' => 'CUSTOMER',
            'is_active' => true,
        ]);
        $this->customerToken = $this->customer->createToken('test-app')->plainTextToken;

        $this->customerB = User::factory()->create([
            'role' => 'CUSTOMER',
            'is_active' => true,
        ]);
        $this->customerBToken = $this->customerB->createToken('test-app-b')->plainTextToken;

        $this->cashier = User::factory()->create([
            'role' => 'CASHIER',
            'is_active' => true,
        ]);
        $this->cashierToken = $this->cashier->createToken('test-pos')->plainTextToken;

        $this->court1 = PadelCourt::create([
            'name' => 'Lapangan 1 (Panoramic Pro)',
            'type' => 'INDOOR',
            'hourly_rate_regular' => 200000,
            'hourly_rate_prime' => 300000,
            'is_active' => true,
        ]);

        $this->court2 = PadelCourt::create([
            'name' => 'Lapangan 2 (Tournament Std)',
            'type' => 'INDOOR',
            'hourly_rate_regular' => 200000,
            'hourly_rate_prime' => 300000,
            'is_active' => true,
        ]);

        $this->racket = CourtEquipment::create([
            'name' => 'Raket Carbon Pro',
            'type' => 'RACKET',
            'rental_price' => 50000,
            'stock_quantity' => 10,
        ]);
    }

    /**
     * 1. Test Endpoint Publik: Courts, Schedule, Equipments
     */
    public function test_schedule_rejects_unknown_timezone_instead_of_server_error(): void
    {
        $tomorrow = now()->addDay()->format('Y-m-d');

        $this->getJson("/api/v1/padel/schedule?date={$tomorrow}&timezone=abc")
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');

        foreach (['Asia/Jakarta', 'Asia/Makassar', '+07:00', '7'] as $timezone) {
            $this->getJson('/api/v1/padel/schedule?'.http_build_query(['date' => $tomorrow, 'timezone' => $timezone]))
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    public function test_public_can_access_courts_schedule_and_equipments(): void
    {
        $this->getJson('/api/v1/padel/courts')
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data');

        $tomorrow = now()->addDay()->format('Y-m-d');
        $this->getJson("/api/v1/padel/schedule?date={$tomorrow}")
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'date',
                    'timezone',
                    'courts' => [
                        '*' => [
                            'court_id',
                            'court_name',
                            'type',
                            'slots',
                        ],
                    ],
                ],
            ]);

        $this->getJson('/api/v1/padel/equipments')
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');
    }

    /**
     * 2. Test Member Bisa Hold Multi-Slot secara Atomik (All-or-Nothing Sukses)
     */
    public function test_member_can_hold_multi_slot_atomic_success(): void
    {
        $bookingDate = now()->addDays(2)->format('Y-m-d');

        $payload = [
            'booking_date' => $bookingDate,
            'slots' => [
                [
                    'court_id' => $this->court1->id,
                    'start_time' => '08:00',
                    'end_time' => '09:00',
                ],
                [
                    'court_id' => $this->court1->id,
                    'start_time' => '09:00',
                    'end_time' => '10:00',
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', $payload);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.hold_seconds_remaining', 600)
            // Jam berurutan di lapangan yang sama digabung jadi SATU booking 08:00-10:00 (tidak bisa dipecah saat reschedule).
            ->assertJsonCount(1, 'data.bookings');

        $this->assertDatabaseCount('padel_bookings', 1);
        $booking = PadelBooking::first();
        $this->assertSame('LOCKED', $booking->status);
        $this->assertSame('08:00', $booking->start_time->format('H:i'));
        $this->assertSame('10:00', $booking->end_time->format('H:i'));
    }

    /**
     * 3. Test Atomicity: All-or-Nothing Rollback saat 1 Slot Tabrakan
     */
    public function test_atomic_multi_slot_all_or_nothing_when_one_slot_collides(): void
    {
        $bookingDate = now()->addDays(3)->format('Y-m-d');

        // Customer A duluan hold jam 08:00 - 09:00
        $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '08:00',
                        'end_time' => '09:00',
                    ],
                ],
            ])
            ->assertStatus(201);

        // Customer B mencoba hold 2 slot: (08:00 - 09:00) DAN (09:00 - 10:00)
        $responseB = $this->withHeader('Authorization', "Bearer {$this->customerBToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '08:00',
                        'end_time' => '09:00',
                    ],
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '09:00',
                        'end_time' => '10:00',
                    ],
                ],
            ]);

        // Customer B WAJIB DITOLAK 409 Conflict
        $responseB->assertStatus(409)
            ->assertJson([
                'success' => false,
                'errors' => [
                    'code' => 'SLOT_CONFLICT',
                ],
            ]);

        // VERIFIKASI KRUSIAL: Slot kedua (09:00 - 10:00) TIDAK BOLEH tersimpan di DB karena All-or-Nothing!
        $this->assertDatabaseMissing('padel_bookings', [
            'user_id' => $this->customerB->id,
        ]);
        $this->assertDatabaseCount('padel_bookings', 1); // Hanya booking Customer A yang ada
    }

    /**
     * 4. Test Batas Jam Berurutan (Consecutive Boundaries: 08-09 vs 09-10) TIDAK BENTROK
     */
    public function test_consecutive_boundary_slots_do_not_conflict(): void
    {
        $bookingDate = now()->addDays(4)->format('Y-m-d');

        // Customer A: 08:00 - 09:00
        $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '08:00',
                        'end_time' => '09:00',
                    ],
                ],
            ])
            ->assertStatus(201);

        // Customer B: 09:00 - 10:00 (tepat di batas menit ke 09:00) WAJIB BERHASIL!
        $this->withHeader('Authorization', "Bearer {$this->customerBToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '09:00',
                        'end_time' => '10:00',
                    ],
                ],
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('padel_bookings', 2);
    }

    /**
     * 5. Test Overlap Parsial Ditolak (08:00-10:00 vs 09:00-11:00)
     */
    public function test_partial_overlap_is_strictly_rejected(): void
    {
        $bookingDate = now()->addDays(5)->format('Y-m-d');

        // Customer A: 08:00 - 10:00 (2 jam)
        $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '08:00',
                        'end_time' => '10:00',
                    ],
                ],
            ])
            ->assertStatus(201);

        // Customer B: 09:00 - 11:00 (tabrakan di jam 09:00-10:00)
        $this->withHeader('Authorization', "Bearer {$this->customerBToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '09:00',
                        'end_time' => '11:00',
                    ],
                ],
            ])
            ->assertStatus(409);
    }

    /**
     * 6. Test Validasi Waktu Terbalik & Tanggal Lampau
     */
    public function test_invalid_time_and_past_date_are_rejected(): void
    {
        // Tanggal kemarin
        $yesterday = now()->subDay()->format('Y-m-d');
        $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $yesterday,
                'slots' => [
                    ['court_id' => $this->court1->id, 'start_time' => '08:00', 'end_time' => '09:00'],
                ],
            ])
            ->assertStatus(422);

        // Jam terbalik (selesai lebih kecil dari mulai)
        $future = now()->addDays(2)->format('Y-m-d');
        $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $future,
                'slots' => [
                    ['court_id' => $this->court1->id, 'start_time' => '10:00', 'end_time' => '08:00'],
                ],
            ])
            ->assertStatus(422);
    }

    /**
     * 7. Test Checkout Idempotent dengan X-Idempotency-Key
     */
    public function test_checkout_with_idempotency_key_and_equipment_addons(): void
    {
        $bookingDate = now()->addDays(6)->format('Y-m-d');

        $holdResponse = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    ['court_id' => $this->court1->id, 'start_time' => '10:00', 'end_time' => '11:00'],
                ],
            ])
            ->assertStatus(201);

        $bookingId = $holdResponse->json('data.bookings.0.id');
        $idempotencyKey = (string) Str::uuid();

        // 1. Checkout tanpa header Idempotency -> Ditolak 400
        $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/checkout', [
                'booking_ids' => [$bookingId],
                'payment_method' => 'QRIS',
            ])
            ->assertStatus(400);

        // 2. Checkout dengan header Idempotency -> Berhasil 200
        $checkoutResponse1 = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/padel/checkout', [
                'booking_ids' => [$bookingId],
                'equipments' => [
                    ['equipment_id' => $this->racket->id, 'quantity' => 2],
                ],
                // Dulu ada voucher_code "HEMAT10" yang tidak pernah dibuat (diabaikan diam-diam). Kode tidak valid sekarang
                // ditolak 422 — lihat SecurityHardeningAuditTest::test_voucher_rejected_when_expired_or_quota_exhausted.
                'payment_method' => 'QRIS',
            ]);

        $checkoutResponse1->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.payment_status', 'PAID');

        $this->assertDatabaseHas('padel_bookings', [
            'id' => $bookingId,
            'status' => 'PAID',
        ]);
        $this->assertDatabaseHas('padel_booking_equipments', [
            'booking_id' => $bookingId,
            'quantity' => 2,
        ]);

        // 3. Retry request dengan Idempotency-Key yang sama persis -> Mengembalikan response identik
        $checkoutResponse2 = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/padel/checkout', [
                'booking_ids' => [$bookingId],
                'payment_method' => 'QRIS',
            ]);

        $checkoutResponse2->assertStatus(200)
            ->assertJsonPath('data.order_id', $checkoutResponse1->json('data.order_id'));
    }

    /**
     * 8. Test Single-Use QR Check-In & Anti-Replay Rejection
     */
    public function test_single_use_qr_check_in_and_replay_rejection(): void
    {
        $bookingDate = now()->format('Y-m-d');
        $startTime = now()->addMinutes(15);
        $endTime = $startTime->copy()->addHour();

        $booking = PadelBooking::create([
            'booking_code' => 'BK-PAD-TEST01',
            'user_id' => $this->customer->id,
            'court_id' => $this->court1->id,
            'booking_date' => $bookingDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'court_fee' => 200000,
            'total_amount' => 200000,
            'status' => 'PAID',
            'qr_code_hash' => hash_hmac('sha256', 'BK-PAD-TEST01' . $this->customer->id, config('app.key')),
        ]);

        // Scan Pertama oleh Kasir -> Berhasil 200 OK (Alert Hijau)
        $response1 = $this->withHeader('Authorization', "Bearer {$this->cashierToken}")
            ->postJson('/api/v1/padel/check-in', [
                'qr_code_hash' => $booking->qr_code_hash,
            ]);

        $response1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'already_checked_in' => false,
                    'booking_code' => 'BK-PAD-TEST01',
                ],
            ]);

        $this->assertDatabaseHas('padel_bookings', [
            'id' => $booking->id,
            'status' => 'CHECKED_IN',
        ]);
        $this->assertNotNull($booking->fresh()->checked_in_at);

        // Scan Kedua (Double-Scan Kasir / Shift Change) -> Ramah Kasir 200 OK (Alert Kuning)
        $response2 = $this->withHeader('Authorization', "Bearer {$this->cashierToken}")
            ->postJson('/api/v1/padel/check-in', [
                'qr_code_hash' => $booking->qr_code_hash,
            ]);

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'already_checked_in' => true,
                    'booking_code' => 'BK-PAD-TEST01',
                ],
            ]);
    }

    /**
     * 9. Test Auto-Expiry Garbage Collector Command
     */
    public function test_auto_expiry_garbage_collector_releases_locked_slots(): void
    {
        $bookingDate = now()->addDays(7)->format('Y-m-d');

        // Buat booking LOCKED
        $expiredBooking = PadelBooking::create([
            'booking_code' => 'BK-PAD-EXP01',
            'user_id' => $this->customer->id,
            'court_id' => $this->court1->id,
            'booking_date' => $bookingDate,
            'start_time' => Carbon::parse("{$bookingDate} 14:00"),
            'end_time' => Carbon::parse("{$bookingDate} 15:00"),
            'court_fee' => 200000,
            'total_amount' => 200000,
            'status' => 'LOCKED',
        ]);

        // Simulasikan waktu maju 11 menit (melewati 10 menit hold TTL)
        $this->travel(11)->minutes();

        // Jalankan Artisan Command
        Artisan::call('padel:release-expired-slots');

        // Status harus berubah menjadi EXPIRED
        $this->assertDatabaseHas('padel_bookings', [
            'id' => $expiredBooking->id,
            'status' => 'EXPIRED',
        ]);
    }

    /**
     * 10. QA DEFENSE 1: Test Midtrans Webhook Signature Verification and Settlement
     */
    public function test_midtrans_webhook_settlement_and_valid_signature(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-TEST-KEY']);

        $booking = PadelBooking::create([
            'booking_code' => 'BK-PAD-MID01',
            'user_id' => $this->customer->id,
            'court_id' => $this->court1->id,
            'booking_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => Carbon::parse(now()->addDays(2)->format('Y-m-d') . ' 10:00'),
            'end_time' => Carbon::parse(now()->addDays(2)->format('Y-m-d') . ' 11:00'),
            'court_fee' => 300000,
            'total_amount' => 302800,
            'status' => 'PENDING_PAYMENT',
        ]);

        $orderId = 'ORD-PAD-TEST888';
        \Illuminate\Support\Facades\Cache::put("order_bookings:{$orderId}", [$booking->id], 3600);

        $grossAmount = '302800.00';
        $statusCode = '200';
        $signature = hash('sha512', $orderId . $statusCode . $grossAmount . 'SB-Mid-server-TEST-KEY');

        $response = $this->postJson('/api/v1/padel/webhook/midtrans', [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('padel_bookings', [
            'id' => $booking->id,
            'status' => 'PAID',
        ]);

        $refreshed = $booking->fresh();
        $this->assertNotNull($refreshed->qr_code_hash);
    }

    /**
     * 11. QA DEFENSE 1: Test Spoofing Attack with Forged Signature is Rejected (HTTP 400)
     */
    public function test_midtrans_webhook_spoofing_is_rejected(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-REAL-SECRET']);

        $orderId = 'ORD-PAD-HACK01';
        $fakeSignature = 'completely-forged-signature-hash-123456';

        $response = $this->postJson('/api/v1/padel/webhook/midtrans', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '500000.00',
            'signature_key' => $fakeSignature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Akses ditolak: Signature Key tidak valid (Spoofing rejected).',
            ]);
    }



    /**
     * 14. Test Check-In Kasir Mengembalikan Rincian Alat Sewa (Handover) & Scan Kepagian Ditolak (400)
     */
    public function test_checkin_returns_equipment_handover_and_early_checkin_is_rejected(): void
    {
        $bookingDate = now()->addDays(8)->format('Y-m-d');
        // Jadwal main 3 jam lagi (kepagian > 45 menit)
        $startTime = now()->addHours(3);
        $endTime = $startTime->copy()->addHour();

        $booking = PadelBooking::create([
            'booking_code' => 'BK-PAD-HANDOVER',
            'order_id' => 'ORD-PAD-HO1',
            'user_id' => $this->customer->id,
            'court_id' => $this->court1->id,
            'booking_date' => $bookingDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'court_fee' => 200000,
            'total_amount' => 250000,
            'status' => 'PAID',
            'qr_code_hash' => hash_hmac('sha256', 'BK-PAD-HANDOVER' . $this->customer->id, config('app.key')),
        ]);

        \App\Models\Padel\PadelBookingEquipment::create([
            'order_id' => 'ORD-PAD-HO1',
            'booking_id' => $booking->id,
            'equipment_id' => $this->racket->id,
            'quantity' => 2,
            'unit_price' => 25000,
            'subtotal' => 50000,
        ]);

        // 1. Scan Kepagian (> 45 menit sebelum start_time) -> Ditolak 400
        $responseEarly = $this->withHeader('Authorization', "Bearer {$this->cashierToken}")
            ->postJson('/api/v1/padel/check-in', [
                'qr_code_hash' => $booking->qr_code_hash,
            ]);

        $responseEarly->assertStatus(400);

        // 2. Sesuaikan waktu ke 15 menit sebelum start_time (jendela check-in valid)
        $booking->update([
            'start_time' => now()->addMinutes(15),
            'end_time' => now()->addMinutes(75),
        ]);

        $responseValid = $this->withHeader('Authorization', "Bearer {$this->cashierToken}")
            ->postJson('/api/v1/padel/check-in', [
                'qr_code_hash' => $booking->qr_code_hash,
            ]);

        $responseValid->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'already_checked_in' => false,
                    'equipments' => [
                        [
                            'name' => 'Raket Carbon Pro',
                            'quantity' => 2,
                        ],
                    ],
                ],
            ]);
    }

    /**
     * 15. Test Multi-Hour Consolidated Order, Flat Equipment (Celah 2), and Anti-Jadwal Bolong (Celah 1)
     */
    public function test_multi_hour_consolidated_checkout_and_flat_equipment_and_anti_jadwal_bolong(): void
    {
        // Gunakan hari kerja (weekday) untuk menguji tarif reguler 200.000/jam
        $bookingDate = now()->next(Carbon::TUESDAY)->format('Y-m-d');

        // Skenario A: Multi-hour nempel / contiguous 3 jam (08:00 - 11:00)
        $holdContinuous = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    ['court_id' => $this->court1->id, 'start_time' => '08:00', 'end_time' => '11:00'],
                ],
            ])
            ->assertStatus(201);

        $bookingIdContinuous = $holdContinuous->json('data.bookings.0.id');

        // Checkout 3 jam + 1 Raket Carbon flat (Celah 2)
        $checkoutA = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/padel/checkout', [
                'booking_ids' => [$bookingIdContinuous],
                'equipments' => [
                    ['equipment_id' => $this->racket->id, 'quantity' => 1],
                ],
                'payment_method' => 'QRIS',
            ])
            ->assertStatus(200);

        $orderIdA = $checkoutA->json('data.order_id');
        $this->assertNotEmpty($orderIdA);

        // Biaya lapangan 3 jam regular (3 x 200.000 = 600.000), Flat raket 1x (1 x 50.000 = 50.000)
        // Grand total = 650.000 (tidak ada surcharge liar saat admin fee disabled)
        $this->assertEquals(600000, $checkoutA->json('data.court_fee'));
        $this->assertEquals(50000, $checkoutA->json('data.equipment_fee'));
        $this->assertEquals(650000, $checkoutA->json('data.grand_total'));

        // Query ticket by order_id
        $ticketA = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->getJson("/api/v1/padel/bookings/{$orderIdA}/ticket")
            ->assertStatus(200);

        $this->assertEquals('08:00', Carbon::parse($ticketA->json('data.start_time'))->timezone('Asia/Jakarta')->format('H:i'));
        $this->assertEquals('11:00', Carbon::parse($ticketA->json('data.end_time'))->timezone('Asia/Jakarta')->format('H:i'));
        $this->assertEquals(650000, $ticketA->json('data.order_grand_total'));

        // Skenario B: Jadwal Bolong / Non-Contiguous (08:00-09:00 dan 10:00-11:00, jam 09:00 dilewati)
        $bookingDateB = Carbon::parse($bookingDate)->next(Carbon::TUESDAY)->format('Y-m-d');
        $holdBolong = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDateB,
                'slots' => [
                    ['court_id' => $this->court1->id, 'start_time' => '08:00', 'end_time' => '09:00'],
                    ['court_id' => $this->court1->id, 'start_time' => '10:00', 'end_time' => '11:00'],
                ],
            ])
            ->assertStatus(201);

        $bookingIdsBolong = collect($holdBolong->json('data.bookings'))->pluck('id')->all();
        $this->assertCount(2, $bookingIdsBolong);

        // Checkout kedua slot bolong dalam 1 Order ID
        $checkoutB = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/padel/checkout', [
                'booking_ids' => $bookingIdsBolong,
                'equipments' => [
                    ['equipment_id' => $this->racket->id, 'quantity' => 2],
                ],
                'payment_method' => 'QRIS',
            ])
            ->assertStatus(200);

        $orderIdB = $checkoutB->json('data.order_id');
        $this->assertNotEmpty($orderIdB);
        $this->assertEquals(500000, $checkoutB->json('data.grand_total'));

        // Tiket per order tetap menjaga 2 sesi terpisah (anti jadwal bolong)
        $ticketB = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->getJson("/api/v1/padel/bookings/{$orderIdB}/ticket")
            ->assertStatus(200);

        // order_bookings memiliki 2 item terpisah sehingga frontend memunculkan tab sesi terpisah
        $this->assertCount(2, $ticketB->json('data.order_bookings'));
        $this->assertEquals('08:00', Carbon::parse($ticketB->json('data.order_bookings.0.start_time'))->timezone('Asia/Jakarta')->format('H:i'));
        $this->assertEquals('09:00', Carbon::parse($ticketB->json('data.order_bookings.0.end_time'))->timezone('Asia/Jakarta')->format('H:i'));
        $this->assertEquals('10:00', Carbon::parse($ticketB->json('data.order_bookings.1.start_time'))->timezone('Asia/Jakarta')->format('H:i'));
        $this->assertEquals('11:00', Carbon::parse($ticketB->json('data.order_bookings.1.end_time'))->timezone('Asia/Jakarta')->format('H:i'));

        // Flat equipment tetap 1x per order (2 x 50.000 = 100.000)
        $this->assertEquals(100000, $ticketB->json('data.order_equipment_fee'));
        $this->assertEquals(500000, $ticketB->json('data.order_grand_total'));
    }

    /**
     * 16. Test Ganti Metode Pembayaran (Retry Payment via On-the-Fly Suffix).
     */
    public function test_retry_payment_generates_suffixed_snap_token_and_updates_fee(): void
    {
        $tomorrow = Carbon::parse('next Tuesday')->format('Y-m-d');
        $hold = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $tomorrow,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '14:00',
                        'end_time' => '15:00',
                    ],
                ],
            ])
            ->assertStatus(201);

        $bookingId = $hold->json('data.bookings.0.id');

        // Checkout awal menggunakan BCA_VA
        $checkout = $this->withHeaders([
            'Authorization' => "Bearer {$this->customerToken}",
            'X-Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/padel/checkout', [
            'booking_ids' => [$bookingId],
            'payment_method' => 'BCA_VA',
        ])->assertStatus(200);

        $orderId = $checkout->json('data.order_id');
        $this->assertEquals(200000, $checkout->json('data.grand_total'));

        // Simulasikan status PENDING_PAYMENT saat menunggu pembayaran customer (di environment test
        // checkout langsung lunas lewat mock, jadi pembayarannya juga dikembalikan ke PENDING — kondisi
        // nyata saat customer belum membayar di Midtrans).
        PadelBooking::where('id', $bookingId)->update(['status' => 'PENDING_PAYMENT']);
        \App\Models\Pos\Order::where('order_number', $orderId)->update(['payment_status' => 'PENDING']);
        \App\Models\Pos\Payment::whereHas('order', fn ($q) => $q->where('order_number', $orderId))->update(['status' => 'PENDING', 'payment_gateway' => 'MIDTRANS']);

        // Customer menutup Snap dan ganti metode ke QRIS
        $retry = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson("/api/v1/padel/bookings/{$bookingId}/retry-payment", [
                'payment_method' => 'QRIS',
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_cash' => false,
                'order_id' => $orderId,
                'gateway_fee' => 0,
                'payment_method' => 'QRIS',
            ]);

        // Pastikan order_id yang dikirim ke Midtrans memiliki suffix waktu (_timestamp)
        $suffixedOrderId = $retry->json('suffixed_order_id');
        $this->assertStringStartsWith($orderId . '_', $suffixedOrderId);
        $this->assertNotEmpty($retry->json('snap_token'));
        $this->assertEquals(200000, $retry->json('grand_total'));

        // Database orders tetap memiliki order_number bersih
        $this->assertDatabaseHas('orders', [
            'order_number' => $orderId,
            'grand_total' => 200000,
        ]);
    }

    /**
     * 17. Test Ganti Metode Pembayaran ke Tunai di Meja Kasir (CASH).
     */
    public function test_idempotency_key_reused_by_another_account_never_returns_someone_elses_checkout(): void
    {
        $bookingId = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => now()->addDays(6)->format('Y-m-d'),
                'slots' => [['court_id' => $this->court1->id, 'start_time' => '10:00', 'end_time' => '11:00']],
            ])->assertStatus(201)->json('data.bookings.0.id');
        $key = (string) Str::uuid();

        $this->withHeader('Authorization', "Bearer {$this->customerToken}")->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/v1/padel/checkout', ['booking_ids' => [$bookingId], 'payment_method' => 'QRIS'])
            ->assertOk();

        $this->app['auth']->forgetGuards(); // guard Sanctum menyimpan user request sebelumnya di dalam satu test
        // Akun lain memakai kunci yang sama → diproses sebagai checkout-nya sendiri (booking bukan miliknya → ditolak),
        // bukan menerima respons checkout akun pertama (link bayar, kode booking, QR).
        $this->withHeader('Authorization', "Bearer {$this->customerBToken}")->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/v1/padel/checkout', ['booking_ids' => [$bookingId], 'payment_method' => 'QRIS'])
            ->assertStatus(422)
            ->assertJsonMissingPath('data.order_number');
    }

    public function test_retry_payment_with_cash_is_rejected(): void
    {
        $tomorrow = Carbon::parse('next Tuesday')->format('Y-m-d');
        $hold = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $tomorrow,
                'slots' => [
                    [
                        'court_id' => $this->court1->id,
                        'start_time' => '15:00',
                        'end_time' => '16:00',
                    ],
                ],
            ])
            ->assertStatus(201);

        $bookingId = $hold->json('data.bookings.0.id');

        $checkout = $this->withHeaders([
            'Authorization' => "Bearer {$this->customerToken}",
            'X-Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/padel/checkout', [
            'booking_ids' => [$bookingId],
            'payment_method' => 'QRIS',
        ])->assertStatus(200);

        $orderId = $checkout->json('data.order_id');

        // Simulasikan status PENDING_PAYMENT saat menunggu pembayaran customer (di environment test
        // checkout langsung lunas lewat mock, jadi pembayarannya juga dikembalikan ke PENDING — kondisi
        // nyata saat customer belum membayar di Midtrans).
        PadelBooking::where('id', $bookingId)->update(['status' => 'PENDING_PAYMENT']);
        \App\Models\Pos\Order::where('order_number', $orderId)->update(['payment_status' => 'PENDING']);
        \App\Models\Pos\Payment::whereHas('order', fn ($q) => $q->where('order_number', $orderId))->update(['status' => 'PENDING', 'payment_gateway' => 'MIDTRANS']);

        // Venue 100% Cashless: retry-payment ke CASH wajib ditolak validasi.
        $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->postJson("/api/v1/padel/bookings/{$bookingId}/retry-payment", [
                'payment_method' => 'CASH',
            ])
            ->assertStatus(422);
    }

    /**
     * 18. Test Midtrans Webhook dengan On-the-Fly Suffix (ORD-PAD-XXXX_timestamp).
     */
    public function test_midtrans_webhook_with_suffixed_order_id_updates_clean_order_and_records_payment(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-TEST-KEY']);

        $cleanOrderId = 'ORD-PAD-CLEAN999';
        $order = \App\Models\Pos\Order::create([
            'order_number' => $cleanOrderId,
            'user_id' => $this->customer->id,
            'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 200000,
            'grand_total' => 202800,
            'payment_status' => 'PENDING',
        ]);

        $booking = PadelBooking::create([
            'booking_code' => 'BK-PAD-SUF01',
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'court_id' => $this->court1->id,
            'booking_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => Carbon::parse(now()->addDays(2)->format('Y-m-d') . ' 16:00'),
            'end_time' => Carbon::parse(now()->addDays(2)->format('Y-m-d') . ' 17:00'),
            'court_fee' => 200000,
            'total_amount' => 202800,
            'status' => 'PENDING_PAYMENT',
        ]);

        // Simulasikan Midtrans mengirim webhook dengan suffix ID
        $suffixedOrderId = $cleanOrderId . '_1700000000';
        \Illuminate\Support\Facades\Cache::put("order_bookings:{$suffixedOrderId}", [$booking->id], 3600);

        $grossAmount = '202800.00';
        $statusCode = '200';
        // Signature dihitung Midtrans menggunakan full suffixed order ID
        $signature = hash('sha512', $suffixedOrderId . $statusCode . $grossAmount . 'SB-Mid-server-TEST-KEY');

        $response = $this->postJson('/api/v1/padel/webhook/midtrans', [
            'order_id' => $suffixedOrderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'payment_type' => 'qris',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        // Verifikasi status booking dan QR code
        $this->assertDatabaseHas('padel_bookings', [
            'id' => $booking->id,
            'status' => 'PAID',
        ]);
        $this->assertNotNull($booking->fresh()->qr_code_hash);

        // Verifikasi Order tabel lokal tetap bersih
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_number' => $cleanOrderId,
            'payment_status' => 'PAID',
        ]);

        // Verifikasi tabel payments mencatat transaction_id utuh bersuffix
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'transaction_id' => $suffixedOrderId,
            'payment_gateway' => 'MIDTRANS',
            'payment_method' => 'QRIS',
            'amount' => 202800,
            'status' => 'SUCCESS',
        ]);
    }

    /**
     * 19. Booking online yang belum dibayar TIDAK bisa dilunasi di kasir — wajib dibayar online (bayar di kasir = booking
     * walk-in baru). Dulu bisa, sehingga customer bisa menahan slot lewat booking online tanpa pernah membayar.
     * Selisih reschedule tetap bisa dilunasi di kasir (lihat ReschedulePaymentTest / PaymentOrchestrationIntegrityTest).
     */
    public function test_cashier_cannot_settle_unpaid_online_booking(): void
    {
        $date = now()->addDays(2)->format('Y-m-d');
        $make = fn (string $code, string $status, string $hour) => PadelBooking::create([
            'booking_code' => $code,
            'user_id' => $this->customer->id,
            'court_id' => $this->court1->id,
            'booking_date' => $date,
            'start_time' => Carbon::parse("{$date} {$hour}"),
            'end_time' => Carbon::parse("{$date} {$hour}")->addHour(),
            'court_fee' => 300000,
            'total_amount' => 300000,
            'status' => $status,
        ]);
        $unpaid = $make('BK-PAD-CASH01', 'PENDING_PAYMENT', '17:00');
        $cartHold = $make('BK-PAD-CASH02', 'LOCKED', '19:00'); // keranjang online (belum checkout), bukan selisih reschedule

        PosCashierShift::create([
            'shift_number' => 'SFT-PADEL-' . now()->format('Ymd') . '-0001',
            'counter' => 'PADEL_FRONTDESK',
            'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id,
            'opened_at' => now(),
            'starting_cash' => 200000,
            'expected_cash' => 200000,
        ]);

        $service = app(\App\Services\Padel\PadelBookingService::class);
        foreach ([$unpaid, $cartHold] as $booking) {
            try {
                $service->adminSettleCashierPayment(
                    bookingId: $booking->id,
                    paymentMethod: 'QRIS',
                    amountReceived: 300000,
                    cashierUser: $this->cashier,
                    paymentProof: ['qris_rrn' => 'RRNTEST0001'],
                );
                $this->fail("{$booking->booking_code} seharusnya ditolak di kasir");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertStringContainsString('hanya bisa dibayar customer lewat pembayaran online', $e->getMessage());
            }
            $this->assertSame($booking->status, $booking->fresh()->status);
        }

        $this->assertDatabaseMissing('payments', ['payment_gateway' => 'CASHIER_POS']);

        // Layar kasir: link "?tagihan=" untuk booking online belum dibayar ditolak dengan pemberitahuan.
        $this->actingAs($this->cashier);
        \Livewire\Livewire::test(\App\Filament\Pages\BookOfflineCourt::class)
            ->call('startSettlement', $unpaid->id)
            ->assertSet('settleBill', null)
            ->assertNotified('Dibayar Online Saja');
    }
}

