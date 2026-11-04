<div class="c61 km-page">
    @include('filament.partials.c61-admin-style')
    {{-- Gaya khusus halaman Katalog Merchandise (prefix km-) di atas design system c61. --}}
    <style>
        .km-tabs-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .km-head-text { min-width: 0; flex: 1 1 320px; }
        .km-table { min-width: 900px; }
        .km-table th:first-child, .km-table td:first-child { padding-left: 1.25rem; }
        .km-table th:last-child, .km-table td:last-child { padding-right: 1.25rem; }
        .km-right { text-align: right; white-space: nowrap; }
        .km-center { text-align: center; }
        .km-product { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
        .km-thumb { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; border: 1px solid var(--c-line); background: var(--c-paper); flex-shrink: 0; }
        .km-thumb-empty { display: flex; align-items: center; justify-content: center; font-size: 0.625rem; font-weight: 800; color: var(--c-faint); }
        .km-name { font-weight: 700; font-size: 0.875rem; color: var(--c-brown); }
        .km-sub { font-size: 0.6875rem; color: var(--c-muted); margin-top: 0.15rem; }
        .km-money { font-family: var(--font-mono); font-weight: 800; font-size: 0.875rem; color: var(--c-brown); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .km-money.is-muted { color: var(--c-muted); font-weight: 700; }
        .km-stock { font-family: var(--font-mono); font-weight: 800; color: var(--c-brown); white-space: nowrap; }
        .km-stock.is-low { color: #B45309; }
        .km-stock.is-empty { color: #B42318; }
        .km-variant-chips { display: flex; flex-wrap: wrap; gap: 0.3rem; max-width: 320px; }
        .km-chip { display: inline-flex; align-items: center; gap: 0.25rem; height: 22px; padding: 0 0.5rem; border-radius: 999px; background: var(--c-paper); border: 1px solid var(--c-line); font-size: 0.6875rem; font-weight: 700; color: var(--c-brown); white-space: nowrap; }
        .km-chip.is-off { opacity: 0.5; text-decoration: line-through; }
        .km-chip.is-low { border-color: #FCD34D; background: #FFFBEB; }
        .km-toggle { display: inline-flex; align-items: center; gap: 0.5rem; }
        .km-toggle.is-clickable { cursor: pointer; }
        .km-switch { position: relative; width: 40px; height: 22px; background: #E7E0D3; border-radius: 999px; border: 1px solid #D9CFBE; display: inline-block; flex-shrink: 0; }
        .km-switch.on { background: #16A34A; border-color: #15803D; }
        .km-switch-knob { position: absolute; top: 1px; left: 1px; width: 18px; height: 18px; background: #FFFFFF; border-radius: 50%; box-shadow: 0 1px 3px rgba(42, 20, 16, 0.25); transition: transform 0.2s; }
        .km-switch.on .km-switch-knob { transform: translateX(18px); }
        .km-toggle-text { font-size: 0.6875rem; font-weight: 800; letter-spacing: 0.04em; color: var(--c-faint); }
        .km-toggle-text.is-on { color: #047857; }
        .km-btn-warn { background: #FEF3C7; border-color: #FDE68A; color: #92400E; }
        .km-btn-del { background: #FEF2F2; border-color: #FECACA; color: #B42318; }
        .km-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .km-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem; }
        .km-field { display: flex; flex-direction: column; min-width: 0; }
        .km-field .c61-label { margin-bottom: 0.35rem; }
        .km-err { font-size: 0.71875rem; font-weight: 600; color: #B42318; margin-top: 0.3rem; }
        .km-hint { font-size: 0.6875rem; color: var(--c-muted); margin-top: 0.3rem; line-height: 1.45; }
        .km-check { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; font-weight: 700; color: var(--c-brown); cursor: pointer; }
        .km-check input { accent-color: var(--c-terra); width: 16px; height: 16px; }
        .km-line { display: grid; grid-template-columns: minmax(0, 1fr) 110px 150px 36px; gap: 0.5rem; align-items: start; }
        .km-line + .km-line { margin-top: 0.5rem; }
        .km-line-del { height: 40px; border-radius: 8px; border: 1px solid #FECACA; background: #FEF2F2; color: #B42318; font-weight: 900; cursor: pointer; }
        .km-qty-in { color: #047857; font-weight: 800; font-family: var(--font-mono); }
        .km-qty-out { color: #B42318; font-weight: 800; font-family: var(--font-mono); }
        .km-variant-form { background: var(--c-paper); border: 1px solid var(--c-line); border-radius: 12px; padding: 0.9rem; display: flex; flex-direction: column; gap: 0.75rem; }
        .km-variant-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .km-variant-table th { text-align: left; font-size: 0.65625rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--c-muted); padding: 0.4rem 0.5rem; border-bottom: 1px solid var(--c-line); }
        .km-variant-table td { padding: 0.5rem; border-bottom: 1px solid var(--c-line-soft); vertical-align: middle; }
        @media (max-width: 640px) {
            .km-grid-2, .km-grid-3 { grid-template-columns: 1fr; }
            .km-line { grid-template-columns: 1fr 1fr; }
        }
    </style>

    @php $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp

    {{-- HEADER --}}
    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span><span>Master Data &bull; Merchandise</span></div>
            <div class="c61-hero-title">Katalog Merchandise</div>
            <div class="c61-hero-sub">
                Produk, varian ukuran / warna, harga, HPP, dan stok merchandise yang dijual di Kasir Pro Shop. Terpisah dari Perlengkapan Padel &mdash; merchandise tidak pernah muncul sebagai add-on booking.
            </div>
        </div>
        <div class="c61-hero-actions">
            @if ($activeTab === 'produk' && $this->canManageProducts)
                <button type="button" wire:click="openCreateProductModal" class="c61-btn c61-btn-cream"><span>+ Tambah Produk</span></button>
            @elseif ($activeTab === 'stok' && $this->canManageStock)
                <button type="button" wire:click="openAdjustModal" class="c61-btn c61-btn-outline-cream"><span>Penyesuaian Stok</span></button>
                <button type="button" wire:click="openReceiveModal" class="c61-btn c61-btn-cream"><span>+ Barang Masuk</span></button>
            @endif
        </div>
    </div>

    <div class="km-tabs-row">
        <div class="c61-seg">
            <button type="button" wire:click="setActiveTab('produk')" class="c61-seg-btn {{ $activeTab === 'produk' ? 'is-active' : '' }}"><span>Produk &amp; Varian</span></button>
            <button type="button" wire:click="setActiveTab('stok')" class="c61-seg-btn {{ $activeTab === 'stok' ? 'is-active' : '' }}"><span>Stok &amp; Kartu Stok</span></button>
        </div>
    </div>

    {{-- ================= TAB PRODUK ================= --}}
    @if ($activeTab === 'produk')
        <div class="c61-card">
            <div class="c61-card-head">
                <div class="km-head-text">
                    <div class="c61-card-title">Daftar Produk Merchandise</div>
                    <div class="c61-card-sub">Harga jual varian = harga produk + tambahan harga varian (boleh Rp0, tidak boleh minus).{{ $this->canManageProducts ? ' HPP (modal per unit) dipakai untuk Laporan Merchandise.' : '' }}</div>
                </div>
                <div class="c61-seg">
                    <button type="button" wire:click="setProductFilter('ALL')" class="c61-seg-btn {{ $productFilter === 'ALL' ? 'is-active' : '' }}">Semua</button>
                    <button type="button" wire:click="setProductFilter('ACTIVE')" class="c61-seg-btn {{ $productFilter === 'ACTIVE' ? 'is-active' : '' }}">Aktif</button>
                    <button type="button" wire:click="setProductFilter('INACTIVE')" class="c61-seg-btn {{ $productFilter === 'INACTIVE' ? 'is-active' : '' }}">Nonaktif</button>
                </div>
            </div>
            <div class="c61-table-wrap">
                <table class="c61-table km-table">
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Harga Jual</th>
                            {{-- HPP (modal) hanya untuk pengelola produk — staf yang sekadar melihat stok tidak melihat harga modal. --}}
                            @if ($this->canManageProducts)
                                <th>HPP</th>
                            @endif
                            <th>Varian &amp; Stok</th>
                            <th class="km-center">Status</th>
                            <th class="km-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->products as $product)
                            <tr wire:key="km-product-{{ $product->id }}" data-merch-row="{{ $product->name }}">
                                <td>
                                    <div class="km-product">
                                        @if ($product->imageUrl())
                                            <img src="{{ $product->imageUrl() }}" alt="" class="km-thumb" loading="lazy">
                                        @else
                                            <div class="km-thumb km-thumb-empty">FOTO</div>
                                        @endif
                                        <div style="min-width:0;">
                                            <div class="km-name">{{ $product->name }}</div>
                                            <div class="km-sub">{{ $product->categoryLabel() }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><div class="km-money">{{ $rupiah($product->base_price) }}</div></td>
                                @if ($this->canManageProducts)
                                    <td>
                                        <div class="km-money is-muted">{{ $rupiah($product->hpp) }}</div>
                                        @if ((float) $product->hpp <= 0)
                                            <div class="km-sub" style="color:#B45309;">HPP belum diisi</div>
                                        @endif
                                    </td>
                                @endif
                                <td>
                                    @if ($product->variants->isEmpty())
                                        <span class="km-sub" style="color:#B45309;">Belum ada varian &mdash; belum bisa dijual</span>
                                    @else
                                        <div class="km-variant-chips">
                                            @foreach ($product->variants as $v)
                                                <span class="km-chip {{ $v->is_active ? '' : 'is-off' }} {{ $v->is_active && $v->isLowStock() ? 'is-low' : '' }}" title="SKU {{ $v->sku }}">
                                                    {{ $v->label() }} &middot; {{ $v->stock_quantity }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="km-center">
                                    <div class="km-toggle {{ $this->canManageProducts ? 'is-clickable' : '' }}" @if ($this->canManageProducts) wire:click="toggleProductStatus('{{ $product->id }}')" @endif>
                                        <div class="km-switch {{ $product->is_active ? 'on' : '' }}"><div class="km-switch-knob"></div></div>
                                        <span class="km-toggle-text {{ $product->is_active ? 'is-on' : '' }}">{{ $product->is_active ? 'AKTIF' : 'OFF' }}</span>
                                    </div>
                                </td>
                                <td class="km-right">
                                    @if ($this->canManageProducts)
                                        <div class="c61-actions">
                                            <button type="button" wire:click="openVariantModal('{{ $product->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Varian</button>
                                            <button type="button" wire:click="openEditProductModal('{{ $product->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Edit</button>
                                            <button type="button" class="c61-btn c61-btn-sm km-btn-del"
                                                x-on:click="$dispatch('club61-confirm', {
                                                    title: @js('Hapus '.$product->name.'?'),
                                                    message: 'Produk yang belum pernah terjual dan belum punya mutasi stok dihapus permanen. Yang sudah punya riwayat hanya dinonaktifkan.',
                                                    confirmLabel: 'Ya, Lanjutkan',
                                                    tone: 'danger',
                                                    onConfirm: () => $wire.deleteProduct(@js($product->id)),
                                                })">Hapus</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $this->canManageProducts ? 6 : 5 }}" class="c61-empty">Belum ada produk merchandise.{{ $this->canManageProducts ? ' Klik "+ Tambah Produk".' : '' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= TAB STOK ================= --}}
    @if ($activeTab === 'stok')
        <div class="c61-card">
            <div class="c61-card-head">
                <div class="km-head-text">
                    <div class="c61-card-title">Stok Saat Ini</div>
                    <div class="c61-card-sub">Stok bertambah lewat Barang Masuk, berkurang saat terjual di Kasir Pro Shop. Yang habis / di bawah stok minimum tampil paling atas.</div>
                </div>
            </div>
            <div class="c61-table-wrap">
                <table class="c61-table km-table" style="min-width:720px;">
                    <thead>
                        <tr>
                            <th>Produk &middot; Varian</th>
                            <th>SKU</th>
                            <th>Stok</th>
                            <th>Stok Minimum</th>
                            <th class="km-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->stockRows as $v)
                            <tr wire:key="km-stock-{{ $v->id }}" data-stock-row="{{ $v->sku }}">
                                <td>
                                    <div class="km-name">{{ $v->product?->name ?? '-' }} &middot; {{ $v->label() }}</div>
                                    @if (! $v->is_active || ! $v->product?->is_active)
                                        <div class="km-sub">Nonaktif</div>
                                    @endif
                                </td>
                                <td><span class="c61-mono" style="font-size:0.75rem;">{{ $v->sku }}</span></td>
                                <td>
                                    <span class="km-stock {{ $v->stock_quantity <= 0 ? 'is-empty' : ($v->isLowStock() ? 'is-low' : '') }}">{{ $v->stock_quantity }}</span>
                                    @if ($v->stock_quantity <= 0)
                                        <span class="c61-pill c61-pill-danger" style="margin-left:0.35rem;">Habis</span>
                                    @elseif ($v->isLowStock())
                                        <span class="c61-pill c61-pill-warn" style="margin-left:0.35rem;">Menipis</span>
                                    @endif
                                </td>
                                <td><span class="km-sub">{{ $v->min_stock ?? '-' }}</span></td>
                                <td class="km-right">
                                    <div class="c61-actions">
                                        <button type="button" wire:click="$set('movementVariantFilter', '{{ $v->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Kartu Stok</button>
                                        @if ($this->canManageStock)
                                            <button type="button" wire:click="openAdjustModal('{{ $v->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Sesuaikan</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="c61-empty">Belum ada varian merchandise.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="c61-card">
            <div class="c61-card-head">
                <div class="km-head-text">
                    <div class="c61-card-title">Kartu Stok</div>
                    <div class="c61-card-sub">100 mutasi terakhir. Kartu stok tidak bisa diedit / dihapus &mdash; salah input dibetulkan lewat Penyesuaian Stok (alasan "Salah input").</div>
                </div>
                <div style="min-width:260px; max-width:420px; flex:1;">
                    <select wire:model.live="movementVariantFilter" class="c61-select" aria-label="Filter varian kartu stok">
                        <option value="">Semua varian</option>
                        @foreach ($this->variantOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="c61-table-wrap">
                <table class="c61-table km-table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Produk &middot; Varian</th>
                            <th>Jenis</th>
                            <th class="km-right">Perubahan</th>
                            <th class="km-right">Stok Setelah</th>
                            <th>Keterangan</th>
                            <th>Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->movements as $m)
                            <tr wire:key="km-move-{{ $m->id }}">
                                <td style="white-space:nowrap;">{{ $m->created_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
                                <td><div class="km-name">{{ $m->product_name }} &middot; {{ $m->variant_label }}</div></td>
                                <td><span class="c61-pill {{ $m->qty_change > 0 ? 'c61-pill-ok' : 'c61-pill-gray' }}">{{ \App\Models\Merch\MerchStockMovement::TYPES[$m->type] ?? $m->type }}</span></td>
                                <td class="km-right"><span class="{{ $m->qty_change > 0 ? 'km-qty-in' : 'km-qty-out' }}">{{ sprintf('%+d', $m->qty_change) }}</span></td>
                                <td class="km-right"><span class="km-stock">{{ $m->stock_after }}</span></td>
                                <td>
                                    <div class="km-sub" style="margin-top:0;">
                                        @if ($m->order) Order {{ $m->order->order_number }} @endif
                                        @if ($m->reference_number) {{ $m->reference_number }}@if ($m->supplier) &middot; {{ $m->supplier }}@endif @if ($m->invoice_number) &middot; Nota {{ $m->invoice_number }}@endif @endif
                                        @if ($m->reason) {{ \App\Models\Merch\MerchStockMovement::REASONS[$m->reason] ?? $m->reason }} @endif
                                        @if ($m->notes) &mdash; {{ $m->notes }} @endif
                                    </div>
                                </td>
                                <td><span class="km-sub" style="margin-top:0;">{{ $m->creator?->name ?? 'Sistem' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="c61-empty">Belum ada mutasi stok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= MODAL PRODUK ================= --}}
    @if ($showProductModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width:620px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top:0;">{{ $editingProductId ? 'Edit Produk Merchandise' : 'Tambah Produk Merchandise' }}</div>
                    <button type="button" wire:click="closeProductModal" class="c61-modal-close">&times;</button>
                </div>
                <div class="c61-modal-body" style="display:flex; flex-direction:column; gap:1rem;">
                    <div class="km-field">
                        <label class="c61-label">Nama Produk</label>
                        <input type="text" wire:model="productName" class="c61-input" placeholder="Contoh: Kaos Club 61 Classic" maxlength="100">
                        @error('productName') <span class="km-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="km-grid-2">
                        <div class="km-field">
                            <label class="c61-label">Kategori</label>
                            <select wire:model="productCategory" class="c61-select">
                                @foreach (\App\Models\Merch\MerchProduct::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('productCategory') <span class="km-err">{{ $message }}</span> @enderror
                        </div>
                        <div class="km-field">
                            <label class="c61-label">Status di Kasir</label>
                            <label class="km-check" style="height:40px;">
                                <input type="checkbox" wire:model="productIsActive"> Aktif (muncul di Kasir Pro Shop)
                            </label>
                        </div>
                    </div>
                    <div class="km-grid-2">
                        <div class="km-field">
                            <label class="c61-label">Harga Jual (Rp)</label>
                            <input type="number" step="1000" min="0" wire:model="productPrice" class="c61-input" placeholder="150000">
                            @error('productPrice') <span class="km-err">{{ $message }}</span> @enderror
                        </div>
                        <div class="km-field">
                            <label class="c61-label">HPP / Modal per Unit (Rp)</label>
                            <input type="number" step="1000" min="0" wire:model="productHpp" class="c61-input" placeholder="80000">
                            <span class="km-hint">Berlaku untuk semua varian. Penjualan lama tidak berubah saat HPP diganti.</span>
                            @error('productHpp') <span class="km-err">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="km-field">
                        <label class="c61-label">Deskripsi (opsional)</label>
                        <textarea wire:model="productDescription" class="c61-textarea" rows="2" maxlength="1000"></textarea>
                        @error('productDescription') <span class="km-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="km-field">
                        <label class="c61-label">Foto Produk (opsional)</label>
                        <input type="file" wire:model="productPhotoUpload" accept="image/jpeg,image/png,image/webp" class="c61-input" style="padding-top:0.45rem;">
                        <span class="km-hint">JPG / PNG / WEBP, maksimal 2 MB.</span>
                        @error('productPhotoUpload') <span class="km-err">{{ $message }}</span> @enderror
                        @if ($editingProductId && ! $productPhotoUpload)
                            <label class="km-check" style="margin-top:0.4rem;"><input type="checkbox" wire:model="removeProductPhoto"> Hapus foto saat ini</label>
                        @endif
                    </div>
                    @unless ($editingProductId)
                        <div class="km-field">
                            <label class="km-check"><input type="checkbox" wire:model="productHasVariants"> Produk ini punya pilihan ukuran / warna</label>
                            <span class="km-hint">Tidak dicentang = satu varian "Standar". Dicentang = tambahkan varian lewat tombol Varian setelah produk disimpan.</span>
                        </div>
                    @endunless
                </div>
                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeProductModal" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="saveProduct" wire:loading.attr="disabled" wire:target="saveProduct,productPhotoUpload" class="c61-btn c61-btn-primary">Simpan Produk</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL VARIAN ================= --}}
    @if ($variantProductId && ($vp = $this->variantProduct))
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width:760px;">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-title" style="margin-top:0;">Varian {{ $vp->name }}</div>
                    </div>
                    <button type="button" wire:click="closeVariantModal" class="c61-modal-close">&times;</button>
                </div>
                <div class="c61-modal-body" style="display:flex; flex-direction:column; gap:1rem;">
                    @if ($vp->variants->isEmpty())
                        <div class="c61-note c61-note-warn">Belum ada varian &mdash; produk belum bisa dijual. Tambahkan minimal satu varian di bawah.</div>
                    @else
                        <div class="c61-table-wrap">
                            <table class="km-variant-table">
                                <thead>
                                    <tr><th>Varian</th><th>SKU</th><th>Harga</th><th>Stok</th><th>Status</th><th class="km-right">Aksi</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($vp->variants as $v)
                                        <tr wire:key="km-variant-{{ $v->id }}">
                                            <td><span class="km-name">{{ $v->label() }}</span></td>
                                            <td><span class="c61-mono" style="font-size:0.75rem;">{{ $v->sku }}</span></td>
                                            <td><span class="km-money">{{ $rupiah((float) $vp->base_price + (float) $v->additional_price) }}</span></td>
                                            <td><span class="km-stock">{{ $v->stock_quantity }}</span></td>
                                            <td>
                                                <button type="button" wire:click="toggleVariantStatus('{{ $v->id }}')" class="c61-pill {{ $v->is_active ? 'c61-pill-ok' : 'c61-pill-gray' }}" style="border:none; cursor:pointer;">{{ $v->is_active ? 'Aktif' : 'Nonaktif' }}</button>
                                            </td>
                                            <td class="km-right">
                                                <div class="c61-actions">
                                                    <button type="button" wire:click="editVariant('{{ $v->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Edit</button>
                                                    <button type="button" class="c61-btn c61-btn-sm km-btn-del"
                                                        x-on:click="$dispatch('club61-confirm', {
                                                            title: @js('Hapus varian '.$v->label().'?'),
                                                            message: 'Varian yang belum punya transaksi / mutasi stok dihapus permanen. Yang sudah punya riwayat hanya dinonaktifkan.',
                                                            confirmLabel: 'Ya, Lanjutkan',
                                                            tone: 'danger',
                                                            onConfirm: () => $wire.deleteVariant(@js($v->id)),
                                                        })">Hapus</button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="km-variant-form">
                        <div class="c61-label" style="margin:0;">{{ $editingVariantId ? 'Edit Varian' : 'Tambah Varian' }}</div>
                        <div class="km-grid-3">
                            <div class="km-field">
                                <label class="c61-label">Warna</label>
                                <input type="text" wire:model="variantColor" class="c61-input" placeholder="Hitam" maxlength="30">
                                @error('variantColor') <span class="km-err">{{ $message }}</span> @enderror
                            </div>
                            <div class="km-field">
                                <label class="c61-label">Ukuran</label>
                                <input type="text" wire:model="variantSize" class="c61-input" placeholder="L" maxlength="10">
                                @error('variantSize') <span class="km-err">{{ $message }}</span> @enderror
                            </div>
                            <div class="km-field">
                                <label class="c61-label">SKU (opsional)</label>
                                <input type="text" wire:model="variantSku" class="c61-input" placeholder="Otomatis bila kosong" maxlength="50">
                                @error('variantSku') <span class="km-err">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="km-grid-2">
                            <div class="km-field">
                                <label class="c61-label">Tambahan Harga (Rp)</label>
                                <input type="number" step="1000" wire:model="variantAdditionalPrice" class="c61-input" placeholder="0">
                                <span class="km-hint">Mis. ukuran XXL +Rp 10.000. Isi 0 bila sama dengan harga produk.</span>
                                @error('variantAdditionalPrice') <span class="km-err">{{ $message }}</span> @enderror
                            </div>
                            <div class="km-field">
                                <label class="c61-label">Stok Minimum (opsional)</label>
                                <input type="number" step="1" min="0" wire:model="variantMinStock" class="c61-input" placeholder="3">
                                <span class="km-hint">Peringatan "Menipis" bila stok di bawah / sama dengan angka ini.</span>
                                @error('variantMinStock') <span class="km-err">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="c61-actions" style="justify-content:flex-end;">
                            @if ($editingVariantId)
                                <button type="button" wire:click="cancelVariantEdit" class="c61-btn c61-btn-ghost c61-btn-sm">Batal Edit</button>
                            @endif
                            <button type="button" wire:click="saveVariant" class="c61-btn c61-btn-primary c61-btn-sm">{{ $editingVariantId ? 'Simpan Varian' : '+ Tambah Varian' }}</button>
                        </div>
                        @unless ($editingVariantId)
                            <span class="km-hint" style="margin-top:0;">Varian baru mulai dari stok 0 &mdash; catat stoknya lewat Barang Masuk.</span>
                        @endunless
                    </div>
                </div>
                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeVariantModal" class="c61-btn c61-btn-primary">Selesai</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL BARANG MASUK ================= --}}
    @if ($showReceiveModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width:760px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top:0;">Barang Masuk Merchandise</div>
                    <button type="button" wire:click="closeReceiveModal" class="c61-modal-close">&times;</button>
                </div>
                <div class="c61-modal-body" style="display:flex; flex-direction:column; gap:1rem;">
                    <div class="km-grid-2">
                        <div class="km-field">
                            <label class="c61-label">Supplier (opsional)</label>
                            <input type="text" wire:model="receiveSupplier" class="c61-input" maxlength="100">
                        </div>
                        <div class="km-field">
                            <label class="c61-label">No. Nota / Faktur (opsional)</label>
                            <input type="text" wire:model="receiveInvoice" class="c61-input" maxlength="60">
                        </div>
                    </div>
                    <div>
                        <div class="km-line" style="margin-bottom:0.35rem;">
                            <span class="c61-label" style="margin:0;">Varian</span>
                            <span class="c61-label" style="margin:0;">Jumlah</span>
                            <span class="c61-label" style="margin:0;">Total Harga Beli (Rp)</span>
                            <span></span>
                        </div>
                        @foreach ($receiveLines as $i => $line)
                            <div class="km-line" wire:key="km-receive-{{ $i }}">
                                <select wire:model="receiveLines.{{ $i }}.variant_id" class="c61-select" aria-label="Varian baris {{ $i + 1 }}">
                                    <option value="">Pilih varian...</option>
                                    @foreach ($this->variantOptions as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="number" min="1" step="1" wire:model="receiveLines.{{ $i }}.qty" class="c61-input" placeholder="0" aria-label="Jumlah baris {{ $i + 1 }}">
                                <input type="number" min="0" step="1000" wire:model="receiveLines.{{ $i }}.total" class="c61-input" placeholder="Opsional" aria-label="Total harga beli baris {{ $i + 1 }}">
                                <button type="button" wire:click="removeReceiveLine({{ $i }})" class="km-line-del" aria-label="Hapus baris {{ $i + 1 }}">&times;</button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addReceiveLine" class="c61-btn c61-btn-ghost c61-btn-sm" style="margin-top:0.6rem;">+ Tambah Baris</button>
                    </div>
                    <div class="km-field">
                        <label class="c61-label">Catatan (opsional)</label>
                        <input type="text" wire:model="receiveNotes" class="c61-input" maxlength="500">
                    </div>
                </div>
                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeReceiveModal" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="saveReceive" wire:loading.attr="disabled" wire:target="saveReceive" class="c61-btn c61-btn-primary">Simpan Barang Masuk</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL PENYESUAIAN ================= --}}
    @if ($showAdjustModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width:560px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top:0;">Penyesuaian Stok</div>
                    <button type="button" wire:click="closeAdjustModal" class="c61-modal-close">&times;</button>
                </div>
                <div class="c61-modal-body" style="display:flex; flex-direction:column; gap:1rem;">
                    <div class="km-field">
                        <label class="c61-label">Varian</label>
                        <select wire:model="adjustVariantId" class="c61-select">
                            <option value="">Pilih varian...</option>
                            @foreach ($this->variantOptions as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="km-grid-2">
                        <div class="km-field">
                            <label class="c61-label">Arah</label>
                            <select wire:model="adjustDirection" class="c61-select">
                                <option value="OUT">Kurangi stok</option>
                                <option value="IN">Tambah stok</option>
                            </select>
                        </div>
                        <div class="km-field">
                            <label class="c61-label">Jumlah</label>
                            <input type="number" min="1" step="1" wire:model="adjustQty" class="c61-input" placeholder="1">
                        </div>
                    </div>
                    <div class="km-field">
                        <label class="c61-label">Alasan</label>
                        <select wire:model="adjustReason" class="c61-select">
                            @foreach (\App\Models\Merch\MerchStockMovement::REASONS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="km-field">
                        <label class="c61-label">Keterangan</label>
                        <input type="text" wire:model="adjustNotes" class="c61-input" maxlength="500" placeholder="Wajib, minimal 5 karakter">
                        <span class="km-hint">Barang baru dari supplier dicatat lewat Barang Masuk, bukan Penyesuaian.</span>
                    </div>
                </div>
                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeAdjustModal" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="saveAdjust" wire:loading.attr="disabled" wire:target="saveAdjust" class="c61-btn c61-btn-primary">Simpan Penyesuaian</button>
                </div>
            </div>
        </div>
    @endif
</div>
