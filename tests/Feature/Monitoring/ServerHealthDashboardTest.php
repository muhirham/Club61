<?php

namespace Tests\Feature\Monitoring;

use App\Livewire\Monitoring\PaymentsCard;
use App\Livewire\Monitoring\ServicesCard;
use App\Livewire\Monitoring\StorageCard;
use App\Models\Payment\DokuNotification;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Permission\Club61PermissionMatrix;
use App\Support\Monitoring\HealthSnapshot;
use App\Support\Monitoring\LogAlertRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dashboard Monitoring Sistem (Laravel Pulse, /pulse) — docs/PLAN_INSIDEN_PEMBAYARAN_DAN_MONITORING.md Langkah 4.
 */
class ServerHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(string $role): User
    {
        return User::factory()->role($role)->create(['is_active' => true]);
    }

    public function test_only_holders_of_view_server_health_can_open_the_dashboard(): void
    {
        $this->get('/pulse')->assertForbidden();

        $this->actingAs($this->user('cashier'))->get('/pulse')->assertForbidden();

        // Admin mendapat hampir semua izin, tapi view_server_health termasuk backdoor (bawaan hanya super_admin).
        $this->assertNotContains('view_server_health', Club61PermissionMatrix::defaultRolePermissions('admin'));
        $this->actingAs($this->user('admin'))->get('/pulse')->assertForbidden();

        $this->actingAs($this->user('super_admin'))->get('/pulse')
            ->assertOk()
            ->assertSee('Pembayaran Club 61')
            ->assertSee('Storage Club 61')
            ->assertSee('Layanan Club 61');
    }

    public function test_a_role_given_the_permission_can_open_it_outside_local_environment(): void
    {
        // Gate bawaan Pulse hanya mengizinkan APP_ENV=local; Gate viewPulse kita memakai izin.
        $role = Role::findOrCreate('monitoring_viewer', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view_server_health', 'web'));
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);

        $this->actingAs($user)->get('/pulse')->assertOk();
    }

    public function test_sidebar_menu_is_shown_only_to_permission_holders(): void
    {
        $this->actingAs($this->user('super_admin'))->followingRedirects()->get('/admin')->assertSee('Monitoring Sistem');
        $this->actingAs($this->user('admin'))->followingRedirects()->get('/admin')->assertDontSee('Monitoring Sistem');
    }

    public function test_snapshot_command_stores_storage_services_and_payment_summary(): void
    {
        ActivityLogger::record(module: 'FINANCE', event: 'payment.paid', description: 'lunas webhook', meta: ['gateway' => 'DOKU'], asSystem: true);
        ActivityLogger::record(module: 'FINANCE', event: 'payment.paid_via_reconcile', description: 'lunas polling', meta: ['gateway' => 'MIDTRANS'], asSystem: true);
        // Pembayaran kasir manual tidak dihitung sebagai webhook / polling gateway.
        ActivityLogger::record(module: 'FINANCE', event: 'payment.paid', description: 'kasir', meta: ['gateway' => 'CASHIER_POS'], asSystem: true);
        ActivityLogger::record(module: 'FINANCE', event: 'payment.late_settlement_refund', description: 'telat',
            meta: ['gateway' => 'DOKU', 'no_order' => 'ORD-PAD-TELAT1', 'pelanggan' => 'Budi', 'nominal_dibayar' => 200000], severity: ActivityLogger::CRITICAL, asSystem: true);

        DokuNotification::create([
            'request_id' => 'req-1', 'invoice_number' => 'ORD-PAD-24Y3BXH8RT-1', 'status' => 'SUCCESS', 'amount' => 200000,
            'result' => DokuNotification::IGNORED, 'result_note' => 'API status: CLOSED', 'attempts' => 1, 'payload' => [],
            'received_at' => now()->subHour(),
        ]);
        DokuNotification::create([
            'request_id' => 'req-2', 'invoice_number' => 'INV-RETRY', 'status' => 'SUCCESS', 'amount' => 100000,
            'result' => DokuNotification::RETRY, 'attempts' => 2, 'payload' => [], 'received_at' => now()->subMinutes(10),
        ]);

        $this->artisan('monitoring:snapshot')->assertSuccessful();

        Storage::disk('local')->assertExists(HealthSnapshot::FILE);
        Storage::disk('local')->assertExists(HealthSnapshot::HISTORY_FILE);
        $snapshot = HealthSnapshot::load();

        $this->assertArrayNotHasKey('error', $snapshot['storage']);
        $this->assertArrayNotHasKey('error', $snapshot['services']);
        $this->assertArrayNotHasKey('error', $snapshot['payments']);
        $this->assertGreaterThan(0, $snapshot['storage']['disk']['total']);
        $this->assertContains('logs', array_column($snapshot['storage']['folders'], 'key'));

        $payments = $snapshot['payments'];
        $this->assertSame(1, $payments['settled']['webhook_24h']);
        $this->assertSame(1, $payments['settled']['polling_24h']);
        $this->assertNotNull($payments['settled']['last_webhook']['DOKU']);
        $this->assertNull($payments['settled']['last_webhook']['MIDTRANS']);
        $this->assertSame(1, $payments['late_settlements']['count_24h']);
        $this->assertSame('ORD-PAD-TELAT1', $payments['late_settlements']['latest'][0]['order']);
        $this->assertSame(1, $payments['doku']['contradicted_7d']);
        $this->assertSame('ORD-PAD-24Y3BXH8RT-1', $payments['doku']['contradicted_latest'][0]['invoice']);
        $this->assertSame(1, $payments['doku']['by_result_24h'][DokuNotification::RETRY] ?? null);
        $this->assertSame(0, $payments['hanging']['count']);
    }

    public function test_cards_render_snapshot_data_and_warn_when_scheduler_stops(): void
    {
        DokuNotification::create([
            'request_id' => 'req-1', 'invoice_number' => 'ORD-PAD-24Y3BXH8RT-1', 'status' => 'SUCCESS', 'amount' => 200000,
            'result' => DokuNotification::IGNORED, 'result_note' => 'API status: CLOSED', 'attempts' => 1, 'payload' => [],
            'received_at' => now()->subHour(),
        ]);
        $this->actingAs($this->user('super_admin'));

        // Belum ada snapshot → petunjuk menjalankan perintahnya, tugas otomatis ditandai tidak berjalan.
        Livewire::test(StorageCard::class)->assertSee('Belum ada data');
        Livewire::test(ServicesCard::class)->assertSee('Tidak berjalan');

        $this->artisan('monitoring:snapshot')->assertSuccessful();

        Livewire::test(PaymentsCard::class)
            ->assertSee('SUCCESS dibantah API (7 hari): 1')
            ->assertSee('ORD-PAD-24Y3BXH8RT-1');
        Livewire::test(StorageCard::class)->assertSee('Disk server')->assertSee('Log aplikasi');
        Livewire::test(ServicesCard::class)->assertSee('Berjalan')->assertDontSee('Tidak berjalan');

        // Snapshot lebih tua dari 15 menit → scheduler kemungkinan berhenti.
        $this->travel(20)->minutes();
        Livewire::test(ServicesCard::class)->assertSee('Tidak berjalan');
    }

    public function test_alert_log_lines_are_recorded_in_pulse_once_and_other_lines_are_ignored(): void
    {
        config(['logging.default' => 'null']);
        LogAlertRecorder::flushDedupe();
        Pulse::startRecording();

        try {
            Log::warning("[ALERT] Notifikasi DOKU 'SUCCESS' DITOLAK: API status DOKU menyatakan CLOSED untuk [INV-1] — cek manual.");
            // Ditulis ke dua channel sekaligus (DokuClient::alert) → tetap dihitung sekali.
            Log::warning("[ALERT] Notifikasi DOKU 'SUCCESS' DITOLAK: API status DOKU menyatakan CLOSED untuk [INV-1] — cek manual.");
            Log::warning('Bukan peringatan');
            Log::info('[INFO] juga bukan');

            Pulse::ingest();
        } finally {
            Pulse::stopRecording();
        }

        $entries = DB::table('pulse_entries')->where('type', LogAlertRecorder::TYPE)->get();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('SUCCESS', $entries->first()->key);
        $this->assertStringNotContainsString('[ALERT]', $entries->first()->key);
    }
}
