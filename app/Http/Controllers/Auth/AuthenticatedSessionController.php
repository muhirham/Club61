<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Permission\HomeRouteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     * Smart 1-Door Redirection:
     * - SUPER_ADMIN / ADMIN -> /admin (Filament Dashboard)
     * - CASHIER             -> /pos (POS Kasir Frontdesk)
     * - KITCHEN             -> /kitchen (Kitchen Display System)
     * - CUSTOMER / Default  -> /dashboard (Member Dashboard)
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        // 1. HOME ROUTE terdaftar yang dipilih di role → langsung ke sana.
        // 2. Kosong / tidak terdaftar → otomatis dari permission (Dashboard → POS → KDS → halaman admin
        //    pertama yang dicentang). Lihat HomeRouteResolver.
        $configured = HomeRouteResolver::configured($user);
        $destination = HomeRouteResolver::resolve($user);

        if ($configured !== null || str_starts_with($destination, HomeRouteResolver::ADMIN)) {
            $request->session()->forget('url.intended');

            return redirect($destination);
        }

        if ($destination === HomeRouteResolver::CUSTOMER) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return redirect()->intended($destination);
    }

    /**
     * Destroy an authenticated session.
     * Redirects back to login portal (/login) so user can immediately sign in.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}