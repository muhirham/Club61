<?php

namespace App\Http\Middleware;

use App\Services\Permission\HomeRouteResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal customer (/dashboard, /booking, /my-club, /corporate, dst) khusus akun customer.
 * Akun staf (admin, kasir, resepsionis, role custom) dikembalikan ke home route role-nya —
 * supaya staf tidak "nyasar" ke tampilan customer dan melihat menu yang tidak diatur lewat
 * matriks izin (mis. "Sponsor Team" yang muncul karena staf kebetulan tercatat sebagai PIC).
 */
class CustomerPortalOnly
{
    private const STAFF_HOME_PREFIXES = ['/admin', '/pos', '/kitchen'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isCustomer()) {
            return $next($request);
        }

        return redirect($this->staffHome($user));
    }

    private function staffHome($user): string
    {
        // Home route yang mengarah ke portal customer akan memantul balik ke sini (loop) → pakai tujuan
        // otomatis dari permission, lalu /admin sebagai pengaman terakhir.
        foreach ([HomeRouteResolver::resolve($user), HomeRouteResolver::automatic($user)] as $homeRoute) {
            foreach (self::STAFF_HOME_PREFIXES as $prefix) {
                if ($homeRoute === $prefix || str_starts_with($homeRoute, $prefix.'/')) {
                    return $homeRoute;
                }
            }
        }

        return '/admin';
    }
}
