<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use App\Support\Monitoring\PulseUserLabel;
use App\Support\Monitoring\UsageRequestFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\UserRequests;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kartu Usage Pulse: hanya aktivitas orang yang dihitung — request otomatis (polling QR kasir, refresh jadwal,
 * cek hak akses, cek status bayar customer) dibuang; link bio & TV signage dikecualikan lewat config.
 */
class UsageRequestFilterTest extends TestCase
{
    use RefreshDatabase;

    private function livewireRoute(): Route
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->first(fn (Route $route) => $route->named('*livewire.update'));
    }

    /** @param  list<array{updates?: array, calls?: list<array{method: string, params?: array}>}>  $components */
    private function livewireRequest(array $components): Request
    {
        $route = $this->livewireRoute();
        $payload = array_map(fn (array $c) => [
            'snapshot' => json_encode(['memo' => ['path' => 'admin/book-offline-court']]),
            'updates' => $c['updates'] ?? [],
            'calls' => array_map(fn (array $call) => ['path' => '', 'method' => $call['method'], 'params' => $call['params'] ?? []], $c['calls'] ?? []),
        ], $components);

        $request = Request::create('/'.$route->uri(), 'POST', ['components' => $payload]);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function pageRequest(string $uri, array $headers = []): Request
    {
        $request = Request::create($uri, 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match(Request::create(strtok($uri, '?'), 'GET')));

        return $request;
    }

    public function test_automatic_livewire_requests_are_detected(): void
    {
        // Popup QR kasir (wire:poll tiap 3 dtk), refresh jadwal, bagian halaman yang dimuat menyusul, status TV.
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => 'pollPendingQris']]]])));
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => '$refresh']]]])));
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => '__lazyLoad', 'params' => ['x']]]]])));
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => 'screenLastSeen']]]])));
        // Event otomatis: menu sidebar diperbarui setelah hak akses berubah, event Reverb.
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => '__dispatch', 'params' => ['refresh-sidebar', []]]]]])));
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => '__dispatch', 'params' => ['echo-private:board,changed', []]]]]])));
        // Beberapa komponen sekaligus, semuanya otomatis.
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->livewireRequest([
            ['calls' => [['method' => '$refresh']]],
            ['calls' => [['method' => 'pollPendingQris']]],
        ])));
    }

    public function test_clicks_and_form_input_count_as_activity(): void
    {
        // Klik tombol kasir.
        $this->assertFalse(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => 'checkout']]]])));
        // Mengisi form (wire:model.live) walau ikut $refresh.
        $this->assertFalse(UsageRequestFilter::isAutomatic($this->livewireRequest([['updates' => ['search' => 'budi'], 'calls' => [['method' => '$refresh']]]])));
        // Satu komponen otomatis + satu klik dalam request yang sama → tetap dihitung.
        $this->assertFalse(UsageRequestFilter::isAutomatic($this->livewireRequest([
            ['calls' => [['method' => 'pollPendingQris']]],
            ['calls' => [['method' => 'cancelPendingQris']]],
        ])));
        // Event yang dikirim karena klik (bukan daftar otomatis).
        $this->assertFalse(UsageRequestFilter::isAutomatic($this->livewireRequest([['calls' => [['method' => '__dispatch', 'params' => ['open-cart', []]]]]])));
        // Membuka halaman biasa.
        $this->assertFalse(UsageRequestFilter::isAutomatic($this->pageRequest('/admin')));
        $this->assertFalse(UsageRequestFilter::isAutomatic($this->pageRequest('/api/v1/padel/my-bookings')));
    }

    public function test_access_probe_and_customer_payment_polling_are_automatic(): void
    {
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->pageRequest('/admin', ['X-Club61-Access-Probe' => '1'])));
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->pageRequest('/api/v1/padel/bookings/5/ticket?verify_payment=1')));
        $this->assertTrue(UsageRequestFilter::isAutomatic($this->pageRequest('/api/v1/membership/my-purchases?verify_payment=1')));
        // Membuka tiket sendiri (tanpa verify_payment) tetap dihitung.
        $this->assertFalse(UsageRequestFilter::isAutomatic($this->pageRequest('/api/v1/padel/bookings/5/ticket')));
    }

    public function test_link_bio_signage_and_access_check_pages_are_ignored_by_config(): void
    {
        $patterns = config('pulse.recorders.'.UserRequests::class.'.ignore');
        $ignored = fn (string $path) => collect($patterns)->contains(fn (string $p) => preg_match($p, $path) === 1);

        $this->assertTrue(config('pulse.recorders.'.UserRequests::class.'.enabled'));
        $this->assertFalse(config('pulse.recorders.'.\Laravel\Pulse\Recorders\CacheInteractions::class.'.enabled'));

        foreach (['/links', '/tv/{token}', '/signature/{token}', '/signature/{token}/live', '/admin/signature/preview/{key}/live',
            '/panduan/{token}/status', '/akses/versi', '/api/v1/padel/schedule', '/broadcasting/auth', '/pulse'] as $path) {
            $this->assertTrue($ignored($path), "$path seharusnya dikecualikan");
        }
        foreach (['/admin', '/admin/book-offline-court', '/booking', '/api/v1/padel/checkout', '/admin/signatures'] as $path) {
            $this->assertFalse($ignored($path), "$path seharusnya dihitung");
        }
    }

    public function test_pulse_drops_automatic_user_requests_but_keeps_other_entries(): void
    {
        Pulse::startRecording();

        try {
            // Polling QR kasir → dibuang.
            $this->app->instance('request', $this->livewireRequest([['calls' => [['method' => 'pollPendingQris']]]]));
            Pulse::record('user_request', '7')->count();
            // Data lain di request yang sama (mis. query lambat) tetap disimpan.
            Pulse::record('slow_query', 'select 1', 1500)->max();
            Pulse::ingest();

            // Klik kasir → disimpan.
            $this->app->instance('request', $this->livewireRequest([['calls' => [['method' => 'checkout']]]]));
            Pulse::record('user_request', '7')->count();
            Pulse::ingest();
        } finally {
            Pulse::stopRecording();
        }

        $this->assertSame(1, DB::table('pulse_entries')->where('type', 'user_request')->count());
        $this->assertSame(1, DB::table('pulse_entries')->where('type', 'slow_query')->count());
    }

    public function test_users_are_shown_with_name_and_role_instead_of_email(): void
    {
        $cashier = User::factory()->role('cashier')->create(['name' => 'Sinta']);
        $this->assertSame(['name' => 'Sinta', 'extra' => 'Kasir'], PulseUserLabel::fields($cashier));

        $customer = User::factory()->create(['name' => 'Budi']);
        $customer->syncRoles([]);
        $this->assertSame('Customer', PulseUserLabel::fields($customer->fresh())['extra']);

        $custom = User::factory()->create(['name' => 'Andi']);
        $custom->syncRoles([Role::findOrCreate('front_office', 'web')]);
        $this->assertSame('Front Office', PulseUserLabel::fields($custom->fresh())['extra']);
    }

    public function test_usage_card_lists_active_accounts_with_role_and_no_gravatar(): void
    {
        $admin = User::factory()->role('super_admin')->create(['is_active' => true]);
        $cashier = User::factory()->role('cashier')->create(['name' => 'Sinta Kasir']);

        // Kartu dimuat menyusul (lazy) di dashboard.
        $this->actingAs($admin)->get('/pulse')->assertOk()->assertSee('pulse.usage', false);

        Pulse::startRecording();
        try {
            $this->app->instance('request', $this->pageRequest('/admin'));
            Pulse::record('user_request', (string) $cashier->id)->count();
            Pulse::ingest();
        } finally {
            Pulse::stopRecording();
        }

        \Livewire\Livewire::withoutLazyLoading()->test(\Laravel\Pulse\Livewire\Usage::class, ['type' => 'requests'])
            ->assertSee('Top 10 Users Making Requests')
            ->assertSee('Sinta Kasir')
            ->assertSee('Kasir')
            ->assertDontSee('gravatar.com');
    }
}
