<?php

namespace App\Services\Signature;

use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Signature\SignatureContent;
use App\Models\Signature\SignatureRunningText;
use App\Models\Signature\SignatureScreen;
use App\Models\Signature\SignatureSponsor;
use App\Models\Signature\SignatureTemplate;
use App\Models\Signature\SignatureVersion;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Modul 26 — Signature (layar TV venue).
 *
 * - Yang diedit admin (template, konten, running text, sponsor, pengaturan layar) = DRAFT.
 * - buildPayload() merangkum draft (+ perubahan form yang belum disimpan, untuk Preview) jadi satu JSON tampilan.
 * - publish() menyimpan JSON itu sebagai versi baru; TV hanya menayangkan versi yang ditunjuk layar.
 * - liveData() = data yang berubah terus (monitoring lapangan, jadwal, slot kosong, notifikasi, running text otomatis),
 *   diambil TV tiap ±12 detik. Hanya data yang tampil di layar — tanpa No. HP, email, atau nominal.
 */
class SignatureService
{
    public const TIMEZONE = 'Asia/Jakarta';

    /** Preset dari sketsa owner. Posisi zona dalam persen kanvas 16:9. */
    public const LAYOUTS = [
        'PRESET_A' => [
            'label' => 'A — Konten + Panel Samping + Running Text',
            'zones' => [
                ['key' => 'main', 'kind' => 'playlist', 'x' => 0, 'y' => 0, 'w' => 75, 'h' => 91],
                ['key' => 'side', 'kind' => 'side_panel', 'x' => 75, 'y' => 0, 'w' => 25, 'h' => 91],
                ['key' => 'ticker', 'kind' => 'ticker', 'x' => 0, 'y' => 91, 'w' => 100, 'h' => 9],
            ],
        ],
        'PRESET_B' => [
            'label' => 'B — Full Slider',
            'zones' => [
                ['key' => 'main', 'kind' => 'playlist', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100],
            ],
        ],
        'PRESET_C' => [
            'label' => 'C — Full + Running Text',
            'zones' => [
                ['key' => 'main', 'kind' => 'playlist', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 91],
                ['key' => 'ticker', 'kind' => 'ticker', 'x' => 0, 'y' => 91, 'w' => 100, 'h' => 9],
            ],
        ],
    ];

    // ------------------------------------------------------------------ TEMPLATE CUSTOM (drag & drop)

    public const CUSTOM_LAYOUT = 'CUSTOM';

    public const CUSTOM_LABEL = 'Custom — susun sendiri (drag & drop)';

    /** Kanvas editor custom: grid 24 × 24 di atas layar 16:9. Disimpan dalam satuan grid, dikirim ke TV dalam persen. */
    public const GRID = 24;

    public const MAX_ZONES = 6;

    /** Jenis zona custom: label & batas ukuran (satuan grid) supaya isinya tetap terbaca di TV. */
    public const ZONE_KINDS = [
        'playlist' => ['label' => 'Zona konten', 'min_w' => 4, 'min_h' => 4, 'max_h' => self::GRID, 'max' => self::MAX_ZONES],
        'side_panel' => ['label' => 'Panel samping', 'min_w' => 4, 'min_h' => 6, 'max_h' => self::GRID, 'max' => 2],
        'ticker' => ['label' => 'Running text', 'min_w' => 6, 'min_h' => 2, 'max_h' => 4, 'max' => 1],
    ];

    /** Font Google yang terbaca dari jauh (dimuat di halaman TV). */
    public const FONTS = ['Archivo', 'Source Serif 4', 'Montserrat', 'Poppins', 'Bebas Neue', 'JetBrains Mono'];

    public const DEFAULT_THEME = [
        'background' => '#1E120F',
        'panel' => '#2A1A16',
        'accent' => '#662721',
        'text' => '#F7F0DB',
        'font' => 'Archivo',
        'transition' => 'fade',
    ];

    /** Animasi pergantian konten di zona playlist (ringan, CSS — aman untuk browser Smart TV). */
    public const TRANSITIONS = [
        'fade' => 'Pudar (fade)',
        'slide' => 'Geser dari kanan',
        'zoom' => 'Zoom halus',
        'none' => 'Tanpa animasi',
    ];

    public const DEFAULT_TICKER = [
        'font' => 'Archivo',
        'font_size' => 44,
        'speed' => 120,
        'direction' => 'left',
        'background' => '#662721',
        'color' => '#F7F0DB',
        'separator' => '•',
    ];

    /** Widget sistem yang selalu tersedia di library konten. */
    private const DEFAULT_WIDGETS = [
        'MONITORING' => ['Monitoring Lapangan', 20],
        'SCHEDULE' => ['Jadwal Hari Ini', 15],
        'SPONSOR' => ['Sponsor', 12],
        'CLOCK' => ['Jam & Tanggal', 10],
    ];

