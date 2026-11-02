<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\JualMembership;
use App\Livewire\Pos\FnbCashierTerminal;
use App\Models\Audit\ActivityLog;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Pos\DailySalesRecapService;
use App\Services\Pos\ShiftClosingService;
use App\Support\ReceiptPaper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rekap Penjualan Harian (X-Report) di POS: periode dari shift pertama hari itu, angka per metode = kategori form
 * Tutup Shift, pembayaran online terpisah, izin print_sales_recap, tercatat di log & Z-Report, baris muat kertas.
 */
class DailySalesRecapTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->travelTo(CarbonImmutable::parse('2026-10-26 14:30', 'Asia/Jakarta'));
        $this->cashier = User::factory()->cashier()->create(['name' => 'Kasir Rina', 'is_active' => true]);
    }

    private function shift(string $counter, string $openedAt, ?string $closedAt = null): PosCashierShift
    {
        return PosCashierShift::create([
            'shift_number' => 'SFT-'.$counter.'-'.str_replace([' ', ':', '-'], '', $openedAt),
            'counter' => $counter,
            'status' => $closedAt ? 'CLOSED' : 'OPEN',
            'opened_by_id' => $this->cashier->id,
            'opened_at' => CarbonImmutable::parse($openedAt, 'Asia/Jakarta'),
            'closed_at' => $closedAt ? CarbonImmutable::parse($closedAt, 'Asia/Jakarta') : null,
            'starting_cash' => 0,
            'expected_cash' => 0,
        ]);
    }

    private function payment(?PosCashierShift $shift, float $amount, string $method, array $log = [], string $gateway = 'CASHIER_POS', string $status = 'SUCCESS', ?string $paidAt = null): Payment
    {
        static $n = 0;
        $n++;
        $order = Order::create(['order_number' => 'ORD-RCP-'.$n, 'order_type' => 'WALK_IN', 'subtotal' => $amount, 'grand_total' => $amount, 'payment_status' => 'PAID']);

        $payment = Payment::create([
            'order_id' => $order->id, 'pos_shift_id' => $shift?->id, 'payment_gateway' => $gateway, 'transaction_id' => 'ORD-RCP-'.$n,
            'amount' => $amount, 'payment_method' => $method, 'status' => $status, 'payload_log' => $log,
        ]);
        if ($paidAt) {
            Payment::query()->toBase()->where('id', $payment->id)->update(['paid_at' => CarbonImmutable::parse($paidAt, 'Asia/Jakarta')]);
        }

        return $payment;
    }

    private function edc(string $terminal, string $card): array
    {
        return ['edc_details' => ['terminal' => $terminal, 'card_type' => $card]];
    }

    private function build(string $counter = 'PADEL_FRONTDESK', string $date = '2026-10-26'): ?array
    {
        return app(DailySalesRecapService::class)->build($counter, $date, $this->cashier);
    }

    public function test_recap_covers_every_shift_opened_that_day_grouped_like_the_closing_form(): void
    {
        $morning = $this->shift('PADEL_FRONTDESK', '2026-10-26 08:02', '2026-10-26 13:00');
        $evening = $this->shift('PADEL_FRONTDESK', '2026-10-26 13:05');
        $this->payment($morning, 300000, 'QRIS', ['qris_details' => ['provider' => 'BCA_QRIS']]);
        $this->payment($morning, 150000, 'DEBIT_CARD', $this->edc('EDC_BCA', 'DEBIT'));
        $this->payment($evening, 100000, 'DEBIT_CARD', $this->edc('EDC_BCA', 'DEBIT'));
        $this->payment($evening, 150000, 'CREDIT_CARD', $this->edc('EDC_MANDIRI', 'CREDIT'));
        $this->payment($evening, 999000, 'QRIS', ['qris_details' => ['provider' => 'BCA_QRIS']], status: 'PENDING');

        // Bukan bagian rekap: shift kemarin & loket F&B.
        $yesterday = $this->shift('PADEL_FRONTDESK', '2026-10-25 09:00', '2026-10-25 22:00');
        $this->payment($yesterday, 77000, 'QRIS', ['qris_details' => ['provider' => 'BCA_QRIS']]);
        $fnb = $this->shift('FNB_COUNTER', '2026-10-26 08:00');
        $this->payment($fnb, 38000, 'QRIS', ['qris_details' => ['provider' => 'BCA_QRIS']]);

        $recap = $this->build();

        $this->assertSame('08:02', $recap['opened_at']);
        $this->assertSame('14:30', $recap['until']);
        $this->assertTrue($recap['still_open']);
        $this->assertSame(2, $recap['shift_count']);
        $this->assertSame([], $recap['running_shifts'], 'shift dibuka hari ini = rekap harian, tidak diulang');
        $this->assertSame(4, $recap['transactions']);
        $this->assertSame(700000.0, $recap['total']);
        $this->assertSame('Senin, 26/10/2026', $recap['date_label']);
        $this->assertSame([
            ['label' => 'Debit EDC BCA', 'count' => 2, 'amount' => 250000.0],
            ['label' => 'Kredit EDC Mandiri', 'count' => 1, 'amount' => 150000.0],
            ['label' => 'QRIS BCA', 'count' => 1, 'amount' => 300000.0],
        ], $recap['methods']);

        // Angka per metode = angka sistem form Tutup Shift (kategori yang sama).
        $closing = collect(app(ShiftClosingService::class)->systemRows($evening))->firstWhere('key', 'EDC_MANDIRI_CREDIT');
        $this->assertSame(150000.0, (float) $closing['system_amount']);
    }

    public function test_period_ends_at_the_last_closing_when_every_shift_is_closed(): void
    {
        $this->shift('PADEL_FRONTDESK', '2026-10-26 08:00', '2026-10-26 12:15');

        $recap = $this->build();

        $this->assertFalse($recap['still_open']);
        $this->assertSame('12:15', $recap['until']);
    }

    public function test_no_shift_that_day_means_no_recap(): void
    {
        $this->assertNull($this->build());
    }

    public function test_shift_left_open_since_an_earlier_day_is_recapped_per_calendar_day(): void
    {
        // Shift dibuka 24/10 dan lupa ditutup sampai sekarang (26/10 14:30).
        $shift = $this->shift('PADEL_FRONTDESK', '2026-10-24 15:05');
        $qris = ['qris_details' => ['provider' => 'BCA_QRIS']];
        $this->payment($shift, 100000, 'QRIS', $qris, paidAt: '2026-10-24 16:00');
        $this->payment($shift, 200000, 'QRIS', $qris, paidAt: '2026-10-25 10:00');
        $this->payment($shift, 300000, 'QRIS', $qris, paidAt: '2026-10-26 09:00');

        // Hari ini: hanya pembayaran hari ini, periode mulai 00:00, ada tanda shift belum ditutup.
        $today = $this->build();
        $this->assertSame([1, 300000.0, '00:00', '14:30', true], [$today['transactions'], $today['total'], $today['opened_at'], $today['until'], $today['still_open']]);
        $this->assertSame([['since' => '24/10 15:05', 'still_open' => true]], $today['carried_over']);
        $html = view('pos.partials.sales-recap-body', ['recap' => $today])->render();
        $this->assertStringContainsString('Shift sejak 24/10 15:05', $html);
        $this->assertStringContainsString('BELUM DITUTUP', $html);

        // Plus total seluruh shift yang belum ditutup (24/10 s/d sekarang), kelompok metode = form Tutup Shift.
        $this->assertSame([[
            'shift_number' => $shift->shift_number, 'since' => '24/10 15:05', 'transactions' => 3, 'total' => 600000.0,
            'methods' => [['label' => 'QRIS BCA', 'count' => 3, 'amount' => 600000.0]],
        ]], $today['running_shifts']);
        $this->assertSame(600000.0, (float) collect(app(ShiftClosingService::class)->systemRows($shift))->firstWhere('key', 'QRIS_BCA_QRIS')['system_amount']);
        $this->assertStringContainsString('SHIFT BELUM DITUTUP', $html);
        $this->assertStringContainsString('Sejak 24/10 15:05 s/d skrg', $html);
        $this->assertStringContainsString('Rp 600.000', $html);
        $this->assertLessThanOrEqual(ReceiptPaper::PC_COLUMNS, mb_strlen('Sejak 24/10 15:05 s/d skrg'));

        // Hari-hari sebelumnya: masing-masing hanya pembayaran tanggal itu (dulu tanggal buka shift ikut menghitung semuanya).
        $first = $this->build(date: '2026-10-24');
        $this->assertSame([1, 100000.0, '15:05', '23:59', []], [$first['transactions'], $first['total'], $first['opened_at'], $first['until'], $first['carried_over']]);
        $middle = $this->build(date: '2026-10-25');
        $this->assertSame([1, 200000.0, '00:00', '23:59'], [$middle['transactions'], $middle['total'], $middle['opened_at'], $middle['until']]);

        // Ditutup hari ini → rekap hari ini berakhir saat ditutup; tanggal yang belum tiba tidak punya rekap.
        $shift->update(['status' => 'CLOSED', 'closed_at' => CarbonImmutable::parse('2026-10-26 12:00', 'Asia/Jakarta')]);
        $closed = $this->build();
        $this->assertSame(['12:00', false, 300000.0], [$closed['until'], $closed['still_open'], $closed['total']]);
        $this->assertSame([['since' => '24/10 15:05', 'still_open' => false]], $closed['carried_over']);
        $this->assertSame([], $closed['running_shifts'], 'sudah ditutup → total shift ada di Z-Report');
        $this->assertNull($this->build(date: '2026-10-27'));
        $this->assertNull($this->build(date: '2026-10-23'));
    }

    public function test_online_payments_are_listed_separately_for_the_padel_counter_only(): void
    {
        $shift = $this->shift('PADEL_FRONTDESK', '2026-10-26 08:00');
        $this->payment($shift, 100000, 'QRIS', ['qris_details' => ['provider' => 'BCA_QRIS']]);
        $this->payment(null, 200000, 'BCA_VA', gateway: 'MIDTRANS', paidAt: '2026-10-26 10:00');
        $this->payment(null, 120000, 'QRIS', gateway: 'DOKU', paidAt: '2026-10-26 11:00');
        $this->payment(null, 50000, 'BCA_VA', gateway: 'MIDTRANS', paidAt: '2026-10-26 07:00'); // sebelum loket buka
        $this->payment(null, 0, 'MEMBERSHIP_QUOTA', gateway: 'MEMBERSHIP_QUOTA', paidAt: '2026-10-26 10:00');

        $recap = $this->build();

        $this->assertSame(100000.0, $recap['total'], 'online tidak masuk total kasir');
        $this->assertSame(2, $recap['online']['transactions']);
        $this->assertSame(320000.0, $recap['online']['total']);
        $this->assertContains('BCA Virtual Account', array_column($recap['online']['methods'], 'label'));

        $this->shift('FNB_COUNTER', '2026-10-26 08:00');
        $this->assertNull($this->build('FNB_COUNTER')['online']);
    }

    public function test_every_printed_line_fits_the_receipt_paper(): void
    {
        $shift = $this->shift('PADEL_FRONTDESK', '2026-10-26 08:00');
        $this->payment($shift, 123456789, 'CREDIT_CARD', $this->edc('EDC_MANDIRI', 'CREDIT'));
        $this->payment($shift, 1000, 'QRIS', ['qris_details' => ['provider' => 'DOKU_QRIS']]);
        $this->payment($shift, 1000, 'QRIS', ['qris_details' => ['provider' => 'PENYEDIA_QRIS_BARU_YANG_NAMANYA_PANJANG']]);
        $this->payment($shift, 0, 'MEMBERSHIP_AND_SPONSOR_VOUCHER');
        $this->payment(null, 5000, 'SOME_VERY_LONG_ONLINE_METHOD_CODE_FROM_GATEWAY', gateway: 'MIDTRANS', paidAt: '2026-10-26 09:00');
        $this->cashier->forceFill(['name' => 'Kasir Dengan Nama Yang Sangat Panjang Sekali'])->save();

        $recap = $this->build();
        $html = view('pos.partials.sales-recap-body', ['recap' => $recap])->render();

        // Baris label metode: muat satu baris kertas PC (kolom tersempit).
        foreach (array_merge($recap['methods'], $recap['online']['methods']) as $method) {
            $this->assertLessThanOrEqual(ReceiptPaper::PC_COLUMNS, mb_strlen($method['label']), $method['label']);
        }
        // Baris "N transaksi .... Rp X" & "Oleh: nama" juga muat.
        $this->assertLessThanOrEqual(ReceiptPaper::PC_COLUMNS, mb_strlen('999 transaksi Rp 123.456.789'));
        $this->assertLessThanOrEqual(ReceiptPaper::PC_COLUMNS, mb_strlen('Oleh: '.$recap['printed_by']));
        $this->assertStringContainsString('id="printable-sales-recap"', $html);
        $this->assertStringContainsString('Rp 123.456.789', $html);
        $this->assertStringContainsString('BUKAN LAPORAN PENUTUPAN', $html);
    }

    public function test_cashier_opens_the_recap_from_walk_in_history_and_it_is_logged(): void
    {
        $shift = $this->shift('PADEL_FRONTDESK', '2026-10-26 08:00');
        $this->payment($shift, 250000, 'QRIS', ['qris_details' => ['provider' => 'BCA_QRIS']]);
        $this->actingAs($this->cashier);

        Livewire::test(BookOfflineCourt::class)
            ->call('showHistory')
            ->assertSee('Cetak Rekap')
            ->call('openSalesRecap')
            ->assertSet('showSalesRecapModal', true)
            ->assertSee('REKAP PENJUALAN HARIAN')
            ->assertSee('Rp 250.000');

        $log = ActivityLog::where('event', DailySalesRecapService::LOG_EVENT)->firstOrFail();
        $this->assertSame((string) $shift->id, (string) $log->subject_id);
        $this->assertSame(1, DailySalesRecapService::viewsDuringShift($shift));
        $this->assertSame(1, ShiftClosingService::reportData($shift)['sales_recap_views']);
    }

    public function test_recap_follows_the_selected_history_date(): void
    {
        $yesterday = $this->shift('PADEL_FRONTDESK', '2026-10-25 09:00', '2026-10-25 22:00');
        $this->payment($yesterday, 77000, 'QRIS', ['qris_details' => ['provider' => 'BCA_QRIS']], paidAt: '2026-10-25 15:00');
        $this->actingAs($this->cashier);

        Livewire::test(BookOfflineCourt::class)
            ->call('showHistory')
            ->set('historyDate', '2026-10-25')
            ->call('openSalesRecap')
            ->assertSet('salesRecapData.date', '2026-10-25')
            ->assertSee('Rp 77.000')
            ->assertSee('Semua shift tutup');
    }

    public function test_membership_and_fnb_pos_have_the_recap_too(): void
    {
        $this->shift('PADEL_FRONTDESK', '2026-10-26 08:00');
        $this->shift('FNB_COUNTER', '2026-10-26 08:00');
        $this->cashier->givePermissionTo(['View:JualMembership', 'sell_membership']);
        $this->actingAs($this->cashier);

        Livewire::test(JualMembership::class)
            ->call('showHistory')
            ->call('openSalesRecap')
            ->assertSet('salesRecapData.counter', 'PADEL_FRONTDESK');

        Livewire::test(FnbCashierTerminal::class)
            ->set('posStep', 'history')
            ->assertSee('Cetak Rekap')
            ->call('openSalesRecap')
            ->assertSet('salesRecapData.counter', 'FNB_COUNTER')
            ->assertSee('Kasir F&amp;B', false);
    }

    public function test_without_permission_the_button_is_hidden_and_the_server_refuses(): void
    {
        $this->shift('PADEL_FRONTDESK', '2026-10-26 08:00');
        $this->cashier->revokePermissionTo(DailySalesRecapService::PERMISSION);
        $this->cashier->roles->each->revokePermissionTo(DailySalesRecapService::PERMISSION);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->cashier->fresh());

        Livewire::test(BookOfflineCourt::class)
            ->call('showHistory')
            ->assertDontSee('Cetak Rekap')
            ->call('openSalesRecap')
            ->assertSet('showSalesRecapModal', false)
            ->assertSet('salesRecapData', null);

        $this->assertFalse(ActivityLog::where('event', DailySalesRecapService::LOG_EVENT)->exists());
    }

    public function test_recap_numbers_cannot_be_replaced_from_the_browser(): void
    {
        $this->shift('PADEL_FRONTDESK', '2026-10-26 08:00');
        $this->actingAs($this->cashier);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(BookOfflineCourt::class)->call('openSalesRecap')->set('salesRecapData.total', 1);
    }

    public function test_cashier_and_receptionist_presets_include_the_permission(): void
    {
        $this->assertContains('print_sales_recap', Club61PermissionMatrix::defaultRolePermissions('cashier'));
        $this->assertContains('print_sales_recap', Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->assertContains('print_sales_recap', Club61PermissionMatrix::defaultRolePermissions('admin'));
        $this->assertNotContains('print_sales_recap', Club61PermissionMatrix::defaultRolePermissions('kitchen'));
        $this->assertTrue($this->cashier->can('print_sales_recap'));
    }
}
