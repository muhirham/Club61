<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\BookOfflineCourt;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * * POS Walk-In → Tambah Alat ke Booking (izin add_booking_equipment): bola / raket / handuk untuk booking hari ini / ke depan lewat
 * alur kasir yang sama tanpa memilih lapangan. Booking lunas → transaksi baru di kasir (booking lama tidak berubah);
 * sesi coaching Tagihan Berkala → alat masuk tagihan academy.
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
        $this->receptionist->givePermissionTo([...Club61PermissionMatrix::defaultRolePermissions('receptionist'), BookingAddonService::PERMISSION]);
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

        $page = Livewire::test(BookOfflineCourt::class)
            ->assertSee('+ Tambah Alat ke Booking')
            ->call('startBookingAddon')->assertSet('addonPicking', true)
            ->assertSee('Ketuk booking di jadwal')
            ->assertSeeHtml("pickAddonBooking('{$booking->id}')")
            ->set('addonSearch', 'Budi')->assertSee('#'.$booking->booking_code)
            ->call('pickAddonBooking', $booking->id)
            ->assertSet('bookingAddon.code', $booking->booking_code)
            ->assertSet('addonPicking', false)
            ->assertSee('Transaksi booking ini tidak berubah')
            ->call('incrementEquipment', $this->ball->id, 10)
            ->call('incrementEquipment', $this->ball->id, 10)
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->assertSee('Bayar Lunas & Cetak Struk')
            ->assertSee('Tambah alat untuk #'.$booking->booking_code)
            ->assertSee('2 alat')->assertSee('Ubah Pilihan Alat')->assertDontSee('slot dipilih');
        $this->assertSame(1, substr_count($page->html(), 'data-addon-booking='), 'kartu booking tampil sekali di layar bayar');

        $page->set('paymentMethod', 'QRIS')->set('qrisMode', 'MANUAL')->set('qrisRrn', 'RRN11223344')
            ->call('submitWalkInBooking')
            ->assertNotified('Tambahan Alat Lunas')
            ->assertSet('showSuccessModal', true)
            ->assertSet('bookingAddon', null)
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

        // Riwayat Transaksi: jenis "Tambahan Alat" untuk booking itu.
        Livewire::test(BookOfflineCourt::class)->call('showHistory')
            ->assertSee('Tambahan Alat')->assertSee('Alat untuk #'.$booking->booking_code);
    }

    public function test_qris_from_the_payment_gateway_counts_the_equipment_only_after_payment(): void
    {
        $booking = $this->paidBooking();
        $pickBalls = fn () => Livewire::test(BookOfflineCourt::class)->call('startBookingAddon')
            ->call('pickAddonBooking', $booking->id)
            ->call('incrementEquipment', $this->ball->id, 10)->call('incrementEquipment', $this->ball->id, 10)
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->set('paymentMethod', 'QRIS')->assertSet('qrisMode', 'MIDTRANS')
            ->call('submitWalkInBooking')
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
            ->assertSet('posStep', 'receipt')
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

        // Retur alat dari booking mengembalikan semua raket, termasuk yang dibeli terpisah.
        $returned = app(PadelBookingService::class)->returnEquipment($booking->id, $this->receptionist);
        $this->assertSame(3, collect($returned['items'])->sum('quantity'));
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

        $page = Livewire::test(BookOfflineCourt::class)->call('startBookingAddon')
            ->call('pickAddonBooking', $booking->id)
            ->assertSet('bookingAddon.mode', BookingAddonService::MODE_TAB)
            ->assertSee('alat ditambahkan ke tagihan academy')
            ->call('incrementEquipment', $this->ball->id, 10)
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->assertSee('Catat ke Tagihan Berkala');
        $page->call('submitWalkInBooking')->assertNotified('Dicatat ke Tagihan Berkala');

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
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->givePermissionTo(Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->actingAs($cashier);
        Livewire::test(BookOfflineCourt::class)->assertDontSee('+ Tambah Alat ke Booking')
            ->call('startBookingAddon')->assertNotified('Akses Ditolak')->assertSet('addonPicking', false);
        $this->expectRejected(fn () => $service->checkout($booking->id, [$this->ball->id => 1], 'PAID', 50000, 'EDC_BCA', [], $cashier), 'add_booking_equipment', 403);

        $this->actingAs($this->receptionist);
        Livewire::test(BookOfflineCourt::class)->call('startBookingAddon')->call('pickAddonBooking', $yesterday->id)
            ->assertNotified('Booking Tidak Bisa Ditambah Alat')->assertSet('bookingAddon', null);
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

        // Dicari dengan nama dari hari ini: hasilnya menampilkan tanggal booking.
        Livewire::test(BookOfflineCourt::class)->call('startBookingAddon')
            ->set('addonSearch', 'Budi')
            ->assertSee('#'.$booking->booking_code)
            ->assertSee($nextWeek->locale('id')->translatedFormat('D, d M').' · 13:00 - 14:00')
            ->call('pickAddonBooking', $booking->id)
            ->assertSet('bookingAddon.date', $nextWeek->toDateString())
            ->assertSee($nextWeek->locale('id')->translatedFormat('D, d M Y'));

        // Bola terjual sekarang; raket baru dipotong saat check-in di hari main.
        $this->addon($booking, [$this->ball->id => 1, $this->racket->id => 1]);
        $this->assertSame([9, 5], [$this->ball->fresh()->stock_quantity, $this->racket->fresh()->stock_quantity]);

        // Di jadwal tanggal itu bloknya bisa diketuk.
        Livewire::test(BookOfflineCourt::class)->call('startBookingAddon')
            ->call('setDate', $nextWeek->toDateString())
            ->assertSeeHtml("pickAddonBooking('{$booking->id}')");
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
