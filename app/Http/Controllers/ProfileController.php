<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Customer\CustomerAccountService;
use App\Services\Customer\CustomerPhoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, CustomerAccountService $account): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,
            'membership' => $account->activeMembership($user),
            'voucherCount' => $account->availableVoucherCount($user),
            'isSponsorPic' => $account->isSponsorPic($user),
        ]);
    }

    /** Voucher Saya (Modul 23): voucher saldo & voucher jam corporate, tab Tersedia / Riwayat. */
    public function vouchers(Request $request, CustomerAccountService $account): View
    {
        $tab = $request->query('tab') === 'history' ? 'history' : 'available';
        $vouchers = $account->vouchers($request->user());

        return view('profile.vouchers', [
            'tab' => $tab,
            'available' => $vouchers['available'],
            'history' => $vouchers['history'],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, CustomerPhoneService $phones): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        // Field tidak dikirim → nomor lama tetap (tidak terhapus diam-diam).
        if (! array_key_exists('phone', $validated)) {
            $validated['phone'] = $user->phone;
        }
        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if (! $user->isDirty('phone') || $user->phone === null) {
            $user->save();

            return Redirect::route('profile.edit')->with('status', 'profile-updated');
        }

        // Nomor HP baru: dikunci & dicek ulang supaya dua akun tidak menyimpan nomor yang sama bersamaan (Modul 33).
        $lock = Cache::lock('customer-phone:'.$user->phone, 10);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['phone' => __('account.profile.phone_busy')]);
        }

        try {
            if ($phones->ownerOf($user->phone, $user->id)) {
                throw ValidationException::withMessages(['phone' => __('account.profile.phone_taken')]);
            }

            $user->save();
        } finally {
            $lock->release();
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    // Hapus akun mandiri ditiadakan (Modul 33): riwayat booking, invoice, membership & voucher tetap melekat ke akun.
    // Penonaktifan / penghapusan akun hanya lewat admin (Akun Login).
}
