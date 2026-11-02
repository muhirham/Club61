<?php

namespace Tests\Feature\Coaching;

use App\Filament\Pages\BookingSystem;
use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\KelolaPemesanan;
use App\Filament\Pages\TagihanCoaching;
use App\Filament\Resources\Coaching\CoachingAcademyResource;
use App\Filament\Resources\Coaching\Pages\ManageCoachingAcademies;
use App\Models\Audit\ActivityLog;
use App\Models\Coaching\Coach;
use App\Models\Coaching\CoachingAcademy;
use App\Models\Coaching\CoachingBooking;
use App\Models\Coaching\CoachingSettlement;
use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Coaching\CoachingBillingService;
use App\Services\Coaching\CoachingInvoice;
use App\Services\Mail\InvoicePdf;
use App\Services\Coaching\CoachingPricingService;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Pos\ShiftClosingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 31 Tahap 1 — academy "Tagihan Berkala": booking coaching di POS dicatat ke tagihan (tanpa bayar), slot tetap
 * terkunci, bisa check-in, tidak bisa dibayar online / dilepas dari keranjang, batal hanya sebelum batas waktu,
 * peringatan & penolakan booking bila tagihan melewati jatuh tempo (docs/PRD_MODUL_31_TAGIHAN_COACHING.md).
 */
class CoachingTabTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private PadelCourt $court;

    private CoachingAcademy $academy;

    private Coach $maimun;

    private PosCashierShift $shift;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        app(CoachingPricingService::class)->flush();

        $this->receptionist = User::factory()->create(['name' => 'Resepsionis Sari', 'is_active' => true]);
        $this->receptionist->givePermissionTo(Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        $this->academy = CoachingAcademy::where('name', 'SR Academy')->firstOrFail();
        $this->academy->update(['billing_mode' => CoachingAcademy::BILLING_TAB, 'billing_cycle' => CoachingAcademy::CYCLE_WEEKLY]);
        $this->maimun = Coach::create(['academy_id' => $this->academy->id, 'name' => 'Maimun', 'title' => 'HEAD_COACH', 'is_active' => true]);
        $this->date = now()->addDays(10)->format('Y-m-d');

        $this->shift = PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->receptionist->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $this->actingAs($this->receptionist);
    }

    private function tabCheckout(string $hour, ?string $date = null, string $method = 'TAGIHAN'): array
    {
        $service = app(PadelBookingService::class);

        return $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Coach Maimun', phone: '081299887700'),
            slots: [['court_id' => $this->court->id, 'start_time' => "{$hour}:00", 'end_time' => sprintf('%02d:00', (int) $hour + 1)]],
            bookingDate: $date ?? $this->date, equipments: [], paymentMethod: $method, cashier: $this->receptionist,
            paymentMeta: ['terminal' => 'EDC_BCA', 'card_last_4' => '1234', 'approval_code' => 'A'.$hour, 'trace_number' => 'T'.$hour],
            coaching: ['head_coach_id' => $this->maimun->id],
        );
    }

    private function expectRejected(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("Seharusnya ditolak: {$message}");
        } catch (HttpException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_receptionist_saves_coaching_to_the_academy_tab_without_payment(): void
    {
        $page = Livewire::test(BookOfflineCourt::class)
            ->set('bookingDate', $this->date)
            ->set('selectedSlots', ["{$this->court->id}_18:00:00" => [
                'court_id' => $this->court->id, 'court_name' => 'Court A', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'time_label' => '18:00', 'price' => 300000,
            ]])
            ->call('setCustomerMode', 'coaching')
            ->set('walkInName', 'Coach Maimun')->set('walkInPhone', '081299887700')
            ->set('coachingCoachId', $this->maimun->id)
            ->assertSee('SR Academy menggunakan Tagihan Berkala · Mingguan.')
            ->call('proceedToPayment')->assertSet('posStep', 'payment')
            ->assertSee('Catat ke Tagihan Berkala')
            ->assertDontSee('Bayar Lunas & Cetak Struk');

        $page->call('submitWalkInBooking')->assertSet('posStep', 'receipt')->assertSee('TAGIHAN BERKALA ACADEMY (BELUM LUNAS)');

        $booking = PadelBooking::sole();
        $this->assertSame('PENDING_PAYMENT', $booking->status);
        $this->assertNull($booking->expires_at, 'tanpa batas bayar');
        $this->assertSame('UNPAID', $booking->order->payment_status);
        $this->assertSame(0, Payment::count(), 'belum ada uang masuk');
        $this->assertSame(0, LedgerEntry::count());

        $record = CoachingBooking::sole();
        $this->assertSame([CoachingAcademy::BILLING_TAB, CoachingAcademy::CYCLE_WEEKLY, $this->academy->id, $this->shift->id],
            [$record->billing, $record->billing_cycle, $record->academy_id, $record->cashier_shift_id]);
        $this->assertSame(Carbon::parse($this->date)->endOfWeek(Carbon::SUNDAY)->format('Y-m-d H:i'), $record->due_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'coaching_tab.saved']);
    }

    public function test_tab_session_is_not_released_cannot_be_paid_online_and_can_check_in(): void
    {
        $this->tabCheckout('18', now()->addDay()->format('Y-m-d'));
        $booking = PadelBooking::sole();
        $owner = $booking->user;
        $service = app(PadelBookingService::class);

        // Pembersih booking tidak dibayar (lewat batas bayar online) tidak menyentuh sesi tagihan.
        $this->travel(3)->hours();
        $service->releaseExpiredLocks();
        $this->assertSame('PENDING_PAYMENT', $booking->fresh()->status);

        // Pemilik akun tidak bisa melepas dari keranjang / membayar online.
        $this->assertSame(0, $service->releaseSlots([$booking->id], $owner));
        $this->expectRejected(fn () => $service->retryPayment($booking->id, 'QRIS', $owner), 'dilunasi di kasir');
        $this->assertSame('PENDING_PAYMENT', $booking->fresh()->status);

        // Slotnya tetap terkunci untuk orang lain.
        try {
            $service->holdBatchSlots([['court_id' => $this->court->id, 'start_time' => '18:00', 'end_time' => '19:00']],
                $booking->booking_date->format('Y-m-d'), User::factory()->create());
            $this->fail('Slot sesi tagihan seharusnya tidak bisa ditahan orang lain.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        // Coach boleh check-in walau belum lunas; tagihannya tetap ada.
        $this->travelTo($booking->start_time->copy()->subMinutes(30));
        $service->checkIn($booking->booking_code, $this->receptionist);
        $this->assertSame('CHECKED_IN', $booking->fresh()->status);
        $this->assertSame('UNPAID', $booking->order->fresh()->payment_status);
        $this->assertSame(1, app(CoachingBillingService::class)->unpaidSessions($this->academy->id)->count());
    }

    public function test_payment_method_follows_the_academy_setting_not_the_cashier(): void
    {
        $this->expectRejected(fn () => $this->tabCheckout('09', method: 'EDC_BCA'), 'menggunakan Tagihan Berkala');

        $this->academy->update(['billing_mode' => CoachingAcademy::BILLING_PER_BOOKING]);
        $this->expectRejected(fn () => $this->tabCheckout('09'), 'hanya untuk booking coaching academy');
        $this->assertSame(0, PadelBooking::whereNotIn('status', ['CANCELLED'])->count(), 'slot yang ditahan dilepas lagi');

        // Per Booking: alur lama — langsung lunas.
        $this->tabCheckout('10', method: 'EDC_BCA');
        $this->assertSame('PAID', PadelBooking::where('status', '!=', 'CANCELLED')->sole()->status);
        $this->assertSame(CoachingAcademy::BILLING_PER_BOOKING, CoachingBooking::sole()->billing);
    }

    public function test_overdue_tab_warns_the_receptionist_and_can_block_new_bookings(): void
    {
        $this->academy->update(['billing_cycle' => CoachingAcademy::CYCLE_DAILY]);
        $this->tabCheckout('18');
        $billing = app(CoachingBillingService::class);
        $this->assertSame(0, $billing->overdue($this->academy->fresh())['sessions']);

        $this->travelTo(Carbon::parse($this->date)->addDay()->setTime(9, 0));
        $overdue = $billing->overdue($this->academy->fresh());
        $this->assertSame(1, $overdue['sessions']);
        $this->assertEqualsWithDelta(150000, $overdue['amount'], 0.01);

        Livewire::test(BookOfflineCourt::class)->call('setCustomerMode', 'coaching')->set('coachingCoachId', $this->maimun->id)
            ->assertSee('Tagihan melewati jatuh tempo: 1 sesi, Rp 150.000')->assertSee('Mohon ingatkan academy');

        // Sakelar blok mati → tetap bisa booking; nyala → ditolak di server.
        $nextDate = Carbon::parse($this->date)->addDays(3)->format('Y-m-d');
        $this->tabCheckout('10', $nextDate);
        $this->academy->update(['block_when_overdue' => true]);
        $this->expectRejected(fn () => $this->tabCheckout('11', $nextDate), 'melewati jatuh tempo');
    }

    public function test_per_shift_tab_is_overdue_once_the_shift_is_closed(): void
    {
        $this->academy->update(['billing_cycle' => CoachingAcademy::CYCLE_SHIFT]);
        $this->tabCheckout('18');
        $this->assertNull(CoachingBooking::sole()->due_at);

        $billing = app(CoachingBillingService::class);
        $this->assertSame(0, $billing->overdue($this->academy->fresh())['sessions']);
        $this->shift->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $this->assertSame(1, $billing->overdue($this->academy->fresh())['sessions']);

        // Per Shift tanpa shift kasir yang buka → ditolak.
        $this->expectRejected(fn () => $this->tabCheckout('19'), 'Buka shift dulu');
    }

    public function test_cancel_before_the_deadline_frees_the_slot_after_it_stays_billed(): void
    {
        $this->tabCheckout('18');
        $booking = PadelBooking::sole();

        Livewire::test(KelolaPemesanan::class)
            ->assertSee('Tagihan Berkala · SR Academy')
            ->call('openCancelTabModal', $booking->id)->assertSet('showCancelTabModal', true)
            ->set('cancelTabReason', 'Coach berhalangan')
            ->call('executeCancelTab')->assertSet('showCancelTabModal', false);

        $this->assertSame('CANCELLED', $booking->fresh()->status);
        $this->assertSame('CANCELLED', $booking->order->fresh()->payment_status);
        $this->assertSame(0, app(CoachingBillingService::class)->unpaidSessions()->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'coaching_tab.cancelled']);

        // Slotnya bisa dibooking lagi; lewat batas waktu (2 jam sebelum main) → tetap ditagih.
        $this->tabCheckout('18');
        $second = PadelBooking::where('status', 'PENDING_PAYMENT')->sole();
        $this->travelTo($second->start_time->copy()->subHour());
        $this->expectRejected(fn () => app(CoachingBillingService::class)->cancelTab($second, 'Batal mendadak', $this->receptionist), 'tetap tercatat pada tagihan');
        $this->assertSame('PENDING_PAYMENT', $second->fresh()->status);
    }

    public function test_due_dates_follow_the_billing_cycle(): void
    {
        $billing = app(CoachingBillingService::class);
        $wednesday = Carbon::parse('2026-11-04 19:00');

        $this->academy->billing_cycle = CoachingAcademy::CYCLE_DAILY;
        $this->assertSame('2026-11-04 23:59', $billing->dueAt($this->academy, $wednesday)->format('Y-m-d H:i'));
        $this->academy->billing_cycle = CoachingAcademy::CYCLE_WEEKLY;
        $this->assertSame('2026-11-08 23:59', $billing->dueAt($this->academy, $wednesday)->format('Y-m-d H:i'));
        $this->academy->billing_cycle = CoachingAcademy::CYCLE_BIWEEKLY;
        $this->academy->billing_anchor_date = Carbon::parse('2026-10-26');
        $this->assertSame('2026-11-08 23:59', $billing->dueAt($this->academy, $wednesday)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-22 23:59', $billing->dueAt($this->academy, Carbon::parse('2026-11-09 08:00'))->format('Y-m-d H:i'));
        $this->academy->billing_cycle = CoachingAcademy::CYCLE_SHIFT;
        $this->assertNull($billing->dueAt($this->academy, $wednesday));
    }

    public function test_admin_sets_the_billing_mode_and_new_academies_default_to_per_shift_tab(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageCoachingAcademies::class)
            ->callAction('create', ['name' => 'Akademi Baru', 'is_active' => true])
            ->assertHasNoActionErrors();
        $new = CoachingAcademy::where('name', 'Akademi Baru')->sole();
        $this->assertSame([CoachingAcademy::BILLING_TAB, CoachingAcademy::CYCLE_SHIFT, false], [$new->billing_mode, $new->billing_cycle, $new->block_when_overdue]);

        Livewire::test(ManageCoachingAcademies::class)
            ->callAction('create', ['name' => 'Kontrak 2 Minggu', 'billing_mode' => 'TAB', 'billing_cycle' => 'BIWEEKLY', 'is_active' => true])
            ->assertHasActionErrors(['billing_anchor_date' => 'required']);

        // Academy lama (sebelum fitur ini) tetap Per Booking sampai diubah admin.
        $this->assertSame('Bayar Langsung', (new CoachingAcademy(['billing_mode' => 'PER_BOOKING']))->billingLabel());
    }

    // ── Tahap 2: Pelunasan Tagihan Coaching ────────────────────────────────────────────────────────────────────

    public function test_receptionist_settles_all_open_sessions_at_once_with_qris(): void
    {
        $this->tabCheckout('09');
        $this->tabCheckout('18');

        $page = Livewire::test(BookOfflineCourt::class)
            ->assertSee('Pelunasan Tagihan Coaching')
            ->call('openCoachingSettlement')
            ->assertSet('settleAcademyId', $this->academy->id)
            ->assertSee('Sesi belum lunas');
        $this->assertCount(2, $page->get('settleOrderIds'), 'semua transaksi academy otomatis dicentang');
        $this->assertEqualsWithDelta(250000, $page->instance()->coachingSettleTotal, 0.01, 'pagi Rp100rb + malam Rp150rb');

        // Tombol mengirim total yang tampil di layar; tagihan berubah sebelum ditekan (mis. sesi dipindah) → ditolak.
        $page->set('settleMethod', 'QRIS')->set('settleQrisRrn', 'RRN778899')
            ->assertSeeHtml('submitCoachingSettlement(250000)')
            ->call('submitCoachingSettlement', 200000)->assertNotified('Pelunasan Belum Tersimpan');
        $this->assertSame(0, CoachingSettlement::count());
        $this->assertSame(0, Payment::count());

        $page->call('submitCoachingSettlement', 250000)
            ->assertSee('BUKTI PELUNASAN TAGIHAN COACHING')->assertSee('LUNAS');

        $settlement = CoachingSettlement::sole();
        $this->assertSame([2, 'QRIS', 'RRN778899', $this->shift->id], [$settlement->session_count, $settlement->payment_method, $settlement->reference, $settlement->cashier_shift_id]);
        $this->assertEqualsWithDelta(250000, (float) $settlement->total_amount, 0.01);
        $this->assertSame(['PAID'], PadelBooking::pluck('status')->unique()->values()->all());
        $this->assertSame(2, CoachingBooking::where('settlement_id', $settlement->id)->count());
        $this->assertSame(0, app(CoachingBillingService::class)->unpaidSessions()->count());

        // Uang masuk shift kasir yang sedang buka, Buku Transaksi kategori Coaching.
        $this->assertSame(2, Payment::where('pos_shift_id', $this->shift->id)->where('status', 'SUCCESS')->count());
        $this->assertEqualsWithDelta(250000, (float) LedgerEntry::where('category', 'COACHING')->sum('total_amount'), 0.01);
        $row = collect(app(ShiftClosingService::class)->systemRows($this->shift->fresh()))->firstWhere('key', 'QRIS_BCA_QRIS');
        $this->assertSame([2, 250000.0], [$row['count'], (float) $row['system_amount']]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'coaching_tab.settled']);

        // Bukti yang sama tidak bisa dipakai lagi.
        $this->tabCheckout('10');
        $this->expectRejected(fn () => app(CoachingBillingService::class)->settle($this->academy,
            [PadelBooking::where('status', 'PENDING_PAYMENT')->value('order_id')], 'QRIS', ['qris_rrn' => 'RRN778899'], null, $this->receptionist), 'sudah pernah dipakai');
    }

    public function test_partial_settlement_by_bank_transfer_needs_proof_and_is_an_info_row_at_shift_close(): void
    {
        Storage::fake('local');
        $this->tabCheckout('09');
        $this->tabCheckout('18');
        $first = PadelBooking::orderBy('start_time')->first();
        $billing = app(CoachingBillingService::class);
        $transfer = ['transfer_bank' => 'BCA', 'transfer_reference' => 'TRF-20261031-01', 'transfer_sender_name' => 'SR Academy'];

        $this->expectRejected(fn () => $billing->settle($this->academy, [$first->order_id], 'TRANSFER_BANK', $transfer, null, $this->receptionist), 'bukti transfer');

        $settlement = $billing->settle($this->academy, [$first->order_id], 'TRANSFER_BANK', $transfer, UploadedFile::fake()->image('bukti.jpg', 600, 900), $this->receptionist);
        Storage::disk('local')->assertExists($settlement->proof_path);
        $this->assertSame('PAID', $first->fresh()->status);
        $this->assertSame(1, $billing->unpaidSessions($this->academy->id)->count(), 'sesi lain tetap di tagihan');

        // Tutup Shift: transfer coaching = baris informasi (tidak diketik kasir), tetap cocok tanpa isian.
        $rows = app(ShiftClosingService::class)->systemRows($this->shift->fresh());
        $row = collect($rows)->firstWhere('key', PosCashierShift::TRANSFER_COACHING_KEY);
        $this->assertSame([ShiftClosingService::TYPE_TRANSFER, 100000.0], [$row['type'], (float) $row['system_amount']]);
        $this->assertTrue(app(ShiftClosingService::class)->reconcile($rows, [], [])['matched']);
    }

    public function test_settlement_keeps_check_in_status_and_rejects_stale_or_foreign_sessions(): void
    {
        $this->tabCheckout('18', now()->addDay()->format('Y-m-d'));
        $booking = PadelBooking::sole();
        $this->travelTo($booking->start_time->copy()->subMinutes(30));
        app(PadelBookingService::class)->checkIn($booking->booking_code, $this->receptionist);

        $billing = app(CoachingBillingService::class);
        $other = CoachingAcademy::create(['name' => 'Academy Lain', 'billing_mode' => 'TAB', 'billing_cycle' => 'DAILY', 'is_active' => true]);
        $this->expectRejected(fn () => $billing->settle($other, [$booking->order_id], 'QRIS', ['qris_rrn' => 'RRN000111'], null, $this->receptionist), 'bukan tagihan');

        $billing->settle($this->academy, [$booking->order_id], 'EDC_BCA', ['card_type' => 'DEBIT', 'card_last_4' => '4321', 'approval_code' => 'APP123', 'trace_number' => 'TRC123'], null, $this->receptionist);
        $this->assertSame('CHECKED_IN', $booking->fresh()->status, 'status check-in tidak ditimpa');
        $this->assertSame('PAID', $booking->order->fresh()->payment_status);
        $this->expectRejected(fn () => $billing->settle($this->academy, [$booking->order_id], 'QRIS', ['qris_rrn' => 'RRN000222'], null, $this->receptionist), 'sudah dilunasi');
    }

    public function test_settlement_needs_permission_and_per_shift_tab_warns_at_shift_close(): void
    {
        $this->academy->update(['billing_cycle' => CoachingAcademy::CYCLE_SHIFT]);
        $this->tabCheckout('18');
        $closing = app(ShiftClosingService::class);
        $this->assertStringContainsString('Tagihan coaching Per Shift dari shift ini belum dilunasi (SR Academy)', implode(' ', $closing->warnings($this->shift->fresh())));

        $noAccess = User::factory()->create(['is_active' => true]);
        $noAccess->givePermissionTo(['View:BookOfflineCourt', 'process_walkin_booking']);
        $this->actingAs($noAccess);
        $pos = Livewire::test(BookOfflineCourt::class)->assertDontSee('wire:click="openCoachingSettlement"', false)
            ->call('openCoachingSettlement')->assertSet('showCoachingSettlement', false)
            // Dibuka paksa dari browser pun daftar tagihan academy tetap kosong.
            ->set('showCoachingSettlement', true)->set('settleAcademyId', $this->academy->id);
        $this->assertTrue($pos->instance()->coachingSettlementAcademies->isEmpty());
        $this->assertTrue($pos->instance()->coachingOpenBills->isEmpty());
        try {
            app(CoachingBillingService::class)->settle($this->academy, [PadelBooking::sole()->order_id], 'QRIS', ['qris_rrn' => 'RRN333444'], null, $noAccess);
            $this->fail('Tanpa izin seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        app(CoachingBillingService::class)->settle($this->academy, [PadelBooking::sole()->order_id], 'QRIS', ['qris_rrn' => 'RRN333444'], null, $this->receptionist);
        $this->assertStringNotContainsString('Tagihan coaching Per Shift', implode(' ', $closing->warnings($this->shift->fresh())));
    }

    // ── Tahap 3: halaman Tagihan Coaching, invoice PDF, tampilan jadwal ─────────────────────────────────────────

    public function test_tab_session_shows_as_booked_on_pos_grid_and_monitoring_not_as_hold(): void
    {
        $this->tabCheckout('18');

        Livewire::test(BookOfflineCourt::class)->set('bookingDate', $this->date)
            ->assertSeeHtml('<span class="sub">Tagihan Berkala</span>')
            ->assertSee('Coach Maimun');
        Livewire::test(BookingSystem::class)->set('selectedDate', $this->date)
            ->assertSee('TAGIHAN BERKALA');
    }

    public function test_admin_monitors_tab_sessions_settlements_and_downloads_the_invoice(): void
    {
        Storage::fake('local');
        $this->tabCheckout('09');
        $this->tabCheckout('18');
        [$paid, $open] = PadelBooking::orderBy('start_time')->get()->all();
        $settlement = app(CoachingBillingService::class)->settle($this->academy, [$paid->order_id], 'TRANSFER_BANK',
            ['transfer_bank' => 'BCA', 'transfer_reference' => 'TRF-0001'], UploadedFile::fake()->image('bukti.jpg'), $this->receptionist);

        $this->actingAs(User::factory()->admin()->create());
        $page = Livewire::test(TagihanCoaching::class)
            ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$paid])
            ->assertSee('Rp 150.000')->assertSee('Riwayat Pelunasan')->assertSee($settlement->settlement_number);
        $page->filterTable('billing_status', TagihanCoaching::STATUS_PAID)->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$open]);
        $page->call('downloadProof', $settlement->id)->assertFileDownloaded('bukti-transfer-'.$settlement->settlement_number.'.jpg');

        // Invoice: hanya sesi belum lunas, template invoice yang sama dengan status Belum Lunas.
        $invoice = app(CoachingInvoice::class)->data($this->academy, null, null);
        $this->assertSame(['Belum Lunas', 1, 150000.0], [$invoice['status_label'], count($invoice['bookings']), $invoice['grand_total']]);
        $html = InvoicePdf::html($invoice);
        foreach (['Invoice Tagihan', 'Belum Lunas', 'Total tagihan', 'Menunggu pembayaran', $open->booking_code] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString($paid->booking_code, $html);
        $page->callAction('invoice', ['academy_id' => $this->academy->id])->assertFileDownloaded($invoice['number'].'.pdf');
        $this->assertDatabaseHas('activity_logs', ['event' => 'coaching_tab.invoice_downloaded']);

        // Invoice lunas customer tidak berubah.
        $paidHtml = InvoicePdf::html(InvoicePdf::sample());
        $this->assertStringContainsString('Total dibayar', $paidHtml);
        $this->assertStringContainsString('Pembayaran diterima', $paidHtml);

        // Periode tanpa tagihan belum lunas → tidak ada PDF.
        $this->assertNull(app(CoachingInvoice::class)->data($this->academy, '2020-01-01', '2020-01-31'));
    }

    public function test_tagihan_coaching_page_needs_its_permission(): void
    {
        // Resepsionis (preset) tidak punya izin halaman ini; diberi izin → bisa.
        $this->assertFalse(TagihanCoaching::canAccess());
        $this->receptionist->givePermissionTo('View:TagihanCoaching');
        $this->actingAs($this->receptionist->fresh());
        $this->assertTrue(TagihanCoaching::canAccess());

        // Admin (preset) bisa membuka; kasir tidak (staf tanpa izin dialihkan ke halaman awalnya).
        $this->actingAs(User::factory()->admin()->create());
        $this->get(TagihanCoaching::getUrl())->assertOk();
        $this->actingAs(User::factory()->cashier()->create(['is_active' => true]));
        $this->assertFalse(TagihanCoaching::canAccess());
        $this->get(TagihanCoaching::getUrl())->assertRedirect();
    }

    // ── Reschedule sesi tagihan, hapus academy, bukti transfer, omzet POS ───────────────────────────────────────

    public function test_tab_session_reschedules_with_global_rules_and_the_bill_follows_the_new_rate(): void
    {
        // Biaya layanan per transaksi: tetap sekali per order, tidak bertambah di setiap reschedule.
        ClubFinanceSetting::getSettings()->update(['is_tax_enabled' => false, 'is_admin_fee_enabled' => true, 'admin_fee_type' => 'FIXED', 'admin_fee_amount' => 5000, 'admin_fee_channels' => 'ALL']);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);
        $this->tabCheckout('18');
        $booking = PadelBooking::sole();
        $this->assertEqualsWithDelta(155000, (float) $booking->order->grand_total, 0.01);

        $nextWeek = Carbon::parse($this->date)->addWeek()->format('Y-m-d');
        $admin = User::factory()->superAdmin()->create(['is_active' => true]);
        $this->actingAs($admin);
        Livewire::test(KelolaPemesanan::class)
            ->call('openRescheduleModal', $booking->id)->assertSet('showRescheduleModal', true)
            ->set('rescheduleDate', $nextWeek)->set('rescheduleStartTime', '10:00')
            // Label jam memakai tarif coaching (Tarif Setoran), bukan Reguler / Prime Time lapangan.
            ->assertSee('10:00 - 11:00 WIB (1 Jam) · Rp 100.000')->assertSee('18:00 - 19:00 WIB (1 Jam) · Rp 150.000')
            ->assertDontSee('[Prime Time]')
            ->assertSee('tidak ada selisih yang ditagih atau hangus')
            ->call('executeReschedule')->assertNotified('Reschedule Berhasil')->assertSet('showRescheduleModal', false);

        $booking->refresh();
        $order = $booking->order->fresh('items');
        $this->assertSame(['PENDING_PAYMENT', 'UNPAID', 1, "{$nextWeek} 10:00"],
            [$booking->status, $order->payment_status, (int) $booking->reschedule_count, $booking->start_time->format('Y-m-d H:i')]);
        $this->assertEqualsWithDelta(100000, (float) $booking->court_fee, 0.01);
        $this->assertEqualsWithDelta(105000, (float) $order->grand_total, 0.01, 'tarif pagi Rp100rb + biaya layanan sekali');
        $this->assertEqualsWithDelta(100000, (float) $order->items->firstWhere('item_type', 'PADEL')->subtotal, 0.01);
        $this->assertEquals(0, (float) $booking->reschedule_forfeited_amount, 'tidak ada selisih hangus');
        $this->assertSame(0, Payment::count(), 'tidak ada tagihan selisih / uang masuk');
        $this->assertSame(Carbon::parse($nextWeek)->endOfWeek(Carbon::SUNDAY)->format('Y-m-d H:i'), CoachingBooking::sole()->due_at->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(105000, app(CoachingBillingService::class)->openBills($this->academy)->sole()['amount'], 0.01);
        // Log mencatat perubahan tagihan, bukan selisih yang ditagih / hangus.
        $meta = ActivityLog::where('event', 'booking.rescheduled')->sole()->meta;
        $this->assertSame('Rp 155.000 → Rp 105.000', $meta['tagihan_coaching']);
        $this->assertArrayNotHasKey('selisih_hangus', $meta);
        $this->assertArrayNotHasKey('total_tagihan_selisih', $meta);

        // Pindah ke jam malam → tagihan naik lagi; reschedule ke-4 ditolak (maks. 3x).
        $service = app(PadelBookingService::class);
        $service->adminRescheduleBooking($booking->id, $this->court->id, $nextWeek, '19:00', 'Pindah malam', $admin);
        $this->assertEqualsWithDelta(155000, (float) $order->fresh()->grand_total, 0.01);
        $service->adminRescheduleBooking($booking->id, $this->court->id, $nextWeek, '20:00', 'Pindah lagi', $admin);
        $this->expectRejected(fn () => $service->adminRescheduleBooking($booking->id, $this->court->id, $nextWeek, '21:00', 'Keempat', $admin), 'batas maksimal');

        // Lewat batas waktu reschedule → ditolak.
        $this->tabCheckout('09');
        $late = PadelBooking::whereKeyNot($booking->id)->sole();
        $this->travelTo($late->start_time->copy()->subHour());
        $this->expectRejected(fn () => $service->adminRescheduleBooking($late->id, $this->court->id, $this->date, '11:00', 'Telat', $admin), 'paling lambat');
        $this->assertSame(['PENDING_PAYMENT', 0], [$late->fresh()->status, (int) $late->fresh()->reschedule_count]);
    }

    public function test_academy_with_unpaid_tab_sessions_cannot_be_deleted(): void
    {
        $this->tabCheckout('18');
        $this->actingAs(User::factory()->admin()->create());

        // Coach dipindah ke academy lain dulu: dulu academy lama bisa dihapus dan tagihannya tidak bisa dilunasi lagi.
        $this->maimun->update(['academy_id' => CoachingAcademy::create(['name' => 'Academy Baru', 'is_active' => true])->id]);
        $this->assertFalse(CoachingAcademyResource::canDelete($this->academy));

        app(CoachingBillingService::class)->cancelTab(PadelBooking::sole(), 'Coach berhalangan', $this->receptionist);
        $this->assertTrue(CoachingAcademyResource::canDelete($this->academy));
    }

    public function test_transfer_proof_shows_a_preview_after_upload(): void
    {
        Storage::fake('local');
        $this->tabCheckout('18');

        $page = Livewire::test(BookOfflineCourt::class)->call('openCoachingSettlement')->set('settleMethod', 'TRANSFER_BANK')
            ->assertSee('Belum ada foto dipilih')
            ->set('settleTransferProof', UploadedFile::fake()->image('bukti-trf.jpg', 600, 900))
            ->assertHasNoErrors()->assertSee('bukti-trf.jpg')->assertSee('siap disimpan')->assertSee('Ganti Foto');

        $page->set('settleTransferProof', null)->assertHasNoErrors()->assertDontSee('bukti-trf.jpg')->assertSee('Belum ada foto dipilih');

        $page->set('settleTransferProof', UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))
            ->assertHasErrors(['settleTransferProof'])->assertDontSee('siap disimpan');
    }

    public function test_pos_walk_in_revenue_counts_only_paid_orders(): void
    {
        $this->tabCheckout('18');
        $stats = fn (float $revenue) => fn (array $s) => $s['count'] === 1 && abs($s['revenue'] - $revenue) < 0.01;
        Livewire::test(BookOfflineCourt::class)->assertViewHas('walkInStatsToday', $stats(0.0));

        app(CoachingBillingService::class)->settle($this->academy, [PadelBooking::sole()->order_id], 'QRIS', ['qris_rrn' => 'RRN555666'], null, $this->receptionist);
        Cache::flush();
        Livewire::test(BookOfflineCourt::class)->assertViewHas('walkInStatsToday', $stats(150000.0));
    }
}
