<?php

namespace App\Services\Permission;

use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Throwable;
use UnitEnum;

/**
 * Halaman pendaratan setelah login (dan tombol "buka dashboard" untuk staf).
 *
 * 1. Role memilih HOME ROUTE yang terdaftar (Role::getHomeRouteOptions) → dipakai.
 *    "/admin" tanpa izin Dashboard → halaman admin pertama yang boleh dibuka (bukan 403).
 * 2. HOME ROUTE kosong / tidak terdaftar (mis. sisa seeder lama "/admin/book-offline-court") → otomatis
 *    dari permission: Dashboard admin → Terminal POS → KDS Dapur → halaman admin pertama yang dicentang
 *    (urutan menu sidebar). Contoh: role yang hanya dicentang Buku Transaksi → /admin/buku-transaksi.
 */
class HomeRouteResolver
{
    public const ADMIN = '/admin';

    public const POS = '/pos';

    public const KITCHEN = '/kitchen';

    public const CUSTOMER = '/dashboard';

    /** Home route yang dipilih eksplisit di role (hanya nilai terdaftar), atau null = otomatis. */
    public static function configured(User $user): ?string
    {
        $route = trim((string) ($user->roles()->first()?->home_route ?? ''));

        return $route !== '' && array_key_exists($route, Role::getHomeRouteOptions()) ? $route : null;
    }

    public static function resolve(User $user): string
    {
        $configured = self::configured($user);

        if ($configured === self::ADMIN) {
            return self::adminLanding() ?? self::ADMIN;
        }

        return $configured ?? self::automatic($user);
    }

    /** Tujuan otomatis dari permission user (dipakai bila HOME ROUTE tidak dipilih). */
    public static function automatic(User $user): string
    {
        if (self::canOpenDashboard()) {
            return self::ADMIN;
        }

        if ($user->can('access_pos_terminal') || ($user->hasRole('cashier') && $user->roles->count() === 1)) {
            return self::POS;
        }

        if (($user->hasRole('kitchen') && $user->roles->count() === 1)
            || (($user->hasRole('kitchen') || $user->isAdmin()) && $user->can('view_kitchen_kds'))) {
            return self::KITCHEN;
        }

        if ($user->canAccessPanel(Filament::getPanel('admin'))) {
            return self::firstAdminPage() ?? self::ADMIN;
        }

        return self::CUSTOMER;
    }

    /** Dashboard admin bila diizinkan, selain itu halaman admin pertama di menu yang boleh dibuka. */
    public static function adminLanding(): ?string
    {
        return self::canOpenDashboard() ? self::ADMIN : self::firstAdminPage();
    }

    /** URL (path) halaman/resource admin pertama yang boleh dibuka, mengikuti urutan menu sidebar. */
    public static function firstAdminPage(): ?string
    {
        try {
            $panel = Filament::getPanel('admin');
            $groupOrder = array_values(array_map(
                fn ($group) => is_string($group) ? $group : (method_exists($group, 'getLabel') ? $group->getLabel() : (string) $group),
                $panel->getNavigationGroups()
            ));

            $candidates = [];
            foreach ([...$panel->getPages(), ...$panel->getResources()] as $class) {
                if (! $class::shouldRegisterNavigation() || ! $class::canAccess()) {
                    continue;
                }

                $group = $class::getNavigationGroup();
                $group = $group instanceof UnitEnum ? $group->name : $group;
                $groupIndex = $group === null ? -1 : (array_search($group, $groupOrder, true) === false ? PHP_INT_MAX : array_search($group, $groupOrder, true));

                $candidates[] = [
                    'class' => $class,
                    'key' => [$groupIndex, $class::getNavigationSort() ?? PHP_INT_MAX, (string) $class::getNavigationLabel()],
                ];
            }

            usort($candidates, fn ($a, $b) => $a['key'] <=> $b['key']);

            foreach ($candidates as $candidate) {
                $class = $candidate['class'];
                $url = is_subclass_of($class, \Filament\Resources\Resource::class)
                    ? $class::getUrl('index', panel: 'admin')
                    : $class::getUrl(panel: 'admin');
                $path = parse_url($url, PHP_URL_PATH);

                if (is_string($path) && $path !== '') {
                    return $path;
                }
            }
        } catch (Throwable) {
            // Panel belum siap (mis. konteks konsol) → biarkan pemanggil memakai fallback /admin.
        }

        return null;
    }

    private static function canOpenDashboard(): bool
    {
        try {
            return \App\Filament\Pages\Dashboard::canAccess();
        } catch (Throwable) {
            return false;
        }
    }
}
