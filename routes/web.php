<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Layar Browser Laptop & Monitor Venue)
|--------------------------------------------------------------------------
*/

// 1. Layar Web Customer (Depan)
Route::get('/', function () {
    return view('welcome');
});

// Toggle bahasa ID/EN situs customer — disimpan di session + cookie 1 tahun (lihat SetLocale middleware), redirect
// balik ke halaman asal supaya posisi halaman tidak berubah.
Route::get('/lang/{locale}', function (string $locale) {
    abort_unless(in_array($locale, \App\Http\Middleware\SetLocale::ALLOWED_LOCALES, true), 404);
    session(['site_locale' => $locale]);
    // Hanya kembali ke halaman situs ini — Referer dari situs lain tidak diikuti (open redirect).
    $back = url()->previous();
    $back = parse_url($back, PHP_URL_HOST) === request()->getHost() ? $back : url('/');

    return redirect()->to($back)->withCookie(cookie(\App\Http\Middleware\SetLocale::COOKIE, $locale, \App\Http\Middleware\SetLocale::COOKIE_MINUTES));
})->name('lang.switch');

// 2. Layar POS Kasir Frontdesk & KDS Dapur (Wajib Auth & Otorisasi Staf)
Route::middleware(['auth'])->group(function () {
    Route::get('/pos', function () {
        if (! auth()->user()->isStaff() || ! auth()->user()->can('access_pos_terminal')) {
            abort(403, 'Akses Ditolak: Hanya staf kasir atau admin yang dapat mengakses terminal POS.');
        }
        return view('pos.index');
    })->name('pos.index');

    Route::post('/pos/check-in', function (\Illuminate\Http\Request $request, \App\Services\Padel\PadelBookingService $service) {
        $user = auth()->user();
        // Izin check-in saja (resepsionis punya izin ini tapi bukan isStaff()).
        if (! $user || ! $user->can('checkin_padel_ticket')) {
            return response()->json([
                'success' => false,
                'message' => 'Akses Ditolak: Hanya staf kasir atau admin yang berhak melakukan check-in tiket.',
            ], 403);
        }

        $code = trim($request->input('code') ?? $request->input('qr_code_hash') ?? $request->input('booking_code') ?? '');
        if (empty($code)) {
            return response()->json(['success' => false, 'message' => 'Kode tiket atau QR wajib diisi.'], 422);
        }

        try {
            $result = $service->checkIn($code, $user);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Pesan bisnis (tiket tidak valid, belum jam main, dsb.) memang untuk kasir.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // Error lain (mis. query DB) jangan dibocorkan ke layar kasir — cukup dicatat di log.
            report($e);

            return response()->json(['success' => false, 'message' => 'Check-in gagal diproses. Coba lagi atau hubungi admin.'], 500);
        }
    })->name('pos.checkin');

    // 3. Layar Monitor Dapur / KOT (Kitchen Display System)
    Route::get('/kitchen', function () {
        if (! (auth()->user()->hasRole('kitchen') || auth()->user()->isAdmin()) || ! auth()->user()->can('view_kitchen_kds')) {
            abort(403, 'Akses Ditolak: Hanya staf dapur atau admin yang dapat mengakses KDS.');
        }
        return view('kitchen.kds');
    })->name('kitchen.kds');

    // Sidik jari hak akses akun (bukan daftar izinnya) — dipantau partials.access-watch supaya menu & halaman ikut
    // menyesuaikan saat admin mengubah role tanpa user harus logout.
    Route::get('/akses/versi', fn () => response()
        ->json(['v' => \App\Support\StaffAccess::version(auth()->user())])
        ->header('Cache-Control', 'no-store'))
        ->name('staff.access-version');
});

// 4. Dashboard Member / Customer
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified', \App\Http\Middleware\CustomerPortalOnly::class])->name('dashboard');

