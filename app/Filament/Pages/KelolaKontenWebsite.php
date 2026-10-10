<?php

namespace App\Filament\Pages;

use App\Models\Setting\CompanyProfileFacility;
use App\Models\Setting\CompanyProfileSetting;
use App\Models\Setting\CompanyProfileValueProp;
use App\Services\Media\SecureImageUploader;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use UnitEnum;

class KelolaKontenWebsite extends Page
{
    use HasPageShield;
    use WithFileUploads;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Konten Website';

    protected static string | UnitEnum | null $navigationGroup = 'Marketing & Event';

    protected static ?string $title = 'Konten Halaman Depan & Company Profile';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.kelola-konten-website';

    // --- Hero (§1) ---
    public string $heroBadgeText = '';
    public string $heroBadgeTextEn = '';
    public string $heroHeadlineLine1 = '';
    public string $heroHeadlineLine1En = '';
    public string $heroHeadlineHighlight = '';
    public string $heroHeadlineHighlightEn = '';
    public string $heroHeadlineLine2 = '';
    public string $heroHeadlineLine2En = '';
    public string $heroSubtitle = '';
    public string $heroSubtitleEn = '';

    // --- Fakta Venue (§2) ---
    public int|string $courtCount = 3;
    public array $facilityCards = [];

    // --- Facilities Showcase dengan Foto (§3) ---
    public array $facilities = [];
    public array $facilityUploads = [];
    public array $facilityRemovePhoto = [];

    // --- Kenapa Pilih Club61 (§4) ---
    public array $valueProps = [];

    // --- Lokasi & Kontak (§5) ---
    public string $addressLine = '';
    public string $operatingHoursText = '';
    public string $operatingHoursTextEn = '';
    public string $whatsappNumber = '';
    public string $mapsEmbedUrl = '';

    // --- Footer (§6) ---
    public string $portalDomainText = '';
    public string $footerTagline = '';
    public string $footerTaglineEn = '';
    public string $footerInstagram = '';
    public string $footerFacebook = '';
    public string $footerTiktok = '';

    public function mount(): void
    {
        $settings = CompanyProfileSetting::current();

        $this->heroBadgeText = (string) $settings->hero_badge_text;
        $this->heroBadgeTextEn = (string) $settings->hero_badge_text_en;
        $this->heroHeadlineLine1 = (string) $settings->hero_headline_line1;
        $this->heroHeadlineLine1En = (string) $settings->hero_headline_line1_en;
        $this->heroHeadlineHighlight = (string) $settings->hero_headline_highlight;
        $this->heroHeadlineHighlightEn = (string) $settings->hero_headline_highlight_en;
        $this->heroHeadlineLine2 = (string) $settings->hero_headline_line2;
        $this->heroHeadlineLine2En = (string) $settings->hero_headline_line2_en;
        $this->heroSubtitle = (string) $settings->hero_subtitle;
        $this->heroSubtitleEn = (string) $settings->hero_subtitle_en;
        $this->courtCount = (int) $settings->court_count;
        $this->facilityCards = $settings->facility_cards ?: [];
        $this->addressLine = (string) $settings->address_line;
        $this->operatingHoursText = (string) $settings->operating_hours_text;
        $this->operatingHoursTextEn = (string) $settings->operating_hours_text_en;
        $this->portalDomainText = (string) $settings->portal_domain_text;
        $this->whatsappNumber = (string) $settings->whatsapp_number;
        $this->mapsEmbedUrl = (string) $settings->maps_embed_url;
        $this->footerTagline = (string) $settings->footer_tagline;
        $this->footerTaglineEn = (string) $settings->footer_tagline_en;

        $social = $settings->footer_social_links ?: [];
        $this->footerInstagram = (string) ($social['instagram'] ?? '');
        $this->footerFacebook = (string) ($social['facebook'] ?? '');
        $this->footerTiktok = (string) ($social['tiktok'] ?? '');

        $facilities = CompanyProfileFacility::orderBy('sort_order')->get();
        $this->facilities = $facilities->map(fn ($f) => [
            'id' => $f->id,
            'title' => $f->title,
            'title_en' => (string) $f->title_en,
            'description' => (string) $f->description,
            'description_en' => (string) $f->description_en,
            'amenities_text' => implode("\n", $f->amenities ?: []),
            'amenities_en_text' => implode("\n", $f->amenities_en ?: []),
            'photo_path' => $f->photo_path,
        ])->all();

        $valueProps = CompanyProfileValueProp::orderBy('sort_order')->get();
        $this->valueProps = $valueProps->map(fn ($v) => [
            'id' => $v->id,
            'icon_key' => $v->icon_key,
            'title' => $v->title,
            'title_en' => (string) $v->title_en,
            'description' => (string) $v->description,
            'description_en' => (string) $v->description_en,
        ])->all();
    }

