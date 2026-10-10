<?php

namespace App\Models\Setting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CompanyProfileSetting extends Model
{
    protected $table = 'company_profile_settings';

    protected $fillable = [
        'hero_badge_text',
        'hero_badge_text_en',
        'hero_headline_line1',
        'hero_headline_line1_en',
        'hero_headline_highlight',
        'hero_headline_highlight_en',
        'hero_headline_line2',
        'hero_headline_line2_en',
        'hero_subtitle',
        'hero_subtitle_en',
        'court_count',
        'facility_cards',
        'address_line',
        'operating_hours_text',
        'operating_hours_text_en',
        'portal_domain_text',
        'whatsapp_number',
        'maps_embed_url',
        'footer_tagline',
        'footer_tagline_en',
        'footer_social_links',
        'updated_by',
    ];

    protected $casts = [
        'court_count' => 'integer',
        'facility_cards' => 'array',
        'footer_social_links' => 'array',
    ];

    public const CACHE_KEY = 'company_profile_settings';

    /**
     * Daftar tertutup icon_key yang boleh dipakai kartu fasilitas — sengaja bukan upload
     * SVG/HTML bebas dari staf (lihat komponen <x-company-profile.icon>), karena SVG mentah
     * adalah vektor XSS yang sudah ditandai di checklist keamanan proyek ini.
     */
    public const ALLOWED_ICON_KEYS = ['arena', 'wellness', 'lounge', 'gate', 'tournament', 'star'];

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget(self::CACHE_KEY);
        });

        static::deleted(function () {
            Cache::forget(self::CACHE_KEY);
        });
    }

    public const DEFAULT_ADDRESS = 'Gedung Indosat, Jl. Perintis Kemerdekaan No. 39, Medan, Sumatera Utara.';

    /**
     * Alamat venue untuk struk & invoice — satu sumber dengan Konten Website (diubah dari menu Konten Website).
     * Dulu struk kasir & Z-Report menulis alamat lain secara hardcode ("Lebak Bulus"). Gagal baca DB / cache tidak boleh
     * menggagalkan cetak struk, jadi jatuh ke alamat default.
     */
    public static function receiptAddress(): string
    {
        $address = rescue(fn () => trim((string) self::current()->address_line), '', false);

        return rtrim($address !== '' ? $address : self::DEFAULT_ADDRESS, '.');
    }

    /**
     * Ambil singleton record konten company profile dengan proteksi cache.
     */
    public static function current(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return self::firstOrCreate(
                ['id' => 1],
                [
                    'hero_badge_text' => 'Medan Flagship Venue • Gedung Indosat • Open Daily',
                    'hero_headline_line1' => 'The Sanctuary for',
                    'hero_headline_highlight' => 'Padel Athletes',
                    'hero_headline_line2' => 'in Medan.',
                    'hero_subtitle' => 'Fasilitas terpadu berstandar internasional di Gedung Indosat Medan: {court_count} Lapangan Padel Panoramic Full Indoor ber-AC, Thermal Wellness Recovery (Sauna).',
                    'court_count' => 3,
                    'facility_cards' => [
                        ['icon_key' => 'arena', 'title' => 'Padel Arena', 'subtitle' => '+ Panoramic Courts'],
                        ['icon_key' => 'wellness', 'title' => 'Wellness Suite', 'subtitle' => 'Sauna'],
                        ['icon_key' => 'lounge', 'title' => 'Social Lounge', 'subtitle' => 'Artisan Cafe & Bar'],
                    ],
                    'address_line' => self::DEFAULT_ADDRESS,
                    'operating_hours_text' => 'Open 06:00 – 23:00',
                    'portal_domain_text' => 'portal.club61padel.com',
                ]
            );
        });
    }

    /**
     * Subtitle hero dengan placeholder {court_count} sudah diganti angka asli — dipakai
     * langsung oleh halaman publik supaya staf tidak perlu ketik ulang angka lapangan
     * manual di tengah kalimat kalau jumlahnya berubah.
     */
    public function renderedHeroSubtitle(): string
    {
        return str_replace('{court_count}', (string) $this->court_count, $this->localized('hero_subtitle'));
    }

    /**
     * Ambil versi teks sesuai locale aktif ("id"/"en"). Kolom Inggris ("{field}_en") itu
     * opsional — kalau staf belum isi (atau localenya bukan "en"), otomatis balik ke teks
     * Indonesia yang selalu terisi supaya toggle bahasa tidak pernah menampilkan kotak kosong.
     */
    public function localized(string $field): string
    {
        if (app()->getLocale() === 'en') {
            $enValue = trim((string) ($this->{$field.'_en'} ?? ''));
            if ($enValue !== '') {
                return $enValue;
            }
        }

        return (string) ($this->{$field} ?? '');
    }

    /**
     * Kartu ringkas fasilitas (facility_cards JSON) dengan title/subtitle sudah disesuaikan
     * locale aktif. Struktur tiap kartu boleh punya "title_en"/"subtitle_en" opsional.
     */
    public function localizedFacilityCards(): array
    {
        $locale = app()->getLocale();

        return collect($this->facility_cards ?: [])->map(function (array $card) use ($locale) {
            if ($locale === 'en') {
                $card['title'] = trim((string) ($card['title_en'] ?? '')) !== '' ? $card['title_en'] : $card['title'];
                $card['subtitle'] = trim((string) ($card['subtitle_en'] ?? '')) !== '' ? $card['subtitle_en'] : ($card['subtitle'] ?? '');
            }

            return $card;
        })->all();
    }

    /** Host Google Maps yang boleh dipasang sebagai iframe peta di halaman depan. */
    public const MAPS_EMBED_HOSTS = ['www.google.com', 'google.com', 'maps.google.com'];

    /**
     * Link web biasa (http/https dengan host). filter_var saja tidak cukup: "javascript://x%0Aalert(1)"
     * lolos FILTER_VALIDATE_URL, padahal dijalankan browser sebagai skrip di href/iframe.
     */
    public static function isSafeWebUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /** URL embed peta: wajib https ke Google Maps (iframe ber-origin lain tidak boleh disisipkan). */
    public static function isSafeMapsEmbedUrl(?string $url): bool
    {
        if (! self::isSafeWebUrl($url)) {
            return false;
        }

        $url = trim((string) $url);

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::MAPS_EMBED_HOSTS, true)
            && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/maps');
    }

    /** URL iframe peta yang aman dipakai halaman publik: embed tersimpan kalau valid, selain itu peta dari alamat. */
    public function safeMapsEmbedUrl(): string
    {
        return self::isSafeMapsEmbedUrl($this->maps_embed_url)
            ? trim((string) $this->maps_embed_url)
            : 'https://www.google.com/maps?q='.urlencode((string) $this->address_line).'&output=embed';
    }

    /** Link media sosial footer yang aman di-render (data lama yang tidak valid dilewati). */
    public function safeSocialLinks(): array
    {
        return array_filter(
            array_map(fn ($url) => trim((string) $url), $this->footer_social_links ?: []),
            fn (string $url) => self::isSafeWebUrl($url),
        );
    }
}
