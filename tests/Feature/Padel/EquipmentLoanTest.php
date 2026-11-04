<?php

namespace Tests\Feature\Padel;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\KasirProShop;
use App\Filament\Pages\KelolaPemesanan;
use App\Filament\Pages\PeminjamanAlat;
use App\Models\Audit\ActivityLog;
use App\Models\Finance\LedgerEntry;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\EquipmentLoan;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Order;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Padel\EquipmentLoanService;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Peminjaman Alat: raket / handuk yang diserahkan saat check-in tercatat (peminjam, penyerah, batas kembali), diterima
 * kembali dengan kondisi per unit; rusak / hilang karena disengaja → denda diputuskan management → dibayar di POS kasir.
 */
class EquipmentLoanTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private User $manager;

    private PadelCourt $court;

    private CourtEquipment $racket;

    private string $today = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->travelTo(Carbon::parse("{$this->today} 14:00", 'Asia/Jakarta'));

        $this->receptionist = User::factory()->create(['name' => 'Resepsionis Sari', 'is_active' => true]);
        $this->receptionist->givePermissionTo([...Club61PermissionMatrix::defaultRolePermissions('receptionist'), 'View:PeminjamanAlat', 'View:KasirProShop', EquipmentLoanService::PERMISSION_RETURN]);
        $this->manager = User::factory()->create(['name' => 'Manager Vina', 'is_active' => true]);
        $this->manager->givePermissionTo(['View:PeminjamanAlat', EquipmentLoanService::PERMISSION_DECIDE]);

        $this->court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
        $this->racket = CourtEquipment::create([
            'name' => 'Raket', 'type' => 'RACKET', 'rental_price' => 30000, 'stock_quantity' => 8, 'is_active' => true,
            'fine_minor' => 100000, 'fine_major' => 1000000, 'fine_lost' => 1000000,
        ]);
        PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->receptionist->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $this->actingAs($this->receptionist);
    }

    /** Booking walk-in jam 14:00 dengan 2 raket, lalu check-in (raket diserahkan). */
    private function checkedInWithRackets(int $rackets = 2): PadelBooking
    {
        $service = app(PadelBookingService::class);
        $booking = $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Budi Santoso', phone: '081255514000'),
            slots: [['court_id' => $this->court->id, 'start_time' => '14:00', 'end_time' => '15:00']],
            bookingDate: $this->today, equipments: [['equipment_id' => $this->racket->id, 'quantity' => $rackets]], paymentMethod: 'DEBIT_CARD', cashier: $this->receptionist,
            paymentMeta: ['terminal' => 'EDC_BCA', 'card_type' => 'DEBIT', 'card_last_4' => '1234', 'approval_code' => 'APR14', 'trace_number' => 'TRC14'],
        )['bookings']->first();
        $service->checkIn($booking->booking_code, $this->receptionist);

        return $booking->fresh();
    }

    private function expectRejected(callable $callback, string $message, int $status): void
    {
        try {
            $callback();
            $this->fail("Seharusnya ditolak: {$message}");
        } catch (HttpException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_check_in_opens_a_loan_and_a_good_return_puts_the_stock_back(): void
    {
        $booking = $this->checkedInWithRackets();
        $this->assertSame(6, $this->racket->fresh()->stock_quantity, 'raket dipotong saat diserahkan');

        $loan = EquipmentLoan::sole();
        $this->assertSame([2, 'Budi Santoso', $booking->id, $this->receptionist->id], [$loan->quantity, $loan->borrower_name, $loan->booking_id, $loan->handed_out_by_id]);
        $this->assertTrue($loan->due_at->eq($booking->end_time), 'batas kembali = jam selesai booking');

        // Satu baris per transaksi; Detail membuka semua alatnya.
        Livewire::test(PeminjamanAlat::class)->assertCanSeeTableRecords([$booking])->assertSee('Budi Santoso')->assertSee('2 alat')->assertSee('Dipinjam')
            ->assertDontSee('data-loan-detail', false)
            ->callTableAction('detail', $booking)
            ->assertSet('detailBookingId', $booking->id)
            ->assertSee('data-loan-detail', false)->assertSee('2× Raket')->assertSee('RULES PEMINJAMAN RAKET PADEL')
            ->assertSet("returnForm.{$loan->id}.good", 2)
            ->call('saveReturn', $loan->id)
            ->assertNotified('Alat diterima kembali')
            ->assertSee('Kondisi baik');

        $loan->refresh();
        $this->assertNotNull($loan->returned_at);
        $this->assertSame([$this->receptionist->id, 2, EquipmentLoan::FINE_NONE], [$loan->returned_by_id, $loan->qty_good, $loan->fine_status]);
        $this->assertSame(8, $this->racket->fresh()->stock_quantity);
        $this->assertNotNull(PadelBookingEquipment::whereKey($loan->booking_equipment_id)->value('returned_at'));
        $this->assertTrue(ActivityLog::where('event', 'equipment.loan_returned')->exists());

        $this->expectRejected(fn () => app(EquipmentLoanService::class)->returnLoan($loan->id, ['good' => 2], null, $this->receptionist), 'sudah diterima kembali', 409);
        $this->assertSame(8, $this->racket->fresh()->stock_quantity, 'tidak dobel');
    }

    public function test_damaged_racket_fine_is_decided_by_management_then_paid_at_the_cashier(): void
    {
        $this->checkedInWithRackets();
        $loan = EquipmentLoan::sole();
        $service = app(EquipmentLoanService::class);

        $this->expectRejected(fn () => $service->returnLoan($loan->id, ['good' => 1, 'major' => 0], null, $this->receptionist), 'harus sama dengan yang dipinjam', 422);
        $this->expectRejected(fn () => $service->returnLoan($loan->id, ['good' => 1, 'major' => 1], '', $this->receptionist), 'catatan kondisi', 422);

        $service->returnLoan($loan->id, ['good' => 1, 'major' => 1], 'Frame retak, dibanting ke kaca', $this->receptionist);
        $loan->refresh();
        $this->assertSame([EquipmentLoan::FINE_PENDING, 1000000.0], [$loan->fine_status, (float) $loan->fine_proposed]);
        $this->assertSame(7, $this->racket->fresh()->stock_quantity, 'yang patah tidak kembali ke stok');
        $this->assertSame(0.0, $loan->fineDue(), 'belum ditagih sebelum diputuskan');

        // Resepsionis tidak bisa memutuskan denda.
        $this->expectRejected(fn () => $service->decideFine($loan->id, 'APPROVE', 1000000, 'Disetujui saja', $this->receptionist), 'decide_equipment_fine', 403);

        $this->actingAs($this->manager);
        $booking = PadelBooking::findOrFail($loan->booking_id);
        Livewire::test(PeminjamanAlat::class)
            ->set('tableFilters.status.value', 'fine_pending')
            ->assertCanSeeTableRecords([$booking])->assertSee('Menunggu keputusan')
            ->call('openDetail', $booking->id)
            ->assertSet("decideForm.{$loan->id}.amount", 1000000.0)
            ->set("decideForm.{$loan->id}.amount", 900000)
            ->set("decideForm.{$loan->id}.note", 'Diskon pelanggan tetap')
            ->call('saveDecision', $loan->id)
            ->assertNotified('Denda disetujui')
            ->assertSee('belum dibayar')
            ->assertDontSee('Bayar di Kasir'); // manager ini tidak punya izin menerima pembayaran

        $this->actingAs($this->receptionist);
        Livewire::test(PeminjamanAlat::class)->call('openDetail', $booking->id)->assertSee('Bayar di Kasir');
        $this->actingAs($this->manager);

        // ID peminjaman dari transaksi lain tidak bisa dipakai di detail ini.
        Livewire::test(PeminjamanAlat::class)->call('openDetail', $booking->id)->call('saveDecision', '01NOTTHISLOAN0000000000000')->assertStatus(404);
        $loan->refresh();
        $this->assertSame([EquipmentLoan::FINE_APPROVED, 900000.0, $this->manager->id], [$loan->fine_status, (float) $loan->fine_amount, $loan->fine_decided_by_id]);
        $this->assertSame(900000.0, $loan->fineDue());

        // Tombol "Bayar di Kasir" → Kasir Pro Shop tab Denda (?denda=); denda juga tampil di daftar tab itu.
        $this->actingAs($this->receptionist);
        $this->assertSame(1, Livewire::test(KasirProShop::class)->assertSeeHtml('data-fine-count')->instance()->payableFineCount);
        Livewire::test(KasirProShop::class)
            ->call('setTab', 'denda')->assertSeeHtml('data-payable-fine="'.$loan->id.'"');
        Livewire::withQueryParams(['denda' => $loan->id])->test(KasirProShop::class)
            ->assertSet('tab', 'denda')
            ->assertSet('fineBill.loan_id', $loan->id)
            ->assertSet('fineBill.amount', 900000.0)
            ->assertSee('Denda yang harus dibayar')
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            // Denda tanpa Bayar Otomatis: QRIS = bukti RRN yang diketik kasir.
            ->set('paymentMethod', 'QRIS')->assertSee('Retrieval Reference Number')
            ->set('paymentMethod', 'DEBIT_CARD')->set('edcTerminal', 'EDC_BCA')->set('edcCardType', 'DEBIT')
            ->set('edcLast4', '4321')->set('edcApprovalCode', 'FINE01')->set('edcTraceNumber', 'TRF01')
            ->call('submit')
            ->assertNotified('Denda Lunas')
            ->assertSet('fineBill', null)
            ->assertSee('DENDA ALAT:');
        Livewire::test(KasirProShop::class)->assertDontSeeHtml('data-fine-count')
            ->call('setTab', 'denda')->assertDontSeeHtml('data-payable-fine="'.$loan->id.'"');

        $loan->refresh();
        $this->assertTrue($loan->isFinePaid());
        $this->assertSame(0.0, $loan->fineDue());
        $order = Order::with('items')->findOrFail($loan->fine_order_id);
        $this->assertSame(['WALK_IN', 'PAID', 900000.0, 0.0], [$order->order_type, $order->payment_status, (float) $order->grand_total, (float) $order->tax_amount]);
        $this->assertSame('EQUIPMENT_FINE', $order->items->sole()->item_type);
        $this->assertSame(900000.0, (float) LedgerEntry::where('order_id', $order->id)->where('category', 'DENDA_ALAT')->sum('total_amount'));

        // Tidak bisa dibayar dua kali.
        $this->expectRejected(fn () => $service->payFine($loan->id, 900000, 'EDC_BCA', ['card_type' => 'DEBIT', 'card_last_4' => '1111', 'approval_code' => 'X1', 'trace_number' => 'Y1'], $this->receptionist),
            'Tidak ada denda', 422);
    }

    public function test_waived_fine_lost_racket_and_overdue_alerts(): void
    {
        $this->checkedInWithRackets(1);
        $loan = EquipmentLoan::sole();
        $service = app(EquipmentLoanService::class);

        // Lewat batas kembali + 30 menit → terlambat: badge menu & banner POS.
        $this->travelTo(Carbon::parse("{$this->today} 15:20", 'Asia/Jakarta'));
        $this->assertNull(PeminjamanAlat::getNavigationBadge());
        $this->travelTo(Carbon::parse("{$this->today} 15:40", 'Asia/Jakarta'));
        $this->assertSame('1', PeminjamanAlat::getNavigationBadge());
        Livewire::test(BookOfflineCourt::class)->assertSee('1 transaksi dengan alat sewa lewat batas kembali');
        Livewire::test(PeminjamanAlat::class)->assertSee('Terlambat');

        $service->returnLoan($loan->id, ['lost' => 1], 'Tidak dikembalikan, peminjam pulang', $this->receptionist);
        $this->assertNull(PeminjamanAlat::getNavigationBadge());
        $this->assertSame(7, $this->racket->fresh()->stock_quantity, 'hilang tidak kembali ke stok');

        $this->expectRejected(fn () => $service->decideFine($loan->id, 'WAIVE', null, 'ok', $this->manager), 'alasan keputusan', 422);
        $service->decideFine($loan->id, 'WAIVE', null, 'Ditemukan di lobby keesokan harinya', $this->manager);
        $this->assertSame([EquipmentLoan::FINE_WAIVED, 0.0], [$loan->fresh()->fine_status, $loan->fresh()->fineDue()]);
        Livewire::withQueryParams(['denda' => $loan->id])->test(KasirProShop::class)
            ->assertNotified('Tidak Ada Denda')->assertSet('fineBill', null);
        $this->assertTrue(ActivityLog::where('event', 'equipment.fine_waived')->exists());
    }

    public function test_one_transaction_with_several_items_is_one_row_and_can_be_returned_at_once(): void
    {
        $towel = CourtEquipment::create(['name' => 'Handuk', 'type' => 'TOWEL', 'rental_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
        $service = app(PadelBookingService::class);
        $booking = $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Andi Wijaya', phone: '081700112233'),
            slots: [['court_id' => $this->court->id, 'start_time' => '14:00', 'end_time' => '15:00']],
            bookingDate: $this->today, equipments: [['equipment_id' => $this->racket->id, 'quantity' => 2], ['equipment_id' => $towel->id, 'quantity' => 1]],
            paymentMethod: 'DEBIT_CARD', cashier: $this->receptionist,
            paymentMeta: ['terminal' => 'EDC_BCA', 'card_type' => 'DEBIT', 'card_last_4' => '1234', 'approval_code' => 'APR15', 'trace_number' => 'TRC15'],
        )['bookings']->first();
        $service->checkIn($booking->booking_code, $this->receptionist);
        $this->assertSame(2, EquipmentLoan::count());

        Livewire::test(PeminjamanAlat::class)
            ->assertCountTableRecords(1)->assertSee('3 alat')->assertSee('2× Raket, 1× Handuk')
            ->call('openDetail', $booking->id)
            ->assertSee('2× Raket')->assertSee('1× Handuk')->assertSee('Terima Semua (Kondisi Baik)')
            ->call('returnAllGood')
            ->assertNotified('3 alat diterima kembali (kondisi baik)')
            ->assertDontSee('Terima Semua (Kondisi Baik)');

        $this->assertSame(0, EquipmentLoan::open()->count());
        $this->assertSame([8, 5], [$this->racket->fresh()->stock_quantity, $towel->fresh()->stock_quantity]);
    }

    public function test_kelola_pemesanan_sends_new_loans_to_the_loan_page_and_keeps_old_rentals_working(): void
    {
        $booking = $this->checkedInWithRackets();

        // Raket dengan catatan peminjaman tidak bisa "dikembalikan cepat" tanpa kondisi.
        $this->expectRejected(fn () => app(PadelBookingService::class)->returnEquipment($booking->id, $this->receptionist), 'Tidak ada alat sewa', 422);
        $this->assertSame(6, $this->racket->fresh()->stock_quantity);
        Livewire::test(KelolaPemesanan::class)->assertSee(PeminjamanAlat::getUrl(['booking' => $booking->booking_code]), false);

        // Alat sewa dari sebelum fitur ini (tanpa catatan peminjaman) tetap dikembalikan seperti biasa.
        EquipmentLoan::query()->delete();
        $result = app(PadelBookingService::class)->returnEquipment($booking->id, $this->receptionist);
        $this->assertSame(8, $this->racket->fresh()->stock_quantity);
        $this->assertNotEmpty($result['items']);

        // Role resepsionis bawaan tidak punya izin halaman ini (diberikan manual lewat matriks).
        $frontdesk = User::factory()->role('receptionist')->create(['is_active' => true]);
        $this->actingAs($frontdesk)->get(KelolaPemesanan::getUrl())->assertOk();
        $denied = $this->actingAs($frontdesk)->get(PeminjamanAlat::getUrl());
        $this->assertContains($denied->status(), [302, 403], 'ditolak / dialihkan ke halaman lain');
        $this->assertStringNotContainsString('Raket &amp; handuk yang sedang dipinjam', $denied->getContent());
        $frontdesk->givePermissionTo('View:PeminjamanAlat');
        $this->actingAs($frontdesk->fresh())->get(PeminjamanAlat::getUrl())->assertOk();
    }
}
