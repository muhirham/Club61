<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payment\GatewayCustomer;
use App\Services\Payment\OnlineGatewayRouter;
use App\Services\Payment\PaymentManager;
use App\Support\PersonName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nama orang: satu aturan untuk semua form (huruf & spasi, 2–100 karakter) + satu perapi data customer untuk
 * Midtrans & DOKU (data lama di database tetap bisa bayar).
 */
class CustomerNameRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_rule_accepts_letters_and_spaces_only(): void
    {
        foreach (['Andi Wijaya', 'Siti', 'José Ramírez', "  Andi \t  Wijaya  "] as $name) {
            $this->assertTrue(PersonName::isValid($name), $name);
        }
        foreach (['Andi (Customer)', 'Muh. Irham', "O'Neil", 'Siti-Nur', 'Budi123', 'A', '😀 Budi', '', '   ', str_repeat('a', 101)] as $name) {
            $this->assertFalse(PersonName::isValid($name), $name);
        }
        $this->assertSame('Andi Wijaya', PersonName::clean("  Andi \t  Wijaya  "));
    }

    public function test_web_registration_rejects_symbols_and_stores_the_tidied_name(): void
    {
        $this->post('/register', ['name' => 'Andi (Customer)', 'email' => 'a@example.com', 'phone' => '081234567892', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrors(['name' => PersonName::MESSAGE]);
        $this->assertGuest();

        $this->post('/register', ['name' => 'Andi   Wijaya', 'email' => 'a@example.com', 'phone' => '081234567892', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Andi Wijaya', User::where('email', 'a@example.com')->value('name'));
    }

    public function test_app_registration_rejects_symbols(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Muh. Irham', 'email' => 'irham@example.com', 'phone' => '081299887766', 'password' => 'Password123!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => PersonName::MESSAGE]);
        $this->assertDatabaseMissing('users', ['email' => 'irham@example.com']);
    }

    public function test_profile_update_rejects_symbols(): void
    {
        $user = User::factory()->create(['name' => 'Budi Santoso']);

        $this->actingAs($user)->patch('/profile', ['name' => 'Budi #1', 'email' => $user->email])
            ->assertSessionHasErrors('name');
        $this->assertSame('Budi Santoso', $user->fresh()->name);
    }

    public function test_midtrans_receives_the_same_tidied_customer_data_as_doku(): void
    {
        $sent = null;
        $this->mock(PaymentManager::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('createPayment')->once()->andReturnUsing(function (array $params) use (&$sent) {
                $sent = $params;

                return ['snap_token' => 'tok', 'redirect_url' => 'https://app.sandbox.midtrans.com/x', 'is_mock' => true];
            });
        });

        // Nama lama di database (sebelum aturan nama berlaku) tetap bisa bayar.
        app(OnlineGatewayRouter::class)->createSession(OnlineGatewayRouter::MIDTRANS, [
            'session_id' => 'ORD-NAME-1_1', 'order_number' => 'ORD-NAME-1', 'amount' => 100000, 'method' => 'QRIS',
            'items' => [['id' => 'X', 'price' => 100000, 'quantity' => 1, 'name' => 'Item']],
            'customer' => ['name' => 'Andi Wijaya (Customer)', 'email' => 'bukan-email', 'phone' => '0812-3456-7890'],
            'callback_url' => 'https://example.test/invoice',
        ]);

        $this->assertSame(['first_name' => 'Andi Wijaya Customer', 'phone' => '6281234567890'], $sent['customer_details']);
        $this->assertSame(['name' => 'Andi Wijaya Customer', 'phone' => '6281234567890'], GatewayCustomer::clean(['name' => 'Andi Wijaya (Customer)', 'email' => 'bukan-email', 'phone' => '0812-3456-7890']));
    }

    public function test_cashier_order_without_a_usable_name_sends_no_customer_details(): void
    {
        $this->assertSame([], GatewayCustomer::forMidtrans(['name' => null], fallbackName: null));
        $this->assertSame([], GatewayCustomer::forMidtrans(['name' => '123'], fallbackName: null));
        $this->assertSame(['first_name' => 'Pak Budi'], GatewayCustomer::forMidtrans(['name' => 'Pak Budi!'], fallbackName: null));
    }
}
