<?php

namespace App\Support\Monitoring;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Nama akun di kartu Pulse (mis. Usage): nama + role, bukan email. Tanpa foto — bawaan Pulse memanggil Gravatar
 * (alamat email di-hash dikirim ke layanan luar), jadi sengaja tidak dipakai.
 */
final class PulseUserLabel
{
    public const ROLE_LABELS = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'cashier' => 'Kasir',
        'receptionist' => 'Resepsionis',
        'kitchen' => 'Dapur',
        'trainer' => 'Pelatih',
        'customer' => 'Customer',
    ];

    /** @return array{name: string, extra: string} */
    public static function fields(Authenticatable $user): array
    {
        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames() : collect();

        $labels = $roles->isEmpty()
            ? ['Customer']
            : $roles->map(fn (string $role) => self::ROLE_LABELS[strtolower($role)] ?? Str::headline($role))->all();

        return [
            'name' => (string) ($user->name ?? 'ID: '.$user->getAuthIdentifier()),
            'extra' => implode(', ', $labels),
        ];
    }
}
