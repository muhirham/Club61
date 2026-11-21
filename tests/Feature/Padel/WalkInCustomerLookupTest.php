<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\BookOfflineCourt;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\PosCashierShift;
use App\Models\Pos\Voucher;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * POS Walk-In: Cari Akun (urutan nama + format nomor HP), voucher saldo pemilik nomor HP di Walk-In Baru, dan
 * peringatan nama yang sudah terdaftar supaya kasir tidak membuat akun ganda.
 */
class WalkInCustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private PadelCourt $court;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');

        $this->receptionist = User::factory()->create(['name' => 'Resepsionis Sari', 'is_active' => true]);
        $this->receptionist->givePermissionTo(Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
        $this->date = Carbon::parse('next Tuesday')->format('Y-m-d');

        PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->receptionist->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $this->actingAs($this->receptionist);
    }

    private function pos()
    {
        return Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', $this->date)
            ->set('selectedSlots', ["{$this->court->id}_10:00:00" => [
                'court_id' => $this->court->id, 'court_name' => $this->court->name, 'start_time' => '10:00:00',
                'end_time' => '11:00:00', 'time_label' => '10:00', 'price' => 300000,
            ]]);
    }

    public function test_search_puts_the_exact_name_first_and_finds_every_phone_format(): void
    {
        foreach (['Randi', 'Pandi Wijaya', 'Andika', 'Candi', 'Sandi', 'Rosandi', 'Kandia'] as $i => $name) {
            User::factory()->customer()->create(['name' => $name, 'phone' => '0812000000'.$i]);
        }
        $andi = User::factory()->customer()->create(['name' => 'Andi', 'phone' => '08567858968']);
        User::factory()->customer()->create(['name' => 'Andi Saputra', 'phone' => '081300000001']);

        // Dulu 5 hasil tanpa urutan → "Andi" bisa tidak tampil sama sekali.
        $results = BookOfflineCourt::searchCustomers('andi');
        $this->assertGreaterThanOrEqual(9, count($results), "lebih dari 5 hasil");
        $this->assertSame($andi->id, $results[0]->id, 'nama persis sama paling atas');
        $this->assertSame('Andi Saputra', $results[1]->name, 'lalu nama yang diawali "andi"');

        foreach (['08567858968', '628567858968', '+62 856-7858-968', '8567858968', '62856785'] as $typed) {
            $this->assertSame($andi->id, BookOfflineCourt::searchCustomers($typed)[0]->id, "format nomor: {$typed}");
        }

        $this->pos()->call('setCustomerMode', 'search')
            ->set('customerSearch', 'andi')
            ->assertSeeHtml('data-pos-customer-results')
            ->assertSee('08567858968');
    }

    public function test_walk_in_new_uses_the_credit_voucher_of_the_typed_phone_owner(): void
    {
        $owner = User::factory()->customer()->create(['name' => 'Budi Refund', 'phone' => '081277700066', 'is_active' => true]);
        Voucher::create([
            'code' => 'KR-BUDI0001', 'user_id' => $owner->id, 'discount_type' => Voucher::TYPE_CREDIT, 'discount_value' => 100000,
            'balance' => 100000, 'min_order_amount' => 0, 'used_count' => 0, 'valid_until' => now()->addMonth(), 'is_active' => true,
        ]);

        // Nomor HP orang lain → voucher saldo tidak tampil & ditolak.
        $page = $this->pos()->call('setCustomerMode', 'quick_create')
            ->set('walkInName', 'Budi Refund')
            ->set('walkInPhone', '081299990000')
            ->assertDontSee('KR-BUDI0001')
            ->call('applyVoucher', 'KR-BUDI0001')
            ->assertSet('appliedVoucherCode', null)
            ->assertNotified('Voucher Tidak Bisa Dipakai');

        // Nomor pemilik (format 62…) → voucher saldo langsung tampil di Walk-In Baru dan bisa dipakai, tanpa Cari Akun.
        $page->set('walkInPhone', '6281277700066')
            ->assertSee('KR-BUDI0001')
            ->call('applyVoucher', 'KR-BUDI0001')
            ->assertSet('appliedVoucherCode', 'KR-BUDI0001');
        $this->assertEquals(100000.0, $page->instance()->voucherDiscount);

        $page->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->set('paymentMethod', 'EDC_BCA')->set('edcLast4', '1234')->set('edcApprovalCode', 'APPR01')->set('edcTraceNumber', 'TRC01')
            ->call('submitWalkInBooking')
            ->assertSet('posStep', 'receipt');

        $booking = PadelBooking::with('order')->sole();
        $this->assertSame($owner->id, $booking->user_id, 'transaksi masuk ke akun pemilik nomor, bukan akun baru');
        $this->assertSame('KR-BUDI0001', $booking->order->voucher_code);
        $this->assertSame(1, User::where('name', 'Budi Refund')->count());
    }

    public function test_typing_a_registered_name_with_a_new_phone_offers_the_existing_account(): void
    {
        $andi = User::factory()->customer()->create(['name' => 'Andi', 'phone' => '08567858968']);

        $page = $this->pos()->call('setCustomerMode', 'quick_create')
            ->set('walkInName', '  andi ')
            ->set('walkInPhone', '081211112222')
            ->assertSeeHtml('data-same-name-accounts')
            ->assertSee('0856***8968')
            ->assertDontSee('08567858968');

        // Nomor milik akun itu sendiri → transaksi otomatis ke akun itu, peringatan tidak perlu.
        $page->set('walkInPhone', '08567858968')->assertDontSeeHtml('data-same-name-accounts');

        // Nama lain → tidak ada peringatan.
        $page->set('walkInPhone', '081211112222')->set('walkInName', 'Budi')->assertDontSeeHtml('data-same-name-accounts');

        // "Pakai akun ini" → pindah ke Cari Akun dengan akun itu terpilih.
        $page->set('walkInName', 'Andi')
            ->call('useSameNameAccount', $andi->id)
            ->assertSet('customerMode', 'search')
            ->assertSet('selectedCustomerId', $andi->id)
            ->assertSet('bookingType', 'REGULAR');
    }
}
