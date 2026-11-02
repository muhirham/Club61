<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\AntrianRefund;
use App\Filament\Pages\KelolaPemesanan;
use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\Pos\Refund;
use App\Models\Pos\Voucher;
use App\Models\User;
use App\Notifications\CreditVoucherIssued;
use App\Services\Finance\RefundQueueService;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 21 — refund dua langkah & voucher saldo:
 *  - kasir / resepsionis / admin mengajukan dari Kelola Pemesanan: booking langsung batal, uang PENUH menunggu di
 *    Antrian Refund (tidak ada uang keluar sebelum disetujui);
 *  - disetujui → booking REFUNDED + uang keluar di Buku Transaksi; ditolak → booking CANCELLED + voucher saldo customer;
 *  - voucher saldo bisa dipakai sebagian, online maupun di kasir; customer tidak bisa mengajukan refund sendiri;
 *  - jam main yang sudah mulai tidak bisa di-refund, reschedule dikunci 2 jam sebelum main.
 */
class RefundRequestAndCreditVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected PadelBookingService $service;

    protected User $owner;

    protected User $cashier;

    protected User $customer;

    protected PadelCourt $court;

    protected string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');

        ClubFinanceSetting::getSettings()->update(['is_admin_fee_enabled' => false, 'is_tax_enabled' => false]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);

        $this->service = app(PadelBookingService::class);
        $this->owner = User::factory()->superAdmin()->create(['name' => 'Owner']);
        $this->cashier = User::factory()->role('cashier')->create(['name' => 'Kasir Sinta']);
        $this->customer = User::factory()->customer()->create(['name' => 'Andi', 'email' => 'andi@example.com']);
        $this->court = PadelCourt::create([
            'name' => 'Court Refund', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 200000, 'is_active' => true,
        ]);
        $this->date = now()->addDays(3)->format('Y-m-d');
    }

    private function paidBooking(string $code = 'BK-RF-001', string $start = '10:00', float $amount = 200000, ?string $date = null): PadelBooking
    {
        $date ??= $this->date;
        $order = Order::create([
            'order_number' => 'ORD-'.$code, 'user_id' => $this->customer->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => $amount, 'grand_total' => $amount, 'payment_status' => 'PAID',
        ]);
        Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => 'ORD-'.$code,
            'amount' => $amount, 'payment_method' => 'BCA_VA', 'status' => 'SUCCESS',
        ]);
        $startAt = Carbon::parse("{$date} {$start}");

        return PadelBooking::create([
            'booking_code' => $code, 'order_id' => $order->id, 'user_id' => $this->customer->id, 'court_id' => $this->court->id,
            'booking_date' => $date, 'start_time' => $startAt, 'end_time' => $startAt->copy()->addHour(),
            'court_fee' => $amount, 'total_amount' => $amount, 'status' => 'PAID', 'qr_code_hash' => 'QR-'.$code,
        ]);
    }

    private function creditVoucher(float $balance, ?User $owner = null, string $code = 'KR-TEST0001'): Voucher
    {
        return Voucher::create([
            'user_id' => ($owner ?? $this->customer)->id, 'code' => $code, 'discount_type' => Voucher::TYPE_CREDIT,
            'discount_value' => $balance, 'min_order_amount' => 0, 'balance' => $balance, 'quota' => null,
            'used_count' => 0, 'valid_until' => now()->addMonths(6), 'is_active' => true,
        ]);
    }

    private function holdAndCheckout(string $start, ?string $voucherCode): \Illuminate\Testing\TestResponse
    {
        $hold = $this->actingAs($this->customer)->postJson('/api/v1/padel/hold-slot', [
            'booking_date' => $this->date,
            'slots' => [['court_id' => $this->court->id, 'start_time' => $start, 'end_time' => Carbon::parse($start)->addHour()->format('H:i')]],
        ])->assertStatus(201);

        return $this->actingAs($this->customer)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/padel/checkout', [
                'booking_ids' => [$hold->json('data.bookings.0.id')],
                'voucher_code' => $voucherCode,
                'payment_method' => 'QRIS',
            ]);
    }

    public function test_cashier_requests_a_full_refund_and_no_money_leaves_until_it_is_approved(): void
    {
        $booking = $this->paidBooking();
        $this->actingAs($this->cashier);

        Livewire::test(KelolaPemesanan::class)
            ->call('openCancelRefundModal', $booking->id)
            ->assertSet('showCancelRefundModal', true)
            ->assertSet('refundAmount', 200000.0)
            ->set('refundNotes', 'Customer sakit, minta uang kembali')
            ->call('executeCancelRefund')
            ->assertSet('showCancelRefundModal', false);

        $booking->refresh();
        $this->assertSame('REFUND_PENDING', $booking->status);
        $this->assertNull($booking->qr_code_hash);
        $refund = Refund::where('padel_booking_id', $booking->id)->sole();
        $this->assertSame('PENDING', $refund->status);
        $this->assertEquals(200000, (float) $refund->refund_amount);
        $this->assertSame($this->cashier->id, $refund->requested_by_id);
        $this->assertSame(0, LedgerEntry::where('entry_type', 'REFUND')->count(), 'pengajuan tidak mengeluarkan uang');
        $this->assertFalse(AntrianRefund::canAccess(), 'kasir tidak bisa menyetujui refund');

        // Slot langsung kembali tersedia.
        $slot = collect(collect($this->service->getScheduleMatrix($this->date)['courts'])->firstWhere('court_id', $this->court->id)['slots'])->firstWhere('local_start', '10:00');
        $this->assertSame('AVAILABLE', $slot['status']);

        // Disetujui (boleh oleh pengaju sendiri kalau punya izinnya — di sini owner).
        $this->actingAs($this->owner);
        Livewire::test(AntrianRefund::class)
            ->callTableAction('process', $refund, ['refund_method' => 'TRANSFER_BANK', 'refund_reference' => 'TRF-123456'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('REFUNDED', $booking->fresh()->status);
        $this->assertEquals(-200000, (float) LedgerEntry::where('refund_id', $refund->id)->sum('total_amount'));
    }

    public function test_refund_and_reschedule_are_locked_once_play_time_is_near_or_started(): void
    {
        Carbon::setTestNow(Carbon::parse("{$this->date} 08:30"));
        $soon = $this->paidBooking('BK-RF-SOON', '10:00');      // 1,5 jam lagi
        $started = $this->paidBooking('BK-RF-LIVE', '08:00');   // sedang main

        // Reschedule: paling lambat 2 jam sebelum main.
        try {
            $this->service->adminRescheduleBooking($soon->id, $this->court->id, $this->date, '14:00', 'pindah', $this->owner);
            $this->fail('Reschedule < 2 jam sebelum main harus ditolak');
        } catch (HttpException $e) {
            $this->assertStringContainsString('2 jam sebelum jam main', $e->getMessage());
        }
        $this->actingAs($this->owner);
        Livewire::test(KelolaPemesanan::class)->call('openRescheduleModal', $soon->id)->assertSet('showRescheduleModal', false);

        // Refund masih boleh selama jam main belum mulai, tapi tidak setelah mulai.
        try {
            $this->service->requestCancelAndRefund($started->id, 'PERMINTAAN_CUSTOMER', 'telat datang', $this->owner);
            $this->fail('Booking yang jam mainnya sudah mulai tidak boleh di-refund');
        } catch (HttpException $e) {
            $this->assertStringContainsString('sudah dimulai', $e->getMessage());
        }
        Livewire::test(KelolaPemesanan::class)->call('openCancelRefundModal', $started->id)->assertSet('showCancelRefundModal', false);
        $this->assertSame('PAID', $started->fresh()->status);
        $this->assertSame(0, Refund::count());

        Carbon::setTestNow();
    }

    public function test_rejected_refund_becomes_a_credit_voucher_on_the_customer_account(): void
    {
        Notification::fake();
        $booking = $this->paidBooking();
        $this->service->requestCancelAndRefund($booking->id, 'PERMINTAAN_CUSTOMER', 'berubah pikiran', $this->cashier);
        $refund = Refund::where('padel_booking_id', $booking->id)->sole();

        $this->actingAs($this->owner);
        Livewire::test(AntrianRefund::class)
            ->callTableAction('reject', $refund, ['reason' => 'Di luar kebijakan refund', 'issue_voucher' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame('REJECTED', $refund->fresh()->status);
        $this->assertSame('CANCELLED', $booking->fresh()->status, 'booking tetap batal');
        $this->assertSame(0, LedgerEntry::where('entry_type', 'REFUND')->count(), 'tidak ada uang keluar');

        $voucher = Voucher::where('refund_id', $refund->id)->sole();
        $this->assertSame($this->customer->id, $voucher->user_id);
        $this->assertSame(Voucher::TYPE_CREDIT, $voucher->discount_type);
        $this->assertEquals(200000, (float) $voucher->balance);
        $this->assertStringStartsWith('KR-', $voucher->code);
        $this->assertTrue($voucher->valid_until->gt(now()->addMonths(5)));
        Notification::assertSentTo($this->customer, CreditVoucherIssued::class);

        // Customer melihat hasilnya di tiket & dompet voucher.
        $this->actingAs($this->customer)->getJson("/api/v1/padel/bookings/{$booking->id}/ticket")
            ->assertOk()
            ->assertJsonPath('data.refund_info.status', 'REJECTED')
            ->assertJsonPath('data.refund_info.voucher_code', $voucher->code);
        $this->actingAs($this->customer)->getJson('/api/v1/padel/vouchers/mine')
            ->assertOk()
            ->assertJsonPath('data.0.code', $voucher->code)
            ->assertJsonPath('data.0.available', 200000);
    }

    public function test_rejecting_without_voucher_issues_nothing(): void
    {
        $booking = $this->paidBooking();
        $this->service->requestCancelAndRefund($booking->id, 'SALAH_BAYAR', 'data ganda', $this->owner);
        $refund = Refund::where('padel_booking_id', $booking->id)->sole();

        app(RefundQueueService::class)->reject($refund, $this->owner, 'Sudah dikembalikan di luar sistem', issueVoucher: false);

        $this->assertSame(0, Voucher::count());
        $this->assertSame('CANCELLED', $booking->fresh()->status);
    }

    public function test_credit_voucher_is_used_partially_online_and_belongs_to_its_owner_only(): void
    {
        $voucher = $this->creditVoucher(300000);
        $other = User::factory()->customer()->create();

        $this->actingAs($other)->postJson('/api/v1/padel/vouchers/check', ['code' => $voucher->code, 'amount' => 200000])
            ->assertStatus(422)->assertJsonPath('message', 'Voucher ini milik akun customer lain.');
        $this->actingAs($this->customer)->postJson('/api/v1/padel/vouchers/check', ['code' => strtolower($voucher->code), 'amount' => 200000])
            ->assertOk()->assertJsonPath('data.discount', 200000)->assertJsonPath('data.type', 'CREDIT');

        // Tagihan Rp200.000 ditutup penuh oleh voucher → lunas tanpa bayar, sisa saldo Rp100.000.
        $this->holdAndCheckout('10:00', $voucher->code)->assertOk();
        $order = Order::where('voucher_code', $voucher->code)->sole();
        $this->assertSame('PAID', $order->payment_status);
        $this->assertEquals(200000, (float) $order->discount_amount);
        $this->assertEquals(100000, (float) $voucher->fresh()->balance);

        // Pemakaian kedua: sisa Rp100.000 memotong sebagian tagihan berikutnya.
        $this->holdAndCheckout('12:00', $voucher->code)->assertOk();
        $second = Order::where('voucher_code', $voucher->code)->latest('created_at')->latest('id')->get()->firstWhere('id', '!=', $order->id);
        $this->assertEquals(100000, (float) $second->discount_amount);
        $this->assertEquals(100000, (float) $second->grand_total);
        $this->assertEquals(0, (float) $voucher->fresh()->balance);

        $this->actingAs($this->customer)->postJson('/api/v1/padel/vouchers/check', ['code' => $voucher->code, 'amount' => 200000])
            ->assertStatus(422);
    }

    public function test_balance_reserved_by_an_unpaid_order_cannot_be_spent_twice(): void
    {
        $voucher = $this->creditVoucher(100000);
        Order::create([
            'order_number' => 'ORD-RESERVED', 'user_id' => $this->customer->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 200000, 'discount_amount' => 100000, 'voucher_code' => $voucher->code, 'grand_total' => 100000, 'payment_status' => 'PENDING_PAYMENT',
        ]);

        $this->actingAs($this->customer)->postJson('/api/v1/padel/vouchers/check', ['code' => $voucher->code, 'amount' => 200000])
            ->assertStatus(422);
    }

    public function test_cashier_can_settle_a_walk_in_entirely_with_a_credit_voucher(): void
    {
        PosCashierShift::create([
            'shift_number' => 'SFT-RF-'.Str::random(4), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $voucher = $this->creditVoucher(500000);
        $slots = [['court_id' => $this->court->id, 'start_time' => '15:00', 'end_time' => '16:00']];

        // Voucher customer lain ditolak di kasir (bukan diam-diam diabaikan).
        $stranger = User::factory()->customer()->create();
        try {
            $this->service->processWalkInCheckout($stranger, $slots, $this->date, [], 'VOUCHER', $this->cashier, voucherCode: $voucher->code);
            $this->fail('Voucher milik customer lain harus ditolak');
        } catch (HttpException $e) {
            $this->assertStringContainsString('milik akun customer lain', $e->getMessage());
        }

        $result = $this->service->processWalkInCheckout($this->customer, $slots, $this->date, [], 'VOUCHER', $this->cashier, voucherCode: $voucher->code);

        $order = $result['order'];
        $this->assertSame('PAID', $order->payment_status);
        $this->assertEquals(0, (float) $order->grand_total);
        $this->assertSame($voucher->code, $order->voucher_code);
        $this->assertSame('VOUCHER', $order->payments->sole()->payment_method);
        $this->assertEquals(300000, (float) $voucher->fresh()->balance);
    }

    public function test_voucher_method_is_refused_when_the_voucher_does_not_cover_the_bill(): void
    {
        PosCashierShift::create([
            'shift_number' => 'SFT-RF-'.Str::random(4), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $voucher = $this->creditVoucher(50000);

        $this->expectException(HttpException::class);
        $this->service->processWalkInCheckout($this->customer, [['court_id' => $this->court->id, 'start_time' => '15:00', 'end_time' => '16:00']], $this->date, [], 'VOUCHER', $this->cashier, voucherCode: $voucher->code);
    }

    public function test_cancelling_a_booking_paid_partly_by_voucher_returns_the_voucher_part(): void
    {
        $voucher = $this->creditVoucher(150000);
        $this->holdAndCheckout('10:00', $voucher->code)->assertOk();
        $order = Order::where('voucher_code', $voucher->code)->sole();
        $this->assertEquals(0, (float) $voucher->fresh()->balance);

        // Mock gateway melunasi Rp50.000 tunai; sisanya voucher.
        $booking = PadelBooking::where('order_id', $order->id)->sole();
        $this->assertSame('PAID', $booking->status);

        $result = $this->service->requestCancelAndRefund($booking->id, 'PERMINTAAN_CUSTOMER', 'batal', $this->owner);

        $this->assertEqualsWithDelta(50000, $result['refund_amount'], 0.01, 'hanya uang tunai yang diajukan refund');
        $this->assertEquals(150000, (float) $voucher->fresh()->balance, 'bagian voucher kembali ke saldonya');
    }

    public function test_customer_cannot_request_a_refund_from_the_app(): void
    {
        $booking = $this->paidBooking();

        $this->actingAs($this->customer)->postJson("/api/v1/padel/bookings/{$booking->id}/refund", ['reason' => 'mau refund'])
            ->assertNotFound();
        $this->assertSame('PAID', $booking->fresh()->status);
    }

    public function test_voucher_list_shows_every_voucher_with_outstanding_balance_and_can_deactivate(): void
    {
        $active = $this->creditVoucher(200000, code: 'KR-AKTIF001');
        $used = $this->creditVoucher(0, code: 'KR-HABIS001');
        $expired = $this->creditVoucher(50000, code: 'KR-LEWAT001');
        $expired->update(['valid_until' => now()->subDay()]);
        $promo = Voucher::create(['code' => 'PROMO10', 'discount_type' => 'PERCENT', 'discount_value' => 10, 'quota' => 5, 'used_count' => 0, 'valid_until' => now()->addMonth(), 'is_active' => true]);

        $this->actingAs($this->cashier);
        $this->assertFalse(\App\Filament\Pages\DaftarVoucher::canAccess(), 'saldo voucher customer hanya untuk superadmin / yang diberi izin');

        $this->actingAs($this->owner);
        // Terdaftar di panel admin (menu Keuangan) — dulu kelupaan didaftarkan sehingga menunya tidak muncul.
        $this->assertContains(\App\Filament\Pages\DaftarVoucher::class, \Filament\Facades\Filament::getPanel('admin')->getPages());
        $this->get(\App\Filament\Pages\DaftarVoucher::getUrl())->assertOk()->assertSee('Sisa saldo voucher aktif');
        $this->get(\App\Filament\Pages\AntrianRefund::getUrl())->assertOk()->assertSee('Daftar Voucher');

        Livewire::test(\App\Filament\Pages\DaftarVoucher::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$active, $used, $expired, $promo])
            ->assertSee('Rp 200.000')            // sisa saldo aktif
            ->assertSee('Rp 250.000')            // total terbit
            ->filterTable('status', 'AKTIF')
            ->assertCanSeeTableRecords([$active, $promo])
            ->assertCanNotSeeTableRecords([$used, $expired])
            ->filterTable('status', 'KEDALUWARSA')
            ->assertCanSeeTableRecords([$expired])
            ->assertCanNotSeeTableRecords([$active])
            ->resetTableFilters()
            ->filterTable('jenis', 'PROMO')
            ->assertCanSeeTableRecords([$promo])
            ->assertCanNotSeeTableRecords([$active])
            ->resetTableFilters()
            ->searchTable('Andi')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$promo])
            ->callTableAction('deactivate', $active, ['reason' => 'Salah terbit'])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($active->fresh()->is_active);
        $this->assertTrue(\App\Models\Audit\ActivityLog::where('event', 'voucher.deactivated')->where('severity', 'CRITICAL')->exists());
        $this->actingAs($this->customer)->postJson('/api/v1/padel/vouchers/check', ['code' => $active->code, 'amount' => 100000])->assertStatus(422);
    }

    public function test_pos_refuses_when_the_server_total_differs_from_the_cashier_screen(): void
    {
        PosCashierShift::create([
            'shift_number' => 'SFT-RF-'.Str::random(4), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->cashier->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $voucher = $this->creditVoucher(50000);

        // Layar kasir tidak menampilkan potongan (Rp200.000) tapi server memotong voucher (Rp150.000) → ditolak,
        // jangan sampai EDC menagih Rp200.000 sementara yang tercatat Rp150.000.
        try {
            $this->service->processWalkInCheckout(
                $this->customer, [['court_id' => $this->court->id, 'start_time' => '15:00', 'end_time' => '16:00']], $this->date, [], 'QRIS', $this->cashier,
                paymentMeta: ['qris_rrn' => 'RRN-MISMATCH-1'], voucherCode: $voucher->code, expectedGrandTotal: 200000,
            );
            $this->fail('Total berbeda harus ditolak');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, Order::count());
        $this->assertEquals(50000, (float) $voucher->fresh()->balance);
    }

    public function test_credit_voucher_is_issued_in_whole_rupiah(): void
    {
        $booking = $this->paidBooking();
        $this->service->requestCancelAndRefund($booking->id, 'PERMINTAAN_CUSTOMER', 'batal', $this->owner);
        $refund = Refund::where('padel_booking_id', $booking->id)->sole();
        $refund->update(['refund_amount' => 133333.33]);

        $rejected = app(RefundQueueService::class)->reject($refund, $this->owner, 'Di luar kebijakan');

        $this->assertEquals(133333, (float) $rejected->voucher->balance);
    }

    public function test_voucher_is_not_restored_when_part_of_the_order_was_already_played(): void
    {
        $voucher = $this->creditVoucher(400000);
        $order = Order::create([
            'order_number' => 'ORD-2SLOT', 'user_id' => $this->customer->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 400000, 'discount_amount' => 400000, 'voucher_code' => $voucher->code, 'grand_total' => 0, 'payment_status' => 'PAID',
        ]);
        Payment::create(['order_id' => $order->id, 'payment_gateway' => 'PROMO_VOUCHER', 'transaction_id' => 'ORD-2SLOT', 'amount' => 0, 'payment_method' => 'PROMO_VOUCHER', 'status' => 'SUCCESS']);
        $voucher->update(['balance' => 0]);
        $make = fn (string $code, string $date, string $status) => PadelBooking::create([
            'booking_code' => $code, 'order_id' => $order->id, 'user_id' => $this->customer->id, 'court_id' => $this->court->id,
            'booking_date' => $date, 'start_time' => Carbon::parse("{$date} 10:00"), 'end_time' => Carbon::parse("{$date} 11:00"),
            'court_fee' => 200000, 'total_amount' => 200000, 'status' => $status,
        ]);
        $make('BK-PLAYED', now()->subDay()->format('Y-m-d'), 'COMPLETED');
        $future = $make('BK-FUTURE', $this->date, 'PAID');

        $this->service->requestCancelAndRefund($future->id, 'PERMINTAAN_CUSTOMER', 'batal', $this->owner);

        $this->assertSame('CANCELLED', $future->fresh()->status);
        $this->assertEquals(0, (float) $voucher->fresh()->balance, 'jam yang sudah dimainkan tidak boleh mengembalikan voucher penuh');
    }

    public function test_legacy_customer_refund_request_can_still_be_sent_to_the_queue(): void
    {
        $booking = $this->paidBooking('BK-LEGACY', '10:00', 200000, now()->subDays(2)->format('Y-m-d'));
        $booking->update(['status' => 'REFUND_PENDING', 'cancel_reason' => 'Diajukan customer H-3 (fitur lama)']);

        $this->actingAs($this->owner);
        // Periode "Semua": booking 2 hari lalu bisa jatuh di bulan sebelumnya (default = bulan berjalan).
        Livewire::test(KelolaPemesanan::class)
            ->set('period', 'all')
            ->call('setTab', 'CANCELLED')
            ->assertSee('Ajukan Pembatalan &amp; Refund', false)
            ->call('openCancelRefundModal', $booking->id)
            ->assertSet('showCancelRefundModal', true)
            ->set('refundNotes', 'Pengajuan customer lama dimasukkan ke antrian')
            ->call('executeCancelRefund');

        $this->assertSame('PENDING', Refund::where('padel_booking_id', $booking->id)->sole()->status);
        $this->assertSame('REFUND_PENDING', $booking->fresh()->status);
    }

    /** Order 2 lapangan @Rp100.000, dibayar Rp200.000. */
    private function twoCourtOrder(string $statusA = 'PAID', ?string $dateA = null): array
    {
        $order = Order::create([
            'order_number' => 'ORD-DUO-'.Str::random(4), 'user_id' => $this->customer->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 200000, 'grand_total' => 200000, 'payment_status' => 'PAID',
        ]);
        Payment::create(['order_id' => $order->id, 'payment_gateway' => 'MIDTRANS', 'transaction_id' => $order->order_number, 'amount' => 200000, 'payment_method' => 'QRIS', 'status' => 'SUCCESS']);
        $make = fn (string $code, string $date, string $start, string $status) => PadelBooking::create([
            'booking_code' => $code, 'order_id' => $order->id, 'user_id' => $this->customer->id, 'court_id' => $this->court->id,
            'booking_date' => $date, 'start_time' => Carbon::parse("{$date} {$start}"), 'end_time' => Carbon::parse("{$date} {$start}")->addHour(),
            'court_fee' => 100000, 'total_amount' => 100000, 'status' => $status,
        ]);

        return [$order, $make('BK-DUO-A', $dateA ?? $this->date, '10:00', $statusA), $make('BK-DUO-B', $this->date, '18:00', 'PAID')];
    }

    public function test_last_booking_is_refunded_only_its_own_share_after_a_sibling_was_rejected(): void
    {
        [, $a, $b] = $this->twoCourtOrder();

        $this->assertEqualsWithDelta(100000, $this->service->requestCancelAndRefund($a->id, 'PERMINTAAN_CUSTOMER', 'batal A', $this->owner)['refund_amount'], 0.01);
        app(RefundQueueService::class)->reject(Refund::where('padel_booking_id', $a->id)->sole(), $this->owner, 'Di luar kebijakan');

        // Dulu Rp200.000 (B dianggap pemilik seluruh uang order) → total keluar Rp300.000 dari Rp200.000.
        $this->assertEqualsWithDelta(100000, $this->service->requestCancelAndRefund($b->id, 'PERMINTAAN_CUSTOMER', 'batal B', $this->owner)['refund_amount'], 0.01);
    }

    public function test_cancelling_after_a_sibling_was_played_refunds_only_the_unplayed_court(): void
    {
        [, , $b] = $this->twoCourtOrder('COMPLETED', now()->subDay()->format('Y-m-d'));

        $this->assertEqualsWithDelta(100000, $this->service->requestCancelAndRefund($b->id, 'PERMINTAAN_CUSTOMER', 'batal B', $this->owner)['refund_amount'], 0.01);
    }

    public function test_restoring_into_an_expired_voucher_extends_it(): void
    {
        $voucher = $this->creditVoucher(150000);
        $this->holdAndCheckout('10:00', $voucher->code)->assertOk();
        $booking = PadelBooking::where('order_id', Order::where('voucher_code', $voucher->code)->sole()->id)->sole();
        $voucher->update(['valid_until' => now()->subDay()]);

        $this->service->requestCancelAndRefund($booking->id, 'PERMINTAAN_CUSTOMER', 'batal', $this->owner);

        $voucher->refresh();
        $this->assertEquals(150000, (float) $voucher->balance);
        $this->assertTrue($voucher->valid_until->gt(now()->addMonths(5)), 'saldo yang dikembalikan tidak boleh langsung hangus');
    }
}