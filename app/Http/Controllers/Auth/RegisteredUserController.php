<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Customer\CustomerPhoneService;
use App\Support\ComingSoon;
use App\Support\PersonName;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View|RedirectResponse
    {
        // Mode Coming Soon: pendaftaran ditutup sampai grand opening.
        if (ComingSoon::active()) {
            return redirect()->route('login');
        }

        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if (ComingSoon::active()) {
            return redirect()->route('login');
        }

        $request->validate([
            'name' => PersonName::rules(),
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Modul 33: nomor HP wajib & unik — identitas yang sama dengan kasir POS dan app, supaya satu orang satu akun.
        $phone = PhoneNumber::normalize((string) $request->phone);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('account.register.phone_invalid')]);
        }

        // Klik ganda / dua tab: dua request lolos validasi bersamaan → dulu jadi dua akun dengan email sama.
        $locks = [Cache::lock('register:email:'.$request->email, 15), Cache::lock('register:phone:'.$phone, 15)];
        foreach ($locks as $i => $lock) {
            if (! $lock->get()) {
                foreach (array_slice($locks, 0, $i) as $held) {
                    $held->release();
                }

                throw ValidationException::withMessages(['email' => __('account.register.in_progress')]);
            }
        }

        try {
            if (User::where('email', $request->email)->exists()) {
                throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => 'email'])]);
            }
            if (app(CustomerPhoneService::class)->ownerOf($phone)) {
                throw ValidationException::withMessages(['phone' => __('account.register.phone_taken')]);
            }

            $user = User::create([
                'name' => PersonName::clean($request->name),
                'email' => $request->email,
                'phone' => $phone,
                'password' => Hash::make($request->password),
                'role' => 'CUSTOMER',
                'registration_source' => 'ONLINE',
            ]);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        event(new Registered($user));

        Auth::login($user);

        // Daftar dari link promo / halaman portal lain → kembali ke sana (path relatif yang aman), selain itu Dashboard.
        return redirect(AuthenticatedSessionController::customerIntended($request) ?? route('dashboard', absolute: false));
    }
}
