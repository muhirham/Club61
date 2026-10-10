<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang dinonaktifkan (Kelola Pengguna → Aktif dimatikan) langsung kehilangan akses di semua pintu:
 * sesi web (POS kasir, KDS, customer portal, panel admin) dan token API. Dulu cuma panel /admin
 * (canAccessPanel) dan login API yang mengecek is_active — kasir yang sudah keluar masih bisa membuka
 * /pos, check-in tiket, dan memakai token API 30 harinya.
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'Akun Anda dinonaktifkan. Hubungi admin Club 61.';

    public function handle(Request $request, Closure $next): Response
    {
        // Sesi web dulu; token Bearer hanya di-resolve kalau request membawanya (route publik tetap murah).
        $user = $request->user() ?? ($request->bearerToken() ? $request->user('sanctum') : null);

        if (! $user || $user->is_active !== false) {
            return $next($request);
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['success' => false, 'message' => self::MESSAGE, 'errors' => null], 403);
        }

        return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
    }
}
