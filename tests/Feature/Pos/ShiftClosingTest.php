<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\DetailShiftKasir;
use App\Filament\Pages\ReviewShiftKasir;
use App\Livewire\Pos\FnbCashierTerminal;
use App\Models\Audit\ActivityLog;
use App\Models\Finance\LedgerEntry;
use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\Pos\PosEdcSettlement;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Pos\PaymentMethodCorrection;
use App\Services\Pos\ShiftClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Tutup shift Fase 1: blind, EDC per batch sejak settlement terakhir (ganti shift tanpa settle), toleransi Rp0 → review,
 * koreksi metode bayar oleh admin (Buku Transaksi tetap satu baris), pengingat, dan halaman Review Shift Kasir.
 */
class ShiftClosingTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected User $admin;

    protected FnbMenu $latte;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');

        $this->cashier = User::factory()->cashier()->create(['name' => 'Kasir Andi', 'is_active' => true]);
        $this->cashier->givePermissionTo(['access_pos_terminal', 'process_fnb_order', 'open_pos_shift', 'close_pos_shift']);
        $this->admin = User::factory()->admin()->create(['name' => 'Admin Rina']);

        $category = FnbCategory::create(['name' => 'Coffee', 'sort_order' => 1]);
        $this->latte = FnbMenu::create(['category_id' => $category->id, 'name' => 'Iced Latte', 'base_price' => 38000, 'is_available' => true]);
    }

    private function openShift(): PosCashierShift
    {
        return PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('FNB_COUNTER'),
            'counter' => 'FNB_COUNTER', 'status' => 'OPEN', 'opened_by_id' => $this->cashier->id,
            'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
    }

    /** Checkout 1 latte di POS F&B (pembayaran + baris Buku Transaksi asli). */
    private function pay(string $method, array $fields): Payment
    {
        $this->actingAs($this->cashier);
        $component = Livewire::test(FnbCashierTerminal::class)
            ->call('addToCart', $this->latte->id)
            ->call('proceedToPayment')
            ->call('setPaymentMethod', $method)
            ->set('qrisMode', 'MANUAL');
        foreach ($fields as $name => $value) {
            $component->set($name, $value);
        }
        $component->call('submitFnbCheckout')->assertSet('showReceiptModal', true);

        return Payment::where('status', 'SUCCESS')->latest('created_at')->latest('id')->firstOrFail();
    }

    private function debit(string $approval): Payment
    {
        return $this->pay('DEBIT_CARD', ['edcTerminal' => 'EDC_BCA', 'edcLast4' => '1234', 'edcApprovalCode' => $approval, 'edcTraceNumber' => 'T'.$approval]);
    }

    /** Tutup shift dari layar F&B: isian per baris (yang tidak disebut = 0) & mode tiap mesin EDC. */
    private function close(array $inputs, array $modes, string $notes = '')
    {
        $this->actingAs($this->cashier);
        $component = Livewire::test(FnbCashierTerminal::class)->call('prepareCloseShift');
        foreach (array_keys($component->get('settlementInputs')) as $key) {
            $component->set("settlementInputs.{$key}", (string) ($inputs[$key] ?? 0));
        }
        foreach ($modes as $terminal => $mode) {
            $component->set("edcModes.{$terminal}", $mode);
        }
        $component->set('closingNotes', $notes)->call('executeCloseShift');

        return $component;
    }

    // ── Ganti shift tanpa settle ────────────────────────────────────────────────────────────────────────────────

    public function test_handover_without_settlement_is_checked_against_the_cumulative_audit_report(): void
    {
        // Shift pagi: tidak di-settle (ganti shift) → dicocokkan dengan Audit Report.
        $morning = $this->openShift();
        $a = (int) $this->debit('APR001')->amount;
        $this->close(['EDC_BCA_DEBIT' => $a], ['EDC_BCA' => 'AUDIT'])->assertSet('showShiftReportModal', true);
        $this->assertSame(PosCashierShift::REVIEW_MATCHED, $morning->fresh()->review_status);
        $this->assertTrue(PosEdcSettlement::where('terminal', 'EDC_BCA')->where('source', 'BASELINE')->exists(), 'awal batch pertama dicatat');

        // Shift sore: struk settlement berisi transaksi pagi + sore — itu yang dicocokkan, kasir tidak mengurangi apa pun.
        $this->travel(1)->minutes();
        $evening = $this->openShift();
        $b = (int) $this->debit('APR002')->amount;
        $this->close(['EDC_BCA_DEBIT' => $a + $b], ['EDC_BCA' => 'SETTLE'])->assertSet('showShiftReportModal', true);
        $this->assertSame(PosCashierShift::REVIEW_MATCHED, $evening->fresh()->review_status);
        $this->assertSame(1, PosEdcSettlement::where('terminal', 'EDC_BCA')->where('source', 'CLOSING')->count());

        // Setelah settle: batch mulai dari nol lagi.
        $this->travel(1)->minutes();
        $next = $this->openShift();
        $c = (int) $this->debit('APR003')->amount;
        $this->close(['EDC_BCA_DEBIT' => $c], ['EDC_BCA' => 'SETTLE']);
        $this->assertSame(PosCashierShift::REVIEW_MATCHED, $next->fresh()->review_status);
    }

    public function test_every_edc_machine_needs_a_settle_or_handover_choice(): void
    {
        $this->openShift();
        $a = (int) $this->debit('APR010')->amount;

        $this->close(['EDC_BCA_DEBIT' => $a], [])->assertSet('errorMessage', fn ($m) => str_contains($m, 'EDC BCA'));
        $this->assertSame('OPEN', PosCashierShift::latest('opened_at')->first()->status);
    }

    // ── POS Padel memakai tutup shift yang sama ─────────────────────────────────────────────────────────────────

    public function test_padel_frontdesk_closes_with_the_same_blind_check_and_prints_the_z_report(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $shift = PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $owner->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $order = Order::create(['order_number' => 'ORD-WALKIN-T1', 'order_type' => 'WALK_IN', 'subtotal' => 300000, 'grand_total' => 300000, 'payment_status' => 'PAID']);
        Payment::create([
            'order_id' => $order->id, 'pos_shift_id' => $shift->id, 'payment_gateway' => 'CASHIER_POS', 'transaction_id' => 'POS-T1',
            'amount' => 300000, 'payment_method' => 'QRIS', 'status' => 'SUCCESS',
            'payload_log' => ['qris_details' => ['provider' => 'BCA_QRIS', 'rrn' => '555444333222']],
        ]);

        $this->actingAs($owner);
        $component = Livewire::test(BookOfflineCourt::class)->call('prepareCloseShift')->assertSet('showCloseShiftModal', true);
        $this->assertSame(['QRIS_BCA_QRIS'], array_column($component->get('closingRows'), 'key'));
        $this->assertArrayNotHasKey('system_amount', $component->get('closingRows')[0], 'blind: nominal sistem tidak dikirim ke browser');

        $component->set('settlementInputs.QRIS_BCA_QRIS', '300.000')
            ->call('executeCloseShift')
            ->assertSet('showShiftReportModal', true)
            ->assertSee('printable-z-report', false)
            ->assertSee('Supervisor / Manager');

        $this->assertSame(PosCashierShift::REVIEW_MATCHED, $shift->fresh()->review_status);
        $this->assertTrue(ActivityLog::where('event', 'shift.closed')->where('subject_id', $shift->id)->exists(), 'log tutup shift tetap otomatis');
    }

    // ── Salah klik kasir → koreksi admin ────────────────────────────────────────────────────────────────────────

    public function test_admin_moves_a_misclicked_payment_and_the_ledger_keeps_one_row_with_a_correction_mark(): void
    {
        $shift = $this->openShift();
        $real = $this->debit('APR020');
        // Customer bayar kartu debit, kasir salah klik QRIS.
        $wrong = $this->pay('QRIS', ['qrisProvider' => 'BCA_QRIS', 'qrisRrn' => '123456789012']);
        $a = (int) $real->amount;
        $ledgerRows = LedgerEntry::where('payment_id', $wrong->id)->count();
        $this->assertGreaterThan(0, $ledgerRows);

        // Struk EDC: 2 transaksi debit; aplikasi QRIS: kosong. Selisih pindah baris (EDC +a, QRIS −a).
        $this->close(['EDC_BCA_DEBIT' => 2 * $a, 'QRIS_BCA_QRIS' => 0], ['EDC_BCA' => 'SETTLE'])
            ->assertSet('closingMismatch', fn ($m) => $m !== null);
        $this->close([], [], 'BK customer bayar debit, saya salah klik QRIS')->assertSet('showShiftReportModal', true);
        $shift->refresh();
        $this->assertSame(PosCashierShift::REVIEW_NEEDS_REVIEW, $shift->review_status);

        // Kasir tidak bisa mengoreksi.
        try {
            app(PaymentMethodCorrection::class)->apply($wrong, $this->cashier, 'EDC_BCA', ['card_type' => 'DEBIT', 'card_last_4' => '9876', 'approval_code' => 'APR021', 'trace_number' => 'TAPR021'], 'salah klik kasir');
            $this->fail('Kasir tidak boleh mengoreksi metode bayar');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        app(PaymentMethodCorrection::class)->apply($wrong, $this->admin, 'EDC_BCA', ['card_type' => 'DEBIT', 'card_last_4' => '9876', 'approval_code' => 'APR021', 'trace_number' => 'TAPR021'], 'Salah klik kasir, slip EDC ada');

        $wrong->refresh();
        $this->assertSame('DEBIT_CARD', $wrong->payment_method);
        $this->assertSame('EDC_BCA', $wrong->payload_log['edc_details']['terminal']);
        $this->assertArrayNotHasKey('qris_details', $wrong->payload_log);
        $this->assertSame('QRIS', $wrong->payload_log['method_corrections'][0]['from_method']);
        $this->assertEquals($a, (float) $wrong->amount, 'nominal tidak berubah');

        // Buku Transaksi: tetap jumlah baris yang sama, metode baru + tanda koreksi + metode asal.
        $rows = LedgerEntry::where('payment_id', $wrong->id)->get();
        $this->assertCount($ledgerRows, $rows);
        $this->assertSame('DEBIT_CARD', $rows->first()->payment_method);
        $this->assertSame('Admin Rina', $rows->first()->method_corrected_by_name);
        $this->assertSame('QRIS', $rows->first()->original_payment_method);
        $this->assertStringContainsString('APPR APR021', $rows->first()->payment_reference);

        // Rekonsiliasi shift dihitung ulang: sekarang semua cocok → admin tinggal menyetujui.
        $shift->refresh();
        $this->assertEquals(0, (float) $shift->settlement_difference);
        $this->assertTrue(collect($shift->settlement_reconciliation)->every(fn ($r) => abs($r['difference']) < 0.005));
        $this->assertSame('CRITICAL', ActivityLog::where('event', 'payment.method_corrected')->sole()->severity);

        // Baris buku tetap terkunci untuk kolom lain.
        $this->expectException(LogicException::class);
        $rows->first()->update(['total_amount' => 1]);
    }

    public function test_closing_rows_follow_only_what_was_paid_in_the_shift(): void
    {
        // Shift kemarin pakai QRIS Mandiri & EDC BCA (di-settle).
        $this->openShift();
        $a = (int) $this->pay('QRIS', ['qrisProvider' => 'MANDIRI_QRIS', 'qrisRrn' => '123456789020'])->amount;
        $this->debit('APR050');
        $this->close(['QRIS_MANDIRI_QRIS' => $a, 'EDC_BCA_DEBIT' => $a], ['EDC_BCA' => 'SETTLE'])->assertSet('showShiftReportModal', true);

        // Shift ini cuma QRIS BCA → form cuma baris QRIS BCA, tanpa Mandiri / EDC dari riwayat loket.
        $this->travel(1)->minutes();
        $this->openShift();
        $this->pay('QRIS', ['qrisProvider' => 'BCA_QRIS', 'qrisRrn' => '123456789021']);
        $component = Livewire::test(FnbCashierTerminal::class)->call('prepareCloseShift');
        $this->assertSame(['QRIS_BCA_QRIS'], array_column($component->get('closingRows'), 'key'));
        $this->assertSame([], $component->get('edcModes'));
    }

    public function test_admin_fixes_a_misclick_right_away_while_the_shift_is_still_open(): void
    {
        $shift = $this->openShift();
        $wrong = $this->pay('QRIS', ['qrisProvider' => 'BCA_QRIS', 'qrisRrn' => '123456789022']);
        $a = (int) $wrong->amount;

        // Admin menemukan shift yang masih buka di Review Shift Kasir.
        $this->actingAs($this->admin);
        Livewire::test(ReviewShiftKasir::class)
            ->filterTable('review_status', 'OPEN')
            ->assertCanSeeTableRecords([$shift])
            // Penjualan shift yang masih buka = total berjalan (dulu Rp 0 sampai shift ditutup).
            ->assertTableColumnStateSet('total_sales', (float) $a, $shift);
        Livewire::test(DetailShiftKasir::class, ['shift' => $shift->id])->assertSee('Sedang buka');

        app(PaymentMethodCorrection::class)->apply($wrong, $this->admin, 'EDC_BCA', ['card_type' => 'DEBIT', 'card_last_4' => '4321', 'approval_code' => 'APR051', 'trace_number' => 'TAPR051'], 'Kasir lapor salah klik, slip EDC ada');

        // Kasir tutup shift: form langsung mengikuti metode yang sudah benar → cocok tanpa review.
        $this->actingAs($this->cashier);
        $component = Livewire::test(FnbCashierTerminal::class)->call('prepareCloseShift');
        $this->assertEqualsCanonicalizing(['EDC_BCA_DEBIT', 'EDC_BCA_CREDIT'], array_column($component->get('closingRows'), 'key'));
        $this->close(['EDC_BCA_DEBIT' => $a], ['EDC_BCA' => 'SETTLE'])->assertSet('showShiftReportModal', true);
        $this->assertSame(PosCashierShift::REVIEW_MATCHED, $shift->fresh()->review_status);
    }

    public function test_a_misclick_found_only_at_closing_shows_the_moved_row_as_not_typed_by_the_cashier(): void
    {
        $shift = $this->openShift();
        $wrong = $this->pay('QRIS', ['qrisProvider' => 'BCA_QRIS', 'qrisRrn' => '123456789023']);
        $a = (int) $wrong->amount;

        // Baris debit tidak ada di form (EDC tidak tercatat di shift ini) → kasir jelaskan di catatan. Aplikasi QRIS
        // menunjukkan 5.000 (transaksi lain yang tidak dicatat) — angka ketikan itu tidak boleh hilang setelah koreksi.
        $this->close(['QRIS_BCA_QRIS' => 5000], [])->assertSet('closingMismatch', fn ($m) => $m !== null);
        $this->close([], [], 'Yang ini sebenarnya debit EDC BCA, slip ada')->assertSet('showShiftReportModal', true);

        app(PaymentMethodCorrection::class)->apply($wrong, $this->admin, 'EDC_BCA', ['card_type' => 'DEBIT', 'card_last_4' => '4321', 'approval_code' => 'APR052', 'trace_number' => 'TAPR052'], 'Salah klik kasir, slip EDC ada');

        $rows = collect($shift->fresh()->settlement_reconciliation)->keyBy('key');
        $this->assertTrue($rows['QRIS_BCA_QRIS']['typed_by_cashier']);
        $this->assertEquals(0, $rows['QRIS_BCA_QRIS']['system_amount']);
        $this->assertEquals(5000, $rows['QRIS_BCA_QRIS']['difference'], 'ketikan kasir tetap dihitung walau transaksinya dipindah');
        $this->assertFalse($rows['EDC_BCA_DEBIT']['typed_by_cashier']);
        $this->assertEquals($a, $rows['EDC_BCA_DEBIT']['system_amount']);

        $this->actingAs($this->admin);
        Livewire::test(DetailShiftKasir::class, ['shift' => $shift->id])->assertSee('tidak diketik');
        $this->assertSame(PosCashierShift::REVIEW_NEEDS_REVIEW, $shift->fresh()->review_status, 'tetap diputuskan admin');
    }

    public function test_corrections_are_refused_for_gateway_payments_final_shifts_and_own_transactions(): void
    {
        $shift = $this->openShift();
        $payment = $this->pay('QRIS', ['qrisProvider' => 'BCA_QRIS', 'qrisRrn' => '123456789013']);
        $proof = ['card_type' => 'DEBIT', 'card_last_4' => '1111', 'approval_code' => 'APR030', 'trace_number' => 'TAPR030'];

        // Admin yang juga mencatat transaksi itu sebagai kasir tidak mengoreksi transaksinya sendiri.
        $selfRecorded = clone $payment;
        $log = $payment->payload_log;
        $log['cashier_id'] = $this->admin->id;
        Payment::whereKey($payment->id)->update(['payload_log' => json_encode($log)]);
        try {
            app(PaymentMethodCorrection::class)->apply($payment->fresh(), $this->admin, 'EDC_BCA', $proof, 'salah klik kasir');
            $this->fail('Pencatat tidak boleh mengoreksi transaksinya sendiri');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Payment::whereKey($payment->id)->update(['payload_log' => json_encode($selfRecorded->payload_log)]);

        // QR / VA otomatis: diverifikasi gateway.
        $auto = $payment->replicate();
        $auto->transaction_id = 'AUTO-1';
        $auto->payload_log = ['pos_qris' => ['method' => 'QRIS'], 'qris_details' => ['provider' => 'DOKU_QRIS']];
        $auto->save();
        $this->assertNotNull(PaymentMethodCorrection::blockedReason($auto));

        // Shift yang sudah final (cocok / disetujui) tidak bisa dikoreksi.
        $shift->update(['status' => 'CLOSED', 'review_status' => PosCashierShift::REVIEW_MATCHED]);
        $this->assertNotNull(PaymentMethodCorrection::blockedReason($payment->fresh()));
        $this->expectException(HttpException::class);
        app(PaymentMethodCorrection::class)->apply($payment->fresh(), $this->admin, 'EDC_BCA', $proof, 'salah klik kasir');
    }

    // ── Review ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_review_needs_a_second_person_and_a_note(): void
    {
        $shift = $this->openShift();
        $a = (int) $this->pay('QRIS', ['qrisProvider' => 'BCA_QRIS', 'qrisRrn' => '123456789014'])->amount;
        $this->close(['QRIS_BCA_QRIS' => $a - 1000], []);
        $this->close([], [], 'Kurang seribu, cek mutasi besok');
        $service = app(ShiftClosingService::class);

        foreach ([[$this->cashier, 403], [$this->admin, 422]] as [$user, $code]) {
            try {
                $service->review($shift->id, $user, true, $code === 422 ? '' : 'disetujui');
                $this->fail('harus ditolak');
            } catch (HttpException $e) {
                $this->assertSame($code, $e->getStatusCode());
            }
        }

        // Penutup shift (kalau dia admin) tidak memutuskan shift-nya sendiri.
        $shift->update(['closed_by_id' => $this->admin->id]);
        try {
            $service->review($shift->id, $this->admin, true, 'Sudah dicek, aman');
            $this->fail('penutup shift tidak boleh mereview sendiri');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $shift->update(['closed_by_id' => $this->cashier->id]);

        $this->actingAs($this->admin);
        Livewire::test(DetailShiftKasir::class, ['shift' => $shift->id])
            ->assertSee($shift->shift_number)
            ->assertActionHasUrl('back', ReviewShiftKasir::getUrl())
            ->callAction('approve', ['notes' => 'Mutasi QRIS dicek, selisih biaya admin bank'])
            ->assertHasNoActionErrors();

        $shift->refresh();
        $this->assertSame(PosCashierShift::REVIEW_APPROVED, $shift->review_status);
        $this->assertSame($this->admin->id, $shift->reviewed_by_id);
        $this->assertTrue(ActivityLog::where('event', 'shift.review_approved')->exists());

        // Struk Z-Report rusak → admin cetak ulang: isi dari data tersimpan, bertanda CETAK ULANG, tercatat di log.
        Livewire::test(DetailShiftKasir::class, ['shift' => $shift->id])
            ->mountAction('reprintZ')
            ->assertActionMounted('reprintZ')
            ->assertMountedActionModalSeeHtml('id="printable-z-report"')
            ->assertMountedActionModalSee(['*** CETAK ULANG ***', 'DISETUJUI ADMIN', 'Mutasi QRIS dicek, selisih biaya admin bank', $shift->shift_number]);
        $this->assertTrue(ActivityLog::where('event', 'shift.z_report_reprinted')->where('subject_id', $shift->id)->exists());

        try {
            $service->review($shift->id, $this->admin, false, 'Ditolak lagi');
            $this->fail('shift yang sudah diputuskan tidak bisa direview lagi');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_review_pages_are_for_shift_approvers_only(): void
    {
        $this->actingAs($this->admin);
        $this->get(ReviewShiftKasir::getUrl())->assertOk();

        $this->actingAs($this->cashier);
        $this->get(ReviewShiftKasir::getUrl())->assertDenied();
        $this->assertContains('approve_shift_closing', Club61PermissionMatrix::defaultRolePermissions('admin'));
        $this->assertNotContains('approve_shift_closing', Club61PermissionMatrix::defaultRolePermissions('cashier'));
        $this->assertNotContains('approve_shift_closing', Club61PermissionMatrix::BACKDOOR_PERMISSIONS);
    }

    // ── Pengingat ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_opening_warns_about_edc_left_unsettled_from_yesterday_and_records_the_settlement(): void
    {
        // Kemarin: batch EDC BCA dimulai (BASELINE) lalu shift ditutup tanpa settle.
        $this->travel(-1)->days();
        $this->openShift();
        $a = (int) $this->debit('APR040')->amount;
        $this->close(['EDC_BCA_DEBIT' => $a], ['EDC_BCA' => 'AUDIT']);
        $this->travelBack();

        $this->actingAs($this->cashier);
        $component = Livewire::test(FnbCashierTerminal::class)->call('openShiftModal');
        $this->assertSame(['EDC_BCA' => 'EDC BCA'], $component->instance()->unsettledEdc);
        $component->assertSee('belum di-settle sejak kemarin')
            ->set('settledAtOpening.EDC_BCA', true)
            ->call('executeOpenShift');

        $this->assertTrue(PosEdcSettlement::where('source', 'OPENING')->where('terminal', 'EDC_BCA')->exists());
        $this->assertSame([], app(ShiftClosingService::class)->unsettledFromPreviousDays('FNB_COUNTER'));
    }

    public function test_a_shift_left_open_since_yesterday_is_flagged_on_the_pos_screen(): void
    {
        $this->travel(-1)->days();
        $shift = $this->openShift();
        $this->travelBack();

        $warnings = app(ShiftClosingService::class)->warnings($shift);
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('dibuka sejak', $warnings[0]);

        $this->actingAs($this->cashier);
        Livewire::test(FnbCashierTerminal::class)->assertSee('dibuka sejak');
    }
}
