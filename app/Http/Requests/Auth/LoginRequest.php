<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $login = trim($this->input('email'));
        $password = $this->input('password');

        if (strcasecmp($login, 'admin') === 0) {
            $login = 'admin@club61.com';
        } elseif (! str_contains($login, '@')) {
            $normalizedPhone = PhoneNumber::normalize($login);

            $found = User::where(function ($query) use ($login, $normalizedPhone) {
                if ($normalizedPhone) {
                    $query->orWhere('phone', $normalizedPhone);
                }
                $query->orWhere('email', $login)
                    ->orWhere('email', $login.'@club61.com');
            })->first();

            if ($found) {
                $login = $found->email;
            }
        }

        // Batas percobaan dihitung dari identitas yang SUDAH di-resolve: dulu kuncinya teks mentah, jadi
        // "admin", email, nomor HP (0812…/+62812…) untuk akun yang sama masing-masing dapat jatah 5x.
        $this->resolvedLogin = Str::lower($login);
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => $login,
            'password' => $password,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            $this->hitRateLimits();

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Akun nonaktif (staf yang sudah keluar, customer yang diblokir) tidak boleh mendapat sesi web.
        if (Auth::user()?->is_active === false) {
            Auth::guard('web')->logout();
            $this->hitRateLimits();

            throw ValidationException::withMessages([
                'email' => \App\Http\Middleware\EnsureUserIsActive::MESSAGE,
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountThrottleKey());
    }

    /** Identitas login setelah alias/nomor HP di-resolve ke email (null sebelum authenticate() jalan). */
    protected ?string $resolvedLogin = null;

    /** Maks gagal per akun + IP (pengguna sah yang salah ketik). */
    public const MAX_ATTEMPTS_ACCOUNT_IP = 5;

    /** Maks gagal per akun dari IP mana pun dalam 15 menit (penyerang yang berganti-ganti IP). */
    public const MAX_ATTEMPTS_ACCOUNT = 20;

    /** Maks gagal per IP ke akun mana pun per menit (password spraying ke banyak akun). */
    public const MAX_ATTEMPTS_IP = 30;

    protected function hitRateLimits(): void
    {
        RateLimiter::hit($this->throttleKey());
        RateLimiter::hit($this->accountThrottleKey(), 15 * 60);
        RateLimiter::hit($this->ipThrottleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $limits = [
            $this->throttleKey() => self::MAX_ATTEMPTS_ACCOUNT_IP,
            $this->accountThrottleKey() => self::MAX_ATTEMPTS_ACCOUNT,
            $this->ipThrottleKey() => self::MAX_ATTEMPTS_IP,
        ];

        $blockedKey = null;
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $blockedKey = $key;
                break;
            }
        }

        if ($blockedKey === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($blockedKey);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate($this->loginIdentity().'|'.$this->ip());
    }

    public function accountThrottleKey(): string
    {
        return 'login-account:'.Str::transliterate($this->loginIdentity());
    }

    public function ipThrottleKey(): string
    {
        return 'login-ip:'.$this->ip();
    }

    protected function loginIdentity(): string
    {
        return $this->resolvedLogin ?? Str::lower(trim((string) $this->string('email')));
    }
}
