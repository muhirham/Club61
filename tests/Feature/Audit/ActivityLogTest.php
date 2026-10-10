<?php

namespace Tests\Feature\Audit;

use App\Filament\Pages\KelolaPemesanan;
use App\Filament\Pages\LogAktivitas;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\Audit\ActivityLog;
use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\PosCashierShift;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Fnb\FnbPosService;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Modul 16 — Log Aktivitas. Mengikuti rencana pengujian di PRD_MODUL_16_ACTIVITY_AUDIT_LOG.md §7.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
    }

    private function menu(float $price = 38000): FnbMenu
    {
        $category = FnbCategory::create(['name' => 'Coffee', 'sort_order' => 1]);

        return FnbMenu::create([
            'category_id' => $category->id,
            'name' => 'Iced Latte',
            'base_price' => $price,
            'is_available' => true,
        ]);
    }

    private function paidBooking(): PadelBooking
    {
        $court = PadelCourt::create([
            'name' => 'Court Audit',
            'type' => 'INDOOR',
            'hourly_rate_regular' => 200000,
            'hourly_rate_prime' => 300000,
            'is_active' => true,
        ]);
        $start = Carbon::parse(now()->addDays(2)->format('Y-m-d').' 08:00:00');

        return PadelBooking::create([
            'booking_code' => 'BK-AUDIT-001',
            'user_id' => User::factory()->customer()->create()->id,
            'court_id' => $court->id,
            'booking_date' => $start->format('Y-m-d'),
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
            'court_fee' => 200000,
            'total_amount' => 200000,
            'status' => 'PAID',
            'qr_code_hash' => 'hash_audit',
        ]);
    }

    public function test_editing_menu_price_logs_only_the_changed_column_with_actor_snapshot(): void
    {
        $menu = $this->menu();
        $admin = User::factory()->admin()->create(['name' => 'Manager Rina']);
        $this->actingAs($admin);

        $menu->update(['base_price' => 42000, 'is_available' => true]);

        $log = ActivityLog::where('event', 'fnb_menu.updated')->sole();
        $this->assertSame(['base_price'], array_keys($log->changes));
        $this->assertEquals(38000, $log->changes['base_price']['old']);
        $this->assertEquals(42000, $log->changes['base_price']['new']);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('Manager Rina', $log->causer_name);
        $this->assertSame('admin', $log->causer_role);
        $this->assertSame('STAFF', $log->actor_type);
        $this->assertStringContainsString('Iced Latte', $log->description);
    }

    public function test_saving_without_real_changes_writes_no_log(): void
    {
        $menu = $this->menu();
        $this->actingAs(User::factory()->admin()->create());

        $menu->update(['base_price' => '38000.00']);

        $this->assertSame(0, ActivityLog::where('event', 'fnb_menu.updated')->count());
    }

    public function test_rolled_back_action_leaves_no_log(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        try {
            DB::transaction(function () {
                $this->menu();
                throw new \RuntimeException('gagal di tengah jalan');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, ActivityLog::where('event', 'fnb_menu.created')->count());
    }

    public function test_fnb_checkout_logs_paid_transaction_with_items_and_shares_batch_with_shift(): void
    {
        $menu = $this->menu();
        $cashier = User::factory()->cashier()->create(['name' => 'Kasir Dewi']);
        $this->actingAs($cashier);
        PosCashierShift::create([
            'shift_number' => 'SFT-FNB-AUDIT-0001',
            'counter' => 'FNB_COUNTER',
            'status' => 'OPEN',
            'opened_by_id' => $cashier->id,
            'opened_at' => now(),
            'starting_cash' => 0,
            'expected_cash' => 0,
        ]);

        $result = app(FnbPosService::class)->checkout(
            items: [['menu_id' => $menu->id, 'quantity' => 2]],
            orderType: 'DINE_IN',
            tableNumber: '07',
            cashier: $cashier,
            paymentMethod: 'QRIS',
            customerName: 'Pak Budi',
        );

        $log = ActivityLog::where('event', 'payment.paid')->sole();
        $this->assertSame('FINANCE', $log->module);
        $this->assertSame('Kasir Dewi', $log->causer_name);
        $this->assertStringContainsString($result['order']->order_number, $log->description);
        $this->assertStringContainsString('Kasir F&B', $log->description);
        $this->assertSame('QRIS', $log->meta['metode_bayar']);
        $this->assertSame('07', $log->meta['meja']);
        $this->assertSame('Pak Budi', $log->meta['pelanggan']);
        $this->assertStringContainsString('Iced Latte x2', $log->meta['item'][0]);
        $this->assertArrayNotHasKey('payload_log', $log->meta);

        $shiftLog = ActivityLog::where('event', 'shift.opened')->sole();
        $this->assertSame($shiftLog->batch_id, $log->batch_id, 'Satu request = satu batch');
    }

    public function test_refund_by_super_admin_is_logged_as_critical_with_amount_and_reason(): void
    {
        $booking = $this->paidBooking();
        $owner = User::factory()->superAdmin()->create(['name' => 'Owner']);
        $this->actingAs($owner);

        $result = app(PadelBookingService::class)->requestCancelAndRefund($booking->id, 'CUACA', 'Lapangan bocor', $owner);

        $log = ActivityLog::where('event', 'booking.refund_requested')->sole();
        $this->assertSame('CRITICAL', $log->severity);
        $this->assertStringContainsString(\App\Services\Audit\ActivityLogger::rupiah($result['refund_amount']), $log->description);
        $this->assertStringContainsString('Antrian Refund', $log->description);
        $this->assertStringContainsString('BK-AUDIT-001', $log->description);
        $this->assertSame('Lapangan bocor', $log->meta['catatan']);
        $this->assertSame('Owner', $log->causer_name);
    }

    public function test_admin_refund_attempt_without_permission_is_logged_as_access_denied(): void
    {
        $booking = $this->paidBooking();
        // Bisa membuka Kelola Pemesanan, tapi tidak punya izin mengajukan refund.
        $role = \App\Models\Role::findOrCreate('lihat_pemesanan', 'web');
        $role->givePermissionTo('View:KelolaPemesanan');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['name' => 'Admin Nakal']);
        $user->assignRole($role);
        $this->actingAs($user);

        Livewire::test(KelolaPemesanan::class)
            ->set('cancelBookingId', $booking->id)
            ->set('refundNotes', 'mencoba refund tanpa izin')
            ->call('executeCancelRefund');

        $this->assertSame('PAID', $booking->fresh()->status);
        $log = ActivityLog::where('event', 'auth.access_denied')->sole();
        $this->assertSame('WARNING', $log->severity);
        $this->assertSame('Admin Nakal', $log->causer_name);
        $this->assertStringContainsString('request_refund_padel', $log->description);
        $this->assertSame('LIVEWIRE', $log->http_method, 'Endpoint update Livewire asli tetap dikenali');
        $this->assertSame(0, ActivityLog::where('event', 'booking.refund_requested')->count());
    }

    public function test_blocked_page_access_is_logged_once_per_minute(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(LogAktivitas::getUrl())->assertForbidden();
        $this->get(LogAktivitas::getUrl())->assertForbidden();

        $this->assertSame(1, ActivityLog::where('event', 'auth.access_denied')->count());
    }

    public function test_role_permission_change_logs_added_and_removed_permissions(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'web', 'home_route' => '/admin']);
        $role->syncPermissions(['view_padel_bookings', 'checkin_padel_ticket']);
        $this->actingAs($owner);

        $component = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()]);
        $data = $component->get('data');
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'permissions_')) {
                $data[$key] = [];
            }
        }
        $data['permissions_padel_bookings'] = ['view_padel_bookings', 'cancel_refund_padel'];
        $component->set('data', $data)->call('save')->assertHasNoErrors();

        $log = ActivityLog::where('event', 'role.permissions_changed')->sole();
        $this->assertSame('CRITICAL', $log->severity, 'Menambah izin refund (backdoor) harus kritis');
        $this->assertSame(['cancel_refund_padel'], $log->meta['izin_sensitif_ditambah']);
        $this->assertStringContainsString('[checkin_padel_ticket]', implode(' ', $log->meta['izin_dicabut']));
    }

    public function test_secrets_are_never_stored(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $user = User::factory()->create(['name' => 'Budi', 'password' => 'RahasiaLama123!']);
        $user->update(['password' => 'RahasiaBaru456!']);

        $log = ActivityLog::where('event', 'user.updated')->sole();
        $this->assertSame(['old' => ActivityLogger::REDACTED, 'new' => ActivityLogger::REDACTED], $log->changes['password']);
        $this->assertSame('WARNING', $log->severity);

        ActivityLogger::record('FINANCE', 'test.meta', 'uji redaksi', meta: [
            'snap_token' => 'SNAP-SECRET',
            'nested' => ['server_key' => 'SB-Mid-server-XYZ', 'qr_code_hash' => 'VNT-TICKET-ABC', 'aman' => 'boleh'],
            'payload_log' => ['signature_key' => 'abc'],
        ]);

        $everything = ActivityLog::all()->map(fn ($l) => json_encode([$l->changes, $l->meta]))->implode(' ');
        foreach (['RahasiaLama123!', 'RahasiaBaru456!', 'SNAP-SECRET', 'SB-Mid-server-XYZ', 'VNT-TICKET-ABC', $user->fresh()->password] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertStringContainsString('boleh', $everything);
    }

    public function test_failed_login_records_identifier_but_never_the_password(): void
    {
        $user = User::factory()->create(['email' => 'budi@club61.com']);

        $this->post('/login', ['email' => 'budi@club61.com', 'password' => 'SalahBanget999']);

        $log = ActivityLog::where('event', 'auth.login_failed')->sole();
        $this->assertSame('budi@club61.com', $log->meta['identitas_dicoba']);
        $this->assertSame('LOGIN_PAGE', $log->channel);
        $this->assertStringNotContainsString('SalahBanget999', json_encode($log->toArray()));

        $this->post('/login', ['email' => 'budi@club61.com', 'password' => 'password']);
        $this->assertSame(1, ActivityLog::where('event', 'auth.login')->where('causer_id', $user->id)->count());
    }

    public function test_logs_cannot_be_updated_or_deleted(): void
    {
        $log = ActivityLogger::record('SYSTEM', 'test.immutable', 'tidak boleh diubah');

        try {
            $log->update(['description' => 'dipalsukan']);
            $this->fail('Log tidak boleh bisa diubah');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Log tidak boleh bisa dihapus');
        } catch (LogicException) {
        }

        $this->assertSame('tidak boleh diubah', $log->fresh()->description);
    }

    public function test_only_super_admin_opens_the_log_panel_by_default(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get(LogAktivitas::getUrl())->assertOk();

        foreach (['admin', 'cashier', 'receptionist'] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(LogAktivitas::getUrl())->assertForbidden();
        }
    }

    public function test_actor_name_is_a_snapshot(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Nama Lama']);
        $this->actingAs($admin);
        $this->menu();

        $admin->update(['name' => 'Nama Baru']);
        $admin->delete();

        $this->assertSame('Nama Lama', ActivityLog::where('event', 'fnb_menu.created')->sole()->causer_name);
    }

    /**
     * Laravel TIDAK mengirim event CommandStarting/Finished saat unit test (hanya di CLI asli), jadi
     * event-nya dikirim manual di sini — persis seperti yang terjadi saat `php artisan ...` di server.
     */
    private function runAsArtisan(string $command, callable $callback): void
    {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput;
        event(new \Illuminate\Console\Events\CommandStarting($command, $input, $output));
        try {
            $callback();
        } finally {
            event(new \Illuminate\Console\Events\CommandFinished($command, $input, $output, 0));
        }
    }

    public function test_seeding_and_migrations_do_not_flood_the_log(): void
    {
        $this->runAsArtisan('db:seed', fn () => (new \Database\Seeders\DemoAccessSeeder)->run());
        $this->runAsArtisan('migrate', fn () => $this->menu());
        $this->assertSame(0, ActivityLog::count());

        // Setelah command selesai, pencatatan kembali normal.
        $this->actingAs(User::factory()->admin()->create());
        FnbMenu::first()->update(['base_price' => 50000]);
        $this->assertSame(1, ActivityLog::where('event', 'fnb_menu.updated')->count());
    }

    private function makeBookingPast(PadelBooking $booking): void
    {
        $booking->update(['start_time' => now()->subHours(3), 'end_time' => now()->subHours(2), 'booking_date' => now()->subHours(3)->toDateString()]);
    }

    public function test_scheduler_job_is_logged_as_system(): void
    {
        $this->makeBookingPast($this->paidBooking());

        $this->runAsArtisan('padel:release-expired-slots', fn () => $this->artisan('padel:release-expired-slots')->assertSuccessful());

        $log = ActivityLog::where('event', 'booking.no_show_expired')->sole();
        $this->assertNull($log->causer_id);
        $this->assertSame('SYSTEM', $log->actor_type);
        $this->assertSame('SCHEDULER', $log->channel);
        $this->assertSame(['BK-AUDIT-001'], $log->meta['kode_booking']);
    }

    public function test_system_sync_triggered_by_opening_a_page_is_not_blamed_on_the_user(): void
    {
        $this->makeBookingPast($this->paidBooking());
        $this->actingAs(User::factory()->admin()->create(['name' => 'Admin Pembuka']));

        app(PadelBookingService::class)->syncExpiredAndCompletedBookings();

        $log = ActivityLog::where('event', 'booking.no_show_expired')->sole();
        $this->assertNull($log->causer_id);
        $this->assertSame('SYSTEM', $log->actor_type);
        $this->assertSame('Admin Pembuka', $log->meta['dipicu_saat_dibuka_oleh']);
    }

    public function test_midtrans_webhook_payment_is_logged_with_webhook_actor(): void
    {
        $key = 'SB-Mid-server-AUDIT';
        config(['services.midtrans.server_key' => $key]);
        $customer = User::factory()->customer()->create(['name' => 'Customer Online']);
        $order = \App\Models\Pos\Order::create([
            'order_number' => 'ORD-PAD-AUDIT01',
            'user_id' => $customer->id,
            'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 200000,
            'grand_total' => 200000,
            'payment_status' => 'UNPAID',
        ]);
        \App\Models\Pos\Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'MIDTRANS',
            'transaction_id' => 'ORD-PAD-AUDIT01',
            'amount' => 200000,
            'payment_method' => 'BCA_VA',
            'status' => 'PENDING',
        ]);

        $this->postJson('/api/v1/padel/webhook/midtrans', [
            'order_id' => 'ORD-PAD-AUDIT01',
            'status_code' => '200',
            'gross_amount' => '200000.00',
            'signature_key' => hash('sha512', 'ORD-PAD-AUDIT01200200000.00'.$key),
            'transaction_status' => 'settlement',
            'payment_type' => 'bank_transfer',
        ])->assertOk();

        $log = ActivityLog::where('event', 'payment.paid')->sole();
        $this->assertNull($log->causer_id);
        $this->assertSame('WEBHOOK', $log->actor_type);
        $this->assertSame('WEBHOOK', $log->channel);
        $this->assertSame('Customer Online', $log->meta['pelanggan']);
        $this->assertStringNotContainsString($key, json_encode($log->toArray()));
    }

    public function test_log_panel_escapes_user_supplied_text_and_shows_detail(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $log = ActivityLogger::record('FNB', 'fnb_menu.updated', 'Mengubah Menu <script>alert(1)</script>', changes: [
            'name' => ['old' => '<img src=x onerror=alert(1)>', 'new' => 'Latte'],
        ]);

        Livewire::test(LogAktivitas::class)
            ->assertCanSeeTableRecords([$log])
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->mountTableAction('detail', $log)
            ->assertMountedActionModalSeeHtml('&lt;img src=x onerror=alert(1)&gt;')
            ->assertMountedActionModalSee('Sebelum')
            ->assertDontSeeHtml('<img src=x onerror=alert(1)>');
    }

    public function test_export_requires_its_own_permission_and_neutralizes_formulas(): void
    {
        $viewer = User::factory()->admin()->create();
        Role::findByName('admin', 'web')->givePermissionTo('View:LogAktivitas');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($viewer);

        Livewire::test(LogAktivitas::class)->assertTableActionHidden('export');

        $this->assertSame("'=HYPERLINK(\"http://evil\")", LogAktivitas::csvSafe('=HYPERLINK("http://evil")'));
        $this->assertSame("'+628123", LogAktivitas::csvSafe('+628123'));
        $this->assertSame('Latte', LogAktivitas::csvSafe('Latte'));
    }

    public function test_prune_removes_only_logs_past_retention_and_refuses_zero_months(): void
    {
        $old = ActivityLogger::record('SYSTEM', 'test.old', 'lama');
        ActivityLog::query()->toBase()->where('id', $old->id)->update(['created_at' => now()->subMonths(25)]);
        $fresh = ActivityLogger::record('SYSTEM', 'test.fresh', 'baru');

        config(['audit.retention_months' => 0]);
        $this->artisan('audit:prune')->assertFailed();
        $this->assertNotNull(ActivityLog::find($old->id));

        config(['audit.retention_months' => 24]);
        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertNull(ActivityLog::find($old->id));
        $this->assertNotNull(ActivityLog::find($fresh->id));
        $this->assertSame(1, ActivityLog::where('event', 'activity_log.pruned')->count());
    }

    public function test_shift_close_with_settlement_difference_is_flagged(): void
    {
        $cashier = User::factory()->cashier()->create();
        $this->actingAs($cashier);
        $shift = PosCashierShift::create([
            'shift_number' => 'SFT-PAD-AUDIT-0001',
            'counter' => 'PADEL_FRONTDESK',
            'status' => 'OPEN',
            'opened_by_id' => $cashier->id,
            'opened_at' => now(),
            'starting_cash' => 0,
            'expected_cash' => 0,
        ]);

        $shift->update(['status' => 'CLOSED', 'closed_at' => now(), 'total_sales' => 500000, 'total_transactions' => 3, 'settlement_difference' => -25000]);

        $log = ActivityLog::where('event', 'shift.closed')->sole();
        $this->assertSame('WARNING', $log->severity);
        $this->assertStringContainsString('SELISIH SETORAN -Rp 25.000', $log->description);
    }

    public function test_date_filter_uses_the_wib_calendar_day(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Carbon::setTestNow(Carbon::parse('2026-10-01 18:30:00', 'Asia/Jakarta'));
        $evening = ActivityLogger::record('SYSTEM', 'test.evening', 'kejadian 18:30 WIB');
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:00:00', 'Asia/Jakarta'));
        $yesterday = ActivityLogger::record('SYSTEM', 'test.yesterday', 'kejadian kemarin 20:00 WIB');
        Carbon::setTestNow(Carbon::parse('2026-10-01 19:00:00', 'Asia/Jakarta'));

        try {
            Livewire::test(LogAktivitas::class)
                ->filterTable('tanggal', ['dari' => '2026-10-01', 'sampai' => '2026-10-01'])
                ->assertCanSeeTableRecords([$evening])
                ->assertCanNotSeeTableRecords([$yesterday]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return array<int, array<int, string>> baris CSV tanpa BOM & header */
    private function csvRows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($csv, 3));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        $this->assertSame(LogAktivitas::CSV_HEADER, array_shift($rows));

        return $rows;
    }

    public function test_export_route_checks_permission_streams_filtered_csv_and_logs_the_count(): void
    {
        $viewer = User::factory()->admin()->create();
        Role::findByName('admin', 'web')->givePermissionTo('View:LogAktivitas');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($viewer)->get(route('admin.log-aktivitas.export'))->assertForbidden();
        $this->assertSame(0, ActivityLog::where('event', 'activity_log.exported')->count());

        $this->actingAs(User::factory()->superAdmin()->create());
        ActivityLogger::record('FINANCE', 'test.export', '=HYPERLINK("http://evil")', meta: ['catatan' => 'kutip "ganda" \\ miring']);
        ActivityLogger::record('FINANCE', 'test.export', 'baris kedua');
        ActivityLogger::record('FINANCE', 'test.export', 'baris ketiga');
        ActivityLogger::record('FNB', 'test.other', 'bukan keuangan');

        $response = $this->get(route('admin.log-aktivitas.export', ['module' => ['FINANCE']]));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(3, $rows);
        $this->assertSame(['test.export'], array_values(array_unique(array_column($rows, 5))));
        $evil = collect($rows)->firstWhere(6, "'=HYPERLINK(\"http://evil\")");
        $this->assertNotNull($evil, 'Formula harus dinetralkan');
        $this->assertSame('kutip "ganda" \\ miring', json_decode($evil[12], true)['catatan'], 'JSON dengan \\" tidak boleh rusak');

        // Batas baris: yang di-stream & yang dicatat sama-sama dipotong ke export_max_rows.
        config(['audit.export_max_rows' => 2]);
        $rows = $this->csvRows($this->get(route('admin.log-aktivitas.export', ['module' => ['FINANCE']]))->streamedContent());
        $this->assertCount(2, $rows);

        $counts = ActivityLog::where('event', 'activity_log.exported')->get()->map(fn ($log) => $log->meta['jumlah_baris'])->sort()->values()->all();
        $this->assertSame([2, 3], $counts);
        $this->assertSame(['module' => ['FINANCE']], ActivityLog::where('event', 'activity_log.exported')->first()->meta['filter']);

        // Parameter di luar whitelist ditolak.
        $this->get(route('admin.log-aktivitas.export', ['module' => ['HACK']]))->assertSessionHasErrors('module.0');
    }

    public function test_export_button_links_to_the_route_with_active_filters(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $url = Livewire::test(LogAktivitas::class)
            ->filterTable('module', ['FINANCE'])
            ->searchTable('refund')
            ->instance()
            ->exportUrl();

        $this->assertStringStartsWith(route('admin.log-aktivitas.export'), $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['FINANCE'], $query['module']);
        $this->assertSame('refund', $query['search']);
        $this->assertSame(now('Asia/Jakarta')->toDateString(), $query['dari']);
    }

    public function test_spoofed_livewire_header_on_api_route_cannot_fake_the_channel(): void
    {
        $this->withHeaders(['X-Livewire' => 'true'])->postJson('/api/v1/auth/login', [
            'login' => 'tidakada@club61.com',
            'password' => 'salah',
            'components' => [['snapshot' => json_encode(['memo' => ['path' => 'api/v1/padel/webhook/midtrans']])]],
        ])->assertStatus(401);

        $log = ActivityLog::where('event', 'auth.login_failed')->sole();
        $this->assertSame('CUSTOMER_WEB', $log->channel);
        $this->assertSame('GUEST', $log->actor_type);
        $this->assertSame('/api/v1/auth/login', $log->url);
        $this->assertSame('POST', $log->http_method);
    }

    public function test_invalid_utf8_input_still_writes_the_log(): void
    {
        $this->withHeaders(['User-Agent' => "Mozilla/5.0 \xFF\xFE rusak"])->postJson('/api/v1/auth/login', [
            'login' => 'tidakada@club61.com',
            'password' => 'salah',
        ])->assertStatus(401);

        $log = ActivityLog::where('event', 'auth.login_failed')->sole();
        $this->assertTrue(mb_check_encoding($log->user_agent, 'UTF-8'));
        $this->assertStringStartsWith('Mozilla/5.0', $log->user_agent);

        $direct = ActivityLogger::record('SYSTEM', 'test.utf8', "deskripsi \xC3\x28 rusak", meta: ["kunci\xFF" => ['nilai' => "abc\xFF"]]);
        $this->assertNotNull($direct, 'Byte rusak di meta tidak boleh membuat log hilang');
        $fresh = $direct->fresh();
        $this->assertTrue(mb_check_encoding($fresh->description, 'UTF-8'));
        $this->assertTrue(mb_check_encoding(json_encode($fresh->meta), 'UTF-8'));
    }

    public function test_repeated_failed_logins_write_at_most_one_row_per_minute(): void
    {
        User::factory()->create(['email' => 'budi@club61.com']);

        foreach (range(1, 3) as $attempt) {
            $this->post('/login', ['email' => 'budi@club61.com', 'password' => 'Salah'.$attempt]);
        }
        $this->assertSame(1, ActivityLog::where('event', 'auth.login_failed')->count());

        $this->travel(ActivityLogger::LOGIN_FAILED_WINDOW + 1)->seconds();
        $this->post('/login', ['email' => 'budi@club61.com', 'password' => 'Salah4']);

        $logs = ActivityLog::where('event', 'auth.login_failed')->orderBy('created_at')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(2, $logs->last()->meta['percobaan_tidak_dicatat']);
        $this->assertArrayNotHasKey('percobaan_tidak_dicatat', $logs->first()->meta);
        $this->assertNull($logs->first()->causer_id, 'Pemilik akun bukan pelaku login gagal');

        // API: identitas lain = kunci dedup lain, tetap maksimal satu baris per menit.
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['login' => 'lain@club61.com', 'password' => 'salah'])->assertStatus(401);
        }
        $this->assertSame(3, ActivityLog::where('event', 'auth.login_failed')->count());
    }

    public function test_lockout_is_always_logged(): void
    {
        User::factory()->create(['email' => 'budi@club61.com']);

        foreach (range(1, 7) as $attempt) {
            $this->post('/login', ['email' => 'budi@club61.com', 'password' => 'Salah'.$attempt]);
        }

        $this->assertSame(1, ActivityLog::where('event', 'auth.login_failed')->count());
        $this->assertSame(2, ActivityLog::where('event', 'auth.lockout')->count());
    }

    public function test_failed_api_login_does_not_blame_the_targeted_account(): void
    {
        $victim = User::factory()->create(['email' => 'korban@club61.com', 'name' => 'Korban']);

        $this->postJson('/api/v1/auth/login', ['login' => 'korban@club61.com', 'password' => 'salah'])->assertStatus(401);

        $log = ActivityLog::where('event', 'auth.login_failed')->sole();
        $this->assertNull($log->causer_id);
        $this->assertNull($log->causer_name);
        $this->assertSame('GUEST', $log->actor_type);
        $this->assertSame((string) $victim->id, $log->subject_id);
        $this->assertTrue($log->meta['akun_ditemukan']);
    }

    public function test_access_denied_never_throws_when_cache_fails(): void
    {
        \Illuminate\Support\Facades\Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache mati'));

        ActivityLogger::accessDenied('Akses ditolak: uji cache mati');

        $this->assertSame(1, ActivityLog::where('event', 'auth.access_denied')->count());
    }

    public function test_deadlock_inside_transaction_is_rethrown_but_other_errors_are_swallowed(): void
    {
        $error = new \RuntimeException('kolom tidak dikenal');
        ActivityLog::creating(function () use (&$error) {
            throw $error;
        });
        $this->assertNull(ActivityLogger::record('SYSTEM', 'test.error', 'error biasa ditelan'));

        $error = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        $this->expectException(\PDOException::class);
        DB::transaction(fn () => ActivityLogger::record('SYSTEM', 'test.deadlock', 'deadlock dilempar ulang'));
    }
}
