<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Sengaja TIDAK register login page Filament sendiri — supaya cuma ada SATU pintu
            // login untuk semua orang (staf maupun customer), yaitu /login (LoginRequest.php,
            // sudah mendukung email/No HP + auto-redirect ke /admin kalau stafnya admin/staff).
            // Efeknya: akses /admin tanpa login otomatis diarahkan ke /login (fallback bawaan
            // Laravel saat Filament::getLoginUrl() null), dan logout dari /admin juga diarahkan
            // ke /login lewat binding LogoutResponse custom di AppServiceProvider.
            ->darkMode(false)
            ->brandName('Club 61 Padel & Wellness')
            // Logo panel: monogram + nama brand (resources/views/filament/brand-logo.blade.php).
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/identity/favicon.png').'?v=2')
            ->maxContentWidth('full')
            // Sidebar bisa diciutkan ke samping jadi strip ikon (tombol di header sidebar);
            // status buka/ciut diingat browser per user.
            ->sidebarCollapsibleOnDesktop()
            // Urutan grup menu sidebar (tiap grup bisa dibuka/tutup; statusnya diingat browser per user).
            // Dashboard sengaja tanpa grup supaya selalu di paling atas.
            ->navigationGroups([
                'Operasional Harian',
                'Customer & Membership',
                'Coaching',
                'Sponsor',
                'F&B',
                'Keuangan',
                'Marketing & Event',
                'Master Data',
                'Karyawan & Akses',
            ])
            // Warna utama panel = Terakota brand Club 61 (Pantone 643U). Palet otomatis Filament membuat shade 600
            // jadi merah terang (#D14D42), jadi shade ditulis manual dengan #662721 sebagai warna tombol (600).
            ->colors([
                'primary' => array_map(fn (string $hex): string => Color::convertToOklch($hex), [
                    50 => '#FBF4F2', 100 => '#F6E8E4', 200 => '#EBCDC6', 300 => '#D9A79D', 400 => '#B9786C',
                    500 => '#8F4A40', 600 => '#662721', 700 => '#5A221D', 800 => '#511D18', 900 => '#3F1713', 950 => '#2A0F0C',
                ]),
            ])
            ->spa()
            // Link download (export) jangan dibuka lewat navigasi SPA: Livewire mengambil URL-nya dengan fetch lalu
            // menampilkan isi file (XLSX/PDF/CSV) sebagai halaman teks, bukan mengunduhnya.
            ->spaUrlExceptions(fn (): array => [
                url('/admin/buku-transaksi/export*'),
                url('/admin/log-aktivitas/export*'),
                url('/admin/daftar-voucher/kode/*'),
            ])
            ->resources([
                \App\Filament\Resources\Users\UserResource::class,
                \App\Filament\Resources\Roles\RoleResource::class,
                \App\Filament\Resources\Membership\MembershipPlanResource::class,
                \App\Filament\Resources\Membership\Facilities\MembershipFacilityResource::class,
                \App\Filament\Resources\Sponsor\SponsorOrganizationResource::class,
                \App\Filament\Resources\Sponsor\SponsorAccessScheduleResource::class,
                \App\Filament\Resources\Coaching\CoachResource::class,
                \App\Filament\Resources\Coaching\CoachingAcademyResource::class,
                \App\Filament\Resources\Coaching\CoachingRateResource::class,
                \App\Filament\Resources\Marketing\LinkBioLinkResource::class,
                \App\Filament\Resources\Padel\PadelRecurringScheduleResource::class,
                \App\Filament\Resources\Staff\GuideResource::class,
            ])
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make(),
            ])
            ->renderHook(
                'panels::head.end',
                fn () => view('filament.custom-styles')
            )
            ->renderHook(
                'panels::body.end',
                fn () => view('filament.sidebar-auto-collapse')
            )
            // Cetak struk thermal 58mm: club61PrintReceipt() dipakai semua tombol cetak struk di panel.
            ->renderHook(
                'panels::body.end',
                fn () => view('pos.partials.receipt-print-script')
            )
            // Dialog konfirmasi Club61 (pengganti popup bawaan browser / wire:confirm).
            ->renderHook(
                'panels::body.end',
                fn () => view('filament.confirm-dialog')
            )
            // Sesi habis (419) saat aksi Livewire → dialog Club61 berbahasa Indonesia, bukan popup bawaan browser.
            ->renderHook(
                'panels::body.end',
                fn () => view('partials.livewire-session-expired', ['filamentConfirm' => true])
            )
            // Hak akses diubah admin saat halaman terbuka → menu ikut berubah / dipindah ke halaman yang masih boleh.
            ->renderHook(
                'panels::body.end',
                fn () => view('partials.access-watch', ['filamentConfirm' => true])
            )
            // Update realtime (Reverb): Echo bawaan Filament dinyalakan hanya kalau Reverb dikonfigurasi; halaman jadwal
            // memakai club61LiveRefresh (event + polling cadangan).
            ->bootUsing(fn () => config(['filament.broadcasting.echo' => \App\Support\Realtime::echo()]))
            ->renderHook(
                'panels::body.end',
                fn () => view('filament.realtime-script')
            )
            ->pages([
                \App\Filament\Pages\Dashboard::class,
                \App\Filament\Pages\Analytics::class,
                \App\Filament\Pages\BookingSystem::class,
                \App\Filament\Pages\BookOfflineCourt::class,
                \App\Filament\Pages\JualMembership::class,
                \App\Filament\Pages\KasirProShop::class,
                \App\Filament\Pages\KelolaPemesanan::class,
                \App\Filament\Pages\PeminjamanAlat::class,
                \App\Filament\Pages\Kustomer::class,
                \App\Filament\Pages\KelolaKaryawan::class,
                \App\Filament\Pages\KelolaTurnamen::class,
                \App\Filament\Pages\KelolaClub::class,
                \App\Filament\Pages\Marketing::class,
                \App\Filament\Pages\MasterData::class,
                \App\Filament\Pages\KatalogMerchandise::class,
                \App\Filament\Pages\LaporanMerchandise::class,
                \App\Filament\Pages\PengaturanBiayaPajak::class,
                \App\Filament\Pages\MetodePembayaranOnline::class,
                \App\Filament\Pages\TemplateInvoice::class,
                \App\Filament\Pages\KelolaKontenWebsite::class,
                \App\Filament\Pages\KelolaMenuFnb::class,
                \App\Filament\Pages\SponsorDashboard::class,
                \App\Filament\Pages\LogAktivitas::class,
                \App\Filament\Pages\BukuTransaksi::class,
                \App\Filament\Pages\AntrianRefund::class,
                \App\Filament\Pages\ReviewShiftKasir::class,
                \App\Filament\Pages\DetailShiftKasir::class,
                \App\Filament\Pages\DaftarVoucher::class,
                \App\Filament\Pages\ImportPenjualan::class,
                \App\Filament\Pages\Signature::class,
                \App\Filament\Pages\JadwalCoaching::class,
                \App\Filament\Pages\TagihanCoaching::class,
                \App\Filament\Pages\TagihanBulanan::class,
                \App\Filament\Pages\StokFnb::class,
                \App\Filament\Pages\BukuKasFnb::class,
                \App\Filament\Pages\LaporanFnb::class,
            ])
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\RedirectToFirstAccessiblePanelPage::class,
            ]);
    }
}
