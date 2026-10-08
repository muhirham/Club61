<div class="flex-1 flex flex-col overflow-hidden">
    <style>
        .fnbpos-pill { padding: 0.55rem 1rem; border-radius: 12px; font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; cursor: pointer; transition: all 0.15s ease; border: 1.5px solid #E6DAC0; background: rgba(255,255,255,0.9); color: #4F2F2A; }
        .fnbpos-pill.active { background: #662721; border-color: #662721; color: #F7F0DB; box-shadow: 0 4px 12px rgba(102,39,33,0.22); }
        /* Kartu tinggi seragam — semua kotak sama besar & rapi, isi (foto/teks) menyesuaikan kotak */
        .fnbpos-card { height: 19rem; padding: 0.85rem; border-radius: 16px; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 8px 20px -8px rgba(79,47,42,0.10); border: 1.5px solid #E6DAC0; background: #FFFFFF; display: flex; flex-direction: column; overflow: hidden; }
        .fnbpos-card:hover { transform: scale(1.02); }
        .fnbpos-card-photo { width: 100%; height: 8.5rem; border-radius: 10px; overflow: hidden; margin-bottom: 0.6rem; background: #F7F0DB; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .fnbpos-card-photo img { width: 100% !important; height: 100% !important; max-width: none !important; object-fit: cover; display: block; }
        .fnbpos-card-photo .fnbpos-photo-initial { font-family: 'Cheltenham Classic', 'Source Serif 4', Georgia, serif; font-size: 2rem; font-weight: 900; color: #662721; opacity: 0.55; }
        /* Desktop lebar: 1 halaman = 2 baris x 4 kolom yang dibagi rata mengisi tinggi layar,
           supaya tidak ada ruang kosong di bawah grid. Foto ikut membesar mengisi kotak. */
        @media (min-width: 1280px) {
            .fnbpos-grid { grid-template-rows: repeat(2, minmax(0, 1fr)); overflow-y: hidden; }
            .fnbpos-grid .fnbpos-card { height: 100%; min-height: 0; }
            .fnbpos-grid .fnbpos-card-photo { height: auto; flex: 1 1 auto; min-height: 5rem; }
        }
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
    @include('pos.partials.receipt-print-style', ['selectors' => ['#fnbpos-receipt']])

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
                        <div class="flex items-center justify-between text-[10px] font-bold mb-1.5 shrink-0">
                            <span class="text-[#7A5A52] truncate pr-2">{{ $menu->category?->name }}</span>
                            <span class="px-1.5 py-0.5 rounded-md font-mono shrink-0" style="background: #F7F0DB; border: 1px solid #E6DAC0; color: #7A5A52;">{{ $menu->station }}</span>
                        </div>

                        {{-- Kotak foto ukurannya SAMA di semua kartu; menu tanpa foto tetap dapat kotak
                             placeholder supaya grid tetap rapi & sejajar. --}}
                        <div class="fnbpos-card-photo">
                            @if($menu->image_url)
                                <img src="{{ '/storage/'.$menu->image_url }}" alt="{{ $menu->name }}" loading="lazy">
                            @else
                                <span class="fnbpos-photo-initial">{{ mb_strtoupper(mb_substr($menu->name, 0, 1)) }}</span>
                            @endif
                        </div>

                        <div class="font-bold text-sm text-[#4F2F2A] line-clamp-1">{{ $menu->name }}</div>
                        <div class="text-[11px] text-[#7A5A52] mt-0.5 line-clamp-2">{{ $menu->description }}</div>

                        <div class="mt-auto pt-2 border-t border-[#E6DAC0]/50 flex items-center justify-between font-mono">
                            <span class="text-xs font-black text-[#662721]">Rp {{ number_format($menu->base_price, 0, ',', '.') }}</span>
                            <span class="w-6 h-6 rounded-lg flex items-center justify-center font-bold text-sm" style="background: #662721; color: #F7F0DB;">+</span>
                        </div>
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
            <div class="p-4 border-b border-[#E6DAC0]/60 bg-white/80">
                <div class="font-display font-black text-base text-[#4F2F2A]">Riwayat Transaksi F&amp;B</div>
                <div class="text-[11px] text-[#7A5A52]">{{ $this->activeShift ? 'Selama shift '.$this->activeShift->shift_number.' berjalan' : '50 transaksi terakhir (belum ada shift aktif)' }}</div>
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
    @include('pos.partials.midtrans-qris-modal', ['pendingQris' => $pendingQris, 'pollAction' => 'pollPendingQris', 'cancelAction' => 'cancelPendingQris', 'simulateAction' => 'simulatePendingQrisPaid'])

    @if($showReceiptModal && $completedOrderData)
        {{-- ================= POPUP: STRUK PEMBAYARAN SUKSES ================= --}}
        {{-- Klik di luar popup / tombol Esc juga menutup. --}}
        <div class="fnbpos-modal-backdrop" wire:click.self="closeReceiptModal" x-data x-on:keydown.escape.window="$wire.closeReceiptModal()">
            <div class="fnbpos-modal-dialog" style="max-width:400px; padding:1.25rem; gap:0.85rem;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
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

                @include('pos.receipts.fnb', ['receipt' => $completedOrderData])

                <div class="grid grid-cols-2 gap-3">
                    {{-- Struk hanya dicetak untuk transaksi lunas. --}}
                    @if(($completedOrderData['payment_status'] ?? 'PAID') === 'PAID')
                        <button type="button" onclick="club61PrintReceipt('#fnbpos-receipt')" class="fnbpos-pay-btn flex-1">Cetak Struk</button>
                        {{-- Cetak ulang slip pesanan bar / dapur (tidak tampil di layar, hanya dicetak). --}}
                        <template id="fnbpos-kot-template">@include('pos.receipts.fnb-kitchen', ['receipt' => $completedOrderData])</template>
                        <button type="button" onclick="club61PrintReceiptHtml(document.getElementById('fnbpos-kot-template').innerHTML)" class="fnbpos-pay-btn flex-1">Cetak Pesanan Dapur/Bar</button>
                    @endif
                    <button type="button" wire:click="{{ $posStep === 'history' ? 'closeReceiptModal' : 'startNewTransaction' }}" class="fnbpos-pay-btn active col-span-2">{{ $posStep === 'history' ? 'Tutup' : 'Transaksi Baru' }}</button>
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
                <textarea wire:model="openingNotes" class="fnbpos-input" placeholder="Catatan pembukaan (opsional)"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('showOpenShiftModal', false)" class="fnbpos-pay-btn">Batal</button>
                    <button type="button" wire:click="executeOpenShift" class="fnbpos-pay-btn active">Buka Shift</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL: TUTUP SHIFT & REKONSILIASI SETTLEMENT ================= --}}
    @if($showCloseShiftModal && $this->activeShift)
        <div class="fnbpos-modal-backdrop">
            <div class="fnbpos-modal-dialog" style="max-width:720px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <span style="font-size:0.625rem; font-weight:900; color:#BE123C; text-transform:uppercase; letter-spacing:0.06em; background:#FFF1F2; border:1px solid #FECDD3; border-radius:4px; padding:0.08rem 0.4rem;">Closing Register</span>
                        <div class="font-display font-black text-lg text-[#4F2F2A] mt-1">Tutup Shift &amp; Rekonsiliasi Settlement</div>
                        <div class="text-[11px] text-[#7A5A52]">{{ $this->activeShift->shift_number }} &bull; dibuka {{ $this->activeShift->openedBy?->name ?? 'Kasir' }} ({{ $this->activeShift->opened_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB)</div>
                    </div>
                    <button type="button" wire:click="$set('showCloseShiftModal', false)" style="background:none; border:none; font-size:1.25rem; color:#662721; cursor:pointer;">&times;</button>
                </div>

                <div style="background:#ECFDF5; border:1.5px solid #6EE7B7; border-radius:8px; padding:0.6rem 0.8rem; font-size:0.75rem; color:#065F46; font-weight:700;">
                    Cetak struk settlement di tiap mesin EDC &amp; cek mutasi QRIS, lalu ketik totalnya per kategori di bawah. Sistem menghitung selisih terhadap transaksi yang tercatat di POS.
                </div>

                @if(empty($closingBreakdown))
                    <div class="text-xs text-center py-6 text-[#9CA3AF]">Belum ada transaksi di shift ini — tidak ada yang perlu direkonsiliasi.</div>
                @else
                    <div style="overflow-x:auto;">
                        <table style="width:100%; font-size:0.75rem; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#F7F0DB; color:#4F2F2A; text-transform:uppercase; font-size:0.65rem; letter-spacing:0.04em;">
                                    <th style="text-align:left; padding:0.5rem;">Kategori Pembayaran</th>
                                    <th style="text-align:center; padding:0.5rem;">Trx</th>
                                    <th style="text-align:right; padding:0.5rem;">Tercatat POS</th>
                                    <th style="text-align:right; padding:0.5rem; width:170px;">Settlement EDC/QRIS</th>
                                    <th style="text-align:right; padding:0.5rem;">Selisih</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $totalSystem = 0; $totalSettled = 0; $allFilled = true; @endphp
                                @foreach($closingBreakdown as $row)
                                    @php
                                        $rawInput = str_replace(['.', ',', ' ', 'Rp', 'rp'], '', trim((string) ($settlementInputs[$row['key']] ?? '')));
                                        $isFilled = $rawInput !== '' && ctype_digit($rawInput);
                                        $settled = $isFilled ? (float) $rawInput : null;
                                        $diff = $isFilled ? $settled - $row['system_amount'] : null;
                                        $totalSystem += $row['system_amount'];
                                        $totalSettled += $settled ?? 0;
                                        $allFilled = $allFilled && $isFilled;
                                    @endphp
                                    <tr wire:key="settle-{{ $row['key'] }}" style="border-bottom:1px solid #EFE6D2;">
                                        <td style="padding:0.5rem;">
                                            <div style="font-weight:800; color:#4F2F2A;">{{ $row['label'] }}</div>
                                            <div style="font-size:0.65rem; color:#9CA3AF;">Cek: {{ $row['source'] }}</div>
                                        </td>
                                        <td style="padding:0.5rem; text-align:center;">{{ $row['count'] }}</td>
                                        <td style="padding:0.5rem; text-align:right; font-family:monospace; font-weight:800;">Rp {{ number_format($row['system_amount'], 0, ',', '.') }}</td>
                                        <td style="padding:0.5rem;">
                                            <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="settlementInputs.{{ $row['key'] }}" class="fnbpos-input" style="text-align:right;" placeholder="Nominal struk">
                                        </td>
                                        <td style="padding:0.5rem; text-align:right; font-family:monospace; font-weight:900; color:{{ $diff === null ? '#9CA3AF' : (abs($diff) < 0.01 ? '#15803D' : '#BE123C') }};">
                                            @if($diff === null)
                                                &mdash;
                                            @elseif(abs($diff) < 0.01)
                                                COCOK
                                            @else
                                                {{ $diff > 0 ? '+' : '-' }}Rp {{ number_format(abs($diff), 0, ',', '.') }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr style="background:#F7F0DB; font-weight:900;">
                                    <td style="padding:0.5rem;" colspan="2">TOTAL</td>
                                    <td style="padding:0.5rem; text-align:right; font-family:monospace;">Rp {{ number_format($totalSystem, 0, ',', '.') }}</td>
                                    <td style="padding:0.5rem; text-align:right; font-family:monospace;">{{ $allFilled ? 'Rp '.number_format($totalSettled, 0, ',', '.') : '—' }}</td>
                                    <td style="padding:0.5rem; text-align:right; font-family:monospace; color:{{ ! $allFilled ? '#9CA3AF' : (abs($totalSettled - $totalSystem) < 0.01 ? '#15803D' : '#BE123C') }};">
                                        @if(! $allFilled)
                                            &mdash;
                                        @elseif(abs($totalSettled - $totalSystem) < 0.01)
                                            COCOK
                                        @else
                                            {{ $totalSettled - $totalSystem > 0 ? '+' : '-' }}Rp {{ number_format(abs($totalSettled - $totalSystem), 0, ',', '.') }}
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif

                <div>
                    <label class="text-xs font-bold text-[#4F2F2A] block mb-1">Catatan Penutupan <span class="font-normal text-[#9CA3AF]">(wajib diisi kalau ada selisih)</span></label>
                    <textarea wire:model="closingNotes" rows="2" class="fnbpos-input" placeholder="Penjelasan selisih, nomor batch settlement, catatan serah terima..."></textarea>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('showCloseShiftModal', false)" class="fnbpos-pay-btn">Batal</button>
                    <button type="button" wire:click="executeCloseShift" wire:loading.attr="disabled" class="fnbpos-pay-btn" style="background:#BE123C; color:#FFFFFF; border-color:#BE123C;">Tutup Shift &amp; Simpan Rekonsiliasi</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL: LAPORAN Z SHIFT ================= --}}
    @if($showShiftReportModal && $reportShiftData)
        <div class="fnbpos-modal-backdrop">
            <div class="fnbpos-modal-dialog" style="max-width:560px;">
                <div class="font-display font-black text-lg text-[#4F2F2A]">Laporan Shift {{ $reportShiftData['shift_number'] }}</div>
                <div class="text-xs text-[#4F2F2A] space-y-1 font-mono">
                    <div class="flex justify-between"><span>Dibuka</span><span>{{ $reportShiftData['opened_at'] }} ({{ $reportShiftData['opened_by'] }})</span></div>
                    <div class="flex justify-between"><span>Ditutup</span><span>{{ $reportShiftData['closed_at'] }} ({{ $reportShiftData['closed_by'] }})</span></div>
                    <div class="flex justify-between"><span>Total Transaksi</span><span>{{ $reportShiftData['total_transactions'] }}</span></div>
                    <div class="flex justify-between font-bold border-t pt-1 mt-1"><span>Total Penjualan</span><span>Rp {{ number_format($reportShiftData['total_sales'], 0, ',', '.') }}</span></div>
                </div>

                @if(! empty($reportShiftData['reconciliation']))
                    <div class="text-xs font-mono border-t border-dashed border-[#E6DAC0] pt-2 space-y-1.5">
                        <div class="font-bold text-[#4F2F2A] uppercase text-[10px] tracking-wider">Rekonsiliasi Settlement</div>
                        @foreach($reportShiftData['reconciliation'] as $row)
                            <div>
                                <div class="font-bold text-[#4F2F2A]">{{ $row['label'] }} ({{ $row['count'] }} trx)</div>
                                <div class="flex justify-between text-[#4F2F2A]"><span>POS Rp {{ number_format($row['system_amount'], 0, ',', '.') }} / Settlement Rp {{ number_format($row['settled_amount'], 0, ',', '.') }}</span>
                                    <span style="color:{{ abs($row['difference']) < 0.01 ? '#15803D' : '#BE123C' }}; font-weight:900;">{{ abs($row['difference']) < 0.01 ? 'COCOK' : (($row['difference'] > 0 ? '+' : '-').'Rp '.number_format(abs($row['difference']), 0, ',', '.')) }}</span>
                                </div>
                            </div>
                        @endforeach
                        <div class="flex justify-between font-black border-t pt-1" style="color:{{ abs($reportShiftData['total_difference']) < 0.01 ? '#15803D' : '#BE123C' }};">
                            <span>Total Selisih</span>
                            <span>{{ abs($reportShiftData['total_difference']) < 0.01 ? 'COCOK (Rp 0)' : (($reportShiftData['total_difference'] > 0 ? '+' : '-').'Rp '.number_format(abs($reportShiftData['total_difference']), 0, ',', '.')) }}</span>
                        </div>
                        @if($reportShiftData['closing_notes'])
                            <div class="text-[#7A5A52]">Catatan: {{ $reportShiftData['closing_notes'] }}</div>
                        @endif
                    </div>
                @endif

                <button type="button" wire:click="$set('showShiftReportModal', false)" class="fnbpos-pay-btn active">Tutup</button>
            </div>
        </div>
    @endif
</div>
