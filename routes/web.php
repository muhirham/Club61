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

// Toggle bahasa ID/EN untuk halaman depan — disimpan di session (lihat SetLocale
// middleware), redirect balik ke halaman asal supaya posisi scroll/section tidak berubah.
Route::get('/lang/{locale}', function (string $locale) {
    abort_unless(in_array($locale, \App\Http\Middleware\SetLocale::ALLOWED_LOCALES, true), 404);
    session(['site_locale' => $locale]);

    return redirect()->back();
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
        if (! $user || ! $user->isStaff() || ! $user->can('checkin_padel_ticket')) {
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
        $allPlans = \App\Models\Membership\MembershipPlan::with('benefits')
            ->where('is_active', true)
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

    Route::get('/corporate/sample-csv', function () {
        $csv = "name,phone,hours\nBudi Santoso,081234567890,10\nSiti Rahma,081399887766,5\nAndi Wijaya,081700112233,8\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sponsor-roster-sample.csv"',
        ]);
    })->name('customer.corporate.sample-csv');

    Route::get('/corporate', [\App\Http\Controllers\CorporateDashboardController::class, 'show'])->name('customer.corporate');
});

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

// Export Buku Transaksi (Modul 17) — route GET biasa, izin View:BukuTransaksi + export_ledger dicek di controller.
Route::get('/admin/buku-transaksi/export', \App\Http\Controllers\Admin\LedgerExportController::class)
    ->middleware('auth')
    ->name('admin.buku-transaksi.export');

Route::middleware('auth')->group(function () {
    Route::get('/profile',[ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
