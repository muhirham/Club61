<?php

namespace App\Providers;

use App\Models\Setting\CompanyProfileSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton per request: jadwal jam peak dibaca sekali, bukan sekali per slot grid (lapangan x jam).
        $this->app->singleton(\App\Services\Padel\PeakHourService::class);
        $this->app->singleton(\App\Services\Payment\OnlinePaymentMethodService::class);
        $this->app->singleton(\App\Services\Padel\BookingTimeService::class);
        $this->app->singleton(\App\Services\Membership\MembershipFacilityService::class);

        $this->app->singleton(\App\Services\Payment\PaymentFulfillmentRegistry::class, function () {
            $registry = new \App\Services\Payment\PaymentFulfillmentRegistry();
            $registry->register('PADEL', \App\Services\Padel\Handlers\PadelFulfillmentHandler::class);
            $registry->register('MEMBERSHIP', \App\Services\Membership\MembershipFulfillmentHandler::class);
            $registry->register('FNB', \App\Services\Fnb\FnbFulfillmentHandler::class);

            return $registry;
        });

        // State log aktivitas per request/command (batch id, command yang sedang jalan).
        $this->app->scoped(\App\Services\Audit\AuditContext::class);

        // Filament tidak punya login page sendiri lagi (AdminPanelProvider), jadi begitu staf
        // logout dari /admin, arahkan langsung ke satu-satunya pintu login (/login) — bukan ke
        // dashboard panel /admin (yang defaultnya dituju Filament\Auth\Http\Responses\LogoutResponse
        // kalau tidak ada login page terdaftar), supaya tidak ada hop redirect tambahan.
        $this->app->bind(
            \Filament\Auth\Http\Responses\Contracts\LogoutResponse::class,
            fn () => new class implements \Filament\Auth\Http\Responses\Contracts\LogoutResponse
            {
                public function toResponse($request)
                {
                    return redirect()->route('login');
                }
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return method_exists($user, 'hasRole') && $user->hasRole('super_admin') ? true : null;
        });

        Gate::policy(\Spatie\Permission\Models\Role::class, \App\Policies\RolePolicy::class);
        Gate::policy(\App\Models\Sponsor\SponsorOrganization::class, \App\Policies\Sponsor\SponsorOrganizationPolicy::class);

        $this->registerActivityLog();

        // Satu sumber data company profile dipakai ulang di halaman depan publik (welcome)
        // DAN panel kiri halaman login — supaya fakta venue (jumlah lapangan, daftar
        // fasilitas, alamat/jam) tidak pernah drift antar 2 file lagi seperti insiden
        // "4 vs 3 lapangan" yang memicu Modul 14 (Company Profile Content).
        View::composer(['welcome', 'auth.login'], function ($view) {
            $view->with('companyProfile', CompanyProfileSetting::current());
        });

        // Facilities Showcase, "Kenapa Pilih Club61", Membership Teaser, & stats bar cuma
        // tampil di halaman depan publik (bukan panel login, yang cuma butuh kartu ringkas
        // dari $companyProfile di atas).
        View::composer('welcome', function ($view) {
            $view->with('companyFacilities', \App\Models\Setting\CompanyProfileFacility::active()->get());
            $view->with('companyValueProps', \App\Models\Setting\CompanyProfileValueProp::active()->get());

            // Semua paket individual yang aktif ditampilkan (bukan cuma 3 termurah) — supaya
            // staf yang menambah/menonaktifkan paket lewat resource Membership tidak perlu
            // sentuh halaman depan lagi, cukup 1 sumber data yang sama.
            $view->with(
                'membershipPlans',
                \App\Models\Membership\MembershipPlan::query()
                    ->where('is_active', true)
                    ->where('ownership_type', 'INDIVIDUAL')
                    ->with('benefits')
                    ->orderBy('price')
                    ->get()
            );

            // Angka nyata dari database, bukan dummy — biar hero page ga cuma dekorasi kosong.
            $view->with('venueStats', [
                'active_members' => \App\Models\Membership\UserMembership::where('status', 'ACTIVE')->distinct('user_id')->count('user_id'),
                'sponsor_partners' => \App\Models\Sponsor\SponsorOrganization::count(),
            ]);
        });

        if (! app()->environment('production') && (request()->header('x-forwarded-proto') === 'https' || str_contains(request()->header('host') ?? '', 'ngrok'))) {
            URL::forceScheme('https');
        }
        // 1. Rate Limiting Otentikasi - Anti Brute-Force
        // Dua limit independen: per-IP (10/menit) DAN per-akun (5/menit, dikunci ke
        // identifier login/email-nya sendiri, bukan IP). Tanpa limit per-akun, attacker
        // yang gonta-ganti IP (proxy/botnet) bisa nyoba password tanpa batas ke satu akun
        // yang sama karena limit per-IP tidak pernah kena untuk akun itu.
        $authThrottleResponse = function () {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak percobaan autentikasi. Silakan tunggu 1 menit.',
                'errors' => null,
            ], 429);
        };

        RateLimiter::for('auth-throttle', function (Request $request) use ($authThrottleResponse) {
            $identifier = strtolower(trim((string) ($request->input('login') ?? $request->input('email') ?? '')));

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip())->response($authThrottleResponse),
                Limit::perMinute(5)->by('account:'.($identifier !== '' ? $identifier : $request->ip()))->response($authThrottleResponse),
            ];
        });

        // 2. Rate Limiting Booking Padel (5 hit/menit/User) - Anti Calo & Bot
        RateLimiter::for('booking-throttle', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak permintaan booking. Silakan tunggu 1 menit.',
                    'errors' => null,
                ], 429);
            });
        });
    }

    /**
     * Modul 16 — Log Aktivitas: pencatatan otomatis perubahan data master, login/logout, dan
     * penanda command artisan (supaya aksi scheduler tercatat sebagai SISTEM, dan seeder/migrasi
     * tidak membanjiri log dengan ribuan baris "menambah data").
     */
    private function registerActivityLog(): void
    {
        \App\Services\Audit\AuditRegistry::register();

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\CommandStarting::class, function ($event) {
            $context = app(\App\Services\Audit\AuditContext::class);
            if ($context->consoleCommand === null) {
                $context->consoleCommand = (string) $event->command;
                $context->newBatch();
            }
            if (str_starts_with((string) $event->command, 'migrate') || in_array($event->command, ['db:seed', 'db:wipe'], true)) {
                $context->suppressed++;
            }
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\CommandFinished::class, function ($event) {
            $context = app(\App\Services\Audit\AuditContext::class);
            if (str_starts_with((string) $event->command, 'migrate') || in_array($event->command, ['db:seed', 'db:wipe'], true)) {
                $context->suppressed = max(0, $context->suppressed - 1);
            }
            if ($context->consoleCommand === $event->command) {
                $context->consoleCommand = null;
                $context->batchId = null;
            }
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            \App\Services\Audit\ActivityLogger::record(
                module: 'AUTH',
                event: 'auth.login',
                description: 'Login ke sistem',
                subject: $event->user instanceof \Illuminate\Database\Eloquent\Model ? $event->user : null,
                causer: $event->user,
            );
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event) {
            if (! $event->user) {
                return;
            }
            \App\Services\Audit\ActivityLogger::record(
                module: 'AUTH',
                event: 'auth.logout',
                description: 'Logout dari sistem',
                subject: $event->user instanceof \Illuminate\Database\Eloquent\Model ? $event->user : null,
                causer: $event->user,
            );
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($event) {
            // Hanya identitas yang dicoba — password TIDAK PERNAH ikut dicatat. Akun yang dicoba jadi
            // subject (bukan pelaku), dan percobaan berulang di-dedup per IP + identitas (lihat loginFailed).
            $credentials = (array) $event->credentials;
            $identifier = $credentials['email'] ?? $credentials['phone'] ?? $credentials['login'] ?? '-';
            \App\Services\Audit\ActivityLogger::loginFailed((string) $identifier, $event->user);
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Lockout::class, function ($event) {
            $identifier = (string) ($event->request->input('login') ?? $event->request->input('email') ?? '-');
            \App\Services\Audit\ActivityLogger::record(
                module: 'AUTH',
                event: 'auth.lockout',
                description: 'Login dikunci sementara karena terlalu banyak percobaan untuk "'.\Illuminate\Support\Str::limit($identifier, 80).'"',
                meta: ['identitas_dicoba' => \Illuminate\Support\Str::limit($identifier, 80)],
                severity: \App\Services\Audit\ActivityLogger::CRITICAL,
            );
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\PasswordReset::class, function ($event) {
            \App\Services\Audit\ActivityLogger::record(
                module: 'AUTH',
                event: 'auth.password_reset',
                description: 'Mereset kata sandi lewat link "Lupa Sandi"',
                subject: $event->user instanceof \Illuminate\Database\Eloquent\Model ? $event->user : null,
                severity: \App\Services\Audit\ActivityLogger::WARNING,
                causer: $event->user,
            );
        });
    }
}
