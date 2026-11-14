<?php

namespace App\Services\Audit;

use App\Models\Audit\ActivityLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Throwable;

/**
 * SATU-SATUNYA pintu tulis ke tabel activity_logs (Modul 16).
 *
 * Aturan yang dijaga di sini supaya tidak ada call site yang lupa:
 *   - Rahasia (password, token, key, hash QR, payload gateway mentah) TIDAK PERNAH disimpan —
 *     nilainya diganti "[disembunyikan]", nama kolomnya tetap tampil supaya owner tahu "sandi diganti".
 *   - Nama & role pelaku disimpan sebagai snapshot saat kejadian.
 *   - Gagal menulis log tidak boleh menggagalkan transaksi bisnis: error cukup masuk laravel.log.
 *     Kecuali deadlock / koneksi DB putus di dalam transaksi — itu dilempar ulang supaya transaksinya
 *     gagal bersih (bukan lanjut diam-diam dalam mode autocommit setelah transaksinya hilang).
 *   - Log ditulis di transaksi DB yang sama dengan aksinya — kalau aksinya di-rollback, log-nya
 *     ikut hilang, jadi log tidak pernah mengklaim sesuatu terjadi padahal tidak.
 */
class ActivityLogger
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

    public const INFO = 'INFO';

    public const WARNING = 'WARNING';

    public const CRITICAL = 'CRITICAL';

    public const REDACTED = '[disembunyikan]';

    /** Command terjadwal (routes/console.php) — dicatat dengan channel SCHEDULER. */
    private const SCHEDULED_COMMANDS = [
        'padel:release-expired-slots',
        'membership:sync-expired',
        'payment:reconcile-midtrans',
        'audit:prune',
        'signature:clean-files',
    ];

    private const SENSITIVE_KEYS = [
        'key', 'hash', 'pin', 'cvv', 'card_number', 'payload_log', 'payment_url', 'redirect_url',
    ];

    private const SENSITIVE_FRAGMENTS = ['password', 'secret', 'token', 'signature'];

    private const SENSITIVE_SUFFIXES = ['_key', '_hash'];

    /** Kolom yang namanya kebetulan mirip rahasia tapi isinya aman (nama ikon, bukan kunci). */
    private const SAFE_KEYS = ['icon_key'];

    private const MAX_STRING = 1000;

    private const MAX_JSON_BYTES = 20000;

    /** Jendela dedup login gagal per IP + identitas (detik). */
    public const LOGIN_FAILED_WINDOW = 60;

    public static function record(
        string $module,
        string $event,
        string $description,
        ?Model $subject = null,
        array $meta = [],
        string $severity = self::INFO,
        ?array $changes = null,
        ?Authenticatable $causer = null,
        bool $asSystem = false,
        ?string $subjectLabel = null,
    ): ?ActivityLog {
        try {
            $context = app(AuditContext::class);
            $request = app()->bound('request') ? request() : null;
            $channel = self::resolveChannel($request, $context);

            if ($asSystem) {
                // Aksi sistem yang kebetulan terpicu di request user (mis. sinkronisasi booking hangus saat
                // admin membuka halaman) — pelakunya tetap SISTEM, user-nya cuma dicatat sebagai pemicu.
                if ($trigger = self::currentUser()) {
                    $meta['dipicu_saat_dibuka_oleh'] = $trigger->name;
                }
                $causer = null;
            } else {
                $causer ??= self::currentUser();
            }

            // Semua teks di-scrub ke UTF-8 valid DULU (sebelum dipotong): byte rusak (mis. User-Agent
            // berisi \xFF) membuat insert MySQL utf8mb4 gagal (error 1366) dan log request itu hilang.
            return ActivityLog::create([
                'module' => Str::limit(self::utf8($module), 30, ''),
                'event' => Str::limit(self::utf8($event), 60, ''),
                'severity' => in_array($severity, [self::INFO, self::WARNING, self::CRITICAL], true) ? $severity : self::INFO,
                'description' => Str::limit(self::utf8($description), 252),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? self::utf8((string) $subject->getKey()) : null,
                'subject_label' => Str::limit(self::utf8($subjectLabel ?? ($subject ? AuditRegistry::labelFor($subject) : '')), 117) ?: null,
                'causer_id' => $causer?->getAuthIdentifier(),
                'causer_name' => $causer ? Str::limit(self::utf8((string) ($causer->name ?? '')), 97) : null,
                'causer_role' => $causer instanceof User ? Str::limit(self::utf8(self::roleLabel($causer)), 97) : null,
                'actor_type' => self::resolveActorType($causer, $channel, $asSystem),
                'channel' => $channel,
                'ip_address' => $request?->ip() !== null ? Str::limit(self::utf8((string) $request->ip()), 45, '') : null,
                'user_agent' => $request ? Str::limit(self::utf8((string) $request->userAgent()), 252) ?: null : null,
                'url' => $request && ! $context->consoleCommand ? Str::limit(self::utf8(self::safeUrl($request)), 252) : null,
                'http_method' => $request && ! $context->consoleCommand ? self::originalMethod($request) : null,
                'changes' => $changes ? self::capSize(self::sanitize($changes)) : null,
                'meta' => $meta ? self::capSize(self::sanitize($meta)) : null,
                'batch_id' => $context->batchId(),
            ]);
        } catch (Throwable $e) {
            // Deadlock / koneksi putus di tengah transaksi bisnis: transaksinya sudah batal di sisi DB.
            // Menelan error di sini membuat sisa aksi jalan dalam autocommit — lempar ulang supaya gagal bersih.
            if (self::mustPropagate($e)) {
                throw $e;
            }

            Log::error('[AUDIT] Gagal menulis log aktivitas: '.self::utf8($e->getMessage()), ['event' => self::utf8($event), 'exception' => $e::class]);

            return null;
        }
    }

    private static function mustPropagate(Throwable $e): bool
    {
        try {
            if (DB::transactionLevel() === 0) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $detector = new self;

        return $detector->causedByConcurrencyError($e) || $detector->causedByLostConnection($e);
    }

    /**
     * Percobaan aksi/halaman tanpa izin. Dedup 60 detik per user+alasan supaya klik berulang /
     * polling tidak membanjiri log.
     */
    public static function accessDenied(string $reason, array $meta = []): void
    {
        // Dipanggil dari hook $exceptions->respond (bootstrap/app.php): apa pun yang gagal di sini
        // TIDAK BOLEH dilempar — kalau lolos, respons 403 berubah jadi 500.
        try {
            $reason = trim((string) preg_replace('/^akses ditolak\s*:?\s*/i', '', self::utf8($reason))) ?: 'tidak memiliki izin';

            $user = self::currentUser();
            $request = app()->bound('request') ? request() : null;
            $path = $request ? self::originalPath($request) : '';

            $dedupKey = 'audit:denied:'.sha1(($user?->getAuthIdentifier() ?? $request?->ip() ?? '-').'|'.$path.'|'.$reason);
            try {
                if (! Cache::add($dedupKey, 1, 60)) {
                    return;
                }
            } catch (Throwable $e) {
                // Cache store bermasalah: lewati dedup, tetap catat.
                Log::warning('[AUDIT] Dedup akses ditolak tidak jalan: '.self::utf8($e->getMessage()));
            }

            self::record(
                module: 'AUTH',
                event: 'auth.access_denied',
                description: 'Akses ditolak: '.$reason,
                meta: $meta + ['halaman' => '/'.ltrim($path, '/')],
                severity: self::WARNING,
            );
        } catch (Throwable $e) {
            Log::error('[AUDIT] Gagal mencatat akses ditolak: '.self::utf8($e->getMessage()));
        }
    }

    /**
     * Login gagal (web & API). Dedup per IP + identitas yang dicoba: maksimal SATU baris per
     * LOGIN_FAILED_WINDOW detik — percobaan berikutnya di jendela yang sama cukup dihitung, lalu
     * jumlahnya ikut dicatat di baris berikutnya ("percobaan_tidak_dicatat"). Brute-force tetap
     * terlihat tanpa membanjiri tabel. Event lockout dicatat terpisah dan TIDAK di-dedup.
     *
     * Akun yang dicoba dicatat sebagai subject, BUKAN pelaku — pelakunya tamu yang belum login,
     * bukan pemilik akun (korban) yang kebetulan email/HP-nya diketik.
     */
    public static function loginFailed(string $identifier, ?Authenticatable $targetUser = null, bool $viaApi = false): void
    {
        try {
            $identifier = Str::limit(self::utf8(trim($identifier)), 80);
            $request = app()->bound('request') ? request() : null;
            $key = 'audit:login_failed:'.sha1(($request?->ip() ?? '-').'|'.mb_strtolower($identifier));
            $suppressed = 0;

            try {
                if (! Cache::add($key, 1, self::LOGIN_FAILED_WINDOW)) {
                    // Sudah dicatat di jendela ini — cukup dihitung (counter disimpan 1 hari, dibaca baris berikutnya).
                    Cache::add($key.':ditahan', 0, 86400);
                    Cache::increment($key.':ditahan');

                    return;
                }
                $suppressed = (int) Cache::pull($key.':ditahan', 0);
            } catch (Throwable $e) {
                // Cache bermasalah: tetap catat (tanpa dedup) daripada kehilangan jejak login gagal.
                Log::warning('[AUDIT] Dedup login gagal tidak jalan: '.self::utf8($e->getMessage()));
            }

            self::record(
                module: 'AUTH',
                event: 'auth.login_failed',
                description: ($viaApi ? 'Login API gagal' : 'Login gagal').' untuk "'.$identifier.'"'
                    .($suppressed > 0 ? " (+{$suppressed} percobaan sebelumnya tidak dicatat terpisah)" : ''),
                subject: $targetUser instanceof Model ? $targetUser : null,
                meta: array_filter([
                    'identitas_dicoba' => $identifier,
                    'akun_ditemukan' => $targetUser !== null,
                    'percobaan_tidak_dicatat' => $suppressed ?: null,
                ], fn ($v) => $v !== null),
                severity: self::WARNING,
            );
        } catch (Throwable $e) {
            Log::error('[AUDIT] Gagal mencatat login gagal: '.self::utf8($e->getMessage()));
        }
    }

    /**
     * Perubahan izin sebuah role. Spatie syncPermissions() tidak memicu model event, jadi dicatat
     * dari halaman Roles dengan membandingkan daftar izin sebelum & sesudah simpan.
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function rolePermissionsChanged(Model $role, array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return;
        }

        $labels = self::permissionLabels();
        $describe = fn (array $slugs) => array_map(fn ($s) => ($labels[$s] ?? $s)." [{$s}]", $slugs);
        $backdoorAdded = array_values(array_intersect($added, \App\Services\Permission\Club61PermissionMatrix::BACKDOOR_PERMISSIONS));

        self::record(
            module: 'USER_ROLE',
            event: 'role.permissions_changed',
            description: "Mengubah hak akses role \"{$role->name}\": +".count($added).' izin, -'.count($removed).' izin'
                .($backdoorAdded ? ' (TERMASUK IZIN SENSITIF: '.implode(', ', $backdoorAdded).')' : ''),
            subject: $role,
            meta: array_filter([
                'izin_ditambah' => $describe($added) ?: null,
                'izin_dicabut' => $describe($removed) ?: null,
                'izin_sensitif_ditambah' => $backdoorAdded ?: null,
            ]),
            severity: $backdoorAdded ? self::CRITICAL : self::WARNING,
        );
    }

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function userRolesChanged(User $user, array $before, array $after): void
    {
        sort($before);
        sort($after);
        if ($before === $after) {
            return;
        }

        self::record(
            module: 'USER_ROLE',
            event: 'user.roles_changed',
            description: "Mengubah role akun \"{$user->name}\": ".(implode(', ', $before) ?: 'customer').' -> '.(implode(', ', $after) ?: 'customer'),
            subject: $user,
            severity: in_array('super_admin', $after, true) && ! in_array('super_admin', $before, true) ? self::CRITICAL : self::WARNING,
            changes: ['roles' => ['old' => $before, 'new' => $after]],
        );
    }

    /** @return array<string, string> slug => label */
    private static function permissionLabels(): array
    {
        $labels = [];
        foreach (\App\Services\Permission\Club61PermissionMatrix::getMatrix() as $category) {
            foreach ($category['submodules'] as $submodule) {
                $labels += $submodule['actions'];
            }
        }

        return $labels;
    }

    /** Channel asal aksi saat ini (ADMIN_PANEL, POS_FNB, CUSTOMER_WEB, SCHEDULER, ...). */
    public static function currentChannel(): string
    {
        return self::resolveChannel(app()->bound('request') ? request() : null, app(AuditContext::class));
    }

    /** Path halaman asal aksi (untuk request Livewire: halaman yang memuat komponennya). */
    public static function currentPath(): string
    {
        $request = app()->bound('request') ? request() : null;

        return $request && ! app(AuditContext::class)->consoleCommand ? trim(self::originalPath($request), '/') : '';
    }

    public static function rupiah(float $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.number_format(abs($amount), 0, ',', '.');
    }

    public static function suppressed(): bool
    {
        return app(AuditContext::class)->suppressed > 0;
    }

    /** Matikan pencatatan otomatis (model event) selama callback — dipakai seeder/migrasi. */
    public static function withoutLogging(callable $callback): mixed
    {
        $context = app(AuditContext::class);
        $context->suppressed++;

        try {
            return $callback();
        } finally {
            $context->suppressed--;
        }
    }

    /**
     * Bersihkan nilai sensitif secara rekursif. Nama kolom tetap disimpan, nilainya diganti
     * REDACTED — supaya jelas "password diganti" tanpa pernah menyimpan password-nya.
     */
    public static function sanitize(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value) && count($value) === 2
                ? ['old' => self::REDACTED, 'new' => self::REDACTED]
                : self::REDACTED;
        }

        if ($depth > 6) {
            return '[terlalu dalam]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                // Kunci 'old'/'new' adalah struktur diff, bukan nama kolom — pakai key induk untuk redaksi.
                $clean[is_string($k) ? self::utf8($k) : $k] = self::sanitize($v, is_string($k) && ! in_array($k, ['old', 'new'], true) ? $k : null, $depth + 1);
            }

            return $clean;
        }

        return self::normalizeScalar($value);
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::SAFE_KEYS, true)) {
            return false;
        }

        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function currentUser(): ?Authenticatable
    {
        try {
            return Auth::user();
        } catch (Throwable) {
            return null;
        }
    }

    public static function roleLabel(User $user): string
    {
        $roles = $user->getRoleNames();

        return $roles->isEmpty() ? 'customer' : $roles->implode(', ');
    }

    private static function normalizeScalar(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => $value->value,
            is_string($value) => Str::limit(self::utf8($value), self::MAX_STRING),
            $value instanceof \Stringable => Str::limit(self::utf8((string) $value), self::MAX_STRING),
            is_object($value) && method_exists($value, 'toArray') => self::sanitize($value->toArray()),
            default => '['.get_debug_type($value).']',
        };
    }

    /** Ganti byte yang bukan UTF-8 valid dengan "?" — kolom & JSON utf8mb4 menolak byte rusak. */
    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }

    private static function capSize(array $data): array
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json !== false && strlen($json) <= self::MAX_JSON_BYTES) {
            return $data;
        }

        return ['_catatan' => 'Data terlalu besar, dipotong.', '_ringkasan' => Str::limit((string) $json, 2000)];
    }

    private static function resolveChannel(?Request $request, AuditContext $context): string
    {
        if ($context->consoleCommand !== null) {
            return in_array($context->consoleCommand, self::SCHEDULED_COMMANDS, true) ? 'SCHEDULER' : 'CONSOLE';
        }

        if (! $request) {
            return 'SYSTEM';
        }

        $path = trim(self::originalPath($request), '/');
        $firstSegment = explode('/', $path)[0];

        return match (true) {
            in_array($firstSegment, ['login', 'logout', 'register', 'forgot-password', 'reset-password', 'verify-email', 'confirm-password'], true) => 'LOGIN_PAGE',
            str_starts_with($path, 'admin/book-offline-court') => 'POS_WALKIN',
            str_starts_with($path, 'admin') => 'ADMIN_PANEL',
            str_starts_with($path, 'pos/check-in') => 'POS_CHECKIN',
            str_starts_with($path, 'pos') => 'POS_FNB',
            str_starts_with($path, 'kitchen') => 'KDS',
            // Notifikasi DOKU (/payments/doku/notification & /payments/doku-snap/...) tidak memuat kata "webhook" — dulu
            // tercatat sebagai Web Customer dengan pelaku "Tamu (belum login)".
            str_starts_with($path, 'api/') && (str_contains($path, 'webhook') || str_starts_with($path, 'api/v1/payments/doku')) => 'WEBHOOK',
            str_starts_with($path, 'api/') => $request->bearerToken() ? 'MOBILE_API' : 'CUSTOMER_WEB',
            $path === '' && app()->runningInConsole() => 'SYSTEM',
            default => 'CUSTOMER_WEB',
        };
    }

    private static function resolveActorType(?Authenticatable $causer, string $channel, bool $asSystem): string
    {
        if ($asSystem) {
            return 'SYSTEM';
        }

        if ($causer instanceof User) {
            return $causer->isCustomer() ? 'CUSTOMER' : 'STAFF';
        }

        return match ($channel) {
            'WEBHOOK' => 'WEBHOOK',
            'SCHEDULER', 'CONSOLE', 'SYSTEM' => 'SYSTEM',
            default => 'GUEST',
        };
    }

    private static function originalPath(Request $request): string
    {
        try {
            if (self::isLivewireUpdate($request)) {
                $path = Livewire::originalPath();
                if (is_string($path) && $path !== 'POST') {
                    return $path;
                }
            }
        } catch (Throwable) {
            // bukan request Livewire yang valid — jatuh ke path request biasa
        }

        return $request->path();
    }

    private static function originalMethod(Request $request): string
    {
        return self::isLivewireUpdate($request) ? 'LIVEWIRE' : $request->method();
    }

    /**
     * Header X-Livewire & components.0.snapshot dikirim CLIENT. Livewire::isLivewireRequest() cuma
     * mengecek header itu, jadi di route lain (API login, webhook) siapa pun bisa memalsukan path asal
     * -> channel/pelaku/url di log ikut palsu (mis. login API tercatat sebagai WEBHOOK). Path dari
     * snapshot hanya dipercaya di endpoint update Livewire sendiri (route bernama *livewire.update,
     * yang snapshot-nya diverifikasi checksum oleh Livewire sebelum komponen dijalankan).
     */
    private static function isLivewireUpdate(Request $request): bool
    {
        try {
            return $request->hasHeader('X-Livewire') && (bool) $request->route()?->named('*livewire.update');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * URL tanpa query string (bisa berisi signature/token) dan dengan segmen mirip token
     * (mis. /reset-password/{token}) disamarkan.
     */
    private static function safeUrl(Request $request): string
    {
        $segments = array_map(
            fn (string $segment) => preg_match('/^[A-Za-z0-9_\-]{40,}$/', $segment) ? '[token]' : $segment,
            explode('/', trim(self::originalPath($request), '/'))
        );

        return '/'.implode('/', $segments);
    }
}