    // --- Kartu Fasilitas Ringkas (facility_cards JSON) ---

    public function addFacilityCard(): void
    {
        if (count($this->facilityCards) >= 8) {
            $this->warn('Maksimal 8 kartu ringkas', 'Terlalu banyak kartu akan merusak tata letak grid.');

            return;
        }

        $this->facilityCards[] = ['icon_key' => 'star', 'title' => '', 'title_en' => '', 'subtitle' => '', 'subtitle_en' => ''];
    }

    public function removeFacilityCard(int $index): void
    {
        unset($this->facilityCards[$index]);
        $this->facilityCards = array_values($this->facilityCards);
    }

    public function moveFacilityCardUp(int $index): void
    {
        $this->swap($this->facilityCards, $index, $index - 1);
    }

    public function moveFacilityCardDown(int $index): void
    {
        $this->swap($this->facilityCards, $index, $index + 1);
    }

    // --- Facilities Showcase (company_profile_facilities, dengan foto) ---

    public function addFacility(): void
    {
        if (count($this->facilities) >= 6) {
            $this->warn('Maksimal 6 fasilitas', 'Terlalu banyak akan membuat halaman terlalu panjang.');

            return;
        }

        $this->facilities[] = ['id' => null, 'title' => '', 'title_en' => '', 'description' => '', 'description_en' => '', 'amenities_text' => '', 'amenities_en_text' => '', 'photo_path' => null];
    }

    public function removeFacility(int $index): void
    {
        unset($this->facilities[$index], $this->facilityUploads[$index]);
        $this->facilities = array_values($this->facilities);
        $this->facilityUploads = array_values($this->facilityUploads);
    }

    public function moveFacilityUp(int $index): void
    {
        $this->swap($this->facilities, $index, $index - 1);
    }

    public function moveFacilityDown(int $index): void
    {
        $this->swap($this->facilities, $index, $index + 1);
    }

    public function removeFacilityPhoto(int $index): void
    {
        $this->facilityRemovePhoto[$index] = true;
        $this->facilities[$index]['photo_path'] = null;
    }

    // --- Kenapa Pilih Club61 (company_profile_value_props) ---

    public function addValueProp(): void
    {
        if (count($this->valueProps) >= 6) {
            $this->warn('Maksimal 6 poin', 'Section ini didesain untuk grid 6 kotak.');

            return;
        }

        $this->valueProps[] = ['id' => null, 'icon_key' => 'star', 'title' => '', 'title_en' => '', 'description' => '', 'description_en' => ''];
    }

    public function removeValueProp(int $index): void
    {
        unset($this->valueProps[$index]);
        $this->valueProps = array_values($this->valueProps);
    }

    public function moveValuePropUp(int $index): void
    {
        $this->swap($this->valueProps, $index, $index - 1);
    }

    public function moveValuePropDown(int $index): void
    {
        $this->swap($this->valueProps, $index, $index + 1);
    }

    private function swap(array &$list, int $a, int $b): void
    {
        if ($a < 0 || $b < 0 || ! isset($list[$a]) || ! isset($list[$b])) {
            return;
        }

        [$list[$a], $list[$b]] = [$list[$b], $list[$a]];
    }

    private function warn(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->warning()->send();
    }

    /**
     * Validasi + proses ulang foto upload sebelum disimpan permanen — didelegasikan ke
     * SecureImageUploader (satu implementasi dipakai bersama seluruh panel admin, lihat
     * app/Services/Media/SecureImageUploader.php), supaya pipeline keamanan upload gambar
     * tidak punya 2 salinan kode yang bisa saling drift.
     */
    private function processFacilityPhotoUpload(\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $upload): string
    {
        return SecureImageUploader::store($upload, 'company-profile/facilities');
    }

