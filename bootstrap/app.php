<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // AuthenticateSession: sesi web di perangkat lain otomatis keluar setelah password diganti / direset lewat email
        // (dulu hanya panel admin yang begini — HP customer yang hilang tetap login walau password sudah direset).
        $middleware->web(append: [\App\Http\Middleware\SetLocale::class, \Illuminate\Session\Middleware\AuthenticateSession::class, \App\Http\Middleware\EnsureUserIsActive::class]);
        $middleware->api(append: [\App\Http\Middleware\EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Enforce JSON responses for all /api/* requests
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // 1. Validation Exception (HTTP 422)
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi data gagal.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // 2. Authentication Exception (HTTP 401)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Token tidak valid atau sesi telah berakhir.',
                    'errors' => null,
                ], 401);
            }
        });

        // 3. Model Not Found Exception (HTTP 404)
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data resource tidak ditemukan.',
                    'errors' => null,
                ], 404);
            }
        });

        // 4. General HTTP Exception (e.g. 409 Conflict, 403 Forbidden, 429 Throttle)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Terjadi kesalahan pada request.',
                    'errors' => null,
                ], $e->getStatusCode());
            }
        });

        // 5. Log Aktivitas: setiap percobaan membuka halaman / menjalankan aksi tanpa izin (403).
        // Dicatat di sini — SETELAH transaksi aksi yang ditolak di-rollback — supaya jejaknya tetap ada.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() === 403) {
                $message = $e->getMessage();
                \App\Services\Audit\ActivityLogger::accessDenied(
                    $message !== '' && $message !== 'This action is unauthorized.' ? $message : 'tidak memiliki izin untuk halaman / aksi ini'
                );
            }

            return $response;
        });
    })->create();