Route::middleware(['auth', 'verified', \App\Http\Middleware\CustomerPortalOnly::class])->group(function () {
    Route::get('/booking', function () {
        return view('customer.booking');
    })->name('customer.booking');

    Route::get('/cart', function () {
        return view('customer.cart');
    })->name('customer.cart');

    Route::get('/checkout', function () {
        $clubFinanceSettings = \App\Models\Pos\ClubFinanceSetting::getSettings();
        return view('customer.checkout', [
            'clubFinanceSettings' => $clubFinanceSettings,
        ]);
    })->name('customer.checkout');

    Route::get('/my-club', function () {
        return view('customer.my-club');
    })->name('customer.my-club');

    Route::get('/membership', function (\Illuminate\Http\Request $request) {
        // Paket "Hanya POS / Admin" (mis. kontrak corporate) tidak ditawarkan ke customer.
        $allPlans = \App\Models\Membership\MembershipPlan::with('benefits')
            ->where('is_active', true)
            ->soldOnline()
            ->get();
        $selectedPlanId = $request->query('plan') ?? ($allPlans->firstWhere('code', 'MBR-SILVER')->id ?? $allPlans->first()?->id ?? null);

        return view('customer.membership', [
            'allPlans' => $allPlans,
            'selectedPlanId' => $selectedPlanId,
        ]);
    })->name('customer.membership');

    Route::get('/invoice', function () {
        return view('customer.invoice');
    })->name('customer.invoice');

    // Modul 22 tahap 3 — klaim promo (cara sebar C). Halaman promo publik (di bawah); tombol Klaim wajib login & dibatasi.
    Route::post('/promo/{code}/klaim', [\App\Http\Controllers\PromoClaimController::class, 'claim'])
        ->where('code', '[A-Za-z0-9-]{4,30}')->middleware('throttle:10,1')->name('promo.claim.store');

    // Modul 24 Opsi B Tahap 2 — profil coach (informasi saja; booking coaching lewat WhatsApp admin coaching).
    Route::get('/coaching', function (\App\Services\Coaching\CoachDirectory $directory) {
        return view('customer.coaching', [
            'coaches' => $directory->coaches(),
            'adminWhatsappUrl' => $directory->adminWhatsappUrl(),
            'directory' => $directory,
        ]);
    })->name('customer.coaching');

    Route::get('/corporate/sample-csv', function () {
        $csv = "name,phone,hours\nBudi Santoso,081234567890,10\nSiti Rahma,081399887766,5\nAndi Wijaya,081700112233,8\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sponsor-roster-sample.csv"',
        ]);
    })->name('customer.corporate.sample-csv');

    Route::get('/corporate', [\App\Http\Controllers\CorporateDashboardController::class, 'show'])->name('customer.corporate');
});

// Halaman promo klaim (cara sebar C) — publik: tamu melihat promonya dulu, tombol "Masuk untuk klaim" lalu kembali ke sini.
Route::get('/promo/{code}', [\App\Http\Controllers\PromoClaimController::class, 'show'])
    ->where('code', '[A-Za-z0-9-]{4,30}')->middleware('throttle:60,1')->name('promo.claim');
Route::get('/promo/{code}/{mode}', [\App\Http\Controllers\PromoClaimController::class, 'auth'])
    ->where(['code' => '[A-Za-z0-9-]{4,30}', 'mode' => 'masuk|daftar'])->middleware('throttle:60,1')->name('promo.claim.auth');

// Halaman link bio Instagram (publik, tanpa login) — tombol diatur dari admin Marketing & Event → Link Bio Instagram.
Route::get('/links', function () {
    $settings = rescue(fn () => \App\Models\Setting\CompanyProfileSetting::current(), null, false);

    return view('link-bio', [
        'links' => \App\Models\Marketing\LinkBioLink::visible(),
        'socialLinks' => $settings?->safeSocialLinks() ?? [],
    ]);
})->middleware('throttle:120,1')->name('link-bio');

