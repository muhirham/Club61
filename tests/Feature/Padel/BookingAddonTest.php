<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\KasirProShop;
use App\Filament\Pages\KelolaPemesanan;
use App\Models\Audit\ActivityLog;
use App\Models\Coaching\Coach;
use App\Models\Coaching\CoachingAcademy;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Coaching\CoachingBillingService;
use App\Services\Coaching\CoachingPricingService;
use App\Services\Padel\BookingAddonService;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Pos\ProShopSaleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Kasir Pro Shop (izin add_booking_equipment): tab Sewa = bola / raket / handuk untuk booking hari ini / ke depan; tab Jual =
 * alat bersifat Jual tanpa booking. Booking lunas → transaksi baru di kasir (booking lama tidak berubah); sesi coaching
 * Tagihan Berkala → alat masuk tagihan academy.
 */
class BookingAddonTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private PadelCourt $court;

    private CourtEquipment $ball;

    private CourtEquipment $racket;

    private PosCashierShift $shift;

    private string $today = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        app(CoachingPricingService::class)->flush();
        $this->travelTo(Carbon::parse("{$this->today} 14:00", 'Asia/Jakarta'));

        $this->receptionist = User::factory()->create(['name' => 'Resepsionis Sari', 'is_active' => true]);
        $this->receptionist->givePermissionTo([...Club61PermissionMatrix::defaultRolePermissions('receptionist'), 'View:KasirProShop', BookingAddonService::PERMISSION]);
        $this->court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        $this->ball = CourtEquipment::create(['name' => 'Bola', 'type' => 'BALL', 'rental_price' => 50000, 'stock_quantity' => 10, 'is_active' => true]);
        $this->racket = CourtEquipment::create(['name' => 'Raket', 'type' => 'RACKET', 'rental_price' => 30000, 'stock_quantity' => 5, 'is_active' => true]);
        $this->shift = PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->receptionist->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $this->actingAs($this->receptionist);
    }

    /** Booking walk-in lunas (EDC) hari ini. */
    private function paidBooking(string $hour = '18', ?string $date = null): PadelBooking
    {
        $service = app(PadelBookingService::class);
        $result = $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Budi Santoso', phone: '0812555'.$hour.'000'),
            slots: [['court_id' => $this->court->id, 'start_time' => "{$hour}:00", 'end_time' => sprintf('%02d:00', (int) $hour + 1)]],
            bookingDate: $date ?? $this->today, equipments: [], paymentMethod: 'DEBIT_CARD', cashier: $this->receptionist,
            paymentMeta: ['terminal' => 'EDC_BCA', 'card_type' => 'DEBIT', 'card_last_4' => '1234', 'approval_code' => 'APR'.$hour, 'trace_number' => 'TRC'.$hour],
        );

        return $result['bookings']->first()->fresh();
    }

    private function addon(PadelBooking $booking, array $quantities, string $method = 'EDC_BCA', array $proof = []): array
    {
        $service = app(BookingAddonService::class);
        $quote = $service->quote($booking->fresh('order'), $quantities);

        return $service->checkout($booking->id, $quantities, $quote['mode'], $quote['grand_total'], $method,
            $proof ?: ['card_type' => 'DEBIT', 'card_last_4' => '4321', 'approval_code' => 'ADD'.random_int(100, 999), 'trace_number' => 'TRA'.random_int(100, 999)], $this->receptionist);
    }

    private function expectRejected(callable $callback, string $message, ?int $status = null): void
    {
        try {
            $callback();
            $this->fail("Seharusnya ditolak: {$message}");
        } catch (HttpException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            if ($status) {
                $this->assertSame($status, $e->getStatusCode());
            }
        }
    }

    public function test_paid_booking_gets_a_new_transaction_for_extra_balls_without_touching_the_original(): void
    {
        $booking = $this->paidBooking();
        $original = Order::with('payments')->find($booking->order_id);
        $originalTotal = (float) $original->grand_total;

        // POS Walk-In tidak lagi memuat mode alat — semuanya di Kasir Pro Shop.
        Livewire::test(BookOfflineCourt::class)->assertDontSee('+ Tambah Alat ke Booking')->assertSee('Kasir Pro Shop');

        $page = Livewire::test(KasirProShop::class)
            ->call('setTab', 'sewa')->assertSet('tab', 'sewa')
            // Booking hari ini langsung tampil tanpa mencari.
            ->assertSeeHtml('data-today-booking="'.$booking->booking_code.'"')
            ->set('bookingSearch', 'Budi')->assertSee('#'.$booking->booking_code)
            ->call('pickBooking', $booking->id)
            ->assertSet('rentBooking.code', $booking->booking_code)
            ->assertSee('Transaksi booking ini tidak berubah')
            ->call('incrementEquipment', $this->ball->id)
            ->call('incrementEquipment', $this->ball->id)
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->assertSee('Bayar Lunas & Cetak Struk')
            ->assertSee('Tambah alat untuk #'.$booking->booking_code)
            ->assertSee('2 alat');

        $page->set('paymentMethod', 'QRIS')->set('qrisMode', 'MANUAL')->set('qrisRrn', 'RRN11223344')
            ->call('submit')
            ->assertNotified('Tambahan Alat Lunas')
            ->assertSet('showSuccessModal', true)
            ->assertSet('rentBooking', null)
            ->assertSee('TAMBAHAN ALAT UNTUK BOOKING');

        $addonOrder = Order::where('id', '!=', $booking->order_id)->sole();
        $this->assertSame(['WALK_IN', 'PAID', 100000.0], [$addonOrder->order_type, $addonOrder->payment_status, (float) $addonOrder->subtotal]);
        $this->assertSame(['EQUIPMENT'], $addonOrder->items->pluck('item_type')->unique()->values()->all());
        $payment = Payment::where('order_id', $addonOrder->id)->sole();
        $this->assertSame(['SUCCESS', $this->shift->id, 'BOOKING_ADDON'], [$payment->status, $payment->pos_shift_id, $payment->payload_log['source']]);
        $this->assertEqualsWithDelta((float) $addonOrder->grand_total, (float) $payment->amount, 0.01);

        $row = PadelBookingEquipment::where('order_id', $addonOrder->id)->sole();
        $this->assertSame([$booking->id, 2], [$row->booking_id, $row->quantity]);
        $this->assertNotNull($row->stock_deducted_at, 'bola dipotong saat dibeli');
        $this->assertSame(8, $this->ball->fresh()->stock_quantity);

        // Transaksi booking lama tidak berubah.
        $original->refresh();
        $this->assertEqualsWithDelta($originalTotal, (float) $original->grand_total, 0.01);
        $this->assertSame(1, $original->payments()->count());
        $this->assertSame('PAID', $booking->fresh()->status);
        $this->assertTrue(ActivityLog::where('event', 'booking.addon_equipment')->exists());

        // Riwayat Transaksi Pro Shop: jenis "Tambahan Alat" untuk booking itu — bukan lagi di Riwayat POS Walk-In
        // (yang tetap memuat transaksi booking-nya sendiri).
        Livewire::test(KasirProShop::class)->call('showHistory')->assertSet('posStep', 'history')
            ->assertSee('Riwayat Transaksi Kasir Pro Shop')->assertSee('Tambahan Alat')->assertSee('Alat untuk #'.$booking->booking_code);
        Livewire::test(BookOfflineCourt::class)->call('showHistory')
            ->assertDontSee('Alat untuk #'.$booking->booking_code)->assertSee($original->order_number);
    }

    // ================================================================== JUAL TANPA BOOKING

    public function test_sale_items_can_be_sold_without_a_booking_and_rentals_cannot(): void
    {
        $page = Livewire::test(KasirProShop::class)
            ->assertSet('tab', 'jual')
            ->assertSeeHtml('data-equipment="Bola"')->assertDontSeeHtml('data-equipment="Raket"')   // alat Sewa tidak ditawarkan
            ->call('incrementEquipment', $this->racket->id)->assertSet('quantities', [])        // dipaksa dari browser pun ditolak
            ->call('incrementEquipment', $this->ball->id)->call('incrementEquipment', $this->ball->id)
            ->call('proceedToPayment')->assertNotified('Data Pembeli Belum Lengkap')->assertSet('posStep', 'selection')
            ->set('buyerName', 'Andi Pembeli')->set('buyerPhone', '0812-9988-7766')
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->assertSee('Penjualan Pro Shop tanpa booking')->assertSee('2 barang');

        $page->set('paymentMethod', 'QRIS')->set('qrisMode', 'MANUAL')->set('qrisRrn', 'RRN55667788')
            ->call('submit')
            ->assertNotified('Penjualan Lunas')
            ->assertSet('showSuccessModal', true)->assertSet('quantities', [])
            ->assertSee('PENJUALAN PRO SHOP:')
            ->assertSee('Customer: Andi Pembeli')->assertSee('(081299887766)');

        $order = Order::with('items')->sole();
        $this->assertSame(['WALK_IN', 'PAID', 100000.0], [$order->order_type, $order->payment_status, (float) $order->subtotal]);
        // Pembeli tidak dibuatkan akun — nama & HP disimpan di order.
        $this->assertSame([null, 'Andi Pembeli', '081299887766'], [$order->user_id, $order->customer_name, $order->customer_phone]);
        $this->assertFalse(User::where('name', 'Andi Pembeli')->exists());
        $this->assertSame([['EQUIPMENT', $this->ball->id, 2]], $order->items->map(fn ($i) => [$i->item_type, $i->reference_id, $i->quantity])->all());
        $this->assertSame(0, PadelBooking::count(), 'tidak membuat booking');
        $this->assertSame(0, PadelBookingEquipment::count());
        $this->assertSame(8, $this->ball->fresh()->stock_quantity, 'bola dipotong saat dibayar');
        $payment = Payment::where('order_id', $order->id)->sole();
        $this->assertSame(['SUCCESS', $this->shift->id, ProShopSaleService::SOURCE], [$payment->status, $payment->pos_shift_id, $payment->payload_log['source']]);
        $this->assertTrue(\App\Models\Finance\LedgerEntry::where('order_id', $order->id)->where('customer_name', 'Andi Pembeli')->exists(), 'masuk Buku Transaksi');
        $this->assertTrue(ActivityLog::where('event', 'pos.pro_shop_sale')->exists());

        // Riwayat Pro Shop: tampil dengan nama pembeli, bisa dicari lewat nomor HP-nya, struk bisa dicetak ulang.
        Livewire::test(KasirProShop::class)->call('showHistory')
            ->assertSee('Penjualan Pro Shop')->assertSee('Tanpa booking')->assertSee('Andi Pembeli')
            ->set('historySearch', '9988776')->assertSee($order->order_number)
            ->call('viewTransactionReceipt', $payment->id)->assertSet('showSuccessModal', true)->assertSet('receiptFromHistory', true)
            ->assertSee('CETAK ULANG')->assertSee('Pro Shop')->assertSee('Terima kasih telah berbelanja di Club 61!')->assertSee('2 x Rp 50.000')
            ->call('closeSuccessModal')->assertSet('posStep', 'history');
        Livewire::test(BookOfflineCourt::class)->call('showHistory')->assertDontSee($order->order_number);

        // Query riwayat bersama (tanpa cek izin) tidak bisa dipanggil langsung dari browser — hanya lewat tab Riwayat.
        try {
            Livewire::test(KasirProShop::class)->call('frontdeskHistoryRows', '2026-11-10', '', true);
            $this->fail('frontdeskHistoryRows seharusnya tidak bisa dipanggil dari browser.');
        } catch (\Livewire\Exceptions\MethodNotFoundException) {
        }
        try {
            Livewire::test(BookOfflineCourt::class)->call('frontdeskHistoryRows', '2026-11-10', '', true);
            $this->fail('frontdeskHistoryRows seharusnya tidak bisa dipanggil dari browser.');
        } catch (\Livewire\Exceptions\MethodNotFoundException) {
        }

        // Server menolak alat Sewa yang dipaksa ikut terkirim tanpa booking.
        $service = app(ProShopSaleService::class);
        $this->expectRejected(fn () => $service->checkout([$this->ball->id => 1, $this->racket->id => 1], [], 50000, 'EDC_BCA',
            ['card_type' => 'DEBIT', 'card_last_4' => '1111', 'approval_code' => 'S1', 'trace_number' => 'T1'], 'Andi Pembeli', '081299887766', $this->receptionist), 'alat Sewa', 422);
        $this->assertSame(1, Order::count());
        $this->assertSame(8, $this->ball->fresh()->stock_quantity);
    }

    public function test_cancelled_gateway_qr_for_a_sale_returns_the_stock_once(): void
    {
        $page = Livewire::test(KasirProShop::class)
            ->set('buyerName', 'Andi Pembeli')->set('buyerPhone', '081299887766')
            ->call('incrementEquipment', $this->ball->id)->call('incrementEquipment', $this->ball->id)->call('incrementEquipment', $this->ball->id)
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->set('paymentMethod', 'QRIS')->assertSet('qrisMode', 'MIDTRANS')
            ->call('submit')
            ->assertSee('Menunggu pembayaran customer');
        $this->assertSame(7, $this->ball->fresh()->stock_quantity, 'bola ditahan selama QR menunggu');

        // Halaman dibuka ulang → QR yang menunggu dilanjutkan di Kasir Pro Shop, bukan di POS Walk-In.
        Livewire::test(KasirProShop::class)->assertSet('pendingQris.payment_id', $page->get('pendingQris')['payment_id']);
        Livewire::test(BookOfflineCourt::class)->assertSet('pendingQris', null);

        $pending = $page->get('pendingQris');
        $page->call('cancelPendingQris')->assertSet('pendingQris', null);
        $this->assertSame('CANCELLED', Order::find($pending['order_id'])->payment_status);
        $this->assertSame(10, $this->ball->fresh()->stock_quantity);

        // Dibatalkan lagi (mis. webhook kedaluwarsa menyusul) → stok tidak bertambah dua kali.
        \App\Services\Pos\PosPendingOrderCanceller::cancel(Payment::findOrFail($pending['payment_id']), 'TEST_REPEAT');
        $this->assertSame(10, $this->ball->fresh()->stock_quantity);
    }

    public function test_equipment_usage_follows_master_data_and_keeps_the_old_rules_when_empty(): void
    {
        // Kolom kosong (data lama) = aturan lama dari jenisnya.
        $this->assertTrue($this->ball->isSale());
        $this->assertTrue($this->racket->isRental());
        $this->assertNull(CourtEquipment::create(['name' => 'Coach', 'type' => 'COACH', 'rental_price' => 0, 'stock_quantity' => 0])->resolvedUsage());

        // Admin mengubah "Grip" (OTHER) jadi Jual → bisa dijual tanpa booking.
        $grip = CourtEquipment::create(['name' => 'Grip', 'type' => 'OTHER', 'rental_price' => 25000, 'stock_quantity' => 4, 'is_active' => true]);
        $this->assertSame([], app(ProShopSaleService::class)->quote([$grip->id => 1], [])['items']);
        $admin = User::factory()->superAdmin()->create(['is_active' => true]);
        $this->actingAs($admin);
        Livewire::test(\App\Filament\Pages\MasterData::class)
            ->call('openEditEquipmentModal', $grip->id)->assertSet('equipmentUsage', '')
            ->set('equipmentUsage', CourtEquipment::USAGE_SALE)->call('saveEquipment');
        $this->assertSame(CourtEquipment::USAGE_SALE, $grip->fresh()->usage);
        $this->assertSame(25000.0, app(ProShopSaleService::class)->quote([$grip->id => 1], [])['subtotal']);

        // Sifat tidak bisa diubah selama alatnya dipakai booking aktif (stok booking itu bisa salah hitung).
        $this->actingAs($this->receptionist);
        $booking = $this->paidBooking();
        $this->addon($booking, [$this->ball->id => 1]);
        $this->actingAs($admin);
        Livewire::test(\App\Filament\Pages\MasterData::class)
            ->call('openEditEquipmentModal', $this->ball->id)->assertSet('equipmentUsage', CourtEquipment::USAGE_SALE)
            ->set('equipmentUsage', CourtEquipment::USAGE_RENTAL)->call('saveEquipment')
            ->assertNotified('Sifat Alat Belum Bisa Diubah');
        $this->assertNull($this->ball->fresh()->getAttributes()['usage'], 'tetap kosong = Jual');

        // Alat baru: sifat mengikuti jenis yang dipilih.
        Livewire::test(\App\Filament\Pages\MasterData::class)->call('openCreateEquipmentModal')
            ->set('equipmentType', 'BALL')->assertSet('equipmentUsage', CourtEquipment::USAGE_SALE)
            ->set('equipmentType', 'TOWEL')->assertSet('equipmentUsage', CourtEquipment::USAGE_RENTAL);
    }

    public function test_one_click_changes_the_booking_and_switching_tabs_empties_the_cart(): void
    {
        $booking = $this->paidBooking();

        Livewire::test(KasirProShop::class)
            ->call('setTab', 'sewa')->call('pickBooking', $booking->id)->assertSee('#'.$booking->booking_code)
            ->call('incrementEquipment', $this->racket->id)->assertSet('quantities', [$this->racket->id => 1])
            ->call('clearBooking')
            ->assertSet('rentBooking', null)->assertSet('quantities', [])
            ->assertSeeHtml('data-today-booking="'.$booking->booking_code.'"')
            ->call('pickBooking', $booking->id)->call('incrementEquipment', $this->ball->id)
            ->call('setTab', 'jual')
            ->assertSet('tab', 'jual')->assertSet('rentBooking', null)->assertSet('quantities', []);
    }

    public function test_qris_from_the_payment_gateway_counts_the_equipment_only_after_payment(): void
    {
        $booking = $this->paidBooking();
        $pickBalls = fn () => Livewire::test(KasirProShop::class)->call('setTab', 'sewa')
            ->call('pickBooking', $booking->id)
            ->call('incrementEquipment', $this->ball->id)->call('incrementEquipment', $this->ball->id)
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->set('paymentMethod', 'QRIS')->assertSet('qrisMode', 'MIDTRANS')
            ->call('submit')
            ->assertSee('Menunggu pembayaran customer');

        // Dibayar lewat QR otomatis.
        $page = $pickBalls();
        $pending = $page->get('pendingQris');
        $order = Order::findOrFail($pending['order_id']);
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame(8, $this->ball->fresh()->stock_quantity, 'bola ditahan selama QR menunggu');
        $this->assertSame(0, BookingAddonService::equipmentRowsOf($booking)->count(), 'belum lunas → belum dihitung');

        $payment = Payment::findOrFail($pending['payment_id']);
        app(\App\Services\Payment\PaymentOrchestratorService::class)->markOrderAsPaid($payment->order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $payment->transaction_id, 'payment_method' => 'QRIS',
            'amount' => (float) $payment->amount, 'payload_log' => ['payment_type' => 'qris', 'transaction_status' => 'settlement'],
        ]);
        $page->call('pollPendingQris')
            ->assertSet('pendingQris', null)
            ->assertSet('showSuccessModal', true)
            ->assertSet('completedOrderData.addon_for.code', $booking->booking_code);
        $this->assertSame(['PAID', 'BOOKING_ADDON'], [$order->fresh()->payment_status, $payment->fresh()->payload_log['source']]);
        $this->assertSame($booking->booking_code, $payment->fresh()->payload_log['booking_addon']['booking_code']);
        $this->assertSame(1, BookingAddonService::equipmentRowsOf($booking)->count());

        // QR dibatalkan → order batal, stok bola kembali, alat tidak tercatat di booking.
        $second = $pickBalls();
        $this->assertSame(6, $this->ball->fresh()->stock_quantity);
        $cancelled = $second->get('pendingQris');
        $second->call('cancelPendingQris')->assertSet('pendingQris', null);
        $this->assertSame('CANCELLED', Order::find($cancelled['order_id'])->payment_status);
        $this->assertSame(8, $this->ball->fresh()->stock_quantity);
        $this->assertSame(1, BookingAddonService::equipmentRowsOf($booking)->count());
    }

    public function test_rented_racket_follows_check_in_and_returns_through_the_booking(): void
    {
        $booking = $this->paidBooking();
        $this->addon($booking, [$this->racket->id => 2]);

        // Belum check-in: raket belum diserahkan → stok dipotong saat check-in, seperti alat yang dipesan saat booking.
        $this->assertSame(5, $this->racket->fresh()->stock_quantity);
        $this->travelTo(Carbon::parse("{$this->today} 17:30", 'Asia/Jakarta'));
        app(PadelBookingService::class)->checkIn($booking->booking_code, $this->receptionist);
        $this->assertSame(3, $this->racket->fresh()->stock_quantity, 'tambahan alat ikut dipotong saat check-in');

        // Sudah check-in: tambahan raket langsung diserahkan → stok langsung dipotong.
        $this->addon($booking->fresh(), [$this->racket->id => 1]);
        $this->assertSame(2, $this->racket->fresh()->stock_quantity);

        // Semua raket yang diserahkan (dari booking & tambahan terpisah) tercatat di Peminjaman Alat dan dikembalikan di sana.
        $loans = \App\Models\Padel\EquipmentLoan::where('booking_id', $booking->id)->get();
        $this->assertSame(3, $loans->sum('quantity'));
        $this->receptionist->givePermissionTo(\App\Services\Padel\EquipmentLoanService::PERMISSION_RETURN);
        foreach ($loans as $loan) {
            app(\App\Services\Padel\EquipmentLoanService::class)->returnLoan($loan->id, ['good' => $loan->quantity], null, $this->receptionist);
        }
        $this->assertSame(5, $this->racket->fresh()->stock_quantity);
    }

    public function test_coaching_tab_session_gets_the_equipment_on_its_bill(): void
    {
        $academy = CoachingAcademy::where('name', 'SR Academy')->firstOrFail();
        $academy->update(['billing_mode' => CoachingAcademy::BILLING_TAB, 'billing_cycle' => CoachingAcademy::CYCLE_WEEKLY]);
        $coach = Coach::create(['academy_id' => $academy->id, 'name' => 'Maimun', 'title' => 'HEAD_COACH', 'is_active' => true]);
        $service = app(PadelBookingService::class);
        $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Coach Maimun', phone: '081299887700'),
            slots: [['court_id' => $this->court->id, 'start_time' => '18:00', 'end_time' => '19:00']],
            bookingDate: $this->today, equipments: [], paymentMethod: 'TAGIHAN', cashier: $this->receptionist,
            coaching: ['head_coach_id' => $coach->id],
        );
        $booking = PadelBooking::sole();
        $before = (float) $booking->order->grand_total;

        $page = Livewire::test(KasirProShop::class)->call('setTab', 'sewa')
            // Sesi Tagihan Berkala ikut tampil di daftar hari ini.
            ->assertSeeHtml('data-today-booking="'.$booking->booking_code.'"')
            ->call('pickBooking', $booking->id)
            ->assertSet('rentBooking.mode', BookingAddonService::MODE_TAB)
            ->assertSee('alat ditambahkan ke tagihan academy')
            ->call('incrementEquipment', $this->ball->id)
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->assertSee('Catat ke Tagihan Berkala');
        $page->call('submit')->assertNotified('Dicatat ke Tagihan Berkala');

        $order = $booking->order->fresh('items');
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertGreaterThan($before, (float) $order->grand_total);
        $this->assertSame(['PADEL', 'EQUIPMENT'], $order->items->pluck('item_type')->values()->all());
        $this->assertSame(0, Payment::count(), 'tanpa pembayaran sekarang');
        $this->assertSame(9, $this->ball->fresh()->stock_quantity);
        $this->assertEqualsWithDelta((float) $order->grand_total, app(CoachingBillingService::class)->openBills($academy)->sole()['amount'], 0.01, 'ikut ditagih saat pelunasan');
    }

    public function test_permission_past_dates_and_changed_total_are_enforced(): void
    {
        $booking = $this->paidBooking();
        // Booking kemarin (dibuat kemarin) — jam mainnya sudah lewat.
        $this->travelTo(Carbon::parse("{$this->today} 09:00", 'Asia/Jakarta')->subDay());
        $yesterday = $this->paidBooking('10', Carbon::parse($this->today)->subDay()->format('Y-m-d'));
        $this->travelTo(Carbon::parse("{$this->today} 14:00", 'Asia/Jakarta'));
        $service = app(BookingAddonService::class);

        // Izin dicentang manual oleh super admin — tidak masuk role bawaan.
        $this->assertContains(BookingAddonService::PERMISSION, Club61PermissionMatrix::getAllPermissionSlugs());
        $this->assertNotContains(BookingAddonService::PERMISSION, Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->assertNotContains('View:KasirProShop', Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->givePermissionTo(Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->actingAs($cashier);
        $this->assertFalse(KasirProShop::canAccess());
        Livewire::test(BookOfflineCourt::class)->assertDontSee(route('filament.admin.pages.kasir-pro-shop'), false);
        Livewire::test(KasirProShop::class)->assertForbidden();
        // Akses halaman tanpa izin jual: tab Jual / Sewa tidak tersedia dan aksinya ditolak.
        $cashier->givePermissionTo('View:KasirProShop');
        Livewire::test(KasirProShop::class)->assertDontSeeHtml('data-proshop-tab="jual"')->assertSeeHtml('data-proshop-tab="denda"')
            ->assertSet('tab', 'denda')
            ->call('setTab', 'sewa')->assertNotified('Akses Ditolak')->assertSet('tab', 'denda');
        $this->expectRejected(fn () => $service->checkout($booking->id, [$this->ball->id => 1], 'PAID', 50000, 'EDC_BCA', [], $cashier), 'add_booking_equipment', 403);

        $this->actingAs($this->receptionist);
        Livewire::test(KasirProShop::class)->call('setTab', 'sewa')
            ->assertDontSeeHtml('data-today-booking="'.$yesterday->booking_code.'"')
            ->call('pickBooking', $yesterday->id)
            ->assertNotified('Booking Tidak Bisa Ditambah Alat')->assertSet('rentBooking', null);
        $this->expectRejected(fn () => $service->checkout($yesterday->id, [$this->ball->id => 1], 'PAID', 50000, 'EDC_BCA', [], $this->receptionist), 'sudah lewat', 422);

        $quote = $service->quote($booking, [$this->ball->id => 1]);
        $this->expectRejected(fn () => $service->checkout($booking->id, [$this->ball->id => 1], 'PAID', $quote['grand_total'] - 5000, 'EDC_BCA',
            ['card_type' => 'DEBIT', 'card_last_4' => '1111', 'approval_code' => 'APX1', 'trace_number' => 'TRX1'], $this->receptionist), 'Total tambahan berubah', 409);
        $this->expectRejected(fn () => $service->checkout($booking->id, [$this->ball->id => 1], 'TAB', $quote['grand_total'], 'EDC_BCA', [], $this->receptionist), 'Tagihan coaching', 409);
        $this->assertSame(10, $this->ball->fresh()->stock_quantity, 'yang ditolak tidak memotong stok');
        $this->assertSame(2, Order::count());
    }

    public function test_future_bookings_can_get_equipment_and_search_shows_their_date(): void
    {
        $nextWeek = Carbon::parse($this->today)->addDays(5);
        $booking = $this->paidBooking('13', $nextWeek->format('Y-m-d'));

        // Bukan booking hari ini → tidak ada di daftar awal, tapi bisa dicari; hasilnya menampilkan tanggal booking.
        Livewire::test(KasirProShop::class)->call('setTab', 'sewa')
            ->assertDontSeeHtml('data-today-booking="'.$booking->booking_code.'"')
            ->set('bookingSearch', 'Budi')
            ->assertSee('#'.$booking->booking_code)
            ->assertSee($nextWeek->locale('id')->translatedFormat('D, d M').' · 13:00 - 14:00')
            ->call('pickBooking', $booking->id)
            ->assertSet('rentBooking.date', $nextWeek->toDateString())
            ->assertSee($nextWeek->locale('id')->translatedFormat('D, d M Y'));

        // Bola terjual sekarang; raket baru dipotong saat check-in di hari main.
        $this->addon($booking, [$this->ball->id => 1, $this->racket->id => 1]);
        $this->assertSame([9, 5], [$this->ball->fresh()->stock_quantity, $this->racket->fresh()->stock_quantity]);
    }

    public function test_kelola_pemesanan_lists_the_extra_equipment_on_the_booking(): void
    {
        $booking = $this->paidBooking();
        $this->addon($booking, [$this->ball->id => 2]);

        $this->actingAs(User::factory()->superAdmin()->create(['is_active' => true]));
        Livewire::test(KelolaPemesanan::class)->set('period', 'all')
            ->assertSee($booking->booking_code)
            ->assertSee('+ Alat: Bola ×2 · Rp 100.000');
    }
}
