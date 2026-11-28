<?php

namespace App\Support\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Value;

/**
 * Kartu "Usage" Pulse (akun paling aktif) hanya menghitung aktivitas orang — membuka halaman / klik — bukan request
 * yang dikirim browser sendiri berkala. Tanpa ini, tablet kasir yang dibiarkan menyala tetap "aktif" sepanjang hari.
 * Halaman yang seluruhnya dikecualikan (link bio, TV signage, cek hak akses, sambungan realtime) ada di
 * config/pulse.php (UserRequests ignore).
 */
final class UsageRequestFilter
{
    /**
     * Method Livewire yang dipanggil otomatis:
     * - $refresh: refresh jadwal (filament/realtime-script: Reverb / cadangan tiap 30 dtk), wire:poll tanpa method
     *   (mis. lonceng notifikasi Filament);
     * - __lazyLoad / __lazyLoadIsland: bagian halaman yang dimuat menyusul saat halaman dibuka;
     * - pollPendingQris: popup QR kasir menunggu pembayaran (tiap 3 dtk);
     * - screenLastSeen: status Online / Offline TV di halaman Signage.
     */
    public const AUTOMATIC_CALLS = ['$refresh', '__lazyLoad', '__lazyLoadIsland', 'pollPendingQris', 'screenLastSeen'];

    /** Event Livewire yang dikirim otomatis (partials/access-watch: menu sidebar diperbarui setelah hak akses berubah). */
    public const AUTOMATIC_EVENTS = ['refresh-sidebar'];

    public function __invoke(Entry|Value $entry): bool
    {
        if (! $entry instanceof Entry || $entry->type !== 'user_request') {
            return true;
        }

        return ! self::isAutomatic(request());
    }

    public static function isAutomatic(Request $request): bool
    {
        // partials/access-watch: cek apakah halaman masih boleh dibuka setelah hak akses berubah.
        if ($request->headers->has('X-Club61-Access-Probe')) {
            return true;
        }

        // Halaman tiket customer menanyakan status bayar berkala (booking & membership) — hanya polling yang memakai ini.
        if ($request->query('verify_payment')) {
            return true;
        }

        $route = $request->route();

        return $route instanceof Route && $route->named('*livewire.update') && self::isAutomaticLivewire($request);
    }

    /** Satu request Livewire bisa membawa beberapa komponen: otomatis hanya kalau tidak ada isian & semua panggilannya otomatis. */
    private static function isAutomaticLivewire(Request $request): bool
    {
        $components = $request->input('components');
        if (! is_array($components)) {
            return false;
        }

        $sawAutomaticCall = false;

        foreach ($components as $component) {
            if (! is_array($component) || ! empty($component['updates'] ?? [])) {
                return false;
            }

            foreach ((array) ($component['calls'] ?? []) as $call) {
                $method = is_array($call) ? ($call['method'] ?? null) : null;

                $automatic = $method === '__dispatch'
                    ? self::isAutomaticEvent($call['params'][0] ?? null)
                    : in_array($method, self::AUTOMATIC_CALLS, true);

                if (! $automatic) {
                    return false;
                }

                $sawAutomaticCall = true;
            }
        }

        return $sawAutomaticCall;
    }

    private static function isAutomaticEvent(mixed $event): bool
    {
        // echo:* / echo-private:* = event Reverb (broadcast), bukan klik.
        return is_string($event) && (in_array($event, self::AUTOMATIC_EVENTS, true) || str_starts_with($event, 'echo'));
    }
}
