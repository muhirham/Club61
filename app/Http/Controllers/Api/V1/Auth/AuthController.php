<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\LoginThrottle;
use App\Support\PersonName;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => PersonName::rules(),
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $normalizedPhone = PhoneNumber::normalize($validated['phone']);

        if (! $normalizedPhone) {
            throw ValidationException::withMessages([
                'phone' => ['Format nomor HP tidak valid.'],
            ]);
        }

        // Modul 33: dua request daftar bersamaan (ketuk ganda) dulu bisa lolos validasi dua-duanya → dua akun dengan
        // email / nomor HP yang sama. Dikunci per email & nomor, lalu dicek ulang di dalam kunci.
        $locks = [
            \Illuminate\Support\Facades\Cache::lock('register:email:'.strtolower($validated['email']), 15),
            \Illuminate\Support\Facades\Cache::lock('register:phone:'.$normalizedPhone, 15),
        ];
        foreach ($locks as $i => $lock) {
            if (! $lock->get()) {
                foreach (array_slice($locks, 0, $i) as $held) {
                    $held->release();
                }

                throw ValidationException::withMessages(['email' => ['Pendaftaran sedang diproses. Tunggu sebentar lalu coba masuk.']]);
            }
        }

        try {
            if (User::where('email', $validated['email'])->exists()) {
                throw ValidationException::withMessages(['email' => ['Email ini sudah terdaftar.']]);
            }

            if (User::where('phone', $normalizedPhone)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages([
                    'phone' => ['Nomor HP ini sudah terdaftar.'],
                ]);
            }

            $user = User::create([
                'name' => PersonName::clean($validated['name']),
                'email' => $validated['email'],
                'phone' => $normalizedPhone,
                'password' => Hash::make($validated['password']),
                'role' => 'CUSTOMER',
                'is_active' => true,
            ]);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        $token = $user->createToken('flutter-mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registrasi akun berhasil.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'token' => $token,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required_without:email', 'string'],
            'email' => ['required_without:login', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($validated['login'] ?? $validated['email']);

        if (str_contains($identifier, '@')) {
            $user = User::where('email', $identifier)->first();
        } else {
            $normalizedPhone = PhoneNumber::normalize($identifier);
            $user = $normalizedPhone ? User::where('phone', $normalizedPhone)->first() : null;
        }

        // Jeda bertahap per akun, sama dengan login web (G13): sedang ditunda → ditolak tanpa memeriksa sandi.
        $wait = LoginThrottle::waitSeconds($user, $identifier);
        if ($wait > 0) {
            return $this->tooManyAttempts($wait);
        }

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            // Login API tidak lewat Auth::attempt(), jadi event Failed bawaan tidak terpicu — catat manual.
            // Akun yang dicoba dicatat sebagai subject, bukan pelaku (pemilik akun bukan yang mencoba).
            \App\Services\Audit\ActivityLogger::loginFailed($identifier, $user, viaApi: true);
            $delay = LoginThrottle::failed($user, $identifier, viaApi: true);
            if ($delay > 0) {
                return $this->tooManyAttempts($delay);
            }

            return response()->json([
                'success' => false,
                'message' => 'Email/No HP atau password yang Anda masukkan salah.',
                'errors' => null,
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda sedang dinonaktifkan.',
                'errors' => null,
            ], 403);
        }

        LoginThrottle::succeeded($user);
        $token = $user->createToken('flutter-mobile-app')->plainTextToken;

        \App\Services\Audit\ActivityLogger::record(
            module: 'AUTH',
            event: 'auth.login',
            description: 'Login ke sistem lewat aplikasi (API)',
            subject: $user,
            causer: $user,
        );

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'token' => $token,
            ],
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Profil pengguna berhasil diambil.',
            'data' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil. Token telah dicabut.',
        ]);
    }

    private function tooManyAttempts(int $seconds): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => LoginRequest::waitMessage($seconds),
            'errors' => null,
        ], 429)->header('Retry-After', (string) $seconds);
    }
}
