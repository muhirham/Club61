<?php

namespace App\Models\Signature;

use App\Services\Signature\SignatureFileJanitor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Satu konten di library Signature: gambar, video, video YouTube, atau widget dari sistem. */
class SignatureContent extends Model
{
    use HasUlids;

    public const TYPES = [
        'IMAGE' => 'Gambar',
        'VIDEO' => 'Video MP4',
        'YOUTUBE' => 'Video YouTube',
        'COURTSEE' => 'Live Courtsee',
        'MONITORING' => 'Monitoring Lapangan',
        'SCHEDULE' => 'Jadwal Hari Ini',
        'SPONSOR' => 'Sponsor',
        'CLOCK' => 'Jam & Tanggal',
    ];

    /** Jenis yang berupa file upload. */
    public const FILE_TYPES = ['IMAGE', 'VIDEO'];

    /** Konten buatan admin (bisa diedit penuh & dihapus); sisanya widget dari sistem. */
    public const LIBRARY_TYPES = ['IMAGE', 'VIDEO', 'YOUTUBE', 'COURTSEE'];

    /** Diputar sampai selesai (bukan durasi tetap) dan bisa bersuara. */
    public const PLAYABLE_TYPES = ['VIDEO', 'YOUTUBE'];

    /** Host link YouTube yang diterima — link lain ditolak supaya halaman sembarang tidak bisa tampil di TV. */
    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'];

    /**
     * ID video (11 karakter) dari link YouTube: watch?v=, youtu.be/, shorts/, embed/, live/. Null = bukan link YouTube
     * yang sah. Yang disimpan hanya ID-nya, jadi isi kolom `url` tidak pernah dipakai mentah sebagai alamat.
     */
    public static function youtubeId(?string $link): ?string
    {
        $link = trim((string) $link);
        if ($link === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $link)) {
            $link = 'https://'.$link;
        }

        $parts = parse_url($link);
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if ($host === 'youtu.be') {
            $candidate = explode('/', ltrim($path, '/'))[0] ?? '';
        } elseif (preg_match('#^/(?:shorts|embed|live|v)/([^/?]+)#', $path, $m)) {
            $candidate = $m[1];
        } else {
            parse_str($parts['query'] ?? '', $query);
            $candidate = is_string($query['v'] ?? null) ? $query['v'] : '';
        }

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) ? $candidate : null;
    }

    /**
     * ID tayangan (UUID) dari link halaman watch Courtsee: https://courtsee.com/watch/{uuid}. Null = bukan link Courtsee
     * yang sah. Sama seperti YouTube, yang disimpan hanya ID-nya; alamat halaman disusun ulang di TV.
     */
    public static function courtseeId(?string $link): ?string
    {
        $link = trim((string) $link);
        if ($link === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $link)) {
            $link = 'https://'.$link;
        }

        $parts = parse_url($link);
        if (! in_array(strtolower($parts['host'] ?? ''), ['courtsee.com', 'www.courtsee.com'], true)) {
            return null;
        }

        return preg_match('#^/watch/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/?$#i', $parts['path'] ?? '', $m)
            ? strtolower($m[1]) : null;
    }

    protected $fillable = ['name', 'type', 'file_path', 'url', 'duration_seconds', 'sound', 'volume', 'is_active'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'integer', 'sound' => 'boolean', 'volume' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // File lama dibuang saat diganti / kontennya dihapus — kecuali masih / baru saja tayang di TV (SignatureFileJanitor).
        static::updated(function (self $content) {
            if ($content->wasChanged('file_path')) {
                app(SignatureFileJanitor::class)->release($content->getOriginal('file_path'));
            }
        });
        static::deleted(fn (self $content) => app(SignatureFileJanitor::class)->release($content->file_path));
    }

    public function playlistItems()
    {
        return $this->hasMany(SignaturePlaylistItem::class, 'content_id');
    }
}
