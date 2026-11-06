<?php

namespace App\Support;

class PhoneNumber
{
    /** Panjang kolom users.phone. */
    public const MAX_LENGTH = 20;

    /**
     * Normalize a loosely-formatted Indonesian phone number (e.g. "+62 812-3456-7890",
     * "6281234567890", "0812 3456 7890") to the canonical storage/lookup format "08xxxxxxxxxx".
     * Returns null when the input doesn't look like a phone number at all (too short, empty,
     * or an email address), so callers can safely fall back to other identifier checks.
     */
    public static function normalize(string $input): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '' || str_contains($trimmed, '@')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        if ($digits === '' || strlen($digits) < 8) {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        } elseif (! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        // Kolom users.phone = 20 karakter. Lebih panjang dari itu bukan nomor HP — dulu lolos lalu MySQL menolak
        // saat disimpan (error 500 di daftar web/app, profil, kasir walk-in, karyawan sponsor).
        if (strlen($digits) > self::MAX_LENGTH) {
            return null;
        }

        return $digits;
    }

    public static function looksLikePhone(string $input): bool
    {
        return self::normalize($input) !== null;
    }
}
