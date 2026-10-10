<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        /* Halaman Menu F&B — gaya khusus di atas sistem c61 */
        .fnb-table th:first-child, .fnb-table td:first-child { padding-left: 1.25rem; }
        .fnb-table th:last-child, .fnb-table td:last-child { padding-right: 1.25rem; text-align: right; white-space: nowrap; }
        .fnb-table-menu { min-width: 860px; }
        .fnb-table-cat { min-width: 520px; }
        .fnb-table-mod { min-width: 640px; }
        .fnb-table-station { min-width: 680px; }
        .fnb-table .fnb-mono { font-family: var(--font-mono); font-weight: 700; color: var(--c-brown); white-space: nowrap; }
        .fnb-table .fnb-center { text-align: center; }
        .fnb-table .fnb-price { font-family: var(--font-mono); font-weight: 700; color: var(--c-terra); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .fnb-table .fnb-mods { font-size: 0.75rem; color: var(--c-muted); max-width: 200px; line-height: 1.4; }

        .fnb-thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 10px; border: 1px solid var(--c-line); display: block; background: var(--c-paper); }
        .fnb-thumb-empty { width: 44px; height: 44px; border-radius: 10px; background: var(--c-paper); border: 1px dashed var(--c-line); }
        .fnb-thumb-lg { width: 56px; height: 56px; }

        .fnb-act { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; cursor: pointer; border: 1px solid var(--c-line); background: #FFFFFF; color: var(--c-brown); transition: background 0.15s, border-color 0.15s, color 0.15s; }
        .fnb-act:hover { border-color: var(--c-terra); color: var(--c-terra); }
        .fnb-act-danger { background: #FEF2F2; border-color: #FECACA; color: #B42318; }
        .fnb-act-danger:hover { background: #FEE2E2; border-color: #FCA5A5; color: #912018; }
        .fnb-act:disabled, .fnb-act[disabled] { background: #F5F2EC; border-color: #E7E0D3; color: #B5ABA2; cursor: not-allowed; }

        .fnb-switch { position: relative; width: 44px; height: 24px; background: #E7E0D3; border-radius: 9999px; transition: background-color 0.2s ease; border: 1px solid #D8CDB8; display: inline-block; vertical-align: middle; }
        .fnb-switch.on { background: #059669; border-color: #047857; }
        .fnb-switch-knob { position: absolute; top: 1px; left: 1px; width: 20px; height: 20px; background: #FFFFFF; border-radius: 50%; box-shadow: 0 1px 3px rgba(42, 20, 16, 0.25); transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .fnb-switch.on .fnb-switch-knob { transform: translateX(20px); }

        .fnb-filters { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .fnb-filters .c61-search { width: 240px; max-width: 100%; }
        .fnb-filters .c61-select { width: 190px; max-width: 100%; }

        .fnb-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 1rem; }
        @media (max-width: 560px) { .fnb-grid-2 { grid-template-columns: 1fr; } }
        .fnb-error { display: block; font-size: 0.6875rem; font-weight: 600; color: #B42318; margin-top: 0.35rem; }
        .fnb-photo-row { display: flex; align-items: center; gap: 0.75rem; margin-top: 0.6rem; padding: 0.6rem; border: 1px solid var(--c-line-soft); border-radius: 12px; background: var(--c-paper); }
        .fnb-file { height: auto; padding: 0.45rem 0.6rem; }
        .fnb-file::file-selector-button { margin-right: 0.75rem; height: 28px; padding: 0 0.75rem; border-radius: 7px; border: 1px solid var(--c-line); background: #FFFFFF; color: var(--c-terra); font-size: 0.75rem; font-weight: 700; cursor: pointer; }
        .fnb-radios { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .fnb-choice { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 0.8rem; border: 1px solid var(--c-line); border-radius: 10px; background: #FFFFFF; font-size: 0.8125rem; font-weight: 700; cursor: pointer; }
        .fnb-choice input { accent-color: var(--c-terra); }
        .fnb-choice-ok { color: #047857; }
        .fnb-choice-muted { color: var(--c-muted); }
        .fnb-checks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; }
        @media (max-width: 560px) { .fnb-checks { grid-template-columns: 1fr; } }
        .fnb-checks .fnb-choice { font-size: 0.8125rem; font-weight: 600; color: var(--c-brown); min-width: 0; }
        .fnb-checks-empty { font-size: 0.75rem; color: var(--c-faint); grid-column: 1 / -1; }
        .fnb-options { display: flex; flex-direction: column; gap: 0.5rem; }
        .fnb-option-row { display: grid; grid-template-columns: minmax(0, 1fr) 150px 34px; gap: 0.5rem; align-items: start; }
        @media (max-width: 520px) { .fnb-option-row { grid-template-columns: minmax(0, 1fr) 110px 34px; } }
        .fnb-option-row .c61-icon-btn { margin-top: 3px; font-size: 1.1rem; line-height: 1; }
        .fnb-add-opt { align-self: flex-start; margin-top: 0.6rem; }
        .fnb-modal-text { font-size: 0.875rem; color: var(--c-brown); line-height: 1.55; }
    </style>

    {{-- HEADER BANNER --}}
    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow">
                <span class="dot"></span>
                <span>F&amp;B Cafe &amp; Kitchen &bull; Katalog Menu</span>
            </div>
            <div class="c61-hero-title">Kelola Menu F&amp;B &amp; Tambahan</div>
            <div class="c61-hero-sub">
                Kategori, menu makanan/minuman (lengkap dengan foto), grup tambahan/modifier (mis. pilihan susu, level gula), dan stasiun produksi beserta printernya — semuanya di 1 halaman ini.
            </div>
        </div>

        <div class="c61-hero-actions">
            @if($activeTab === 'categories')
                <button type="button" wire:click="openCreateCategoryModal" class="c61-btn c61-btn-cream c61-btn-lg">+ Tambah Kategori</button>
            @elseif($activeTab === 'menus')
                <button type="button" wire:click="openCreateMenuModal" class="c61-btn c61-btn-cream c61-btn-lg">+ Tambah Menu</button>
            @elseif($activeTab === 'modifiers')
                <button type="button" wire:click="openCreateModifierGroupModal" class="c61-btn c61-btn-cream c61-btn-lg">+ Tambah Grup Tambahan</button>
            @else
                <button type="button" wire:click="openCreateStationModal" class="c61-btn c61-btn-cream c61-btn-lg">+ Tambah Stasiun</button>
            @endif
        </div>
    </div>

    {{-- NAVIGASI TAB --}}
    <div>
        <div class="c61-seg">
            <button type="button" wire:click="requestTabChange('categories')" class="c61-seg-btn {{ $activeTab === 'categories' ? 'is-active' : '' }}">Kategori Menu</button>
            <button type="button" wire:click="requestTabChange('menus')" class="c61-seg-btn {{ $activeTab === 'menus' ? 'is-active' : '' }}">Menu F&amp;B</button>
            <button type="button" wire:click="requestTabChange('modifiers')" class="c61-seg-btn {{ $activeTab === 'modifiers' ? 'is-active' : '' }}">Tambahan / Modifier</button>
            <button type="button" wire:click="requestTabChange('stations')" class="c61-seg-btn {{ $activeTab === 'stations' ? 'is-active' : '' }}">Stasiun &amp; Printer</button>
        </div>
    </div>

    {{-- ================= TAB 1: KATEGORI ================= --}}
    @if($activeTab === 'categories')
        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Daftar Kategori Menu</div>
                    <div class="c61-card-sub">Urutan di sini menentukan urutan kategori di layar kasir POS.</div>
                </div>
                <span class="c61-pill c61-pill-cream">Total: {{ $this->categories->count() }} Kategori</span>
            </div>

            <div class="c61-table-wrap">
                <table class="c61-table fnb-table fnb-table-cat">
                    <thead>
                        <tr>
                            <th>Nama Kategori</th>
                            <th>Urutan</th>
                            <th>Jumlah Menu</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->categories as $category)
                            <tr>
                                <td class="strong">{{ $category->name }}</td>
                                <td><span class="c61-pill c61-pill-cream">{{ $category->sort_order }}</span></td>
                                <td>{{ $category->menus_count }}</td>
                                <td>
                                    <div class="c61-actions">
                                        <button type="button" wire:click="openEditCategoryModal('{{ $category->id }}')" class="fnb-act">Edit</button>
                                        @if($category->menus_count > 0)
                                            <button type="button" class="fnb-act" title="Masih dipakai {{ $category->menus_count }} menu" disabled>Hapus</button>
                                        @else
                                            <button type="button" x-on:click="$dispatch('club61-confirm', { title: @js('Hapus kategori '.$category->name.'?'), message: 'Kategori akan dihapus dari daftar menu F&B.', confirmLabel: 'Ya, Hapus', tone: 'danger', onConfirm: () => $wire.deleteCategory(@js($category->id)) })" class="fnb-act fnb-act-danger">Hapus</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="c61-empty">Belum ada kategori. Klik "+ Tambah Kategori".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= TAB 2: MENU F&B ================= --}}
    @if($activeTab === 'menus')
        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Katalog Menu F&amp;B</div>
                    <div class="c61-card-sub">{{ $this->menus->count() }} menu ditampilkan.</div>
                </div>
                <div class="fnb-filters">
                    <div class="c61-search">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        <input type="text" wire:model.live.debounce.400ms="menuSearch" class="c61-input" placeholder="Cari nama menu...">
                    </div>
                    <select wire:model.live="menuCategoryFilter" class="c61-select">
                        <option value="ALL">Semua Kategori</option>
                        @foreach($this->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="c61-table-wrap">
                <table class="c61-table fnb-table fnb-table-menu">
                    <thead>
                        <tr>
                            <th>Foto</th>
                            <th>Nama Menu</th>
                            <th>Kategori</th>
                            <th>Stasiun</th>
                            <th>Harga</th>
                            <th>Tambahan</th>
                            <th class="fnb-center">Tersedia</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->menus as $menu)
                            <tr>
                                <td>
                                    @if($menu->image_url)
                                        <img src="{{ '/storage/'.$menu->image_url }}" class="fnb-thumb">
                                    @else
                                        <div class="fnb-thumb-empty"></div>
                                    @endif
                                </td>
                                <td class="strong">{{ $menu->name }}</td>
                                <td><span class="c61-pill c61-pill-cream">{{ $menu->category?->name }}</span></td>
                                <td><span class="c61-pill {{ $menu->station ? 'c61-pill-warn' : 'c61-pill-gray' }}">{{ $menu->station?->name ?? 'Kasir' }}</span></td>
                                <td class="fnb-price">Rp {{ number_format($menu->base_price, 0, ',', '.') }}</td>
                                <td class="fnb-mods">{{ $menu->modifierGroups->pluck('name')->implode(', ') ?: '-' }}</td>
                                <td class="fnb-center">
                                    <div class="fnb-switch {{ $menu->is_available ? 'on' : '' }}"><div class="fnb-switch-knob"></div></div>
                                </td>
                                <td>
                                    <div class="c61-actions">
                                        <button type="button" wire:click="openEditMenuModal('{{ $menu->id }}')" class="fnb-act">Edit</button>
                                        <button type="button" x-on:click="$dispatch('club61-confirm', { title: @js('Hapus menu '.$menu->name.'?'), message: 'Menu akan dihapus dari daftar menu F&B.', confirmLabel: 'Ya, Hapus', tone: 'danger', onConfirm: () => $wire.deleteMenu(@js($menu->id)) })" class="fnb-act fnb-act-danger">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="c61-empty">Belum ada menu. Klik "+ Tambah Menu".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= TAB 3: TAMBAHAN / MODIFIER ================= --}}
    @if($activeTab === 'modifiers')
        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Grup Tambahan / Modifier</div>
                    <div class="c61-card-sub">Contoh: "Milk Option", "Sugar Level". Dipasangkan ke menu lewat form Menu di tab sebelah.</div>
                </div>
                <span class="c61-pill c61-pill-cream">Total: {{ $this->modifierGroups->count() }} Grup</span>
            </div>

            <div class="c61-table-wrap">
                <table class="c61-table fnb-table fnb-table-mod">
                    <thead>
                        <tr>
                            <th>Nama Grup</th>
                            <th>Wajib?</th>
                            <th>Maks. Pilihan</th>
                            <th>Jumlah Opsi</th>
                            <th>Dipakai di Menu</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->modifierGroups as $group)
                            <tr>
                                <td class="strong">{{ $group->name }}</td>
                                <td><span class="c61-pill {{ $group->is_required ? 'c61-pill-terra' : 'c61-pill-gray' }}">{{ $group->is_required ? 'Ya' : 'Tidak' }}</span></td>
                                <td>{{ $group->max_selection }}</td>
                                <td>{{ $group->options_count }}</td>
                                <td>{{ $group->menus_count }}</td>
                                <td>
                                    <div class="c61-actions">
                                        <button type="button" wire:click="openEditModifierGroupModal('{{ $group->id }}')" class="fnb-act">Edit</button>
                                        <button type="button" x-on:click="$dispatch('club61-confirm', { title: @js('Hapus grup '.$group->name.'?'), message: 'Grup modifier ini akan dilepas dari semua menu yang memakainya.', confirmLabel: 'Ya, Hapus', tone: 'danger', onConfirm: () => $wire.deleteModifierGroup(@js($group->id)) })" class="fnb-act fnb-act-danger">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="c61-empty">Belum ada grup tambahan. Klik "+ Tambah Grup Tambahan".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= TAB 4: STASIUN & PRINTER ================= --}}
    @if($activeTab === 'stations')
        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Stasiun Produksi &amp; Printer</div>
                    <div class="c61-card-sub">Begitu pesanan lunas, slip menu tiap stasiun dikirim tablet kasir ke printer LAN stasiunnya. Menu "Kasir (tanpa slip)" dibuat langsung di kasir.</div>
                </div>
                <span class="c61-pill c61-pill-cream">Total: {{ $this->stations->count() }} Stasiun</span>
            </div>

            <div class="c61-table-wrap">
                <table class="c61-table fnb-table fnb-table-station">
                    <thead>
                        <tr>
                            <th>Nama Stasiun</th>
                            <th>Printer (IP : Port)</th>
                            <th>Jumlah Menu</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->stations as $station)
                            <tr wire:key="station-{{ $station->id }}">
                                <td class="strong">{{ $station->name }}</td>
                                <td>
                                    @if($station->hasPrinter())
                                        <span class="fnb-mono">{{ $station->printer_host }}:{{ $station->printer_port }}</span>
                                    @else
                                        <span class="c61-pill c61-pill-warn">IP printer belum diisi</span>
                                    @endif
                                </td>
                                <td>{{ $station->menus_count }}</td>
                                <td><span class="c61-pill {{ $station->is_active ? 'c61-pill-ok' : 'c61-pill-gray' }}">{{ $station->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                <td>
                                    <div class="c61-actions">
                                        <button type="button" wire:click="openEditStationModal('{{ $station->id }}')" class="fnb-act">Edit</button>
                                        <button type="button" x-on:click="$dispatch('club61-confirm', { title: @js('Hapus stasiun '.$station->name.'?'), message: @js($station->menus_count > 0 ? $station->menus_count.' menu di stasiun ini akan dipindah ke '.\App\Models\Fnb\FnbStation::CASHIER_LABEL.'. Riwayat slip lama tetap tersimpan.' : 'Stasiun akan dihapus dari daftar.'), confirmLabel: 'Ya, Hapus', tone: 'danger', onConfirm: () => $wire.deleteStation(@js($station->id)) })" class="fnb-act fnb-act-danger">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="c61-empty">Belum ada stasiun — semua menu dibuat di kasir. Klik "+ Tambah Stasiun" untuk Kitchen / Bar.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= MODAL: STASIUN & PRINTER ================= --}}
    @if($showStationModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width: 520px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top: 0;">{{ $editingStationId ? 'Edit Stasiun' : 'Tambah Stasiun' }}</div>
                    <button type="button" wire:click="closeStationModal" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body" style="gap: 0;">
                    <div class="c61-field">
                        <label class="c61-label">Nama Stasiun</label>
                        <input type="text" wire:model="stationName" class="c61-input" placeholder="Contoh: Kitchen / Bar">
                        @error('stationName') <span class="fnb-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="fnb-grid-2">
                        <div class="c61-field">
                            <label class="c61-label">IP Printer LAN</label>
                            <input type="text" wire:model="stationPrinterHost" class="c61-input" placeholder="192.168.1.50" inputmode="decimal">
                            @error('stationPrinterHost') <span class="fnb-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="c61-field">
                            <label class="c61-label">Port</label>
                            <input type="number" wire:model="stationPrinterPort" class="c61-input" min="1" max="65535">
                            @error('stationPrinterPort') <span class="fnb-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <span class="c61-hint" style="margin-top: -0.4rem; margin-bottom: 0.9rem; display: block;">Pakai IP tetap (atur di router / printer) supaya slip tidak nyasar. Port printer thermal LAN umumnya 9100.</span>

                    <div class="c61-field" style="margin-bottom: 0;">
                        <label class="c61-label">Status</label>
                        <div class="fnb-radios">
                            <label class="fnb-choice">
                                <input type="radio" wire:model="stationIsActive" value="1">
                                <span class="fnb-choice-ok">Aktif — slip dicetak</span>
                            </label>
                            <label class="fnb-choice">
                                <input type="radio" wire:model="stationIsActive" value="0">
                                <span class="fnb-choice-muted">Nonaktif — menunya tanpa slip</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeStationModal" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="saveStation" class="c61-btn c61-btn-primary">Simpan Stasiun</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL: KATEGORI ================= --}}
    @if($showCategoryModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width: 480px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top: 0;">{{ $editingCategoryId ? 'Edit Kategori' : 'Tambah Kategori' }}</div>
                    <button type="button" wire:click="closeCategoryModal" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body" style="gap: 0;">
                    <div class="c61-field">
                        <label class="c61-label">Nama Kategori</label>
                        <input type="text" wire:model="categoryName" class="c61-input" placeholder="Contoh: Coffee & Drinks">
                        @error('categoryName') <span class="fnb-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="c61-field" style="margin-bottom: 0;">
                        <label class="c61-label">Urutan Tampil</label>
                        <input type="number" wire:model="categorySortOrder" class="c61-input">
                        @error('categorySortOrder') <span class="fnb-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeCategoryModal" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="saveCategory" class="c61-btn c61-btn-primary">Simpan Kategori</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL: MENU ================= --}}
    @if($showMenuModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width: 640px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top: 0;">{{ $editingMenuId ? 'Edit Menu' : 'Tambah Menu' }}</div>
                    <button type="button" wire:click="closeMenuModal" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body" style="gap: 0;">
                    <div class="fnb-grid-2">
                        <div class="c61-field">
                            <label class="c61-label">Kategori</label>
                            <select wire:model="menuCategoryId" class="c61-select">
                                @foreach($this->categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('menuCategoryId') <span class="fnb-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="c61-field">
                            <label class="c61-label">Nama Menu</label>
                            <input type="text" wire:model="menuName" class="c61-input" placeholder="Iced Spanish Latte">
                            @error('menuName') <span class="fnb-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="c61-field">
                        <label class="c61-label">Deskripsi</label>
                        <textarea wire:model="menuDescription" class="c61-textarea" rows="3" placeholder="Espresso double shot dengan susu segar dingin."></textarea>
                        @error('menuDescription') <span class="fnb-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="c61-field">
                        <label class="c61-label">Foto Menu (JPG/PNG/WEBP, maks 2MB)</label>
                        <input type="file" wire:model="menuPhotoUpload" class="c61-input fnb-file" accept="image/png,image/jpeg,image/webp">
                        @if($existingMenuPhotoPath && ! $removeMenuPhoto)
                            <div class="fnb-photo-row">
                                <img src="{{ '/storage/'.$existingMenuPhotoPath }}" class="fnb-thumb fnb-thumb-lg">
                                <button type="button" wire:click="removeMenuPhotoNow" class="fnb-act fnb-act-danger">Hapus Foto</button>
                            </div>
                        @endif
                        @error('menuPhotoUpload') <span class="fnb-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="fnb-grid-2">
                        <div class="c61-field">
                            <label class="c61-label">Harga Dasar (Rp)</label>
                            <input type="number" wire:model="menuBasePrice" class="c61-input" placeholder="38000">
                            @error('menuBasePrice') <span class="fnb-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="c61-field">
                            <label class="c61-label">Stasiun Produksi</label>
                            <select wire:model="menuStationId" class="c61-select">
                                <option value="">{{ \App\Models\Fnb\FnbStation::CASHIER_LABEL }}</option>
                                @foreach($this->stations as $station)
                                    <option value="{{ $station->id }}">{{ $station->name }}{{ $station->is_active ? '' : ' (nonaktif)' }}</option>
                                @endforeach
                            </select>
                            <span class="c61-hint">Slip pesanan dicetak di printer stasiun ini begitu lunas.</span>
                            @error('menuStationId') <span class="fnb-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="c61-field">
                        <label class="c61-label">Status</label>
                        <div class="fnb-radios">
                            <label class="fnb-choice">
                                <input type="radio" wire:model="menuIsAvailable" value="1">
                                <span class="fnb-choice-ok">Tersedia untuk Dijual</span>
                            </label>
                            <label class="fnb-choice">
                                <input type="radio" wire:model="menuIsAvailable" value="0">
                                <span class="fnb-choice-muted">Habis / Disembunyikan</span>
                            </label>
                        </div>
                    </div>

                    <div class="c61-field" style="margin-bottom: 0;">
                        <label class="c61-label">Grup Tambahan / Modifier (Opsional)</label>
                        <div class="fnb-checks">
                            @forelse($this->modifierGroups as $group)
                                <label class="fnb-choice">
                                    <input type="checkbox" wire:model="menuModifierGroupIds" value="{{ $group->id }}">
                                    <span class="c61-truncate">{{ $group->name }}</span>
                                </label>
                            @empty
                                <span class="fnb-checks-empty">Belum ada grup tambahan — buat dulu di tab "Tambahan / Modifier".</span>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeMenuModal" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="saveMenu" class="c61-btn c61-btn-primary">Simpan Menu</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL: GRUP TAMBAHAN / MODIFIER ================= --}}
    @if($showModifierGroupModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width: 640px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top: 0;">{{ $editingModifierGroupId ? 'Edit Grup Tambahan' : 'Tambah Grup Tambahan' }}</div>
                    <button type="button" wire:click="closeModifierGroupModal" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body" style="gap: 0;">
                    <div class="c61-field">
                        <label class="c61-label">Nama Grup</label>
                        <input type="text" wire:model="modifierGroupName" class="c61-input" placeholder="Milk Option">
                        @error('modifierGroupName') <span class="fnb-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="fnb-grid-2">
                        <div class="c61-field">
                            <label class="c61-label">Wajib Dipilih Customer?</label>
                            <select wire:model="modifierGroupIsRequired" class="c61-select">
                                <option value="0">Tidak</option>
                                <option value="1">Ya</option>
                            </select>
                        </div>
                        <div class="c61-field">
                            <label class="c61-label">Maksimal Opsi Dipilih</label>
                            <input type="number" wire:model="modifierGroupMaxSelection" class="c61-input" min="1">
                            @error('modifierGroupMaxSelection') <span class="fnb-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="c61-field" style="margin-bottom: 0; display: flex; flex-direction: column;">
                        <label class="c61-label">Daftar Opsi</label>
                        <div class="fnb-options">
                            @foreach($modifierOptions as $index => $option)
                                <div class="fnb-option-row" wire:key="modifier-option-{{ $index }}">
                                    <div>
                                        <input type="text" wire:model="modifierOptions.{{ $index }}.name" class="c61-input" placeholder="Contoh: Oat Milk">
                                        @error("modifierOptions.{$index}.name") <span class="fnb-error">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <input type="number" wire:model="modifierOptions.{{ $index }}.extra_price" class="c61-input" placeholder="Harga tambahan">
                                    </div>
                                    <button type="button" wire:click="removeModifierOptionRow({{ $index }})" class="c61-icon-btn is-danger">&times;</button>
                                </div>
                            @endforeach
                        </div>
                        <button type="button" wire:click="addModifierOptionRow" class="c61-btn c61-btn-ghost c61-btn-sm fnb-add-opt">+ Tambah Opsi</button>
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeModifierGroupModal" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="saveModifierGroup" class="c61-btn c61-btn-primary">Simpan Grup</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL: KONFIRMASI PERUBAHAN BELUM DISIMPAN ================= --}}
    @if($showUnsavedChangesModal)
        <div class="c61-modal-backdrop" style="z-index:10000;">
            <div class="c61-modal is-danger" style="max-width: 480px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top: 0;">Ada Perubahan Belum Disimpan</div>
                </div>
                <div class="c61-modal-body">
                    <div class="fnb-modal-text">Form yang sedang dibuka belum disimpan. Simpan dulu sebelum pindah tab, atau buang perubahannya?</div>
                </div>
                <div class="c61-modal-foot">
                    <button type="button" wire:click="cancelTabChange" class="c61-btn c61-btn-ghost">Batal Pindah</button>
                    <button type="button" wire:click="discardAndSwitchTab" class="c61-btn c61-btn-danger">Buang Perubahan</button>
                    <button type="button" wire:click="saveAndSwitchTab" class="c61-btn c61-btn-primary">Simpan &amp; Lanjut</button>
                </div>
            </div>
        </div>
    @endif
</div>
