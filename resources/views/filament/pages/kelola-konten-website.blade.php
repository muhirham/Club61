<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        /* Halaman Konten Website: form CMS dwibahasa (ID/EN) di atas desain c61. */
        .kkw-card-body { padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
        .kkw-field { display: flex; flex-direction: column; min-width: 0; }
        .kkw-field .c61-label { display: flex; align-items: center; flex-wrap: wrap; gap: 0.35rem; }
        .kkw-pair, .kkw-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.85rem 1rem; align-items: start; }
        .kkw-grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.85rem 1rem; align-items: start; }
        .kkw-card-body { container-type: inline-size; }
        .kkw-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem 1rem; align-items: start; }
        .kkw-col-2 { grid-column: 2; }
        @container (max-width: 480px) {
            .kkw-cols { grid-template-columns: minmax(0, 1fr); }
            .kkw-col-2 { grid-column: auto; }
        }
        .kkw-pair .c61-textarea,.kkw-field .c61-textarea { min-height: 84px; }
        .kkw-textarea-sm { min-height: 64px !important; }
        .kkw-textarea-md { min-height: 76px !important; }

        .kkw-tag { display: inline-flex; align-items: center; height: 18px; padding: 0 0.4rem; border-radius: 5px; font-size: 0.5625rem; font-weight: 800; letter-spacing: 0.06em; border: 1px solid transparent; }
        .kkw-tag-id { background: #F6EAE7; color: var(--c-terra); border-color: #E8CFC9; }
        .kkw-tag-en { background: #EFF6FF; color: #1D4ED8; border-color: #BFDBFE; }

        .kkw-hint { font-size: 0.6875rem; color: var(--c-muted); line-height: 1.5; padding: 0.6rem 0.8rem; border-radius: 10px; background: var(--c-paper); border: 1px dashed var(--c-line); }
        .kkw-hint code, .c61-hero-sub code { font-family: var(--font-mono); font-size: 0.92em; padding: 0.05rem 0.3rem; border-radius: 4px; background: var(--c-cream); color: var(--c-terra); }
        .c61-hero-sub code { background: rgba(247, 240, 219, 0.14); color: var(--c-cream); }
        .kkw-error { font-size: 0.6875rem; font-weight: 600; color: #B42318; margin-top: 0.35rem; }

        .kkw-list { display: flex; flex-direction: column; gap: 0.85rem; }
        .kkw-item { background: var(--c-paper); border: 1px solid var(--c-line); border-radius: 14px; padding: 1rem; display: flex; flex-direction: column; gap: 0.85rem; min-width: 0; }
        .kkw-item-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; padding-bottom: 0.75rem; border-bottom: 1px solid var(--c-line-soft); }
        .kkw-item-tag { display: inline-flex; align-items: center; height: 24px; padding: 0 0.65rem; border-radius: 999px; background: var(--c-terra); color: var(--c-cream); font-size: 0.625rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
        .kkw-item .c61-input, .kkw-item .c61-select, .kkw-item .c61-textarea { background: #FFFFFF; }
        .kkw-item .c61-icon-btn { width: 32px; height: 32px; min-width: 32px; font-size: 0.9375rem; font-weight: 700; line-height: 1; }

        .kkw-file { height: auto; padding: 0.4rem; font-weight: 500; cursor: pointer; }
        .kkw-file::file-selector-button { margin-right: 0.75rem; height: 30px; padding: 0 0.8rem; border-radius: 8px; border: 1px solid var(--c-terra); background: var(--c-cream); color: var(--c-terra); font-size: 0.75rem; font-weight: 700; cursor: pointer; }
        .kkw-photo { display: flex; align-items: center; gap: 0.65rem; margin-top: 0.6rem; padding: 0.5rem; border-radius: 12px; background: #FFFFFF; border: 1px solid var(--c-line); width: fit-content; max-width: 100%; }
        .kkw-photo img { width: 56px; height: 56px; object-fit: cover; border-radius: 9px; border: 1px solid var(--c-line); display: block; }

        .kkw-add { width: 100%; height: 44px; border-radius: 12px; border: 1.5px dashed #C9A9A2; background: #FFFFFF; color: var(--c-terra); font-size: 0.8125rem; font-weight: 700; cursor: pointer; transition: background 0.15s, border-color 0.15s; }
        .kkw-add:hover { background: #F6EAE7; border-color: var(--c-terra); }

        .kkw-savebar { display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem; flex-wrap: wrap; background: #FFFFFF; border: 1px solid var(--c-line); border-radius: 16px; padding: 0.75rem 0.85rem; }
    </style>

    {{-- HEADER BANNER --}}
    <div class="c61-hero">
        <div style="min-width:0; flex:1 1 360px;">
            <div class="c61-eyebrow">
                <span class="dot"></span>
                <span>Company Profile &bull; Konten Publik</span>
            </div>
            <div class="c61-hero-title">
                Konten Halaman Depan &amp; Panel Login
            </div>
            <div class="c61-hero-sub">
                Teks di sini langsung dipakai ulang di halaman depan publik (<code>/</code>) dan panel kiri halaman login — jumlah lapangan, daftar fasilitas, alamat &amp; jam operasional cuma ada 1 sumber, tidak akan beda-beda lagi antar halaman.
            </div>
        </div>

        <div class="c61-hero-actions">
            <button type="button" wire:click="save" class="c61-btn c61-btn-cream c61-btn-lg">
                <span>Simpan Perubahan</span>
            </button>
        </div>
    </div>

    {{-- KARTU 1: HERO HALAMAN DEPAN --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">1. Hero Halaman Depan (Company Profile)</div>
                <div class="c61-card-sub">Judul besar &amp; badge di halaman depan publik (welcome page).</div>
            </div>
        </div>

        <div class="kkw-card-body">
            <div class="kkw-pair">
                <div class="kkw-field">
                    <label class="c61-label">Badge Status (di atas judul) <span class="kkw-tag kkw-tag-id">ID</span></label>
                    <input type="text" wire:model="heroBadgeText" class="c61-input" placeholder="Contoh: Medan Flagship Venue &bull; Gedung Indosat &bull; Open Daily">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Badge Status <span class="kkw-tag kkw-tag-en">EN</span></label>
                    <input type="text" wire:model="heroBadgeTextEn" class="c61-input" placeholder="Kosongkan = pakai teks ID di atas">
                </div>
            </div>

            <div class="kkw-pair">
                <div class="kkw-field">
                    <label class="c61-label">Judul Baris 1 <span class="kkw-tag kkw-tag-id">ID</span></label>
                    <input type="text" wire:model="heroHeadlineLine1" class="c61-input" placeholder="The Sanctuary for">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Judul Baris 1 <span class="kkw-tag kkw-tag-en">EN</span></label>
                    <input type="text" wire:model="heroHeadlineLine1En" class="c61-input" placeholder="Kosongkan = pakai teks ID di atas">
                </div>
            </div>

            <div class="kkw-pair">
                <div class="kkw-field">
                    <label class="c61-label">Judul Bagian Emas (Highlight) <span class="kkw-tag kkw-tag-id">ID</span></label>
                    <input type="text" wire:model="heroHeadlineHighlight" class="c61-input" placeholder="Padel Athletes">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Judul Bagian Emas <span class="kkw-tag kkw-tag-en">EN</span></label>
                    <input type="text" wire:model="heroHeadlineHighlightEn" class="c61-input" placeholder="Kosongkan = pakai teks ID di atas">
                </div>
            </div>

            <div class="kkw-pair">
                <div class="kkw-field">
                    <label class="c61-label">Judul Baris Penutup <span class="kkw-tag kkw-tag-id">ID</span></label>
                    <input type="text" wire:model="heroHeadlineLine2" class="c61-input" placeholder="in Medan.">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Judul Baris Penutup <span class="kkw-tag kkw-tag-en">EN</span></label>
                    <input type="text" wire:model="heroHeadlineLine2En" class="c61-input" placeholder="Kosongkan = pakai teks ID di atas">
                </div>
            </div>

            <div class="kkw-pair">
                <div class="kkw-field">
                    <label class="c61-label">Subtitle / Deskripsi Fasilitas <span class="kkw-tag kkw-tag-id">ID</span></label>
                    <textarea wire:model="heroSubtitle" class="c61-textarea" placeholder="Fasilitas terpadu berstandar internasional..."></textarea>
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Subtitle / Deskripsi Fasilitas <span class="kkw-tag kkw-tag-en">EN</span></label>
                    <textarea wire:model="heroSubtitleEn" class="c61-textarea" placeholder="Kosongkan = pakai teks ID di samping"></textarea>
                </div>
            </div>
            <div class="kkw-hint">Boleh sisipkan <code>{court_count}</code> di mana pun (ID maupun EN) — otomatis diganti angka dari field "Jumlah Lapangan" di bawah.</div>
        </div>
    </div>

    {{-- KARTU 2: FAKTA VENUE (DIPAKAI ULANG DI 2 HALAMAN) --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">2. Fakta Venue</div>
                <div class="c61-card-sub">Dipakai ulang persis sama di halaman depan DAN panel kiri halaman login — sumbernya cuma satu di sini.</div>
            </div>
        </div>

        <div class="kkw-card-body">
            <div class="kkw-cols">
                <div class="kkw-field">
                    <label class="c61-label">Jumlah Lapangan</label>
                    <input type="number" min="1" wire:model="courtCount" class="c61-input" placeholder="3">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Jam Operasional <span class="kkw-tag kkw-tag-id">ID</span></label>
                    <input type="text" wire:model="operatingHoursText" class="c61-input" placeholder="Open 06:00 – 23:00">
                </div>
                <div class="kkw-field kkw-col-2">
                    <label class="c61-label">Jam Operasional <span class="kkw-tag kkw-tag-en">EN</span></label>
                    <input type="text" wire:model="operatingHoursTextEn" class="c61-input" placeholder="Kosongkan = pakai teks ID di atas">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Alamat</label>
                    <input type="text" wire:model="addressLine" class="c61-input" placeholder="Gedung Indosat, Jl. ...">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Domain Portal (tampil di panel login)</label>
                    <input type="text" wire:model="portalDomainText" class="c61-input" placeholder="portal.club61padel.com">
                </div>
            </div>
        </div>
    </div>

    {{-- KARTU 3: KARTU FASILITAS (REPEATER) --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">3. Kartu Fasilitas Ringkas (Hero &amp; Panel Login)</div>
                <div class="c61-card-sub">Kartu kecil ber-ikon, muncul di kedua halaman. Urutan di sini = urutan tampil. Maksimal 8 kartu.</div>
            </div>
        </div>

        <div class="kkw-card-body">
            <div class="kkw-list">
                @foreach($facilityCards as $index => $card)
                    <div class="kkw-item" wire:key="facility-card-{{ $index }}">
                        <div class="kkw-item-head">
                            <span class="kkw-item-tag">Kartu #{{ $index + 1 }}</span>
                            <div class="c61-actions">
                                <button type="button" class="c61-icon-btn" wire:click="moveFacilityCardUp({{ $index }})" title="Naikkan urutan">↑</button>
                                <button type="button" class="c61-icon-btn" wire:click="moveFacilityCardDown({{ $index }})" title="Turunkan urutan">↓</button>
                                <button type="button" class="c61-icon-btn is-danger" wire:click="removeFacilityCard({{ $index }})" title="Hapus kartu">&times;</button>
                            </div>
                        </div>

                        <div class="kkw-cols">
                            <div class="kkw-field">
                                <label class="c61-label">Ikon</label>
                                <select wire:model="facilityCards.{{ $index }}.icon_key" class="c61-select">
                                    @foreach(\App\Models\Setting\CompanyProfileSetting::ALLOWED_ICON_KEYS as $iconKey)
                                        <option value="{{ $iconKey }}">{{ ucfirst($iconKey) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="kkw-pair">
                            <div class="kkw-field">
                                <label class="c61-label">Judul <span class="kkw-tag kkw-tag-id">ID</span></label>
                                <input type="text" wire:model="facilityCards.{{ $index }}.title" class="c61-input" placeholder="Padel Arena" maxlength="40">
                            </div>
                            <div class="kkw-field">
                                <label class="c61-label">Judul <span class="kkw-tag kkw-tag-en">EN</span></label>
                                <input type="text" wire:model="facilityCards.{{ $index }}.title_en" class="c61-input" placeholder="Kosongkan = pakai ID" maxlength="40">
                            </div>
                        </div>
                        <div class="kkw-pair">
                            <div class="kkw-field">
                                <label class="c61-label">Sub-teks <span class="kkw-tag kkw-tag-id">ID</span></label>
                                <input type="text" wire:model="facilityCards.{{ $index }}.subtitle" class="c61-input" placeholder="+ Panoramic Courts" maxlength="60">
                            </div>
                            <div class="kkw-field">
                                <label class="c61-label">Sub-teks <span class="kkw-tag kkw-tag-en">EN</span></label>
                                <input type="text" wire:model="facilityCards.{{ $index }}.subtitle_en" class="c61-input" placeholder="Kosongkan = pakai ID" maxlength="60">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" class="kkw-add" wire:click="addFacilityCard">+ Tambah Kartu Fasilitas</button>
        </div>
    </div>

    {{-- KARTU 4: FACILITIES SHOWCASE (DENGAN FOTO) --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">4. Facilities Showcase (Detail + Foto)</div>
                <div class="c61-card-sub">Section lebih besar di halaman depan, tiap fasilitas boleh punya foto &amp; daftar amenity. Maksimal 6.</div>
            </div>
        </div>

        <div class="kkw-card-body">
            <div class="kkw-list">
                @foreach($facilities as $index => $facility)
                    <div class="kkw-item" wire:key="facility-{{ $facility['id'] ?? 'new-'.$index }}">
                        <div class="kkw-item-head">
                            <span class="kkw-item-tag">Fasilitas #{{ $index + 1 }}</span>
                            <div class="c61-actions">
                                <button type="button" class="c61-icon-btn" wire:click="moveFacilityUp({{ $index }})" title="Naikkan urutan">↑</button>
                                <button type="button" class="c61-icon-btn" wire:click="moveFacilityDown({{ $index }})" title="Turunkan urutan">↓</button>
                                <button type="button" class="c61-icon-btn is-danger" wire:click="removeFacility({{ $index }})" title="Hapus fasilitas">&times;</button>
                            </div>
                        </div>

                        <div class="kkw-pair">
                            <div class="kkw-field">
                                <label class="c61-label">Judul <span class="kkw-tag kkw-tag-id">ID</span></label>
                                <input type="text" wire:model="facilities.{{ $index }}.title" class="c61-input" placeholder="Padel Arena" maxlength="80">
                            </div>
                            <div class="kkw-field">
                                <label class="c61-label">Judul <span class="kkw-tag kkw-tag-en">EN</span></label>
                                <input type="text" wire:model="facilities.{{ $index }}.title_en" class="c61-input" placeholder="Kosongkan = pakai ID" maxlength="80">
                            </div>
                        </div>

                        <div class="kkw-field">
                            <label class="c61-label">Foto (JPG/PNG/WEBP, maks 2MB)</label>
                            <input type="file" wire:model="facilityUploads.{{ $index }}" class="c61-input kkw-file" accept="image/png,image/jpeg,image/webp">
                            @if($facility['photo_path'] ?? null)
                                <div class="kkw-photo">
                                    <img src="{{ '/storage/'.$facility['photo_path'] }}">
                                    <button type="button" class="c61-icon-btn is-danger" wire:click="removeFacilityPhoto({{ $index }})" title="Hapus foto ini">&times;</button>
                                </div>
                            @endif
                            @error("facilityUploads.{$index}") <div class="kkw-error">{{ $message }}</div> @enderror
                        </div>

                        <div class="kkw-pair">
                            <div class="kkw-field">
                                <label class="c61-label">Deskripsi Singkat <span class="kkw-tag kkw-tag-id">ID</span></label>
                                <textarea wire:model="facilities.{{ $index }}.description" class="c61-textarea kkw-textarea-sm" placeholder="Lapangan padel panoramic full indoor ber-AC..."></textarea>
                            </div>
                            <div class="kkw-field">
                                <label class="c61-label">Deskripsi Singkat <span class="kkw-tag kkw-tag-en">EN</span></label>
                                <textarea wire:model="facilities.{{ $index }}.description_en" class="c61-textarea kkw-textarea-sm" placeholder="Kosongkan = pakai teks ID"></textarea>
                            </div>
                        </div>

                        <div class="kkw-pair">
                            <div class="kkw-field">
                                <label class="c61-label">Daftar Amenity (1 baris = 1 item) <span class="kkw-tag kkw-tag-id">ID</span></label>
                                <textarea wire:model="facilities.{{ $index }}.amenities_text" class="c61-textarea kkw-textarea-md" placeholder="Lantai profesional&#10;Pencahayaan LED&#10;Kaca panoramic"></textarea>
                            </div>
                            <div class="kkw-field">
                                <label class="c61-label">Daftar Amenity <span class="kkw-tag kkw-tag-en">EN</span></label>
                                <textarea wire:model="facilities.{{ $index }}.amenities_en_text" class="c61-textarea kkw-textarea-md" placeholder="Kosongkan = pakai daftar ID"></textarea>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" class="kkw-add" wire:click="addFacility">+ Tambah Fasilitas</button>
        </div>
    </div>

    {{-- KARTU 5: KENAPA PILIH CLUB61 --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">5. Kenapa Pilih Club61</div>
                <div class="c61-card-sub">Grid keunggulan (ikon + judul singkat). Maksimal 6 kotak.</div>
            </div>
        </div>

        <div class="kkw-card-body">
            <div class="kkw-list">
                @foreach($valueProps as $index => $prop)
                    <div class="kkw-item" wire:key="value-prop-{{ $prop['id'] ?? 'new-'.$index }}">
                        <div class="kkw-item-head">
                            <span class="kkw-item-tag">Poin #{{ $index + 1 }}</span>
                            <div class="c61-actions">
                                <button type="button" class="c61-icon-btn" wire:click="moveValuePropUp({{ $index }})" title="Naikkan urutan">↑</button>
                                <button type="button" class="c61-icon-btn" wire:click="moveValuePropDown({{ $index }})" title="Turunkan urutan">↓</button>
                                <button type="button" class="c61-icon-btn is-danger" wire:click="removeValueProp({{ $index }})" title="Hapus poin">&times;</button>
                            </div>
                        </div>

                        <div class="kkw-cols">
                            <div class="kkw-field">
                                <label class="c61-label">Ikon</label>
                                <select wire:model="valueProps.{{ $index }}.icon_key" class="c61-select">
                                    @foreach(\App\Models\Setting\CompanyProfileValueProp::ALLOWED_ICON_KEYS as $iconKey)
                                        <option value="{{ $iconKey }}">{{ ucfirst($iconKey) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="kkw-pair">
                            <div class="kkw-field">
                                <label class="c61-label">Judul <span class="kkw-tag kkw-tag-id">ID</span></label>
                                <input type="text" wire:model="valueProps.{{ $index }}.title" class="c61-input" placeholder="Booking Online Real-Time" maxlength="60">
                            </div>
                            <div class="kkw-field">
                                <label class="c61-label">Judul <span class="kkw-tag kkw-tag-en">EN</span></label>
                                <input type="text" wire:model="valueProps.{{ $index }}.title_en" class="c61-input" placeholder="Kosongkan = pakai ID" maxlength="60">
                            </div>
                        </div>
                        <div class="kkw-pair">
                            <div class="kkw-field">
                                <label class="c61-label">Deskripsi Singkat <span class="kkw-tag kkw-tag-id">ID</span></label>
                                <input type="text" wire:model="valueProps.{{ $index }}.description" class="c61-input" placeholder="Cek slot & bayar langsung dari HP" maxlength="150">
                            </div>
                            <div class="kkw-field">
                                <label class="c61-label">Deskripsi Singkat <span class="kkw-tag kkw-tag-en">EN</span></label>
                                <input type="text" wire:model="valueProps.{{ $index }}.description_en" class="c61-input" placeholder="Kosongkan = pakai ID" maxlength="150">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" class="kkw-add" wire:click="addValueProp">+ Tambah Poin</button>
        </div>
    </div>

    {{-- KARTU 6: LOKASI & KONTAK --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">6. Lokasi &amp; Kontak</div>
                <div class="c61-card-sub">Tombol WhatsApp &amp; peta di halaman depan.</div>
            </div>
        </div>

        <div class="kkw-card-body">
            <div class="kkw-grid-2">
                <div class="kkw-field">
                    <label class="c61-label">Nomor WhatsApp (format: 62812xxxxxxx)</label>
                    <input type="text" wire:model="whatsappNumber" class="c61-input" placeholder="6281234567890">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">URL Embed Google Maps (opsional)</label>
                    <input type="text" wire:model="mapsEmbedUrl" class="c61-input" placeholder="https://www.google.com/maps/embed?...">
                </div>
            </div>
        </div>
    </div>

    {{-- KARTU 7: FOOTER --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">7. Footer</div>
                <div class="c61-card-sub">Tagline singkat &amp; tautan sosial media.</div>
            </div>
        </div>

        <div class="kkw-card-body">
            <div class="kkw-pair">
                <div class="kkw-field">
                    <label class="c61-label">Tagline Footer <span class="kkw-tag kkw-tag-id">ID</span></label>
                    <input type="text" wire:model="footerTagline" class="c61-input" placeholder="Sanctuary padel premium di jantung kota Medan.">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Tagline Footer <span class="kkw-tag kkw-tag-en">EN</span></label>
                    <input type="text" wire:model="footerTaglineEn" class="c61-input" placeholder="Kosongkan = pakai teks ID di samping">
                </div>
            </div>

            <div class="kkw-grid-3">
                <div class="kkw-field">
                    <label class="c61-label">Instagram (URL)</label>
                    <input type="text" wire:model="footerInstagram" class="c61-input" placeholder="https://instagram.com/club61">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">Facebook (URL)</label>
                    <input type="text" wire:model="footerFacebook" class="c61-input" placeholder="https://facebook.com/club61">
                </div>
                <div class="kkw-field">
                    <label class="c61-label">TikTok (URL)</label>
                    <input type="text" wire:model="footerTiktok" class="c61-input" placeholder="https://tiktok.com/@club61">
                </div>
            </div>
        </div>
    </div>

    <div class="kkw-savebar">
        <button type="button" wire:click="save" class="c61-btn c61-btn-primary c61-btn-lg">
            <span>Simpan Perubahan</span>
        </button>
    </div>
</div>