    /** Lama notifikasi booking / check-in dianggap "baru" (detik). */
    public const EVENT_WINDOW = 180;

    // ================================================================== DATA AWAL

    /** Layar utama + 3 template preset + widget + running text otomatis. Aman dipanggil berulang. */
    public function ensureDefaults(): SignatureScreen
    {
        return DB::transaction(function () {
            foreach (self::DEFAULT_WIDGETS as $type => [$name, $duration]) {
                SignatureContent::firstOrCreate(['type' => $type], ['name' => $name, 'duration_seconds' => $duration, 'is_active' => true]);
            }

            if (! SignatureTemplate::exists()) {
                $monitoring = SignatureContent::where('type', 'MONITORING')->first();
                $schedule = SignatureContent::where('type', 'SCHEDULE')->first();
                foreach (['PRESET_A' => 'Monitoring + Panel', 'PRESET_B' => 'Full Slider', 'PRESET_C' => 'Full + Running Text'] as $layout => $name) {
                    $template = SignatureTemplate::create(['name' => $name, 'layout' => $layout, 'theme' => self::DEFAULT_THEME, 'ticker' => self::DEFAULT_TICKER]);
                    $template->playlistItems()->create(['zone_key' => 'main', 'content_id' => $monitoring->id, 'sort_order' => 1]);
                    $template->playlistItems()->create(['zone_key' => 'main', 'content_id' => $schedule->id, 'sort_order' => 2]);
                }
            }

            foreach (SignatureRunningText::DEFAULT_TEXTS as $kind => $text) {
                SignatureRunningText::firstOrCreate(['kind' => $kind], ['text' => $text, 'is_active' => true, 'sort_order' => 100]);
            }

            return SignatureScreen::orderBy('created_at')->first() ?? SignatureScreen::create([
                'name' => 'TV Lobby',
                'token' => $this->newToken(),
                'template_id' => SignatureTemplate::where('layout', 'PRESET_A')->value('id'),
                'name_display' => 'FULL',
                'sound_enabled' => true,
                'operating_hours_only' => true,
            ]);
        });
    }

    /** Layar TV baru (link sendiri, template pertama). Belum menayangkan apa pun sampai Tayangkan ditekan. */
    public function createScreen(User $user): SignatureScreen
    {
        $screen = SignatureScreen::create([
            'name' => 'Layar '.(SignatureScreen::count() + 1),
            'token' => $this->newToken(),
            'template_id' => SignatureTemplate::orderBy('created_at')->value('id'),
            'name_display' => 'FULL',
            'sound_enabled' => true,
            'operating_hours_only' => true,
        ]);
        ActivityLogger::record(module: 'SIGNATURE', event: 'signature.screen_created', description: "Layar {$screen->name} ditambahkan", subject: $screen, causer: $user);

        return $screen;
    }

    /** Hapus layar beserta riwayat tayangannya; link TV-nya langsung tidak berlaku. Minimal satu layar tersisa. */
    public function deleteScreen(SignatureScreen $screen, User $user): void
    {
        DB::transaction(function () use ($screen, $user) {
            if (SignatureScreen::lockForUpdate()->count() <= 1) {
                throw new HttpException(422, 'Minimal harus ada satu layar.');
            }
            ActivityLogger::record(module: 'SIGNATURE', event: 'signature.screen_deleted', description: "Layar {$screen->name} dihapus (link TV-nya tidak berlaku lagi)", subject: $screen, severity: ActivityLogger::WARNING, causer: $user);
            $screen->delete();
        });
    }

    public function regenerateToken(SignatureScreen $screen, User $user): string
    {
        $screen->update(['token' => $this->newToken()]);
        ActivityLogger::record(module: 'SIGNATURE', event: 'signature.link_regenerated', description: "Link layar {$screen->name} dibuat ulang (link lama tidak berlaku)", subject: $screen, severity: ActivityLogger::WARNING, causer: $user);

        return $screen->token;
    }

    /**
     * Kode link layar: pendek supaya bisa diketik pakai remote TV (/tv/{kode}), tanpa huruf/angka yang mirip (0/o, 1/l/i).
     * 10 karakter dari 31 simbol ≈ 8×10^14 kemungkinan + throttle 120/menit per IP → tidak bisa ditebak. Link lama
     * (48 karakter) tetap berlaku sampai "Buat Ulang Link".
     */
    public const TOKEN_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public const TOKEN_LENGTH = 10;

    private function newToken(): string
    {
        do {
            $token = '';
            for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
                $token .= self::TOKEN_ALPHABET[random_int(0, strlen(self::TOKEN_ALPHABET) - 1)];
            }
        } while (SignatureScreen::where('token', $token)->exists());

