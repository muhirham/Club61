<?php

namespace App\Services\Customer;

use App\Models\User;
use App\Support\PhoneNumber;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Modul 33 — satu orang = satu nomor HP = satu akun. Nomor HP (dinormalisasi "08…") adalah identitas yang dipakai web,
 * app, dan kasir POS untuk mengenali orang yang sama. Keunikan dijaga di sini (unique index users(phone, deleted_at)
 * tidak berlaku di MySQL selama deleted_at NULL).
 */
class CustomerPhoneService
{
    public const INVALID = 'Nomor HP tidak valid (minimal 8 digit angka).';

    /** Akun aktif lain yang sudah memakai nomor ini (null = bebas). */
    public function ownerOf(string $phone, ?string $ignoreUserId = null): ?User
    {
        $normalized = PhoneNumber::normalize($phone);
        if ($normalized === null) {
            return null;
        }

        return User::where('phone', $normalized)
            ->when($ignoreUserId, fn ($q) => $q->whereKeyNot($ignoreUserId))
            ->first();
    }

    /**
     * Kasir mengisi nomor HP akun customer yang BELUM punya nomor (mis. akun daftar web lama), supaya kunjungan
     * berikutnya dikenali lewat nomor HP. Tidak pernah menimpa nomor yang sudah ada, tidak untuk akun staf.
     */
    public function attachToCustomer(User $customer, string $phone): User
    {
        $normalized = PhoneNumber::normalize($phone);
        if ($normalized === null) {
            throw new DomainException(self::INVALID);
        }

        $lock = Cache::lock('customer-phone:'.$normalized, 10);
        if (! $lock->get()) {
            throw new DomainException('Nomor HP ini sedang diproses. Coba lagi sebentar.');
        }

        try {
            return DB::transaction(function () use ($customer, $normalized) {
                $fresh = User::whereKey($customer->id)->lockForUpdate()->first();
                if (! $fresh || ! $fresh->isCustomer()) {
                    throw new DomainException('Nomor HP hanya bisa ditambahkan ke akun customer.');
                }
                if (filled($fresh->phone)) {
                    throw new DomainException('Akun ini sudah punya nomor HP ('.$fresh->phone.'). Perubahan nomor dilakukan customer di Edit Profil atau admin di Data Pelanggan.');
                }
                if ($owner = $this->ownerOf($normalized, $fresh->id)) {
                    throw new DomainException('Nomor '.$normalized.' sudah dipakai akun "'.$owner->name.'". Pilih akun itu, atau cek ulang nomornya.');
                }

                $fresh->forceFill(['phone' => $normalized])->save();

                return $fresh;
            });
        } finally {
            $lock->release();
        }
    }
}
