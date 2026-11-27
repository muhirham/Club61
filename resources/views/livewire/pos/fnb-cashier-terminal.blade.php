<div class="flex-1 flex flex-col overflow-hidden">
    <style>
        .fnbpos-pill { padding: 0.55rem 1rem; border-radius: 12px; font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; cursor: pointer; transition: all 0.15s ease; border: 1.5px solid #E6DAC0; background: rgba(255,255,255,0.9); color: #4F2F2A; }
        .fnbpos-pill.active { background: #662721; border-color: #662721; color: #F7F0DB; box-shadow: 0 4px 12px rgba(102,39,33,0.22); }
        /* Kartu persegi (tinggi = lebar) di semua ukuran layar. Urutan: nama menu (atas) → foto → harga → deskripsi.
           Foto mengisi sisa ruang (flex), harga + deskripsi tingginya tetap, jadi harga sejajar di satu baris kartu
           walau nama menunya 1 atau 2 baris. Seluruh kartu bisa diketuk untuk menambah ke keranjang. */
        .fnbpos-card { aspect-ratio: 1 / 1; min-height: 0; padding: 0.75rem; border-radius: 16px; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 8px 20px -8px rgba(79,47,42,0.10); border: 1.5px solid #E6DAC0; background: #FFFFFF; display: flex; flex-direction: column; overflow: hidden; }
        .fnbpos-card:hover { transform: scale(1.02); }
        .fnbpos-card-name { font-size: 0.9375rem; font-weight: 800; line-height: 1.25; color: #4F2F2A; margin-bottom: 0.5rem; flex-shrink: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
        .fnbpos-card-price-row { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; flex-shrink: 0; }
        .fnbpos-card-price { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 0.875rem; font-weight: 800; color: #662721; white-space: nowrap; }
        .fnbpos-card-desc { font-size: 0.6875rem; line-height: 1.3; color: #7A5A52; margin-top: 0.2rem; min-height: 2.6em; flex-shrink: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
        .fnbpos-card-photo { position: relative; width: 100%; flex: 1 1 0; min-height: 3.5rem; border-radius: 10px; overflow: hidden; background: #F7F0DB; display: flex; align-items: center; justify-content: center; }
        /* Foto apa pun ukurannya / orientasinya (portrait, landscape, kecil, besar) dipasang UTUH di dalam bingkai yang
           ukurannya sama di semua kartu: gambar diposisikan absolut supaya ukuran aslinya tidak ikut membesarkan bingkai
           (dulu foto portrait mendorong nama & deskripsi menu ke bawah sampai terpotong), contain = tidak ada yang terpotong. */
        .fnbpos-card-photo.has-photo { background: #FFFFFF; border: 1px solid #EFE6D2; }
        .fnbpos-card-photo img { position: absolute; inset: 0; width: 100% !important; height: 100% !important; max-width: none !important; object-fit: contain; display: block; }
        .fnbpos-card-photo .fnbpos-photo-initial { font-family: 'Cheltenham Classic', 'Source Serif 4', Georgia, serif; font-size: 2rem; font-weight: 900; color: #662721; opacity: 0.55; }
        .fnbpos-page-btn { padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.75rem; font-weight: 800; border: 1.5px solid #E6DAC0; background: rgba(255,255,255,0.95); color: #4F2F2A; cursor: pointer; }
        .fnbpos-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .fnbpos-cart-item { padding: 0.75rem; border-radius: 12px; background: rgba(255,255,255,0.95); border: 1.5px solid #E6DAC0; }
        .fnbpos-note-input { width: 100%; margin-top: 0.5rem; padding: 0.4rem 0.6rem; border-radius: 8px; border: 1px solid #E6DAC0; background: #FCF8EE; font-size: 0.6875rem; color: #4F2F2A; outline: none; }
        .fnbpos-note-input:focus { border-color: #662721; box-shadow: 0 0 0 2px rgba(102,39,33,0.15); }
        .fnbpos-qty-btn { width: 32px; height: 32px; border-radius: 8px; font-size: 1rem; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; cursor: pointer; user-select: none; background: #F7F0DB; border: 1px solid #E6DAC0; color: #7A5A52; }
        .fnbpos-pay-btn { padding: 0.85rem; border-radius: 14px; font-weight: 800; font-size: 0.8125rem; text-align: center; cursor: pointer; border: 1.5px solid #E6DAC0; background: rgba(255,255,255,0.95); color: #4F2F2A; }
        .fnbpos-pay-btn.active { background: #662721; border-color: #662721; color: #F7F0DB; }
        /* Tombol tipe pesanan (Dine-In / Bawa Pulang) — satu ketukan di tablet. */
        .fnbpos-type-btn { padding: 0.6rem 0.5rem; border-radius: 9px; font-size: 0.75rem; font-weight: 800; white-space: nowrap; cursor: pointer; border: 1.5px solid #E6DAC0; background: #FCF8EE; color: #4F2F2A; transition: all 0.15s ease; }
        .fnbpos-type-btn.active { background: #662721; border-color: #662721; color: #F7F0DB; }
        .fnbpos-input { width: 100%; padding: 0.65rem 0.85rem; border-radius: 9px; border: 1.5px solid #E6DAC0; font-size: 0.8125rem; background: #FCF8EE; outline: none; }
        .fnbpos-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.65); backdrop-filter: blur(6px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .fnbpos-modal-dialog { background: #FFFFFF; border: 2px solid #662721; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35); width: 100%; max-width: 480px; padding: 1.75rem; display: flex; flex-direction: column; gap: 1rem; max-height: 90vh; overflow-y: auto; }
        /* Popup struk: struk di kiri, judul + status slip + tombol di kanan — muat satu layar (laptop 14"/15", tablet) tanpa scroll. */
        .fnbpos-receipt-backdrop { padding: 1rem; }
        .fnbpos-receipt-dialog { max-width: 780px; max-height: calc(100dvh - 2rem); padding: 1.25rem; display: grid; grid-template-columns: minmax(0, 360px) minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); grid-template-areas: 'paper head' 'paper side'; gap: 0.85rem 1.25rem; overflow: hidden; }
        .fnbpos-receipt-head { grid-area: head; display: flex; justify-content: space-between; align-items: flex-start; }
        .fnbpos-receipt-paper { grid-area: paper; min-height: 0; overflow-y: auto; scrollbar-width: thin; }
        .fnbpos-receipt-side { grid-area: side; min-height: 0; display: flex; flex-direction: column; gap: 0.6rem; }
        .fnbpos-receipt-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        .fnbpos-receipt-summary > div { background: #FFFDF8; border: 1px solid #E6DAC0; border-radius: 12px; padding: 0.55rem 0.75rem; min-width: 0; }
        .fnbpos-receipt-summary span, .fnbpos-receipt-label { display: block; font-size: 0.625rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #7A5A52; }
        .fnbpos-receipt-summary strong { display: block; margin-top: 0.15rem; font-size: 0.875rem; font-weight: 800; color: #4F2F2A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fnbpos-receipt-summary strong.is-big { font-size: 1.375rem; font-weight: 900; color: #662721; line-height: 1.2; }
        .fnbpos-receipt-actions { margin-top: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
        .fnbpos-receipt-actions .fnbpos-pay-btn { padding: 0.8rem 0.6rem; }
        /* Jarak baris struk di layar dirapatkan (ukuran huruf tetap — hasil cetak tidak berubah). */
        .fnbpos-receipt-paper #fnbpos-receipt { padding: 0.9rem 1rem; }
        .fnbpos-receipt-paper #fnbpos-receipt > :not([hidden]) ~ :not([hidden]) { margin-top: 0.3rem; }
        .fnbpos-receipt-paper #fnbpos-receipt > .border-dashed { margin-bottom: 0.3rem; }
        .fnbpos-receipt-paper #fnbpos-receipt .space-y-1 > :not([hidden]) ~ :not([hidden]) { margin-top: 0.15rem; }
        @media (max-width: 719px) {
            .fnbpos-receipt-dialog { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto auto auto; grid-template-areas: 'head' 'paper' 'side'; overflow-y: auto; }
            .fnbpos-receipt-paper { overflow: visible; }
        }
        .fnbpos-tab-bar { display: flex; gap: 0.5rem; padding: 0.85rem 1rem; border-bottom: 1.5px solid #E6DAC0; background: rgba(255,255,255,0.9); flex-shrink: 0; }
        .fnbpos-status-badge { display: inline-block; font-size: 0.625rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.15rem 0.55rem; border-radius: 999px; white-space: nowrap; }
        .fnbpos-status-paid { background: #ECFDF5; color: #15803D; border: 1px solid #6EE7B7; }
        .fnbpos-status-unpaid { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
        .fnbpos-status-refunded, .fnbpos-status-cancelled { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
        .fnbpos-history-table th { text-align: left; padding: 0.6rem 0.75rem; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.04em; color: #4F2F2A; background: #F7F0DB; white-space: nowrap; }
        /* Riwayat: satu baris per sel (tidak turun ke bawah); teks panjang dipotong "…" — lengkapnya di tooltip. */
        .fnbpos-history-table td { padding: 0.6rem 0.75rem; font-size: 0.75rem; border-top: 1px solid #EFE6D2; vertical-align: middle; white-space: nowrap; }
        .fnbpos-history-table td.fnbpos-cell-clip { max-width: 9rem; overflow: hidden; text-overflow: ellipsis; }
    </style>
    @include('pos.partials.receipt-print-style', ['selectors' => ['#fnbpos-receipt', '#printable-z-report', '#printable-sales-recap']])

    @if($errorMessage)
        <div class="fixed top-4 right-4 z-[10000] max-w-sm p-4 rounded-xl shadow-2xl" style="background: #FEE2E2; border: 1.5px solid #FCA5A5; color: #991B1B;">
            <div class="flex justify-between items-start gap-3">
                <span class="text-xs font-semibold">{{ $errorMessage }}</span>
                <button wire:click="$set('errorMessage', null)" class="font-bold leading-none">&times;</button>
            </div>
        </div>
    @endif

    @if(in_array($posStep, ['selection', 'history'], true))
        <div class="fnbpos-tab-bar">
            <button type="button" wire:click="$set('posStep', 'selection')" class="fnbpos-pill {{ $posStep === 'selection' ? 'active' : '' }}">Kasir</button>
            @if($this->canShowFnbHistoryTab)
                <button type="button" wire:click="$set('posStep', 'history')" class="fnbpos-pill {{ $posStep === 'history' ? 'active' : '' }}">Riwayat Transaksi</button>
            @endif
        </div>
    @endif

    <div class="flex-1 flex overflow-hidden">
    @if($posStep === 'selection')
        {{-- ================= STEP 1: PILIH MENU ================= --}}
        <section class="flex-1 flex flex-col border-r border-[#E6DAC0] overflow-hidden" style="background: rgba(255,255,255,0.72); backdrop-filter: blur(12px);">
            <div class="p-4 border-b border-[#E6DAC0]/60 flex flex-wrap items-center justify-between gap-3 bg-white/80">
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 max-w-full">
                    <button type="button" wire:click="setActiveCategory('ALL')" class="fnbpos-pill {{ $activeCategoryId === 'ALL' ? 'active' : '' }}">Semua Menu</button>
                    @foreach($this->categories as $category)
                        <button type="button" wire:key="cat-pill-{{ $category->id }}" wire:click="setActiveCategory('{{ $category->id }}')" class="fnbpos-pill {{ $activeCategoryId === $category->id ? 'active' : '' }}">{{ $category->name }}</button>
                    @endforeach
                </div>

                <div class="w-full sm:w-64 relative">
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Cari menu..." class="fnbpos-input">
                </div>
            </div>

            @php $menus = $this->menus; @endphp
            <div class="fnbpos-grid flex-1 overflow-y-auto p-4 grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3.5 content-start">
                @forelse($menus as $menu)
                    <div wire:click="addToCart('{{ $menu->id }}')" wire:key="menu-{{ $menu->id }}" class="fnbpos-card">
                        {{-- Nama menu di atas (kategori sudah terlihat di tombol filter; stasiun tidak perlu di kartu). --}}
                        <div class="fnbpos-card-name" title="{{ $menu->name }}">{{ $menu->name }}</div>

                        {{-- Kotak foto: menu tanpa foto tetap dapat kotak placeholder supaya grid tetap rapi & sejajar. --}}
                        <div class="fnbpos-card-photo {{ $menu->image_url ? 'has-photo' : '' }}">
                            @if($menu->image_url)
                                <img src="{{ '/storage/'.$menu->image_url }}" alt="{{ $menu->name }}" loading="lazy">
                            @else
                                <span class="fnbpos-photo-initial">{{ mb_strtoupper(mb_substr($menu->name, 0, 1)) }}</span>
                            @endif
                        </div>

                        <div class="fnbpos-card-price-row">
                            <span class="fnbpos-card-price">Rp {{ number_format($menu->base_price, 0, ',', '.') }}</span>
                            {{-- Modul 29: info stok saja, penjualan tidak pernah diblok (stok boleh minus). --}}
                            @if (in_array($menu->stockStatus(), ['LOW', 'EMPTY', 'MINUS'], true))
                                <span data-stock-badge style="padding: 1px 6px; border-radius: 6px; font-size: 10px; font-weight: 800; white-space: nowrap; {{ $menu->stock_qty > 0 ? 'background:#FEF3C7; color:#92400E;' : 'background:#FEE2E2; color:#B42318;' }}">
                                    {{ $menu->stock_qty < 0 ? 'Stok minus ('.$menu->stock_qty.')' : 'Sisa '.$menu->stock_qty }}
                                </span>
                            @endif
                        </div>
                        {{-- Tinggi 2 baris tetap disediakan walau tanpa deskripsi, supaya harga sejajar antar kartu. --}}
                        <div class="fnbpos-card-desc">{{ $menu->description }}</div>
                    </div>
                @empty
                    <div class="col-span-full text-center py-16 text-[#6B7280] text-sm">Belum ada menu tersedia. Isi lewat panel admin &quot;Menu F&amp;B&quot;.</div>
                @endforelse
            </div>

            @if($menus->hasPages())
                <div class="px-4 py-3 border-t border-[#E6DAC0]/60 bg-white/80 flex items-center justify-between">
                    <button type="button" wire:click="previousPage" @disabled($menus->onFirstPage()) class="fnbpos-page-btn">&larr; Sebelumnya</button>
                    <span class="text-xs font-bold text-[#7A5A52]">Halaman {{ $menus->currentPage() }} / {{ $menus->lastPage() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $menus->hasMorePages()) class="fnbpos-page-btn">Berikutnya &rarr;</button>
                </div>
            @endif
        </section>

        <aside class="w-full sm:w-72 lg:w-80 xl:w-96 flex flex-col justify-between shrink-0 shadow-2xl border-l border-[#E6DAC0]" style="background: rgba(255,255,255,0.94); backdrop-filter: blur(20px);">
            <div class="p-4 border-b border-[#E6DAC0]/60 bg-white/80">
                <div class="flex items-center justify-between mb-2.5">
                    <span class="text-xs font-bold uppercase tracking-wider text-[#7A5A52]">Pesanan Baru</span>
                    @if($this->activeShift)
                        <span class="font-mono text-[10px] font-bold text-[#662721] bg-[#F7F0DB] px-2 py-0.5 rounded-md border border-[#E6DAC0]">{{ $this->activeShift->shift_number }}</span>
                    @else
                        <button type="button" wire:click="openShiftModal" class="font-mono text-[10px] font-bold text-[#F7F0DB] bg-[#662721] px-2 py-0.5 rounded-md border border-[#662721]">Buka Shift</button>
                    @endif
                </div>

                {{-- Pengingat: shift lupa ditutup / EDC belum di-settle menjelang 22.00. --}}
                @include('pos.partials.shift-warnings')

                {{-- Tipe pesanan: satu ketukan (bukan dropdown). Bawa Pulang tidak punya meja — kolom meja disembunyikan. --}}
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <button type="button" wire:click="setOrderType('DINE_IN')" class="fnbpos-type-btn {{ $orderType === 'DINE_IN' ? 'active' : '' }}">Dine-In</button>
                    <button type="button" wire:click="setOrderType('TAKE_AWAY')" class="fnbpos-type-btn {{ $orderType === 'TAKE_AWAY' ? 'active' : '' }}">Take Away</button>
                </div>
                <div class="flex gap-2">
                    @if($orderType === 'DINE_IN')
                        <input type="text" wire:model="tableNumber" placeholder="No. Meja" class="fnbpos-input" style="width: 6.5rem; flex-shrink: 0;">
                    @endif
                    <input type="text" wire:model="customerName" placeholder="{{ $orderType === 'TAKE_AWAY' ? 'Nama Pelanggan (untuk dipanggil)' : 'Nama Pelanggan' }}" class="fnbpos-input min-w-0">
                </div>

                <div class="flex items-center justify-end mt-2">
                    @if($this->activeShift)
                        <button type="button" wire:click="prepareCloseShift" class="text-[10px] font-bold text-[#662721] underline">Tutup Shift F&amp;B</button>
                    @endif
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-2.5">
                @forelse($cart as $menuId => $item)
                    <div class="fnbpos-cart-item" wire:key="cart-{{ $menuId }}">
                        <div class="flex items-center justify-between">
                            <div class="flex-1 pr-2">
                                <div class="text-xs font-bold text-[#4F2F2A]">{{ $item['name'] }}</div>
                                <div class="text-[10px] text-[#7A5A52] font-mono font-medium">Rp {{ number_format($item['price'], 0, ',', '.') }} x {{ $item['quantity'] }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span wire:click="decrementCartItem('{{ $menuId }}')" class="fnbpos-qty-btn">-</span>
                                <span class="text-sm font-bold w-6 text-center">{{ $item['quantity'] }}</span>
                                <span wire:click="incrementCartItem('{{ $menuId }}')" class="fnbpos-qty-btn">+</span>
                            </div>
                        </div>
                        {{-- Catatan untuk bar / dapur — ikut tercetak di slip pesanan stasiunnya. --}}
                        <input type="text" wire:model.blur="cart.{{ $menuId }}.notes" maxlength="120" placeholder="Catatan (mis. less sugar, tanpa es)" class="fnbpos-note-input">
                    </div>
                @empty
                    <div class="text-center py-10 text-xs text-[#9CA3AF]">Keranjang kosong. Klik menu di sebelah kiri.</div>
                @endforelse
            </div>

            <div class="p-4 border-t border-[#E6DAC0]/60 bg-white/90 space-y-3">
                <div class="space-y-1.5 text-xs text-[#7A5A52] font-mono font-medium">
                    <div class="flex justify-between"><span>Subtotal:</span><span class="font-bold text-[#4F2F2A]">Rp {{ number_format($this->finance['subtotal'], 0, ',', '.') }}</span></div>
                    @if($this->finance['tax_enabled'])
                        <div class="flex justify-between text-[11px] text-[#7A5A52]"><span>{{ $this->finance['tax_name'] }} ({{ $this->finance['tax_rate'] }}%):</span><span class="font-bold">Rp {{ number_format($this->finance['tax_amount'], 0, ',', '.') }}</span></div>
                    @endif
                    <div class="flex justify-between text-base font-extrabold text-[#4F2F2A] pt-2 border-t border-[#E6DAC0]/50">
                        <span class="font-display">Total Tagihan:</span>
                        <span class="font-mono font-black text-[#662721] text-lg">Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</span>
                    </div>
                </div>

                <button type="button" wire:click="proceedToPayment" class="w-full fnbpos-pay-btn active">Lanjut ke Pembayaran</button>
            </div>
        </aside>
    @endif

    @if($posStep === 'payment')
        {{-- ================= STEP 2: PEMBAYARAN (layout & form identik POS Walk-In Booking) ================= --}}
        <section class="flex-1 flex flex-col overflow-hidden p-4" style="background: rgba(255,255,255,0.72);">
            <div class="flex-1 flex flex-col rounded-2xl overflow-hidden" style="background:#FFFFFF; border:1.5px solid #E6DAC0; box-shadow:0 10px 30px -10px rgba(79,47,42,0.12);">
                <div class="flex items-center justify-between px-5 py-4" style="background:#F7F0DB; border-bottom:1.5px solid #E6DAC0;">
                    <div>
                        <span style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em; border:1px solid #E6DAC0; background:#FCF8EE; border-radius:4px; padding:0.1rem 0.45rem;">Terminal Kasir F&amp;B</span>
                        <div style="font-size:1.05rem; font-weight:900; color:#4F2F2A; margin-top:0.3rem;">Layar Pembayaran &amp; Penyelesaian Transaksi</div>
                    </div>
                    <button type="button" wire:click="backToSelection" style="padding:0.4rem 0.85rem; font-size:0.75rem; font-weight:800; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:8px; cursor:pointer; color:#4F2F2A;">&larr; Ubah Pesanan</button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 flex flex-col gap-4">
                    @include("pos.partials.payment-method-form", ["grandTotal" => $this->grandTotal, "qrisMidtrans" => true])

                    <div style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem; margin-top:0.5rem;">
                        <button type="button" wire:click="backToSelection" style="padding:0.7rem 1.2rem; border-radius:10px; border:1.5px solid #E6DAC0; background:#FFFFFF; color:#4F2F2A; font-weight:800; font-size:0.8125rem; cursor:pointer;">&larr; Kembali ke Menu</button>
                        <button type="button" wire:click="submitFnbCheckout" wire:loading.attr="disabled" style="flex:1; padding:0.75rem 1.5rem; border-radius:10px; background:#662721; color:#F7F0DB; border:1px solid #662721; font-weight:900; font-size:0.9375rem; cursor:pointer; box-shadow:0 4px 14px rgba(102,39,33,0.25); text-transform:uppercase; letter-spacing:0.05em;">
                            <span wire:loading.remove wire:target="submitFnbCheckout">Bayar Lunas &amp; Cetak Struk</span>
                            <span wire:loading wire:target="submitFnbCheckout">Memproses Transaksi...</span>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <aside class="w-full sm:w-72 lg:w-80 xl:w-96 flex flex-col shrink-0 shadow-2xl border-l border-[#E6DAC0]" style="background: rgba(255,255,255,0.94);">
            <div class="p-4 border-b border-[#E6DAC0]/60 bg-white/80 flex items-center justify-between">
                <div>
                    <span style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; border:1px solid #E6DAC0; border-radius:4px; padding:0.1rem 0.45rem;">Langkah 2 dari 2</span>
                    <div class="font-bold text-sm text-[#4F2F2A] mt-1">Ringkasan Tagihan</div>
                </div>
                <div class="text-right">
                    <div class="font-mono font-black text-lg text-[#662721]">Rp {{ number_format($this->grandTotal, 0, ",", ".") }}</div>
                    <div class="text-[10px] text-[#7A5A52]">{{ collect($cart)->sum("quantity") }} item &bull; {{ $orderType === "TAKE_AWAY" ? "Bawa Pulang" : "Dine-In" }}{{ $tableNumber ? " - ".$tableNumber : "" }}</div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-2">
                @foreach($cart as $menuId => $item)
                    <div class="fnbpos-cart-item flex items-center justify-between" wire:key="pay-cart-{{ $menuId }}">
                        <div>
                            <div class="text-xs font-bold text-[#4F2F2A]">{{ $item["name"] }}</div>
                            <div class="text-[10px] text-[#7A5A52] font-mono">Rp {{ number_format($item["price"], 0, ",", ".") }} x {{ $item["quantity"] }}</div>
                            @if(trim($item["notes"] ?? "") !== "")
                                <div class="text-[10px] italic text-[#662721]">Catatan: {{ $item["notes"] }}</div>
                            @endif
                        </div>
                        <span class="font-mono text-xs font-extrabold text-[#662721]">Rp {{ number_format($item["price"] * $item["quantity"], 0, ",", ".") }}</span>
                    </div>
                @endforeach
            </div>

            <div class="p-4 border-t border-[#E6DAC0]/60 bg-white/90 space-y-1.5 text-xs text-[#7A5A52] font-mono">
                <div class="flex justify-between"><span>Subtotal:</span><span class="font-bold text-[#4F2F2A]">Rp {{ number_format($this->finance["subtotal"], 0, ",", ".") }}</span></div>
                @if($this->finance["tax_enabled"])
                    <div class="flex justify-between text-[11px]"><span>{{ $this->finance["tax_name"] }} ({{ $this->finance["tax_rate"] }}%):</span><span class="font-bold">Rp {{ number_format($this->finance["tax_amount"], 0, ",", ".") }}</span></div>
                @endif
                @if($this->finance["admin_fee_amount"] > 0)
                    <div class="flex justify-between text-[11px]"><span>{{ $this->finance["admin_fee_name"] }}:</span><span class="font-bold">Rp {{ number_format($this->finance["admin_fee_amount"], 0, ",", ".") }}</span></div>
                @endif
                <div class="flex justify-between text-base font-extrabold text-[#4F2F2A] pt-2 border-t border-[#E6DAC0]/50">
                    <span class="font-display">Total Tagihan:</span>
                    <span class="font-mono font-black text-[#662721] text-lg">Rp {{ number_format($this->grandTotal, 0, ",", ".") }}</span>
                </div>
            </div>
        </aside>
    @endif

    @if($posStep === 'history')
        {{-- ================= TAB: RIWAYAT TRANSAKSI ================= --}}
        <section class="flex-1 flex flex-col overflow-hidden" style="background: rgba(255,255,255,0.72);">
            <div class="p-4 border-b border-[#E6DAC0]/60 bg-white/80 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="font-display font-black text-base text-[#4F2F2A]">Riwayat Transaksi F&amp;B</div>
                    <div class="text-[11px] text-[#7A5A52]">{{ $this->activeShift ? 'Selama shift '.$this->activeShift->shift_number.' berjalan' : '50 transaksi terakhir (belum ada shift aktif)' }}</div>
                </div>
                {{-- Rekap Penjualan Harian (X-Report) loket F&B hari ini. --}}
                @include('pos.partials.sales-recap-button')
            </div>

            <div class="flex-1 overflow-auto p-4">
                @if(! $this->canShowFnbHistoryTab)
                    <div class="text-xs text-center py-16 text-[#9CA3AF]">Anda tidak memiliki izin untuk melihat riwayat transaksi F&amp;B.</div>
                @else
                @php $history = $this->orderHistory; @endphp
                @if($history->isEmpty())
                    <div class="text-xs text-center py-16 text-[#9CA3AF]">Belum ada transaksi F&amp;B yang tercatat.</div>
                @else
                    <div class="overflow-x-auto rounded-xl" style="border:1.5px solid #E6DAC0;">
                    <table class="fnbpos-history-table w-full border-collapse" style="background:#FFFFFF;">
                        <thead>
                            <tr>
                                <th>No. Antrian</th>
                                <th>No. Order</th>
                                <th>Waktu</th>
                                <th>Kasir</th>
                                <th>Tipe / Meja</th>
                                <th>Pelanggan</th>
                                <th>Metode Bayar</th>
                                <th style="text-align:right;">Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($history as $order)
                                @php
                                    $statusClass = match($order->payment_status) {
                                        'PAID' => 'fnbpos-status-paid',
                                        'REFUNDED' => 'fnbpos-status-refunded',
                                        'CANCELLED' => 'fnbpos-status-cancelled',
                                        default => 'fnbpos-status-unpaid',
                                    };
                                    $payment = $order->payments->firstWhere('status', 'SUCCESS') ?? $order->payments->first();
                                @endphp
                                <tr wire:key="history-row-{{ $order->id }}">
                                    <td class="font-mono font-black text-[#662721]">{{ $order->queue_number ? str_pad((string) $order->queue_number, 3, '0', STR_PAD_LEFT) : '—' }}</td>
                                    <td class="font-mono font-bold text-[#4F2F2A]">{{ $order->order_number }}</td>
                                    <td class="font-mono">{{ $order->created_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
                                    <td class="fnbpos-cell-clip" title="{{ $order->cashier?->name }}">{{ $order->cashier?->name ?? '-' }}</td>
                                    <td>{{ $order->order_type === 'DINE_IN' ? 'Dine-In' : 'Bawa Pulang' }}{{ $order->table_number ? ' - '.$order->table_number : '' }}</td>
                                    <td class="fnbpos-cell-clip" title="{{ $order->customer_name }}">{{ $order->customer_name ?: '—' }}</td>
                                    <td class="fnbpos-cell-clip" title="{{ $this->paymentLabelFor($payment) }}">{{ $this->paymentLabelFor($payment) }}</td>
                                    <td class="font-mono font-bold text-right">Rp {{ number_format($order->grand_total, 0, ',', '.') }}</td>
                                    <td><span class="fnbpos-status-badge {{ $statusClass }}">{{ $order->payment_status }}</span></td>
                                    <td><button type="button" wire:click="viewOrderReceipt('{{ $order->id }}')" class="text-[11px] font-bold text-[#662721] underline">Lihat Struk</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
                @endif
            </div>
        </section>
    @endif
    </div>

    {{-- QR Midtrans menunggu dibayar customer --}}
    @include('pos.partials.auto-pay-modal', ['pendingQris' => $pendingQris, 'pollAction' => 'pollPendingQris', 'cancelAction' => 'cancelPendingQris', 'simulateAction' => 'simulatePendingQrisPaid'])

    @if($showReceiptModal && $completedOrderData)
        {{-- ================= POPUP: STRUK PEMBAYARAN SUKSES ================= --}}
        {{-- Klik di luar popup / tombol Esc juga menutup. --}}
        <div class="fnbpos-modal-backdrop fnbpos-receipt-backdrop" wire:click.self="closeReceiptModal" x-data x-on:keydown.escape.window="$wire.closeReceiptModal()">
            <div class="fnbpos-modal-dialog fnbpos-receipt-dialog">
                <div class="fnbpos-receipt-head">
                    <div>
                        {{-- Status mengikuti order — dulu selalu "Transaksi Lunas" walau order batal / belum dibayar. --}}
                        @switch($completedOrderData['payment_status'] ?? 'PAID')
                            @case('PAID')
                                <span style="font-size:0.6875rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em; background:#F7F0DB; border:1px solid #E6DAC0; border-radius:4px; padding:0.1rem 0.5rem;">Transaksi Lunas</span>
                                @break
                            @case('CANCELLED')
                                <span style="font-size:0.6875rem; font-weight:900; color:#991B1B; text-transform:uppercase; letter-spacing:0.06em; background:#FEE2E2; border:1px solid #FCA5A5; border-radius:4px; padding:0.1rem 0.5rem;">Dibatalkan — tidak dibayar</span>
                                @break
                            @case('REFUNDED')
                                <span style="font-size:0.6875rem; font-weight:900; color:#991B1B; text-transform:uppercase; letter-spacing:0.06em; background:#FEE2E2; border:1px solid #FCA5A5; border-radius:4px; padding:0.1rem 0.5rem;">Sudah Direfund</span>
                                @break
                            @default
                                <span style="font-size:0.6875rem; font-weight:900; color:#92400E; text-transform:uppercase; letter-spacing:0.06em; background:#FEF3C7; border:1px solid #FCD34D; border-radius:4px; padding:0.1rem 0.5rem;">Belum Dibayar</span>
                        @endswitch
                        <div class="font-display font-black text-lg text-[#4F2F2A] mt-1.5">Struk Pembayaran F&amp;B</div>
                    </div>
                    <button type="button" wire:click="closeReceiptModal" aria-label="Tutup" style="background:none; border:none; font-size:1.5rem; color:#662721; cursor:pointer; line-height:1; padding:0.25rem 0.5rem;">&times;</button>
                </div>

                <div class="fnbpos-receipt-paper">
                    @include('pos.receipts.fnb', ['receipt' => $completedOrderData])
                </div>

                <div class="fnbpos-receipt-side">
                    {{-- Ringkasan untuk memanggil customer (isi lengkapnya di struk sebelah kiri). --}}
                    <div class="fnbpos-receipt-summary">
                        <div>
                            <span>Nomor Antrian</span>
                            <strong class="is-big">{{ $completedOrderData['queue_number'] ? str_pad((string) $completedOrderData['queue_number'], 3, '0', STR_PAD_LEFT) : '—' }}</strong>
                        </div>
                        <div>
                            <span>Total</span>
                            <strong class="is-big">Rp {{ number_format($completedOrderData['grand_total'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Pesanan</span>
                            <strong>{{ ($completedOrderData['order_type'] ?? null) === 'DINE_IN' ? 'Meja '.($completedOrderData['table_number'] ?: '—') : 'Bawa Pulang' }}</strong>
                        </div>
                        <div>
                            <span>Metode</span>
                            <strong>{{ $completedOrderData['payment_method_label'] ?? $completedOrderData['payment_method'] }}</strong>
                        </div>
                    </div>

                    {{-- Slip pesanan per stasiun (Kitchen, dst.): dikirim ke printer stasiunnya, bukan printer kasir. --}}
                    @if(! empty($completedOrderData['stations']))
                        <div style="display:flex; flex-direction:column; gap:0.35rem;">
                            <div class="fnbpos-receipt-label">Slip Stasiun</div>
                            @foreach($completedOrderData['stations'] as $station)
                                <div wire:key="station-status-{{ $station['ticket_id'] }}" style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem; font-size:0.75rem; border:1px solid #E6DAC0; border-radius:10px; padding:0.45rem 0.65rem; background:#FFFDF8;">
                                    <span style="font-weight:800; color:#4F2F2A; white-space:nowrap;">{{ $station['station_name'] }}</span>
                                    @if($station['printed_at'])
                                        <span style="font-weight:800; color:#047857;">Terkirim {{ $station['printed_at'] }}</span>
                                    @elseif($station['error'])
                                        <span style="font-weight:700; color:#B42318; text-align:right;">Gagal: {{ $station['error'] }}</span>
                                    @else
                                        <span style="font-weight:700; color:#92400E;">Belum terkirim</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="fnbpos-receipt-actions">
                        {{-- Struk hanya dicetak untuk transaksi lunas. --}}
                        @if(($completedOrderData['payment_status'] ?? 'PAID') === 'PAID')
                            <button type="button" onclick="club61PrintReceipt('#fnbpos-receipt')" class="fnbpos-pay-btn flex-1">Cetak Struk</button>
                            <button type="button" wire:click="resendStationTickets('{{ $completedOrderData['order_id'] ?? '' }}')" wire:loading.attr="disabled" wire:target="resendStationTickets" class="fnbpos-pay-btn flex-1">Kirim Ulang ke Stasiun</button>
                        @endif
                        <button type="button" wire:click="{{ $posStep === 'history' ? 'closeReceiptModal' : 'startNewTransaction' }}" class="fnbpos-pay-btn active col-span-2">{{ $posStep === 'history' ? 'Tutup' : 'Transaksi Baru' }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif


    {{-- ================= MODAL: BUKA SHIFT ================= --}}
    @if($showOpenShiftModal)
        <div class="fnbpos-modal-backdrop">
            <div class="fnbpos-modal-dialog">
                <div class="font-display font-black text-lg text-[#4F2F2A]">Buka Shift Kasir F&amp;B</div>
                <p class="text-xs text-[#4F2F2A]">Loket beroperasi 100% Cashless (QRIS / EDC). Tidak ada modal kas fisik yang perlu dihitung.</p>
                @include('pos.partials.shift-open-edc')
                <textarea wire:model="openingNotes" class="fnbpos-input" placeholder="Catatan pembukaan (opsional)"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('showOpenShiftModal', false)" class="fnbpos-pay-btn">Batal</button>
                    <button type="button" wire:click="executeOpenShift" class="fnbpos-pay-btn active">Buka Shift</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Tutup shift blind + laporan Z (sama dengan POS Padel) — App\Livewire\Concerns\ClosesPosShift. --}}
    @include('pos.partials.shift-close-modal')
    @include('pos.partials.shift-report-modal')
    {{-- Rekap Penjualan Harian loket F&B (X-Report) — App\Livewire\Concerns\PrintsDailySalesRecap. --}}
    @include('pos.partials.sales-recap-modal')
</div>