        return $token;
    }

    // ================================================================== ZONA TEMPLATE

    /**
     * Rapikan & periksa susunan zona template custom (satuan grid). Dipakai saat simpan, Preview, dan saat menyusun
     * tampilan TV — jadi data rusak / dikirim manual tidak pernah sampai ke TV.
     *
     * @return list<array{key: string, kind: string, x: int, y: int, w: int, h: int, sound?: bool}>
     *
     * @throws ValidationException pesan untuk admin di kunci `tplZones`
     */
    public static function normalizeCustomZones(mixed $zones): array
    {
        $fail = function (string $message): never {
            throw ValidationException::withMessages(['tplZones' => $message]);
        };

        if (! is_array($zones) || $zones === []) {
            $fail('Tambahkan minimal satu zona konten.');
        }
        if (count($zones) > self::MAX_ZONES) {
            $fail('Maksimal '.self::MAX_ZONES.' zona per template.');
        }

        $clean = [];
        $keys = [];
        $counts = [];
        $withSound = 0;
        foreach (array_values($zones) as $zone) {
            $kind = is_array($zone) ? ($zone['kind'] ?? null) : null;
            $rule = is_string($kind) ? (self::ZONE_KINDS[$kind] ?? null) : null;
            $key = is_array($zone) && is_string($zone['key'] ?? null) ? $zone['key'] : '';
            if (! $rule || ! preg_match('/^[a-z0-9]{1,12}$/', $key) || isset($keys[$key])) {
                $fail('Susunan zona tidak valid. Tutup editor lalu buka lagi.');
            }
            $keys[$key] = true;

            $box = [];
            foreach (['x', 'y', 'w', 'h'] as $field) {
                $value = $zone[$field] ?? null;
                if (! is_numeric($value) || (int) $value != $value) {
                    $fail('Susunan zona tidak valid. Tutup editor lalu buka lagi.');
                }
                $box[$field] = (int) $value;
            }
            if ($box['x'] < 0 || $box['y'] < 0 || $box['x'] + $box['w'] > self::GRID || $box['y'] + $box['h'] > self::GRID) {
                $fail('Ada zona yang keluar dari layar.');
            }
            if ($box['w'] < $rule['min_w'] || $box['h'] < $rule['min_h'] || $box['h'] > $rule['max_h']) {
                $fail("{$rule['label']} minimal {$rule['min_w']} × {$rule['min_h']} kotak".($rule['max_h'] < self::GRID ? " dan tinggi maksimal {$rule['max_h']} kotak" : '').'.');
            }

            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            if ($counts[$kind] > $rule['max']) {
                $fail("Maksimal {$rule['max']} {$rule['label']} per template.");
            }

            $item = ['key' => $key, 'kind' => $kind] + $box;
            if ($kind === 'playlist') {
                $item['sound'] = (bool) ($zone['sound'] ?? false);
                $withSound += (int) $item['sound'];
            }
            $clean[] = $item;
        }

        if (($counts['playlist'] ?? 0) === 0) {
            $fail('Tambahkan minimal satu zona konten.');
        }
        if ($withSound > 1) {
            $fail('Hanya satu zona yang boleh bersuara.');
        }
        foreach ($clean as $i => $a) {
            foreach (array_slice($clean, $i + 1) as $b) {
                if ($a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w'] && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h']) {
                    $fail('Ada zona yang saling menumpuk.');
                }
            }
        }

        return $clean;
    }

    /** Zona dalam persen kanvas (untuk TV & gambar mini template): preset dari LAYOUTS, custom dari satuan grid. */
    public static function layoutZones(string $layout, mixed $customZones = null): array
    {
        if ($layout === self::CUSTOM_LAYOUT) {
            try {
                $zones = self::normalizeCustomZones($customZones);
            } catch (ValidationException) {
                return self::LAYOUTS['PRESET_B']['zones'];
            }
            $percent = fn (int $units) => round($units * 100 / self::GRID, 4);

            return array_map(fn (array $z) => array_merge($z, ['x' => $percent($z['x']), 'y' => $percent($z['y']), 'w' => $percent($z['w']), 'h' => $percent($z['h'])]), $zones);
        }

        return self::LAYOUTS[$layout]['zones'] ?? self::LAYOUTS['PRESET_B']['zones'];
    }

    /** Titik awal editor custom: susunan preset (persen) dibulatkan ke satuan grid; zona konten pertama bersuara. */
    public static function presetAsCustom(string $layout): array
    {
        $grid = fn (float|int $percent) => (int) round($percent * self::GRID / 100);
        $first = true;

        return array_map(function (array $z) use ($grid, &$first) {
            $zone = ['key' => $z['key'], 'kind' => $z['kind'], 'x' => $grid($z['x']), 'y' => $grid($z['y']), 'w' => $grid($z['w']), 'h' => $grid($z['h'])];
            if ($z['kind'] === 'playlist') {
                $zone['sound'] = $first;
                $first = false;
            }

            return $zone;
        }, self::LAYOUTS[$layout]['zones'] ?? self::LAYOUTS['PRESET_A']['zones']);
    }

    // ================================================================== TAMPILAN (PAYLOAD)

    /**
     * Rangkum tampilan layar jadi satu JSON untuk TV / Preview.
     *
     * @param  array|null  $draft  perubahan yang belum disimpan (Preview): ['screen' => [...], 'template' => [...],
     *                             'playlists' => ['zona' => [['content_id', 'duration']]]] ('playlist' lama = zona main)
     */
    public function buildPayload(SignatureScreen $screen, ?array $draft = null): array
    {
        $screenAttrs = array_merge($screen->only(['name', 'name_display', 'sound_enabled', 'operating_hours_only', 'template_id']), $draft['screen'] ?? []);

        $templateModel = SignatureTemplate::with('playlistItems.content')->find($draft['template']['id'] ?? $screenAttrs['template_id'])
            ?? SignatureTemplate::with('playlistItems.content')->orderBy('created_at')->first();
        $template = array_merge(
            $templateModel ? $templateModel->only(['id', 'name', 'layout', 'zones', 'theme', 'ticker', 'logo_path']) : ['name' => '-', 'layout' => 'PRESET_B'],
            array_filter($draft['template'] ?? [], fn ($v) => $v !== null)
        );

        $layout = ($template['layout'] ?? '') === self::CUSTOM_LAYOUT || isset(self::LAYOUTS[$template['layout'] ?? '']) ? $template['layout'] : 'PRESET_B';
        $zones = self::layoutZones($layout, $template['zones'] ?? null);

        // Isi playlist per zona: dari form (Preview) atau dari database.
        if (isset($draft['playlist']) && ! isset($draft['playlists'])) {
            $draft['playlists'] = ['main' => $draft['playlist']];
        }
        if (isset($draft['playlists'])) {
            $rows = collect($draft['playlists'])->flatMap(fn ($list, $zone) => collect((array) $list)->map(fn ($row) => $row + ['zone' => (string) $zone]));
            $contents = SignatureContent::whereIn('id', $rows->pluck('content_id')->filter()->all())->get()->keyBy('id');
            $items = $rows->map(fn ($row) => ($c = $contents[$row['content_id'] ?? ''] ?? null) ? [$c, $row['duration'] ?? null, $row['zone']] : null)->filter();
        } else {
            $items = collect($templateModel?->playlistItems ?? [])->filter(fn ($i) => $i->content)->map(fn ($i) => [$i->content, $i->duration_seconds, $i->zone_key]);
        }

        $playlists = [];
        foreach ($zones as $zone) {
            if ($zone['kind'] !== 'playlist') {
                continue;
            }
            // Konten nonaktif & YouTube / Courtsee tanpa ID yang sah dilewati (bukan dikirim kosong ke TV).
            $playlists[$zone['key']] = $items
                ->filter(fn ($item) => $item[2] === $zone['key'] && $item[0]->is_active
                    && ($item[0]->type !== 'YOUTUBE' || SignatureContent::youtubeId('https://youtu.be/'.$item[0]->url))
                    && ($item[0]->type !== 'COURTSEE' || SignatureContent::courtseeId('https://courtsee.com/watch/'.$item[0]->url)))
                ->map(fn ($item) => $this->contentPayload($item[0], $item[1] === null || $item[1] === '' ? null : (int) $item[1]))
                ->values()->all();
        }

        $logoUrl = $template['logo_url'] ?? (! empty($template['logo_path']) ? self::publicFileUrl($template['logo_path']) : '/images/identity/logo-cream.png?v=2');

        return [
            'generated_at' => now()->toIso8601String(),
            'screen' => [
                'name' => $screenAttrs['name'],
                'name_display' => $screenAttrs['name_display'],
                'sound_enabled' => (bool) $screenAttrs['sound_enabled'],
                'operating_hours_only' => (bool) $screenAttrs['operating_hours_only'],
            ],
            'template' => [
                'name' => $template['name'] ?? '-',
                'layout' => $layout,
                'zones' => $zones,
                'theme' => array_merge(self::DEFAULT_THEME, array_filter((array) ($template['theme'] ?? []), fn ($v) => $v !== null && $v !== '')),
                'ticker' => array_merge(self::DEFAULT_TICKER, array_filter((array) ($template['ticker'] ?? []), fn ($v) => $v !== null && $v !== '')),
                'logo_url' => $logoUrl,
            ],
            'playlists' => $playlists,
            'running_texts' => SignatureRunningText::where('is_active', true)->orderBy('sort_order')->orderBy('created_at')->get(['kind', 'text'])->map(fn ($t) => ['kind' => $t->kind, 'text' => $t->text])->all(),
            'sponsors' => SignatureSponsor::with('organization:id,name')->orderBy('sort_order')->orderBy('created_at')->get()
                ->filter(fn (SignatureSponsor $s) => $s->isShowing() && $s->displayName() !== '')
                ->map(fn (SignatureSponsor $s) => [
                    'name' => $s->displayName(),
                    'logo_url' => $s->logo_path ? self::publicFileUrl($s->logo_path) : null,
                    'duration' => max(3, (int) $s->duration_seconds),
                ])->values()->all(),
            'hours' => $this->operatingHours(),
        ];
    }

    private function contentPayload(SignatureContent $content, ?int $duration): array
    {
        return [
            'id' => $content->id,
            'type' => $content->type,
            'name' => $content->name,
            'url' => $content->file_path ? self::publicFileUrl($content->file_path) : null,
            // Hanya ID 11 karakter (divalidasi ulang) — TV menyusun sendiri alamat pemutarnya.
            'youtube_id' => $content->type === 'YOUTUBE' ? SignatureContent::youtubeId('https://youtu.be/'.$content->url) : null,
            'courtsee_id' => $content->type === 'COURTSEE' ? SignatureContent::courtseeId('https://courtsee.com/watch/'.$content->url) : null,
            'duration' => max(3, (int) ($duration ?: $content->duration_seconds)),
            'sound' => in_array($content->type, SignatureContent::PLAYABLE_TYPES, true) && $content->sound,
            'volume' => max(0, min(100, (int) $content->volume)),
        ];
    }

    /**
     * URL file upload TANPA nama host (/storage/...): snapshot yang ditayangkan tetap jalan walau APP_URL berbeda dari
     * alamat yang dibuka TV (mis. IP lokal vs domain). Dipakai juga gambar mini di halaman admin — dulu memakai URL
     * absolut dari APP_URL, sehingga rusak saat admin dibuka lewat alamat lain (127.0.0.1:8000, ngrok).
     */
    public static function publicFileUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /** Jam buka paling awal & tutup paling akhir dari lapangan aktif. */
    private function operatingHours(): array
    {
        $courts = PadelCourt::where('is_active', true)->get(['open_time', 'close_time']);

        return [
            'open' => $courts->pluck('open_time')->filter()->min() ?: '06:00',
            'close' => $courts->pluck('close_time')->filter()->max() ?: '23:00',
        ];
    }

    // ================================================================== TAYANGKAN & VERSI

    /** Riwayat versi yang disimpan per layar (yang sedang tayang tidak pernah dibuang). */
    public const MAX_VERSIONS = 20;

    /**
     * Tayangkan draft saat ini:
     *   - template SAMA dengan versi yang sedang tayang → versi itu DIPERBARUI (nomor tetap, revision naik), atau tidak
     *     ada perubahan kalau isinya sama. Dulu setiap edit template / konten jadi versi baru (v2, v3, …);
     *   - pindah TEMPLATE → tampilan yang sama persis dengan versi lama di riwayat = versi itu ditayangkan lagi, selain
     *     itu versi baru.
     */
    public function publish(SignatureScreen $screen, User $user, ?string $note = null): PublishResult
    {
        $payload = $this->buildPayload($screen);

        return DB::transaction(function () use ($screen, $payload, $user, $note) {
            $screen = SignatureScreen::whereKey($screen->id)->lockForUpdate()->firstOrFail();
            $fingerprint = self::fingerprint($payload);
            $templateId = $this->publishedTemplateId($screen);

            $live = $screen->published_version_id ? SignatureVersion::find($screen->published_version_id) : null;
            if ($live && $this->sameTemplate($live, $templateId, $payload)) {
                if (self::fingerprint((array) $live->payload) === $fingerprint) {
                    return new PublishResult($live, PublishResult::UNCHANGED);
                }

                $payload['version'] = $live->version;
                $payload['revision'] = (int) $live->revision + 1;
                $live->update([
                    'template_id' => $templateId,
                    'revision' => $payload['revision'],
                    'payload' => $payload,
                    'updated_at' => now(),
                    'updated_by_id' => $user->id,
                ]);
                $this->announce($screen, $live, $user, "memperbarui versi {$live->version} (revisi {$live->revision})", 'signature.updated');

                return new PublishResult($live, PublishResult::UPDATED);
            }

            $same = SignatureVersion::where('screen_id', $screen->id)->orderByDesc('version')->get()
                ->first(fn (SignatureVersion $v) => self::fingerprint((array) $v->payload) === $fingerprint);
            if ($same) {
                if ($same->id === $screen->published_version_id) {
                    return new PublishResult($same, PublishResult::UNCHANGED);
                }

                return new PublishResult($this->putOnAir($screen, $same, $user, "menayangkan lagi versi {$same->version} (isi sama, tanpa versi baru)"), PublishResult::REUSED);
            }

            $number = (int) SignatureVersion::where('screen_id', $screen->id)->max('version') + 1;
            $payload['version'] = $number;
            $payload['revision'] = 1;
            $version = SignatureVersion::create([
                'screen_id' => $screen->id,
                'template_id' => $templateId,
                'version' => $number,
                'revision' => 1,
                'payload' => $payload,
                'note' => mb_substr($note ?: 'Tayangkan', 0, 120),
                'published_by_id' => $user->id,
                'created_at' => now(),
            ]);
            $this->putOnAir($screen, $version, $user, "menayangkan versi baru {$number}");
            $this->pruneVersions($screen);

            return new PublishResult($version, PublishResult::CREATED);
        });
    }

    /**
     * Kembalikan ke versi lama = pindahkan tanda Tayang ke versi itu (tidak menyalinnya jadi versi baru). Ditolak kalau
     * ada file versi itu yang sudah dihapus (tidak tayang lebih dari SignatureFileJanitor::KEEP_AFTER_AIRING_DAYS hari)
     * — TV akan menampilkan slide kosong.
     */
    public function restore(SignatureVersion $version, User $user): PublishResult
    {
        $missing = app(SignatureFileJanitor::class)->missingFiles((array) $version->payload);
        if ($missing !== []) {
            throw new MissingSignatureFilesException(count($missing).' file di versi '.$version->version.' sudah dihapus otomatis karena tidak tayang lebih dari '
                .SignatureFileJanitor::KEEP_AFTER_AIRING_DAYS.' hari. Upload ulang kontennya di library, lalu tekan Tayangkan.');
        }

        return DB::transaction(function () use ($version, $user) {
            $screen = SignatureScreen::whereKey($version->screen_id)->lockForUpdate()->firstOrFail();
            if ($screen->published_version_id === $version->id) {
                return new PublishResult($version, PublishResult::UNCHANGED);
            }

            // Pilihan template layar ikut kembali ke template versi itu: Tayangkan berikutnya (setelah edit template /
            // konten) memperbarui versi ini, bukan membuat versi baru.
            if ($version->template_id && SignatureTemplate::whereKey($version->template_id)->exists()) {
                $screen->update(['template_id' => $version->template_id]);
            }

            return new PublishResult($this->putOnAir($screen, $version, $user, "dikembalikan ke versi {$version->version}", 'signature.restored'), PublishResult::REUSED);
        });
    }

    /** Template yang dipakai Tayangkan — sama dengan buildPayload (template layar, cadangan template pertama). */
    private function publishedTemplateId(SignatureScreen $screen): ?string
    {
        return SignatureTemplate::whereKey($screen->template_id)->value('id') ?? SignatureTemplate::orderBy('created_at')->value('id');
    }

    /** Versi yang sedang tayang memakai template yang sama? Versi lama (sebelum kolom template_id) dicocokkan lewat nama. */
    private function sameTemplate(SignatureVersion $live, ?string $templateId, array $payload): bool
    {
        if ($live->template_id !== null) {
            return $live->template_id === $templateId;
        }

        return ($live->payload['template']['name'] ?? null) !== null
            && ($live->payload['template']['name'] ?? null) === ($payload['template']['name'] ?? null);
    }

    /** Pindahkan tanda Tayang: versi lama dicatat berhenti tayang (dasar aturan file 7 hari), versi ini mulai tayang. */
    private function putOnAir(SignatureScreen $screen, SignatureVersion $version, User $user, string $what, string $event = 'signature.published'): SignatureVersion
    {
        if ($screen->published_version_id && $screen->published_version_id !== $version->id) {
            SignatureVersion::whereKey($screen->published_version_id)->update(['aired_until' => now()]);
        }
        $version->update(['aired_at' => now(), 'aired_by_id' => $user->id, 'aired_until' => null]);
        $screen->update(['published_version_id' => $version->id]);

        return $this->announce($screen, $version, $user, $what, $event);
    }

    /** TV memuat ulang tampilan + jejak di Log Aktivitas. */
    private function announce(SignatureScreen $screen, SignatureVersion $version, User $user, string $what, string $event): SignatureVersion
    {
        // TV langsung memuat tampilan baru (tanpa menunggu jadwal polling).
        app(\App\Services\Realtime\RealtimeSignal::class)->signaturePublished();

        $payload = (array) $version->payload;
        ActivityLogger::record(
            module: 'SIGNATURE',
            event: $event,
            description: "Layar {$screen->name} {$what}",
            subject: $screen,
            meta: ['versi' => $version->version, 'template' => $payload['template']['name'] ?? '-', 'jumlah_konten' => array_sum(array_map('count', (array) ($payload['playlists'] ?? [])))],
            causer: $user,
        );

        return $version;
    }

    /** Simpan MAX_VERSIONS versi terbaru per layar; yang sedang tayang selalu disimpan. */
    private function pruneVersions(SignatureScreen $screen): void
    {
        $keep = SignatureVersion::where('screen_id', $screen->id)->orderByDesc('version')->limit(self::MAX_VERSIONS)->pluck('id')
            ->push($screen->published_version_id)->filter()->all();
        SignatureVersion::where('screen_id', $screen->id)->whereNotIn('id', $keep)->delete();
    }

    /** Sidik tampilan: isi payload tanpa nomor versi / revisi & waktu dibuat (dua tayangan dengan isi sama = sidik sama). */
    private static function fingerprint(array $payload): string
    {
        unset($payload['version'], $payload['revision'], $payload['generated_at']);

        return md5(json_encode($payload));
    }

    // ================================================================== PREVIEW

    /** Simpan tampilan sementara untuk Preview (belum ditayangkan) → kunci acak untuk halaman preview. */
    public function storePreview(array $payload): string
    {
        $key = Str::lower(Str::random(40));
        $payload['version'] = 'preview';
        Cache::put("signature:preview:{$key}", $payload, now()->addMinutes(30));

        return $key;
    }

    public function previewPayload(string $key): ?array
    {
        return Cache::get("signature:preview:{$key}");
    }

    // ================================================================== DATA LIVE

    /**
     * Data yang berubah terus untuk TV: status tiap lapangan, jadwal hari ini, slot kosong, notifikasi booking / check-in,
     * dan running text (manual + otomatis). Nama customer mengikuti pengaturan layar.
     */
    public function liveData(array $payload, ?SignatureScreen $screen = null): array
    {
        $now = now();
        $wib = now(self::TIMEZONE);
        $today = $wib->toDateString();
        $nameDisplay = $payload['screen']['name_display'] ?? 'FULL';
        $texts = collect($payload['running_texts'] ?? []);
        $autoText = fn (string $kind) => $texts->firstWhere('kind', $kind)['text'] ?? null;

        $courts = PadelCourt::where('is_active', true)->ordered()->get();
        $bookings = PadelBooking::with('user:id,name')
            ->whereDate('booking_date', $today)
            ->whereIn('status', ['PAID', 'CHECKED_IN', 'COMPLETED'])
            ->orderBy('start_time')
            ->get()
            ->groupBy('court_id');

        $dayStart = Carbon::parse($today);
        $courtRows = [];
        $schedule = [];
        $freeSlots = [];
        foreach ($courts as $court) {
            /** @var Collection $list */
            $list = $bookings[$court->id] ?? collect();
            $live = $list->where('status', '!=', 'COMPLETED');
            $current = $live->first(fn ($b) => $b->start_time->lte($now) && $b->end_time->gt($now));
            $next = $live->first(fn ($b) => $b->start_time->gt($now));

            $courtRows[] = [
                'name' => $court->name,
                'status' => $current ? (($current->status === 'CHECKED_IN' || $current->checked_in_at) ? 'PLAYING' : 'WAITING') : 'FREE',
                'current' => $current ? [
                    'name' => $this->displayName($current->user?->name, $nameDisplay),
                    'start' => $current->start_time->format('H:i'),
                    'end' => $current->end_time->format('H:i'),
                    'remaining_seconds' => (int) max(0, $now->diffInSeconds($current->end_time, false)),
                ] : null,
                'next' => $next ? [
                    'name' => $this->displayName($next->user?->name, $nameDisplay),
                    'start' => $next->start_time->format('H:i'),
                    'end' => $next->end_time->format('H:i'),
                ] : null,
            ];

            $open = (int) substr($court->open_time ?: '06:00', 0, 2);
            $close = $court->close_time === '24:00' ? 24 : (int) substr($court->close_time ?: '23:00', 0, 2);
            $hours = [];
            for ($h = $open; $h < $close; $h++) {
                $slotStart = Carbon::parse("{$today} ".sprintf('%02d:00:00', $h));
                $slotEnd = $slotStart->copy()->addHour();
                $taken = $list->first(fn ($b) => $b->start_time->lt($slotEnd) && $b->end_time->gt($slotStart));
                $state = $taken ? 'BOOKED' : ($slotEnd->lte($now) ? 'PAST' : 'FREE');
                $hours[] = ['time' => sprintf('%02d:00', $h), 'state' => $state];
                if ($state === 'FREE' && $slotStart->gte($now)) {
                    $freeSlots[] = "{$court->name} ".sprintf('%02d:00', $h);
                }
            }
            $schedule[] = [
                'name' => $court->name,
                'open_minutes' => $open * 60,
                'close_minutes' => $close * 60,
                'hours' => $hours,
                // Blok booking untuk tampilan timeline: nama sesuai pengaturan layar, menit dari 00:00 hari ini.
                'bookings' => $list->map(fn ($b) => [
                    'name' => $this->displayName($b->user?->name, $nameDisplay),
                    'start' => $b->start_time->format('H:i'),
                    'end' => $b->end_time->format('H:i'),
                    'start_minutes' => (int) max(0, $dayStart->diffInMinutes($b->start_time, false)),
                    'end_minutes' => (int) min(24 * 60, $dayStart->diffInMinutes($b->end_time, false)),
                    'status' => $b->status === 'COMPLETED' || $b->end_time->lte($now) ? 'DONE'
                        : ($b->start_time->lte($now) ? (($b->status === 'CHECKED_IN' || $b->checked_in_at) ? 'PLAYING' : 'WAITING') : 'UPCOMING'),
                ])->values()->all(),
            ];
        }

        $events = $this->recentEvents($now, $nameDisplay, $autoText('AUTO_CHECKIN'), $autoText('AUTO_BOOKING'));

        $ticker = collect($events)->filter(fn ($e) => $e['age_seconds'] <= 600)->pluck('text')->all();
        foreach ($texts as $text) {
            if ($text['kind'] === 'MANUAL') {
                $ticker[] = $text['text'];
            } elseif ($text['kind'] === 'AUTO_SLOTS' && $freeSlots) {
                $ticker[] = str_replace('{daftar_slot}', implode(', ', array_slice($freeSlots, 0, 8)), $text['text']);
            } elseif ($text['kind'] === 'AUTO_SPONSOR' && ! empty($payload['sponsors'])) {
                $ticker[] = str_replace('{sponsor}', implode(', ', array_column($payload['sponsors'], 'name')), $text['text']);
            }
        }

        $hours = $payload['hours'] ?? ['open' => '06:00', 'close' => '23:00'];
        $clock = $wib->format('H:i');

        return [
            'server_time' => $wib->toIso8601String(),
            'version' => $screen?->publishedVersion?->version ?? ($payload['version'] ?? null),
            // Versi yang sedang tayang bisa diperbarui (template sama) → TV memuat ulang saat versi ATAU revisinya berubah.
            'revision' => (int) ($screen?->publishedVersion?->revision ?? ($payload['revision'] ?? 1)),
            'is_open' => ! ($payload['screen']['operating_hours_only'] ?? true) || ($clock >= $hours['open'] && ($hours['close'] === '24:00' || $clock < $hours['close'])),
            'courts' => $courtRows,
            'schedule' => $schedule,
            // Posisi garis "sekarang" di timeline, satu basis waktu dengan jam booking.
            'now_minutes' => (int) $dayStart->diffInMinutes($now, false),
            'events' => array_values(array_filter($events, fn ($e) => $e['age_seconds'] <= self::EVENT_WINDOW)),
            'ticker' => array_values(array_filter($ticker, fn ($t) => trim((string) $t) !== '')),
        ];
    }

    /** Booking baru (baru lunas) & check-in beberapa menit terakhir, dengan kalimat dari running text otomatis. */
    private function recentEvents(Carbon $now, string $nameDisplay, ?string $checkinText, ?string $bookingText): array
    {
        $since = $now->copy()->subMinutes(10);
        $events = [];

        if ($checkinText) {
            foreach (PadelBooking::with(['user:id,name', 'court:id,name'])->where('checked_in_at', '>=', $since)->orderByDesc('checked_in_at')->limit(10)->get() as $b) {
                $events[] = $this->event('CHECKIN', $b, $b->checked_in_at, $now, $checkinText, $nameDisplay);
            }
        }
        if ($bookingText) {
            // "Booking baru" = booking yang baru jadi lunas (kasir / online), belum check-in.
            foreach (PadelBooking::with(['user:id,name', 'court:id,name'])->where('status', 'PAID')->whereNull('checked_in_at')->where('updated_at', '>=', $since)->orderByDesc('updated_at')->limit(10)->get() as $b) {
                $events[] = $this->event('BOOKING', $b, $b->updated_at, $now, $bookingText, $nameDisplay);
            }
        }

        usort($events, fn ($a, $b) => $a['age_seconds'] <=> $b['age_seconds']);

        return $events;
    }

    private function event(string $type, PadelBooking $b, Carbon $at, Carbon $now, string $template, string $nameDisplay): array
    {
        $name = $this->displayName($b->user?->name, $nameDisplay) ?? 'Customer';

        return [
            'id' => strtolower($type).':'.$b->id.':'.$at->timestamp,
            'type' => $type,
            'age_seconds' => (int) max(0, $at->diffInSeconds($now, false)),
            'text' => strtr($template, [
                '{nama}' => $name,
                '{lapangan}' => $b->court?->name ?? 'court',
                '{jam}' => $b->start_time->format('H:i'),
            ]),
        ];
    }

    /** Nama customer sesuai pengaturan layar. Tidak pernah No. HP / email. */
    public function displayName(?string $name, string $mode): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || $mode === 'BOOKED') {
            return null;
        }
        if ($mode === 'INITIALS') {
            return collect(preg_split('/\s+/u', preg_replace('/\([^)]*\)/u', '', $name)))->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).'.')->take(3)->implode(' ');
        }

        return $name;
    }
}