// Pratinjau read-only dashboard PIC untuk STAF (di-iframe oleh halaman panel "Dashboard Sponsor").
// Sengaja di luar grup portal customer supaya staf tidak ter-redirect.
Route::get('/corporate/preview/{organization}', [\App\Http\Controllers\CorporateDashboardController::class, 'preview'])
    ->middleware('auth')
    ->name('corporate.preview');

// Export CSV Log Aktivitas (Modul 16) — route GET biasa (bukan aksi Livewire) supaya bisa di-stream.
// Izin View:LogAktivitas + export_activity_logs dicek di controller.
Route::get('/admin/log-aktivitas/export', \App\Http\Controllers\Admin\ActivityLogExportController::class)
    ->middleware('auth')
    ->name('admin.log-aktivitas.export');

// Unduh CSV kode unik promo (Modul 22 tahap 3, cara sebar B). Izin manage_vouchers dicek di controller.
Route::get('/admin/daftar-voucher/kode/{voucher}', \App\Http\Controllers\Admin\VoucherCodesExportController::class)
    ->middleware('auth')
    ->name('admin.voucher-codes.export');

// Signature (Modul 26) — layar TV venue. Link rahasia per layar, tanpa login, hanya baca.
Route::middleware('throttle:120,1')->group(function () {
    // Alamat pendek untuk diketik di browser TV (sandbox.club61.id/tv/xxxxxxxxxx). /signature/{token} tetap berlaku.
    Route::get('/tv/{token}', [\App\Http\Controllers\SignatureController::class, 'player'])->name('signature.tv');
    Route::get('/signature/{token}', [\App\Http\Controllers\SignatureController::class, 'player'])->name('signature.player');
    Route::get('/signature/{token}/payload', [\App\Http\Controllers\SignatureController::class, 'payload'])->name('signature.payload');
    Route::get('/signature/{token}/live', [\App\Http\Controllers\SignatureController::class, 'live'])->name('signature.live');
});
// Preview tampilan yang belum ditayangkan — izin View:Signature dicek di controller.
Route::middleware('auth')->group(function () {
    Route::get('/admin/signature/preview/{key}', [\App\Http\Controllers\SignatureController::class, 'preview'])->name('signature.preview');
    Route::get('/admin/signature/preview/{key}/live', [\App\Http\Controllers\SignatureController::class, 'previewLive'])->name('signature.preview.live');
});

// Panduan karyawan — link rahasia tanpa login (seperti Signature), hanya gambar halaman ber-watermark. Gambar dibatasi
// lebih longgar karena satu panduan bisa puluhan halaman.
Route::get('/panduan/{token}', [\App\Http\Controllers\GuideController::class, 'show'])->middleware('throttle:30,1')->name('guide.show');
Route::get('/panduan/{token}/status', [\App\Http\Controllers\GuideController::class, 'status'])->middleware('throttle:30,1')->name('guide.status');
Route::get('/panduan/{token}/halaman/{page}', [\App\Http\Controllers\GuideController::class, 'page'])->whereNumber('page')->middleware('throttle:300,1')->name('guide.page');
// Pratinjau admin (panduan nonaktif pun bisa dicek) — izin manage_guides dicek di controller.
Route::middleware('auth')->group(function () {
    Route::get('/admin/panduan/{guide}/pratinjau', [\App\Http\Controllers\GuideController::class, 'preview'])->name('guide.preview');
    Route::get('/admin/panduan/{guide}/pratinjau/{page}', [\App\Http\Controllers\GuideController::class, 'previewPage'])->whereNumber('page')->name('guide.preview.page');
});

// Export Buku Transaksi (Modul 17) — route GET biasa, izin View:BukuTransaksi + export_ledger dicek di controller.
Route::get('/admin/buku-transaksi/export', \App\Http\Controllers\Admin\LedgerExportController::class)
    ->middleware('auth')
    ->name('admin.buku-transaksi.export');

Route::middleware('auth')->group(function () {
    Route::get('/profile',[ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/profile/vouchers', [ProfileController::class, 'vouchers'])->name('profile.vouchers');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