    public function save(): void
    {
        abort_unless(
            auth()->user() && auth()->user()->can('manage_company_profile_content'),
            403,
            'Akses ditolak: Anda tidak memiliki izin [manage_company_profile_content] untuk mengubah konten website.'
        );

        $this->validate([
            'facilityUploads.*' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $allowedCardIcons = CompanyProfileSetting::ALLOWED_ICON_KEYS;
        $allowedValueIcons = CompanyProfileValueProp::ALLOWED_ICON_KEYS;

        // --- Simpan singleton hero + fakta venue + lokasi + footer ---
        $settings = CompanyProfileSetting::current();

        $cleanCards = collect($this->facilityCards)
            ->filter(fn ($card) => trim($card['title'] ?? '') !== '')
            ->map(fn ($card) => [
                'icon_key' => in_array($card['icon_key'] ?? '', $allowedCardIcons, true) ? $card['icon_key'] : 'star',
                'title' => mb_substr(trim((string) $card['title']), 0, 40),
                'title_en' => mb_substr(trim((string) ($card['title_en'] ?? '')), 0, 40),
                'subtitle' => mb_substr(trim((string) ($card['subtitle'] ?? '')), 0, 60),
                'subtitle_en' => mb_substr(trim((string) ($card['subtitle_en'] ?? '')), 0, 60),
            ])
            ->values()
            ->all();

        $settings->hero_badge_text = mb_substr(trim($this->heroBadgeText) ?: $settings->hero_badge_text, 0, 150);
        $settings->hero_badge_text_en = mb_substr(trim($this->heroBadgeTextEn), 0, 150) ?: null;
        $settings->hero_headline_line1 = mb_substr(trim($this->heroHeadlineLine1) ?: $settings->hero_headline_line1, 0, 80);
        $settings->hero_headline_line1_en = mb_substr(trim($this->heroHeadlineLine1En), 0, 80) ?: null;
        $settings->hero_headline_highlight = mb_substr(trim($this->heroHeadlineHighlight) ?: $settings->hero_headline_highlight, 0, 80);
        $settings->hero_headline_highlight_en = mb_substr(trim($this->heroHeadlineHighlightEn), 0, 80) ?: null;
        $settings->hero_headline_line2 = mb_substr(trim($this->heroHeadlineLine2) ?: $settings->hero_headline_line2, 0, 80);
        $settings->hero_headline_line2_en = mb_substr(trim($this->heroHeadlineLine2En), 0, 80) ?: null;
        $settings->hero_subtitle = trim($this->heroSubtitle) ?: $settings->hero_subtitle;
        $settings->hero_subtitle_en = trim($this->heroSubtitleEn) ?: null;
        $settings->court_count = max(1, (int) $this->courtCount);
        $settings->facility_cards = $cleanCards;
        $settings->address_line = mb_substr(trim($this->addressLine) ?: $settings->address_line, 0, 200);
        $settings->operating_hours_text = mb_substr(trim($this->operatingHoursText) ?: $settings->operating_hours_text, 0, 60);
        $settings->operating_hours_text_en = mb_substr(trim($this->operatingHoursTextEn), 0, 60) ?: null;
        $settings->portal_domain_text = mb_substr(trim($this->portalDomainText) ?: $settings->portal_domain_text, 0, 60);
        $settings->whatsapp_number = mb_substr(trim($this->whatsappNumber), 0, 30);
        // Validasi bukan cuma "tidak kosong" — kolom ini pernah ke-isi teks alamat biasa
        // (bukan link) yang bikin iframe di halaman publik gagal dimuat ("refused to
        // connect") karena browser coba buka teks itu sebagai URL relatif ke web ini
        // sendiri. Kalau isinya bukan URL http(s) yang valid, abaikan saja (biarkan kosong,
        // supaya welcome.blade.php otomatis balik pakai peta hasil generate dari alamat).
        // Wajib https ke Google Maps: filter_var saja menerima "javascript://…", yang lalu dijalankan
        // iframe di browser setiap pengunjung halaman depan.
        $trimmedMapsUrl = trim($this->mapsEmbedUrl);
        if ($trimmedMapsUrl !== '' && ! CompanyProfileSetting::isSafeMapsEmbedUrl($trimmedMapsUrl)) {
            $trimmedMapsUrl = '';
            Notification::make()
                ->title('URL Embed Maps Diabaikan')
                ->body('Isian "URL Embed Google Maps" harus link https dari Google Maps (contoh: https://www.google.com/maps/embed?pb=…), jadi tidak disimpan — halaman depan tetap pakai peta otomatis dari alamat.')
                ->warning()
                ->send();
        }
        $settings->maps_embed_url = mb_substr($trimmedMapsUrl, 0, 500);
        $settings->footer_tagline = mb_substr(trim($this->footerTagline), 0, 200);
        $settings->footer_tagline_en = mb_substr(trim($this->footerTaglineEn), 0, 200) ?: null;

        // Link sosial hanya http(s) — "javascript:" di href footer akan jalan saat diklik pengunjung.
        $socialInput = [
            'instagram' => trim($this->footerInstagram),
            'facebook' => trim($this->footerFacebook),
            'tiktok' => trim($this->footerTiktok),
        ];
        $rejectedSocial = array_keys(array_filter($socialInput, fn (string $url) => $url !== '' && ! CompanyProfileSetting::isSafeWebUrl($url)));
        if ($rejectedSocial !== []) {
            Notification::make()
                ->title('Link Sosial Diabaikan')
                ->body('Link '.implode(', ', array_map('ucfirst', $rejectedSocial)).' harus diawali https:// (contoh: https://instagram.com/club61), jadi tidak disimpan.')
                ->warning()
                ->send();
        }
        $settings->footer_social_links = array_filter(
            array_map(fn (string $url) => mb_substr($url, 0, 300), $socialInput),
            fn (string $url) => CompanyProfileSetting::isSafeWebUrl($url),
        );
        $settings->updated_by = auth()->id();
        $settings->save();

        $this->facilityCards = $settings->facility_cards;

        // --- Sinkronkan Facilities Showcase (dengan foto) ---
        // Diff dihitung terhadap DB LANGSUNG di sini, bukan properti yang diisi saat mount() —
        // properti non-public Livewire (protected/private) di-reset ke default setiap request,
        // jadi kalau dicatat di mount() lalu dibaca lagi di save() (request terpisah), nilainya
        // selalu kosong dan penghapusan tidak akan pernah benar-benar tereksekusi.
        $existingFacilityIds = CompanyProfileFacility::pluck('id')->all();
        $keptFacilityIds = [];

        foreach ($this->facilities as $index => $facility) {
            $title = trim((string) ($facility['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $photoPath = $facility['photo_path'] ?? null;
            $oldPhotoPath = null;

            if (! empty($this->facilityUploads[$index])) {
                $oldPhotoPath = $photoPath;
                $photoPath = $this->processFacilityPhotoUpload($this->facilityUploads[$index]);
            } elseif (! empty($this->facilityRemovePhoto[$index])) {
                $oldPhotoPath = $photoPath;
                $photoPath = null;
            }

            $id = $facility['id'] ?: (string) Str::ulid();

            CompanyProfileFacility::updateOrCreate(['id' => $id], [
                'title' => mb_substr($title, 0, 80),
                'title_en' => mb_substr(trim((string) ($facility['title_en'] ?? '')), 0, 80) ?: null,
                'description' => mb_substr(trim((string) ($facility['description'] ?? '')), 0, 1000),
                'description_en' => mb_substr(trim((string) ($facility['description_en'] ?? '')), 0, 1000) ?: null,
                'amenities' => array_values(array_filter(array_map('trim', explode("\n", (string) ($facility['amenities_text'] ?? ''))))),
                'amenities_en' => array_values(array_filter(array_map('trim', explode("\n", (string) ($facility['amenities_en_text'] ?? ''))))) ?: null,
                'photo_path' => $photoPath,
                'sort_order' => $index,
            ]);

            if ($oldPhotoPath) {
                Storage::disk('public')->delete($oldPhotoPath);
            }

            $keptFacilityIds[] = $id;
        }

        // Hapus baris + file foto milik fasilitas yang dibuang dari form
        $removedFacilityIds = array_diff($existingFacilityIds, $keptFacilityIds);
        if (! empty($removedFacilityIds)) {
            $toDelete = CompanyProfileFacility::whereIn('id', $removedFacilityIds)->get();
            foreach ($toDelete as $facility) {
                if ($facility->photo_path) {
                    Storage::disk('public')->delete($facility->photo_path);
                }
            }
            CompanyProfileFacility::whereIn('id', $removedFacilityIds)->delete();
        }

        $this->facilityUploads = [];
        $this->facilityRemovePhoto = [];

        // --- Sinkronkan Kenapa Pilih Club61 ---
        $existingValuePropIds = CompanyProfileValueProp::pluck('id')->all();
        $keptValuePropIds = [];

        foreach ($this->valueProps as $index => $prop) {
            $title = trim((string) ($prop['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $id = $prop['id'] ?: (string) Str::ulid();

            CompanyProfileValueProp::updateOrCreate(['id' => $id], [
                'icon_key' => in_array($prop['icon_key'] ?? '', $allowedValueIcons, true) ? $prop['icon_key'] : 'star',
                'title' => mb_substr($title, 0, 60),
                'title_en' => mb_substr(trim((string) ($prop['title_en'] ?? '')), 0, 60) ?: null,
                'description' => mb_substr(trim((string) ($prop['description'] ?? '')), 0, 150),
                'description_en' => mb_substr(trim((string) ($prop['description_en'] ?? '')), 0, 150) ?: null,
                'sort_order' => $index,
            ]);

            $keptValuePropIds[] = $id;
        }

        $removedValuePropIds = array_diff($existingValuePropIds, $keptValuePropIds);
        if (! empty($removedValuePropIds)) {
            CompanyProfileValueProp::whereIn('id', $removedValuePropIds)->delete();
        }

        // Re-mount supaya form menampilkan state final yang benar-benar tersimpan (ID baru,
        // path foto hasil re-encode, dsb).
        $this->mount();

        Notification::make()
            ->title('Konten Website Berhasil Disimpan')
            ->body('Perubahan langsung tampil di halaman depan & halaman login.')
            ->success()
            ->send();
    }
}
