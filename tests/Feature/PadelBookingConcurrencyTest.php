<?php

namespace Tests\Feature;

use App\Models\Padel\PadelCourt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PadelBookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrency_anti_double_booking_guard(): void
    {
        // 1. Setup Court
        $court = PadelCourt::create([
            'name' => 'Court 1 - Test Arena',
            'type' => 'INDOOR',
            'hourly_rate_regular' => 300000.00,
            'hourly_rate_prime' => 450000.00,
            'is_active' => true,
        ]);

        // 2. Setup 3 Distinct Users
        $player1 = User::factory()->create();
        $player2 = User::factory()->create();
        $player3 = User::factory()->create();

        $bookingDate = now()->addDays(2)->format('Y-m-d');
        $startTime = '19:00';
        $endTime = '20:00';

        // 3. Player 1 requests slot hold
        $response1 = $this->actingAs($player1, 'sanctum')
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $court->id,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ],
                ],
            ]);

        $response1->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        // 4. Player 2 attempts to claim overlapping slot -> Must fail with 409 Conflict
        $response2 = $this->actingAs($player2, 'sanctum')
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $court->id,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ],
                ],
            ]);

        $response2->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);

        // 5. Player 3 attempts to claim overlapping partial slot (19:30 - 20:30) -> must also fail.
        //    Jadwal hanya per jam bulat, jadi slot 19:30 ditolak lebih awal (422) sebelum cek bentrok.
        $response3 = $this->actingAs($player3, 'sanctum')
            ->postJson('/api/v1/padel/hold-slot', [
                'booking_date' => $bookingDate,
                'slots' => [
                    [
                        'court_id' => $court->id,
                        'start_time' => '19:30',
                        'end_time' => '20:30',
                    ],
                ],
            ]);

        $response3->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_auth_rate_limiting_anti_bruteforce(): void
    {
        // Hit login 11 times in 1 minute -> 11th request must receive 429 Too Many Requests
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'fake_user@example.com',
                'password' => 'wrong_password',
            ]);
        }

        $throttledResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'fake_user@example.com',
            'password' => 'wrong_password',
        ]);

        $throttledResponse->assertStatus(429)
            ->assertJson([
                'success' => false,
                'message' => 'Terlalu banyak percobaan autentikasi. Silakan tunggu 1 menit.',
            ]);
    }
}
