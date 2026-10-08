<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aman dijalankan berulang di database yang sudah berisi data:
 *   php artisan db:seed --class=DemoAccessSeeder
 *
 * - Role baru dapat preset Club61PermissionMatrix. Role yang sudah ada TIDAK ditimpa (centangan
 *   manual dari menu Roles & Hak Akses aman) — hanya izin pintu belakang yang dicabut.
 * - Membuat akun demo yang belum ada. Akun yang sudah ada TIDAK di-reset sandinya.
 */
class DemoAccessSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Club61!@#';

    private const ROLES = [
        'super_admin' => ['Super Administrator Full Access', '/admin'],
        'admin' => ['Administrator Backoffice', '/admin'],
        'cashier' => ['Kasir Frontdesk & POS Terminal', '/pos'],
        // HOME ROUTE null = otomatis ke halaman pertama yang dicentang di matriks izin (HomeRouteResolver).
        'receptionist' => ['Resepsionis Frontdesk (Walk-In Booking & Check-In Tiket)', null],
        'kitchen' => ['Koki Dapur & Barista KDS', '/kitchen'],
        'customer' => ['Pelanggan & Member Club', '/dashboard'],
    ];

    private const ACCOUNTS = [
        ['admin@club61.com', 'Super Admin Club 61', '08110000001', 'super_admin'],
        ['manager@club61.com', 'Admin Venue Club 61', '08110000006', 'admin'],
        ['resepsionis@club61.com', 'Resepsionis Frontdesk', '08110000007', 'receptionist'],
        ['cashier@club61.com', 'Kasir Frontdesk POS', '08110000002', 'cashier'],
    ];

    public function run(): void
    {
        if (app()->environment('production') && ! app()->runningUnitTests()) {
            if (! ($this->command && $this->command->confirm('PERINGATAN: Seeder ini membuat akun demo dengan sandi default di PRODUCTION. Lanjutkan?', false))) {
                $this->command?->warn('Dibatalkan demi keamanan data production.');

                return;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Club61PermissionMatrix::syncAllPermissions('web');

        foreach (self::ROLES as $name => [$description, $homeRoute]) {
            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description, 'home_route' => $homeRoute]
            );

            // Role baru: pasang preset. Role yang sudah ada: JANGAN timpa centangan manual dari
            // menu Roles & Hak Akses — cukup pastikan izin pintu belakang tidak ikut terpegang.
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(Club61PermissionMatrix::defaultRolePermissions($name));
            } elseif ($name !== 'super_admin') {
                $role->revokePermissionTo(
                    $role->permissions->pluck('name')->intersect(Club61PermissionMatrix::BACKDOOR_PERMISSIONS)->all()
                );
            }
        }

        foreach (self::ACCOUNTS as [$email, $name, $phone, $roleName]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'phone' => $phone,
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                    'is_active' => true,
                ]
            );
            $user->syncRoles([$roleName]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
