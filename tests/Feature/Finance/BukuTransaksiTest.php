<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\AntrianRefund;
use App\Filament\Pages\BukuTransaksi;
use App\Filament\Pages\LogAktivitas;
use App\Http\Controllers\Admin\LedgerExportController;
use App\Models\Audit\ActivityLog;
use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\Pos\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\LedgerReport;
use App\Services\Finance\LedgerWriter;
use App\Services\Finance\RefundQueueService;
use App\Services\Finance\TaxAndFeeService;
use App\Services\Payment\PaymentOrchestratorService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 17 Fase 2 — halaman Buku Transaksi, invoice salinan admin, export, dan Antrian Refund.
 */
class BukuTransaksiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        ClubFinanceSetting::getSettings()->update([
            'is_tax_enabled' => true, 'tax_name' => 'PB1', 'tax_type' => 'PERCENTAGE', 'tax_rate' => 10, 'tax_channels' => 'ALL',
            'is_admin_fee_enabled' => true, 'admin_fee_name' => 'Biaya Layanan', 'admin_fee_type' => 'PERCENTAGE',
            'admin_fee_amount' => 3, 'admin_fee_channels' => 'ALL',
        ]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);
        LedgerReport::flushMemo();

        $this->owner = User::factory()->superAdmin()->create(['name' => 'Owner']);
        $this->customer = User::factory()->customer()->create(['name' => 'Budi Santoso', 'phone' => '081277001122']);
        PosCashierShift::create([
            'shift_number' => 'SFT-PADEL-BT-0001', 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->owner->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
    }

    /** Walk-in lapangan 300.000 + raket 50.000, diskon 35.000 → total 355.950 (contoh PRD §2), dilunasi di kasir. */
    /** $customerName = walk-in tanpa akun (nama diketik kasir); null = order milik akun customer. */
    private function paidWalkIn(?string $customerName = null, bool $withBooking = false): Order
    {
        $calc = app(TaxAndFeeService::class)->calculate(350000, 35000, 'POS_WALKIN', 'PADEL');
        $order = Order::create([
            'order_number' => 'ORD-BT-'.strtoupper(Str::random(8)), 'user_id' => $customerName === null ? $this->customer->id : null, 'cashier_id' => $this->owner->id,
            'customer_name' => $customerName, 'order_type' => 'WALK_IN', 'subtotal' => $calc['subtotal'], 'discount_amount' => $calc['discount_amount'],
            'tax_amount' => $calc['tax_amount'], 'service_charge' => $calc['admin_fee_amount'], 'grand_total' => $calc['grand_total'],
            'payment_status' => 'UNPAID',
        ]);
        $order->items()->create(['item_type' => 'PADEL', 'item_name' => 'Sewa Court 1', 'quantity' => 1, 'unit_price' => 300000, 'subtotal' => 300000]);
        $order->items()->create(['item_type' => 'EQUIPMENT', 'item_name' => 'Raket', 'quantity' => 1, 'unit_price' => 50000, 'subtotal' => 50000]);

        if ($withBooking) {
            $court = PadelCourt::create(['name' => 'Court BT', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
            $start = Carbon::parse(now()->addDay()->format('Y-m-d').' 10:00');
            PadelBooking::create([
                'booking_code' => 'BK-BT-'.Str::random(4), 'order_id' => $order->id, 'user_id' => $this->customer->id, 'court_id' => $court->id,
                'booking_date' => $start->toDateString(), 'start_time' => $start, 'end_time' => $start->copy()->addHour(),
                'court_fee' => 300000, 'total_amount' => $calc['grand_total'], 'status' => 'PENDING_PAYMENT',
            ]);
        }

        app(PaymentOrchestratorService::class)->markOrderAsPaid($order, [
            'payment_gateway' => 'CASHIER_POS', 'counter' => 'PADEL_FRONTDESK', 'payment_method' => 'QRIS', 'amount' => (float) $calc['grand_total'],
            'payload_log' => ['qris_details' => ['provider' => 'BCA_QRIS', 'rrn' => 'RRN'.random_int(100000, 999999)]],
        ]);

        return $order->fresh();
    }

    private function groupedRow(Order $order, string $type = LedgerEntry::TYPE_PAYMENT): LedgerEntry
    {
        return LedgerReport::grouped(LedgerEntry::query()->where('order_id', $order->id)->where('entry_type', $type))->firstOrFail();
    }

    private function adminWith(array $permissions): User
    {
        $admin = User::factory()->admin()->create();
        $admin->givePermissionTo($permissions);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    public function test_only_super_admin_opens_the_pages_by_default(): void
    {
        $this->actingAs($this->owner)->get(BukuTransaksi::getUrl())->assertOk();
        $this->actingAs($this->owner)->get(AntrianRefund::getUrl())->assertOk();

        foreach (['admin', 'cashier'] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(BukuTransaksi::getUrl())->assertDenied();
            $this->actingAs($user)->get(route('admin.buku-transaksi.export', ['format' => 'pdf']))->assertForbidden();
        }

        foreach (['View:BukuTransaksi', 'export_ledger', 'view_ledger_invoice'] as $permission) {
            $this->assertContains($permission, Club61PermissionMatrix::BACKDOOR_PERMISSIONS);
            $this->assertNotContains($permission, Club61PermissionMatrix::defaultRolePermissions('admin'));
        }

        // Antrian Refund (audit G11): admin menyetujui refund (dengan aturan dua orang); kasir & resepsionis tidak.
        $this->assertNotContains('process_refund_queue', Club61PermissionMatrix::BACKDOOR_PERMISSIONS);
        $this->assertContains('process_refund_queue', Club61PermissionMatrix::defaultRolePermissions('admin'));
        $this->actingAs(User::factory()->role('admin')->create())->get(AntrianRefund::getUrl())->assertOk();
        foreach (['cashier', 'receptionist'] as $role) {
            $this->assertNotContains('process_refund_queue', Club61PermissionMatrix::defaultRolePermissions($role));
            $this->actingAs(User::factory()->role($role)->create())->get(AntrianRefund::getUrl())->assertDenied();
        }
    }

    public function test_a_refund_is_decided_by_a_second_person_unless_super_admin(): void
    {
        $order = $this->paidWalkIn();
        $paymentId = Payment::where('order_id', $order->id)->value('id');
        $refundBy = fn (?User $requester) => Refund::create([
            'order_id' => $order->id, 'payment_id' => $paymentId, 'requested_by_id' => $requester?->id,
            'refund_amount' => 1000, 'reason' => 'Pembatalan booking', 'status' => 'PENDING',
        ]);
        $adminA = User::factory()->role('admin')->create(['name' => 'Admin A']);
        $adminB = User::factory()->role('admin')->create(['name' => 'Admin B']);
        $service = app(RefundQueueService::class);

        // Diajukan admin A → admin A tidak bisa memproses / menolak sendiri, tombolnya pun nonaktif.
        $own = $refundBy($adminA);
        foreach ([fn () => $service->process($own, $adminA, 'TRANSFER_BANK', 'TRF-OWN'), fn () => $service->reject($own, $adminA, 'Ditolak sendiri')] as $decide) {
            try {
                $decide();
                $this->fail('Pengaju tidak boleh memutuskan refund-nya sendiri');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('PENDING', $own->fresh()->status);
        $this->actingAs($adminA);
        Livewire::test(AntrianRefund::class)->assertTableActionDisabled('process', $own)->assertTableActionDisabled('reject', $own);

        // Admin B (orang kedua) bisa.
        $this->assertSame('PROCESSED', $service->process($own, $adminB, 'TRANSFER_BANK', 'TRF-B')->status);

        // super_admin pegang hak penuh: boleh memutuskan pengajuannya sendiri.
        $this->assertSame('PROCESSED', $service->process($refundBy($this->owner), $this->owner, 'TRANSFER_BANK', 'TRF-OWNER')->status);

        // Refund otomatis dari sistem (tanpa pengaju) → siapa pun yang berizin.
        $this->assertSame('PROCESSED', $service->process($refundBy(null), $adminA, 'TRANSFER_BANK', 'TRF-SYS')->status);
    }

    public function test_one_row_per_payment_and_summary_cards_follow_prd_definitions(): void
    {
        $order = $this->paidWalkIn();
        $this->actingAs($this->owner);

        $component = Livewire::test(BukuTransaksi::class)
            ->assertCountTableRecords(1)
            ->assertSee($order->order_number)
            ->assertSee('Sewa Lapangan Padel + Add-on Padel');

        $summary = $component->instance()->summary();
        $this->assertEquals(315000, $summary['net']);
        $this->assertEquals(9450, $summary['service']);
        $this->assertEquals(31500, $summary['tax']);
        $this->assertEquals(355950, $summary['money_net']);
        $this->assertSame(1, $summary['payments_count']);
    }

    public function test_grouped_table_never_orders_by_a_column_outside_the_group_by(): void
    {
        // Regresi error 500 di server (MySQL ONLY_FULL_GROUP_BY): Filament menambah ORDER BY ledger_entries.id ke query
        // GROUP BY per pembayaran. SQLite / MariaDB lokal tidak menolaknya, jadi yang diperiksa SQL-nya.
        $this->paidWalkIn();
        $this->actingAs($this->owner);

        $component = Livewire::test(BukuTransaksi::class);
        foreach ([null, 'asc', 'desc'] as $direction) {
            if ($direction) {
                $component->call('sortTable', 'occurred_at', $direction);
            }
            $orders = collect($component->instance()->getFilteredSortedTableQuery()->getQuery()->orders)
                ->map(fn (array $order) => $order['column'] ?? $order['sql'] ?? '')
                ->all();

            $this->assertNotEmpty($orders);
            foreach ($orders as $expression) {
                $this->assertMatchesRegularExpression('/^(MAX|MIN)\(/', (string) $expression, 'Urutan harus agregat: '.$expression);
            }
        }
        $component->assertCountTableRecords(1);
    }

    public function test_refund_shows_as_its_own_row_and_reduces_the_net_total(): void
    {
        $order = $this->paidWalkIn();
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        Refund::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'refund_amount' => 50850, 'reason' => 'Raket rusak', 'status' => 'PROCESSED']);
        $this->actingAs($this->owner);

        $component = Livewire::test(BukuTransaksi::class)->assertCountTableRecords(2)->assertSee('Direfund sebagian');
        $summary = $component->instance()->summary();
        $this->assertEquals(-50850, $summary['refunds']);
        $this->assertEquals(355950 - 50850, $summary['money_net']);
        // Kartu dihitung SETELAH refund dan bisa dijumlah: bersih + layanan + pajak = total uang masuk bersih.
        $this->assertEquals($summary['money_net'], $summary['net'] + $summary['service'] + $summary['tax']);
        $this->assertLessThan(315000, $summary['net']);
        $this->assertEquals(315000, $summary['net_before_refund']);

        Livewire::test(BukuTransaksi::class)->filterTable('status', ['REFUND'])->assertCountTableRecords(1);
    }

    public function test_changing_tax_or_fee_settings_later_does_not_change_past_transactions(): void
    {
        $order = $this->paidWalkIn();
        $before = LedgerEntry::where('order_id', $order->id)->orderBy('category')->get(['net_amount', 'service_amount', 'tax_amount', 'total_amount'])->toArray();

        ClubFinanceSetting::getSettings()->update(['tax_rate' => 11, 'admin_fee_amount' => 5]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);

        $this->assertSame($before, LedgerEntry::where('order_id', $order->id)->orderBy('category')->get(['net_amount', 'service_amount', 'tax_amount', 'total_amount'])->toArray());
        $this->assertEquals(31500, LedgerReport::summary(LedgerEntry::query())['tax']);
    }

    public function test_date_presets_use_wib_calendar_days(): void
    {
        $order = $this->paidWalkIn();
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'CASHIER_POS', 'transaction_id' => 'POS-LATE-NIGHT',
            'amount' => 100000, 'payment_method' => 'QRIS', 'status' => 'SUCCESS',
        ]);
        // 23:30 WIB kemarin — dulu jatuh ke hari berikutnya kalau batas hari dihitung UTC.
        $lateNight = Carbon::now(LedgerReport::TIMEZONE)->subDay()->setTime(23, 30)->setTimezone(config('app.timezone'));
        app(LedgerWriter::class)->recordPayment($payment, LedgerEntry::TYPE_OVERPAYMENT, $lateNight);

        $count = fn (string $preset) => LedgerReport::grouped(LedgerReport::applyFilters(LedgerEntry::query(), ['periode' => $preset]))->get()->count();
        $this->assertSame(1, $count('kemarin'));
        $this->assertSame(1, $count('hari_ini'));
        $this->assertSame(2, $count('7_hari'));

        $this->actingAs($this->owner);
        Livewire::test(BukuTransaksi::class)->filterTable('periode', ['preset' => 'kemarin'])->assertCountTableRecords(1)->assertSee('POS-LATE-NIGHT');
    }

    public function test_search_finds_booking_code_and_customer_phone(): void
    {
        $order = $this->paidWalkIn(withBooking: true);
        $code = PadelBooking::where('order_id', $order->id)->value('booking_code');

        foreach ([$code, '081277001122', $order->order_number] as $term) {
            $this->assertSame(1, LedgerReport::grouped(LedgerReport::applySearch(LedgerEntry::query(), $term))->get()->count(), $term);
        }
        $this->assertSame(0, LedgerReport::grouped(LedgerReport::applySearch(LedgerEntry::query(), 'tidak-ada'))->get()->count());
    }

    public function test_detail_slide_over_shows_split_proof_and_order_history(): void
    {
        $order = $this->paidWalkIn(withBooking: true);
        $this->actingAs($this->owner);

        Livewire::test(BukuTransaksi::class)
            ->mountTableAction('detail', $this->groupedRow($order))
            ->assertMountedActionModalSee('Pembagian per kategori')
            ->assertMountedActionModalSee('Add-on Padel')
            ->assertMountedActionModalSee('RRN QRIS')
            ->assertMountedActionModalSee('Riwayat pembayaran')
            ->assertMountedActionModalSee('Booking terkait');
    }

    public function test_invoice_is_an_admin_copy_and_is_logged(): void
    {
        $walkIn = $this->paidWalkIn(withBooking: true);
        $online = $this->paidWalkIn();
        $this->actingAs($this->owner);

        Livewire::test(BukuTransaksi::class)
            ->mountTableAction('invoice', $this->groupedRow($walkIn))
            ->assertMountedActionModalSee('SALINAN ADMIN')
            ->assertMountedActionModalSee($walkIn->order_number);

        Livewire::test(BukuTransaksi::class)
            ->mountTableAction('invoice', $this->groupedRow($online))
            ->assertMountedActionModalSee('SALINAN ADMIN')
            ->assertMountedActionModalSee('TOTAL DIBAYAR');

        $this->assertSame(2, ActivityLog::where('event', 'ledger.invoice_viewed')->count());
    }

    public function test_receipts_use_the_venue_address_from_website_content(): void
    {
        // Dulu struk kasir & Z-Report menulis "Jl. Karang Tengah Raya No. 61, Lebak Bulus" secara hardcode.
        \App\Models\Setting\CompanyProfileSetting::current()->update(['address_line' => 'Jl. Uji Alamat No. 61, Medan.']);
        Cache::forget(\App\Models\Setting\CompanyProfileSetting::CACHE_KEY);
        $walkIn = $this->paidWalkIn(withBooking: true);
        $online = $this->paidWalkIn();
        $this->actingAs($this->owner);

        foreach ([$walkIn, $online] as $order) {
            Livewire::test(BukuTransaksi::class)
                ->mountTableAction('invoice', $this->groupedRow($order))
                ->assertMountedActionModalSee('Jl. Uji Alamat No. 61, Medan')
                ->assertMountedActionModalDontSee('Lebak Bulus');
        }

        $this->assertStringNotContainsString('Lebak Bulus', file_get_contents(resource_path('views/filament/pages/book-offline-court.blade.php')));
    }

    public function test_invoice_and_export_need_their_own_permissions(): void
    {
        $this->paidWalkIn();
        $viewer = $this->adminWith(['View:BukuTransaksi']);
        $this->actingAs($viewer);

        Livewire::test(BukuTransaksi::class)
            ->assertTableActionHidden('invoice', $this->groupedRow(Order::firstOrFail()))
            ->assertTableActionHidden('exportXlsx')
            ->assertTableActionHidden('exportPdf');
        $this->get(route('admin.buku-transaksi.export', ['format' => 'pdf']))->assertForbidden();
        $this->get(route('admin.buku-transaksi.export', ['format' => 'xlsx']))->assertForbidden();
        $this->assertSame(0, ActivityLog::where('event', 'ledger.invoice_viewed')->count());
    }

    public function test_export_links_bypass_spa_navigation_so_the_file_downloads(): void
    {
        // Dulu: mode SPA panel mengambil URL export lewat fetch → isi XLSX tampil sebagai teks di browser.
        $panel = \Filament\Facades\Filament::getPanel('admin');
        foreach (['xlsx', 'pdf'] as $format) {
            $url = route('admin.buku-transaksi.export', ['format' => $format, 'periode' => 'bulan_ini']);
            $this->assertTrue(\Illuminate\Support\Str::is($panel->getSpaUrlExceptions(), $url), $url);
        }
        $this->assertTrue(\Illuminate\Support\Str::is($panel->getSpaUrlExceptions(), route('admin.log-aktivitas.export')));
    }

    /** @return array<string, array<int, array<int, mixed>>> nama lembar → baris */
    private function readXlsx(\Illuminate\Testing\TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();
        @unlink($path);

        return $sheets;
    }

    public function test_xlsx_export_has_both_sheets_neutralizes_formulas_and_omits_phone(): void
    {
        $order = $this->paidWalkIn('=HYPERLINK("http://evil")');
        $this->actingAs($this->owner);

        $response = $this->get(route('admin.buku-transaksi.export', ['format' => 'xlsx', 'periode' => 'hari_ini']))->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $sheets = $this->readXlsx($response);

        $this->assertSame(['Transaksi', 'Rincian Kategori'], array_keys($sheets));
        $this->assertCount(2, $sheets['Transaksi']);
        $this->assertCount(3, $sheets['Rincian Kategori'], 'Header + 2 baris kategori');
        $this->assertSame($order->order_number, $sheets['Transaksi'][1][2]);
        $this->assertSame('\'=HYPERLINK("http://evil")', $sheets['Transaksi'][1][5]);
        $this->assertEquals(355950, $sheets['Transaksi'][1][14]);
        $this->assertStringNotContainsString('081277001122', json_encode($sheets));

        $this->get(route('admin.buku-transaksi.export', ['format' => 'xlsx', 'source' => ['HACK']]))->assertSessionHasErrors('source.0');
        $this->get(route('admin.buku-transaksi.export', ['format' => 'csv']))->assertSessionHasErrors('format');
        $this->assertTrue(ActivityLog::where('event', 'ledger.exported')->exists());
    }

    /** Data PDF (format=pdf) — dirakit jadi file PDF di browser oleh resources/js/ledger-pdf.js. */
    private function pdfData(array $query = []): array
    {
        $response = $this->get(route('admin.buku-transaksi.export', ['format' => 'pdf'] + $query), ['Accept' => 'application/json'])->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_pdf_export_sends_the_report_data_for_the_browser(): void
    {
        $order = $this->paidWalkIn();
        $this->actingAs($this->owner);

        $data = $this->pdfData(['periode' => 'hari_ini']);

        $this->assertSame(1, $data['total']);
        $this->assertCount(1, $data['rows']);
        $this->assertStringStartsWith('riwayat-transaksi-', $data['file_name']);
        $this->assertStringEndsWith('.pdf', $data['file_name']);
        $this->assertSame($this->owner->name, $data['printed_by']);
        $this->assertEquals(355950, $data['summary']['money_net']);
        $this->assertSame('Sewa Lapangan Padel', $data['categories'][0]['name']);

        // Urutan kolom = tabel di ledger-pdf.js.
        [$time, $orderNumber, , , , , $method, , , , $total, $status] = $data['rows'][0];
        $this->assertSame($order->order_number, $orderNumber);
        $this->assertEquals(355950, $total);
        $this->assertSame('Lunas', $status);
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{2} \d{2}:\d{2}$#', $time);
        $this->assertNotSame('', $method);

        // Nomor HP & email customer tidak ikut (PRD §10 no. 5); export tercatat di Log Aktivitas.
        $this->assertStringNotContainsString('081277001122', json_encode($data));
        $this->assertTrue(ActivityLog::where('event', 'ledger.exported')->where('meta->format', 'pdf')->exists());

        // Halaman memuat modul perakit PDF hanya saat tombol ditekan (import dinamis), dengan filter aktif.
        $handler = Livewire::test(BukuTransaksi::class)->instance()->pdfClickHandler();
        $this->assertStringContainsString('import(', $handler);
        $this->assertStringContainsString('Club61LedgerPdf.exportLedgerPdf', $handler);
        $this->assertStringContainsString('format=pdf', $handler);
    }

    public function test_pdf_data_contains_every_row_in_chunks_with_one_refund_query_per_chunk(): void
    {
        $order = $this->paidWalkIn();
        $template = (array) \Illuminate\Support\Facades\DB::table('ledger_entries')->where('order_id', $order->id)->first();
        // Dulu PDF dompdf dibatasi 1.500 baris dan tetap jebol di 128 MB. Sekarang semua baris ikut.
        $count = LedgerExportController::PDF_CHUNK * 2 + 150;
        $paymentIds = [];
        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            \Illuminate\Support\Facades\DB::table('ledger_entries')->insert(array_map(function (int $i) use ($template, &$paymentIds) {
                $paymentIds[] = $paymentId = (string) Str::ulid();

                return ['id' => (string) Str::ulid(), 'payment_id' => $paymentId, 'dedupe_key' => 'bulk-'.$i, 'order_number' => 'ORD-BULK-'.$i,
                    'occurred_at' => now()->subMinutes($i)->format('Y-m-d H:i:s')] + $template;
            }, $chunk));
        }
        // Satu refund menunggu → kolom Status baris itu ikut berubah (refund dibaca per kelompok, bukan per baris).
        // refunds.payment_id ber-FK ke payments → baris itu diberi pembayaran sungguhan.
        $paymentRow = (array) \Illuminate\Support\Facades\DB::table('payments')->where('order_id', $order->id)->first();
        \Illuminate\Support\Facades\DB::table('payments')->insert(['id' => $paymentIds[10], 'transaction_id' => 'BULK-PAY-11'] + $paymentRow);
        Refund::create(['order_id' => $order->id, 'payment_id' => $paymentIds[10], 'refund_amount' => 1000, 'reason' => 'Uji', 'status' => 'PENDING']);
        $this->actingAs($this->owner);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $memoryBefore = memory_get_usage();
        $data = $this->pdfData(['periode' => 'semua']);
        $memoryUsed = memory_get_peak_usage() - $memoryBefore;
        $refundQueries = collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "refunds"'))->count();

        $this->assertSame($count + 1, $data['total']);
        $this->assertCount($count + 1, $data['rows'], 'semua transaksi ikut, tidak dipotong');
        $this->assertSame(1, collect($data['rows'])->pluck(1)->filter(fn ($n) => $n === 'ORD-BULK-11')->count());
        $this->assertSame('Lunas · refund menunggu', collect($data['rows'])->firstWhere(1, 'ORD-BULK-11')[11]);
        $this->assertCount($count + 1, array_unique(array_column($data['rows'], 1)), 'tidak ada baris dobel di batas kelompok');
        $this->assertLessThanOrEqual(5, $refundQueries, '1 query refund per 1.000 baris, bukan 2 query per baris');
        $this->assertLessThan(64 * 1024 * 1024, $memoryUsed, 'memori server tetap kecil');
    }
    public function test_refund_queue_processes_into_the_ledger_once_and_rejects_with_reason(): void
    {
        $order = $this->paidWalkIn();
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $first = Refund::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'refund_amount' => 50000, 'reason' => 'Kelebihan bayar', 'status' => 'PENDING']);
        $second = Refund::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'refund_amount' => 20000, 'reason' => 'Komplain', 'status' => 'PENDING']);
        $this->actingAs($this->owner);

        Livewire::test(AntrianRefund::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->callTableAction('process', $first, ['refund_method' => 'TRANSFER_BANK', 'refund_reference' => 'TRF-889900', 'admin_notes' => 'BCA'])
            ->assertHasNoTableActionErrors();

        $first->refresh();
        $this->assertSame('PROCESSED', $first->status);
        $this->assertSame($this->owner->id, $first->processed_by_id);
        $this->assertEquals(-50000, (float) LedgerEntry::where('refund_id', $first->id)->sum('total_amount'));
        $this->assertTrue(ActivityLog::where('event', 'refund.processed')->where('severity', 'CRITICAL')->exists());

        try {
            app(RefundQueueService::class)->process($first, $this->owner, 'TRANSFER_BANK', 'TRF-2');
            $this->fail('Refund yang sudah diproses tidak boleh diproses lagi');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertEquals(-50000, (float) LedgerEntry::where('refund_id', $first->id)->sum('total_amount'));

        Livewire::test(AntrianRefund::class)
            ->callTableAction('reject', $second, ['reason' => 'Customer membatalkan permintaan'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('REJECTED', $second->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('refund_id', $second->id)->count());
        $this->assertTrue(ActivityLog::where('event', 'refund.rejected')->exists());
    }

    public function test_refund_queue_requires_permission_on_the_server(): void
    {
        $order = $this->paidWalkIn();
        $refund = Refund::create([
            'order_id' => $order->id, 'payment_id' => Payment::where('order_id', $order->id)->value('id'),
            'refund_amount' => 1000, 'reason' => 'x', 'status' => 'PENDING',
        ]);

        try {
            // Kasir tidak pernah punya izin memutuskan refund (admin sekarang punya — audit G11).
            $cashier = User::factory()->role('cashier')->create();
            $cashier->givePermissionTo('View:BukuTransaksi');
            app(RefundQueueService::class)->process($refund, $cashier, 'TRANSFER_BANK', 'TRF-1');
            $this->fail('Tanpa izin process_refund_queue harus ditolak');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('PENDING', $refund->fresh()->status);
    }

    public function test_activity_log_link_opens_filtered_by_order(): void
    {
        $this->actingAs($this->owner);

        Livewire::withQueryParams(['cari' => 'ORD-BT-XYZ'])
            ->test(LogAktivitas::class)
            ->assertSet('tableSearch', 'ORD-BT-XYZ');
    }
}
