{{-- Kasir Pro Shop – loket alat frontdesk (tablet first), gaya sama dengan POS Walk-In Booking & POS Jual Membership. --}}
<div class="walkin-pos-root">
    @include('filament.partials.pos-theme-style')
    @include('pos.partials.receipt-print-style', ['selectors' => ['#printable-pos-receipt', '#printable-sales-recap']])

    @php
        $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $quote = $tab === 'denda' ? null : $this->quote;
        $usageLabel = fn ($eq) => $eq->isSale() ? 'Jual' : ($eq->isRental() ? 'Sewa' : 'Alat');
    @endphp

    {{-- ============================
         BARIS ATAS: tab POS + status shift
         ============================ --}}
    <div class="pos-headbar">
        <div class="pos-headbar-left">
            @include('filament.partials.pos-subnav', ['activePos' => 'proshop', 'inline' => true])
            @include('filament.partials.pos-history-tabs', ['isHistory' => $posStep === 'history', 'canShowHistory' => $this->canShowHistoryTab, 'inline' => true])
        </div>
        <div class="pos-headbar-right">
            {{-- Penjualan alat masuk shift meja frontdesk yang sama dengan POS Walk-In Padel --}}
            @if ($activeShift)
                <span class="pos-shift-chip is-open" title="Shift aktif {{ $activeShift->shift_number }}">
                    <span class="dot"></span>
                    <span class="pos-only-wide">SHIFT AKTIF: {{ $activeShift->shift_number }}</span>
                    <span class="pos-only-narrow">SHIFT {{ \Illuminate\Support\Str::afterLast($activeShift->shift_number, '-') }} AKTIF</span>
                </span>
            @else
                <a href="{{ route('filament.admin.pages.book-offline-court') }}" class="pos-shift-chip is-closed" style="text-decoration:none;">
                    <span class="dot"></span>
                    Shift kasir belum dibuka <span class="pos-hide-md">&mdash; buka di POS Walk-In Booking</span> &rarr;
                </a>
            @endif
        </div>
    </div>

    {{-- Peminjaman Alat: raket / handuk yang lewat batas kembali — kasir / resepsionis lupa menerima kembali. --}}
    @include('filament.partials.pos-alerts')

    {{-- ============================
         TOOLBAR: judul loket + tab Jual / Sewa / Denda
         ============================ --}}
    @if ($posStep !== 'history')
    <div class="pos-toolbar">
        <div style="min-width:0;">
            <div style="font-size:0.9375rem; font-weight:900; color:#4F2F2A;">Kasir Pro Shop</div>
            <div style="font-size:0.75rem; color:#7A5A52; margin-top:0.1rem;">
                @if ($tab === 'jual')
                    Jual perlengkapan padel habis pakai (mis. bola) dan merchandise tanpa booking. Raket &amp; handuk disewakan lewat tab Sewa.
                @elseif ($tab === 'sewa')
                    Tambah alat ke booking hari ini atau ke depan. Raket &amp; handuk tercatat di Peminjaman Alat.
                @else
                    Terima pembayaran denda kerusakan / kehilangan alat yang sudah disetujui management.
                @endif
            </div>
        </div>
        @if ($this->allowedTabs() !== [])
            <div class="pos-tab-group" style="min-width:260px;" role="tablist">
                @foreach ($this->allowedTabs() as $key)
                    <button type="button" wire:click="setTab('{{ $key }}')" data-proshop-tab="{{ $key }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="pos-tab {{ $tab === $key ? 'active' : '' }}" {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                        {{ \App\Filament\Pages\KasirProShop::TABS[$key] }}
                        @if ($key === 'denda' && ($fineCount = $this->payableFineCount) > 0)
                            <span class="pos-count-badge" data-fine-count style="margin-left:0.3rem; {{ $tab === 'denda' ? 'background:#F7F0DB; color:#662721;' : '' }}">{{ $fineCount }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </div>
    @endif

    @if ($this->allowedTabs() === [])
        <div class="pos-grid-card"><div class="pos-empty-note">Anda belum memiliki izin menjual barang Pro Shop atau menerima pembayaran denda.</div></div>
    @elseif ($posStep === 'history')
        {{-- Riwayat Pro Shop: jual, tambah alat ke booking, denda alat (komponen sama dengan POS Walk-In & Membership). --}}
        @include('filament.partials.pos-history-table', [
            'rows' => $this->transactionHistory,
            'title' => 'Riwayat Transaksi Kasir Pro Shop',
            'receiptAction' => 'viewTransactionReceipt',
            'idKey' => 'payment_id',
        ])
    @else
    <div class="pos-main">

        @if ($posStep === 'selection')
        {{-- ==================== KIRI: PILIH PEMBELI / BOOKING / DENDA + ALAT ==================== --}}
        <div class="pos-grid-card">
            <div class="pos-scroll-body">
                @if ($tab === 'jual')
                    {{-- 1. Data pembeli (tidak dibuatkan akun) --}}
                    <section>
                        <div class="pos-step-head">
                            <span class="pos-step-title"><span class="pos-step-num">1</span>Data Pembeli</span>
                        </div>
                        <div class="pos-form-grid">
                            <div>
                                <label class="pos-field-label">Nama Pembeli *</label>
                                <input type="text" wire:model="buyerName" name="proshop-buyer-name" placeholder="Nama pembeli" class="pos-input" autocomplete="off">
                            </div>
                            <div>
                                <label class="pos-field-label">Nomor WhatsApp *</label>
                                <input type="tel" wire:model="buyerPhone" name="proshop-buyer-phone" placeholder="0812xxxxxxxx" class="pos-input" autocomplete="off">
                            </div>
                        </div>
                        <div style="font-size:0.6875rem; color:#9CA3AF; margin-top:0.35rem;">Pembeli tidak dibuatkan akun &mdash; nama &amp; nomor WhatsApp hanya dicatat di transaksi dan struk.</div>
                    </section>
                @elseif ($tab === 'sewa')
                    {{-- 1. Booking yang ditambah alat --}}
                    <section>
                        <div class="pos-step-head">
                            <span class="pos-step-title"><span class="pos-step-num">1</span>Pilih Booking</span>
                        </div>
                        @if ($rentBooking)
                            <div class="pos-addon-card" data-addon-booking="{{ $rentBooking['code'] }}">
                                <div class="pos-addon-card-head">
                                    <span>Tambah Alat ke Booking</span>
                                    <button type="button" wire:click="clearBooking" class="pos-mini-btn is-danger">Ganti Booking</button>
                                </div>
                                <div class="pos-addon-card-name">{{ $rentBooking['customer'] }}</div>
                                @if (! empty($rentBooking['phone']))
                                    <div class="pos-addon-card-sub">{{ $rentBooking['phone'] }}</div>
                                @endif
                                <div class="pos-addon-card-sub" style="margin-top:0.35rem;">
                                    <span style="font-family:var(--font-mono, monospace); font-weight:800;">#{{ $rentBooking['code'] }}</span>
                                    &bull; {{ $rentBooking['court'] }} &bull; {{ $rentBooking['schedule'] }}
                                </div>
                                <div class="pos-addon-card-note">
                                    @if ($this->isTabBill)
                                        Sesi coaching Tagihan Berkala {{ $rentBooking['academy'] }} — alat ditambahkan ke tagihan academy, tanpa pembayaran sekarang.
                                    @else
                                        Alat dibayar sebagai transaksi baru di kasir. Transaksi booking ini tidak berubah.
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="pos-addon-pick-search" style="margin-bottom:0.75rem;">
                                {{-- type search + name sendiri: Chrome tidak menyodorkan isi otomatis alamat / nomor HP. --}}
                                <input type="search" name="proshop-booking-search" wire:model.live.debounce.300ms="bookingSearch" class="pos-input" autocomplete="off"
                                    placeholder="Cari kode booking, nama, atau nomor HP (hari ini & ke depan)">
                                @if ($this->bookingSearchResults !== [])
                                    <div class="pos-search-results">
                                        @foreach ($this->bookingSearchResults as $res)
                                            <button type="button" wire:key="proshop-search-{{ $res['id'] }}" wire:click="pickBooking('{{ $res['id'] }}')" class="pos-search-item">
                                                <div style="font-weight:800; color:#4F2F2A;">{{ $res['customer'] }} &middot; #{{ $res['code'] }}</div>
                                                <div style="font-size:0.75rem; color:#662721;">{{ $res['court'] }} &bull; {{ $res['schedule'] }} WIB{{ $res['tab'] ? ' · Tagihan Berkala' : '' }}</div>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (mb_strlen(trim($bookingSearch)) >= 2)
                                    <div class="pos-addon-pick-empty">Tidak ada booking hari ini atau ke depan yang cocok.</div>
                                @endif
                            </div>

                            <div class="pos-section-label" style="margin-bottom:0.4rem;">Booking Hari Ini</div>
                            @forelse ($this->todayBookings as $row)
                                <button type="button" wire:key="proshop-today-{{ $row['id'] }}" wire:click="pickBooking('{{ $row['id'] }}')" class="pos-search-item" data-today-booking="{{ $row['code'] }}"
                                    style="border:1px solid #E6DAC0; border-radius:10px; margin-bottom:0.4rem; width:100%; text-align:left;">
                                    <div style="display:flex; justify-content:space-between; gap:0.5rem; align-items:baseline;">
                                        <span style="font-weight:800; color:#4F2F2A;">{{ $row['customer'] }} &middot; #{{ $row['code'] }}</span>
                                        <span style="font-size:0.75rem; font-weight:800; color:#662721; white-space:nowrap;">{{ $row['time'] }}</span>
                                    </div>
                                    <div style="font-size:0.75rem; color:#7A5A52;">
                                        {{ $row['court'] }}
                                        @if ($row['checked_in']) &bull; <span style="color:#047857; font-weight:800;">Sudah check-in</span> @endif
                                        @if ($row['tab']) &bull; Tagihan Berkala @endif
                                    </div>
                                </button>
                            @empty
                                <div class="pos-addon-pick-empty">Belum ada booking hari ini yang bisa ditambah alat.</div>
                            @endforelse
                        @endif
                    </section>
                @else
                    {{-- Denda yang sudah disetujui management --}}
                    <section>
                        <div class="pos-step-head">
                            <span class="pos-step-title"><span class="pos-step-num">1</span>Denda Alat</span>
                        </div>
                        @if ($fineBill)
                            <div data-fine-bill style="background:#FFFBEB; border:1.5px solid #FCD34D; border-radius:12px; padding:0.85rem;">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem;">
                                    <div style="font-size:0.625rem; font-weight:900; color:#92400E; text-transform:uppercase; letter-spacing:0.05em;">{{ $fineBill['type'] }}</div>
                                    <button type="button" wire:click="clearFine" class="pos-mini-btn is-danger">Batal</button>
                                </div>
                                <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">{{ $fineBill['customer'] }}</div>
                                @if (! empty($fineBill['phone']))
                                    <div style="font-size:0.6875rem; color:#78350F;">{{ $fineBill['phone'] }}</div>
                                @endif
                                <div style="font-size:0.6875rem; color:#78350F; margin-top:0.35rem; line-height:1.45;">
                                    <span style="font-family:var(--font-mono, monospace); font-weight:800;">#{{ $fineBill['code'] }}</span><br>
                                    {{ $fineBill['court'] }} &bull; {{ $fineBill['schedule'] }}
                                </div>
                                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #FCD34D; margin-top:0.6rem; padding-top:0.5rem;">
                                    <span style="font-size:0.75rem; font-weight:900; color:#92400E;">Denda yang harus dibayar</span>
                                    <span style="font-family:var(--font-mono, monospace); font-size:1rem; font-weight:900; color:#991B1B;">{{ $rupiah($fineBill['amount']) }}</span>
                                </div>
                                <div style="font-size:0.625rem; color:#B45309; margin-top:0.35rem;">Denda kerusakan / kehilangan alat yang sudah disetujui management (tanpa pajak &amp; biaya layanan).</div>
                            </div>
                        @else
                            @forelse ($this->payableFines as $loan)
                                <div wire:key="proshop-fine-{{ $loan->id }}" class="pos-eq-row" data-payable-fine="{{ $loan->id }}"
                                    style="border:1px solid #E6DAC0; border-radius:10px; padding:0.6rem 0.75rem; margin-bottom:0.4rem;">
                                    <div style="min-width:0;">
                                        <div style="font-weight:800; color:#4F2F2A;">{{ $loan->borrower_name }} &middot; {{ $loan->quantity }}&times; {{ $loan->equipment_name }}</div>
                                        <div style="font-size:0.6875rem; color:#7A5A52;">
                                            #{{ $loan->booking?->booking_code ?? '-' }} &bull; {{ app(\App\Services\Padel\EquipmentLoanService::class)->conditionSummary($loan) }}
                                        </div>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:0.5rem; flex-shrink:0;">
                                        <span style="font-weight:900; color:#991B1B;">{{ $rupiah($loan->fineDue()) }}</span>
                                        <button type="button" wire:click="startFine('{{ $loan->id }}')" class="pos-btn pos-btn-primary">Bayar</button>
                                    </div>
                                </div>
                            @empty
                                <div class="pos-empty-note">Tidak ada denda yang menunggu pembayaran.</div>
                            @endforelse
                        @endif
                    </section>
                @endif

                {{-- 2a. Etalase tab Jual (pola kasir F&B): kategori + cari, kartu ringkas. Ketuk kartu = +1; barang bervarian → popup. --}}
                @if ($tab === 'jual')
                    <section data-proshop-shop>
                        <div class="pos-step-head">
                            <span class="pos-step-title"><span class="pos-step-num">2</span>Pilih Barang</span>
                            <span style="font-size:0.8125rem; font-weight:800; color:#662721;">{{ $rupiah($quote['subtotal'] ?? 0) }}</span>
                        </div>
                        <div class="pos-shop-toolbar">
                            <div class="pos-shop-cats" role="tablist" aria-label="Kategori barang">
                                @foreach ($this->shopCategories as $key => $label)
                                    <button type="button" wire:key="proshop-cat-{{ $key }}" wire:click="setShopCategory('{{ $key }}')" role="tab"
                                        aria-selected="{{ $shopCategory === $key ? 'true' : 'false' }}" data-shop-category="{{ $key }}"
                                        class="pos-shop-cat {{ $shopCategory === $key ? 'active' : '' }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            {{-- type search + name sendiri: Chrome tidak menyodorkan isi otomatis. --}}
                            <input type="search" name="proshop-shop-search" wire:model.live.debounce.300ms="shopSearch" class="pos-input pos-shop-search"
                                placeholder="Cari barang / SKU..." autocomplete="off" aria-label="Cari barang atau SKU">
                        </div>

                        @php $shopItems = $this->shopItems; @endphp
                        @if ($shopItems === [])
                            <div class="pos-empty-note">
                                {{ trim($shopSearch) !== '' || $shopCategory !== 'ALL'
                                    ? 'Tidak ada barang yang cocok dengan pencarian / kategori ini.'
                                    : 'Belum ada barang yang dijual. Aktifkan perlengkapan padel bersifat Jual di Master Data atau produk di Katalog Merchandise.' }}
                            </div>
                        @else
                            <div class="pos-shop-grid">
                                @foreach ($shopItems as $item)
                                    @php $out = $item['stock'] < 1; @endphp
                                    <button type="button" wire:key="proshop-item-{{ $item['kind'] }}-{{ $item['id'] }}" wire:click="tapShopItem('{{ $item['kind'] }}', '{{ $item['id'] }}')"
                                        class="pos-shop-card {{ $item['picked'] > 0 ? 'is-selected' : '' }} {{ $out ? 'is-out' : '' }}" {{ $out ? 'disabled' : '' }}
                                        @if ($item['kind'] === 'EQUIPMENT') data-equipment="{{ $item['name'] }}" @else data-merch-product="{{ $item['name'] }}" @endif
                                        aria-label="{{ $item['variants'] > 1 ? 'Pilih varian' : 'Tambah' }} {{ $item['name'] }}">
                                        <span class="pos-shop-photo">
                                            @if ($item['image'])
                                                <img src="{{ $item['image'] }}" alt="" loading="lazy">
                                            @else
                                                <span class="pos-shop-initial">{{ mb_strtoupper(mb_substr($item['name'], 0, 1)) }}</span>
                                            @endif
                                            @if ($item['picked'] > 0)
                                                <span class="pos-shop-badge" data-picked="{{ $item['picked'] }}">{{ $item['picked'] }}</span>
                                            @endif
                                        </span>
                                        <span class="pos-shop-cat-label">{{ $item['category'] }}{{ $item['variants'] > 1 ? ' · '.$item['variants'].' varian' : '' }}</span>
                                        <span class="pos-shop-name">{{ $item['name'] }}</span>
                                        <span class="pos-shop-foot">
                                            <span class="pos-shop-price">@if ($item['price_varies'])<small>mulai</small> @endif{{ $rupiah($item['price']) }}</span>
                                            <span class="pos-shop-stock {{ $out ? 'is-out' : '' }}">{{ $out ? 'Habis' : 'Stok '.$item['stock'] }}</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif

                {{-- 2b. Tab Sewa: semua alat aktif, setelah booking dipilih --}}
                @if ($tab === 'sewa' && $rentBooking)
                    <section>
                        <div class="pos-step-head">
                            <span class="pos-step-title"><span class="pos-step-num">2</span>Pilih Alat</span>
                            <span style="font-size:0.8125rem; font-weight:800; color:#662721;">{{ $rupiah($quote['subtotal'] ?? 0) }}</span>
                        </div>
                        @if ($equipments->isEmpty())
                            <div class="pos-empty-note">Belum ada alat aktif di Master Data.</div>
                        @else
                            <div class="pos-plan-grid">
                                @foreach ($equipments as $eq)
                                    @php $qty = (int) ($quantities[$eq->id] ?? 0); @endphp
                                    <div wire:key="proshop-eq-{{ $eq->id }}" class="pos-plan-card {{ $qty > 0 ? 'is-selected' : '' }}" data-equipment="{{ $eq->name }}" style="cursor:default;">
                                        <div class="pos-plan-top">
                                            <div style="min-width:0;">
                                                <div class="pos-plan-code">{{ $usageLabel($eq) }}</div>
                                                <div class="pos-plan-name">{{ $eq->name }}</div>
                                            </div>
                                            <span class="pos-plan-days">Stok {{ $eq->stock_quantity }}</span>
                                        </div>
                                        <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin-top:0.55rem;">
                                            <div class="pos-plan-price" style="margin-top:0;">{{ $rupiah($eq->rental_price) }}</div>
                                            <div class="pos-qty-ctrl">
                                                <button type="button" wire:click="decrementEquipment('{{ $eq->id }}')" class="pos-qty-btn" aria-label="Kurangi {{ $eq->name }}" {{ $qty < 1 ? 'disabled' : '' }}>-</button>
                                                <span class="pos-qty-val">{{ $qty }}</span>
                                                <button type="button" wire:click="incrementEquipment('{{ $eq->id }}')" class="pos-qty-btn" aria-label="Tambah {{ $eq->name }}" {{ $qty >= $eq->stock_quantity ? 'disabled' : '' }}>+</button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif
            </div>
        </div>

        @else
        {{-- ==================== KIRI: TERMINAL PEMBAYARAN (form bersama POS Walk-In) ==================== --}}
        <div class="pos-terminal-card">
            <div class="pos-terminal-header">
                <div>
                    <span class="pos-terminal-eyebrow">TERMINAL KASIR LOKET</span>
                    <div class="pos-terminal-title">Layar Pembayaran &amp; Penyelesaian Transaksi</div>
                </div>
                <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost">&larr; Ubah Pilihan</button>
            </div>

            <div class="pos-terminal-body">
                @if ($this->isTabBill)
                    <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-radius:12px; padding:0.9rem 1rem; color:#1E3A8A; font-size:0.875rem; font-weight:700; line-height:1.5;">
                        Alat untuk sesi coaching #{{ $rentBooking['code'] }} dicatat pada tagihan berkala {{ $rentBooking['academy'] }}. Tidak ada pembayaran EDC / QRIS saat ini; dilunasi bersama tagihan melalui Pelunasan Tagihan Coaching.
                    </div>
                @else
                    {{-- Denda: hanya bukti EDC / QRIS yang diketik kasir (tanpa Bayar Otomatis). --}}
                    @include('pos.partials.payment-method-form', ['grandTotal' => $this->grandTotal, 'qrisMidtrans' => $tab !== 'denda'])
                @endif

                <div class="pos-terminal-actions">
                    <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost" style="height:50px; padding:0 1.2rem;">&larr; Ubah Pilihan</button>
                    <button type="button" x-on:click="$dispatch('open-modal', { id: 'proshop-confirm-payment' })" wire:loading.attr="disabled" wire:target="submit" class="pos-submit-btn" style="flex:1;">
                        <span wire:loading.remove wire:target="submit">{{ $this->isTabBill ? 'Catat ke Tagihan Berkala' : 'Bayar Lunas & Cetak Struk' }}</span>
                        <span wire:loading wire:target="submit">Memproses Transaksi...</span>
                    </button>
                </div>
            </div>
        </div>
        @endif

        {{-- ==================== KANAN: RINGKASAN ==================== --}}
        <div class="pos-panel-card">
            <div class="pos-panel-header">
                <div>
                    <div class="pos-panel-eyebrow">{{ $posStep === 'payment' ? 'LANGKAH 2 DARI 2' : 'Kasir Pro Shop' }}</div>
                    <div class="pos-panel-title">{{ $posStep === 'payment' ? 'Ringkasan Tagihan' : 'Rincian Transaksi' }}</div>
                </div>
                <div>
                    <div class="pos-panel-total">{{ $rupiah($this->grandTotal) }}</div>
                    @if ($tab !== 'denda')
                        <div class="pos-panel-count">{{ $this->itemCount }} {{ $tab === 'jual' ? 'barang' : 'alat' }}</div>
                    @endif
                </div>
            </div>

            <div class="pos-panel-body">
                <div class="pos-summary-box">
                    @if ($tab === 'jual')
                        <div class="pos-summary-label">Pembeli</div>
                        <div style="font-weight:900; color:#4F2F2A;">{{ trim($buyerName) ?: 'Belum diisi' }}</div>
                        <div style="color:#7A5A52;">{{ trim($buyerPhone) ?: '-' }}</div>
                    @elseif ($tab === 'sewa')
                        <div class="pos-summary-label">Booking</div>
                        @if ($rentBooking)
                            <div style="font-weight:900; color:#4F2F2A;">{{ $rentBooking['customer'] }} &middot; #{{ $rentBooking['code'] }}</div>
                            <div style="color:#7A5A52;">{{ $rentBooking['court'] }} &bull; {{ $rentBooking['schedule'] }}</div>
                        @else
                            <div style="color:#A08F86; font-style:italic;">Belum dipilih</div>
                        @endif
                    @else
                        <div class="pos-summary-label">Denda</div>
                        @if ($fineBill)
                            <div style="font-weight:900; color:#4F2F2A;">{{ $fineBill['customer'] }}</div>
                            <div style="color:#7A5A52;">{{ $fineBill['court'] }} &bull; {{ $fineBill['schedule'] }}</div>
                        @else
                            <div style="color:#A08F86; font-style:italic;">Belum dipilih</div>
                        @endif
                    @endif
                </div>

                @if ($tab !== 'denda')
                    <div class="pos-total-box" data-proshop-total style="display:flex; flex-direction:column; gap:0.35rem;">
                        @forelse ($quote['items'] ?? [] as $item)
                            @if ($tab === 'jual' && $posStep === 'selection')
                                {{-- Tab Jual: jumlah diubah di keranjang (kartu etalase hanya menambah). --}}
                                @php
                                    $isMerch = $item['kind'] === 'MERCH';
                                    $lineId = $isMerch ? $item['variant_id'] : $item['equipment_id'];
                                @endphp
                                <div class="pos-cart-line" wire:key="proshop-line-{{ $lineId }}" data-cart-line="{{ $item['name'] }}">
                                    <div class="pos-cart-line-top">
                                        <span class="pos-cart-line-name">{{ $item['name'] }}</span>
                                        <span class="pos-cart-line-total">{{ $rupiah($item['subtotal']) }}</span>
                                    </div>
                                    <div class="pos-cart-line-bottom">
                                        <span class="pos-cart-line-unit">{{ $rupiah($item['unit_price']) }} / unit</span>
                                        <div class="pos-qty-ctrl">
                                            <button type="button" wire:click="{{ $isMerch ? 'decrementMerch' : 'decrementEquipment' }}('{{ $lineId }}')" class="pos-qty-btn" aria-label="Kurangi {{ $item['name'] }}">-</button>
                                            <span class="pos-qty-val">{{ $item['quantity'] }}</span>
                                            <button type="button" wire:click="{{ $isMerch ? 'incrementMerch' : 'incrementEquipment' }}('{{ $lineId }}')" class="pos-qty-btn" aria-label="Tambah {{ $item['name'] }}">+</button>
                                            <button type="button" wire:click="removeCartItem('{{ $item['kind'] }}', '{{ $lineId }}')" class="pos-cart-remove" aria-label="Hapus {{ $item['name'] }}">&times;</button>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div class="pos-sum-row"><span>{{ $item['quantity'] }}&times; {{ $item['name'] }}</span><span>{{ $rupiah($item['subtotal']) }}</span></div>
                            @endif
                        @empty
                            <div class="pos-sum-row"><span style="font-style:italic;">{{ $tab === 'jual' ? 'Belum ada barang dipilih' : 'Belum ada alat dipilih' }}</span><span>{{ $rupiah(0) }}</span></div>
                        @endforelse
                        @if (($quote['tax_amount'] ?? 0) > 0)
                            <div class="pos-sum-row"><span>{{ \App\Models\Pos\ClubFinanceSetting::getSettings()->tax_name ?: 'Pajak' }}</span><span>{{ $rupiah($quote['tax_amount']) }}</span></div>
                        @endif
                        @if (($quote['service_charge'] ?? 0) > 0)
                            <div class="pos-sum-row"><span>{{ \App\Models\Pos\ClubFinanceSetting::getSettings()->admin_fee_name ?: 'Biaya Layanan' }}</span><span>{{ $rupiah($quote['service_charge']) }}</span></div>
                        @endif
                        <div class="pos-sum-total">
                            <span>{{ $this->isTabBill ? 'Ditambahkan ke Tagihan' : 'Total' }}</span>
                            <span>{{ $rupiah($this->grandTotal) }}</span>
                        </div>
                        @if ($this->isTabBill && $quote)
                            <div style="font-size:0.75rem; color:#1E3A8A;">Tagihan {{ $rentBooking['academy'] }}: {{ $rupiah($quote['tab_before']) }} &rarr; {{ $rupiah($quote['tab_after']) }}</div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="pos-panel-footer">
                @if ($posStep === 'selection')
                    <button type="button" wire:click="proceedToPayment" class="pos-submit-btn">Lanjut ke Pembayaran &rarr;</button>
                @else
                    <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost" style="width:100%; height:46px;">&larr; Ubah Pilihan</button>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if ($posStep === 'payment')
        @php
            $confirmMethod = $this->isTabBill
                ? 'Dicatat pada tagihan '.$rentBooking['academy']
                : match ($paymentMethod) {
                    'DEBIT_CARD', 'DEBIT' => 'Kartu Debit (EDC)',
                    'CREDIT_CARD', 'CREDIT' => 'Kartu Kredit (EDC)',
                    'QRIS', 'QRIS_STATIS' => $tab !== 'denda' && $qrisMode === 'MIDTRANS' ? 'QRIS (QR otomatis)' : 'QRIS Kasir Frontdesk',
                    default => $paymentMethod,
                };
            $confirmName = match ($tab) {
                'sewa' => $rentBooking['customer'] ?? '-',
                'denda' => $fineBill['customer'] ?? '-',
                default => trim($buyerName) ?: '-',
            };
        @endphp
        <x-filament::modal id="proshop-confirm-payment" width="lg" :close-by-clicking-away="false">
            <x-slot name="heading">Pastikan Pesanan Sudah Benar</x-slot>
            <x-slot name="description">Periksa bersama customer sebelum pembayaran diproses.</x-slot>

            <div class="pos-confirm">
                <div class="pos-confirm-row"><span>Atas nama</span><strong>{{ $confirmName }}</strong></div>
                <div class="pos-confirm-row"><span>Transaksi</span>
                    <strong>
                        @if ($tab === 'jual')
                            <span class="pos-confirm-line">Penjualan Pro Shop tanpa booking</span>
                            @if (trim($buyerPhone) !== '')
                                <span class="pos-confirm-line">WhatsApp {{ trim($buyerPhone) }}</span>
                            @endif
                        @elseif ($tab === 'sewa')
                            <span class="pos-confirm-line">Tambah alat untuk #{{ $rentBooking['code'] ?? '-' }}</span>
                            <span class="pos-confirm-line">{{ $rentBooking['court'] ?? '' }} &bull; {{ $rentBooking['schedule'] ?? '' }}</span>
                        @else
                            <span class="pos-confirm-line">Denda {{ $fineBill['court'] ?? '' }}</span>
                            <span class="pos-confirm-line">{{ $fineBill['schedule'] ?? '' }}</span>
                        @endif
                    </strong>
                </div>
                @if ($tab !== 'denda')
                    <div class="pos-confirm-row"><span>{{ $tab === 'jual' ? 'Barang' : 'Alat' }}</span>
                        <strong>
                            @foreach ($quote['items'] ?? [] as $item)
                                <span class="pos-confirm-line">{{ $item['quantity'] }}&times; {{ $item['name'] }}</span>
                            @endforeach
                        </strong>
                    </div>
                @endif
                <div class="pos-confirm-row"><span>Metode bayar</span><strong>{{ $confirmMethod }}</strong></div>
                <div class="pos-confirm-total"><span>Total</span><strong>{{ $rupiah($this->grandTotal) }}</strong></div>
            </div>

            <x-slot name="footer">
                <div class="pos-confirm-actions">
                    <button type="button" class="pos-btn pos-btn-ghost" x-on:click="$dispatch('close-modal', { id: 'proshop-confirm-payment' })">Periksa Lagi</button>
                    <button type="button" class="pos-btn pos-btn-primary" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        x-on:click="$dispatch('close-modal', { id: 'proshop-confirm-payment' })">
                        {{ $this->isTabBill ? 'Ya, Catat ke Tagihan Berkala' : 'Ya, Proses Pembayaran' }}
                    </button>
                </div>
            </x-slot>
        </x-filament::modal>
    @endif

    {{-- Popup pilih varian (ukuran / warna) + jumlah — barang merchandise dengan lebih dari satu varian. --}}
    @if ($posStep === 'selection' && $pickerProductId && ($pp = $this->pickerProduct))
        @php
            $picked = $pp->variants->firstWhere('id', $pickerVariantId);
            $room = $this->pickerRoom();
            $unit = $picked ? (float) $pp->base_price + (float) $picked->additional_price : 0;
        @endphp
        <div class="pos-picker-backdrop" wire:click.self="closeVariantPicker" x-data x-on:keydown.escape.window="$wire.closeVariantPicker()">
            <div class="pos-picker" role="dialog" aria-modal="true" aria-label="Pilih varian {{ $pp->name }}" data-variant-picker="{{ $pp->name }}">
                <div class="pos-picker-head">
                    <div class="pos-picker-title">
                        @if ($pp->imageUrl())
                            <img src="{{ $pp->imageUrl() }}" alt="" class="pos-merch-thumb">
                        @endif
                        <div style="min-width:0;">
                            <div class="pos-plan-code">{{ $pp->categoryLabel() }}</div>
                            <div class="pos-picker-name">{{ $pp->name }}</div>
                        </div>
                    </div>
                    <button type="button" wire:click="closeVariantPicker" class="pos-picker-close" aria-label="Tutup">&times;</button>
                </div>

                <div class="pos-picker-body">
                    <div class="pos-section-label">Pilih Warna / Ukuran</div>
                    <div class="pos-picker-variants">
                        @foreach ($pp->variants as $v)
                            @php
                                $inCart = (int) ($merchQuantities[$v->id] ?? 0);
                                $vPrice = (float) $pp->base_price + (float) $v->additional_price;
                            @endphp
                            <button type="button" wire:key="proshop-pick-{{ $v->id }}" wire:click="selectPickerVariant('{{ $v->id }}')" data-merch-variant="{{ $v->sku }}"
                                class="pos-picker-variant {{ $pickerVariantId === $v->id ? 'active' : '' }}" {{ $v->stock_quantity - $inCart < 1 ? 'disabled' : '' }}>
                                <span class="pos-picker-variant-name">{{ $v->label() }}</span>
                                <span class="pos-picker-variant-meta">
                                    {{ $v->stock_quantity < 1 ? 'Habis' : 'Stok '.$v->stock_quantity }}{{ $inCart > 0 ? ' · '.$inCart.' di keranjang' : '' }}
                                </span>
                                @if ((float) $v->additional_price != 0)
                                    <span class="pos-picker-variant-meta">{{ $rupiah($vPrice) }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    <div class="pos-picker-qty">
                        <div>
                            <div class="pos-section-label" style="margin-bottom:0.1rem;">Jumlah</div>
                            <div class="pos-picker-variant-meta">{{ $picked ? $rupiah($unit).' / unit' : 'Pilih varian dulu' }}</div>
                        </div>
                        <div class="pos-qty-ctrl">
                            <button type="button" wire:click="changePickerQty(-1)" class="pos-qty-btn" aria-label="Kurangi jumlah" {{ $pickerQty <= 1 ? 'disabled' : '' }}>-</button>
                            <span class="pos-qty-val" data-picker-qty>{{ $pickerQty }}</span>
                            <button type="button" wire:click="changePickerQty(1)" class="pos-qty-btn" aria-label="Tambah jumlah" {{ $pickerQty >= $room ? 'disabled' : '' }}>+</button>
                        </div>
                    </div>
                </div>

                <div class="pos-picker-foot">
                    <button type="button" wire:click="closeVariantPicker" class="pos-btn pos-btn-ghost">Batal</button>
                    <button type="button" wire:click="confirmVariantPicker" wire:loading.attr="disabled" wire:target="confirmVariantPicker" class="pos-btn pos-btn-primary" style="flex:1;"
                        {{ ! $picked || $room < 1 ? 'disabled' : '' }}>
                        Tambah ke Keranjang{{ $picked ? ' · '.$rupiah($unit * min($pickerQty, max(1, $room))) : '' }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Rekap Penjualan Harian (X-Report) — tombol Cetak Rekap di Riwayat --}}
    @include('pos.partials.sales-recap-modal')

    {{-- QR Bayar Otomatis menunggu dibayar customer --}}
    @include('pos.partials.auto-pay-modal', ['pendingQris' => $pendingQris, 'pollAction' => 'pollPendingQris', 'cancelAction' => 'cancelPendingQris', 'simulateAction' => 'simulatePendingQrisPaid'])

    {{-- Struk (partial yang sama dengan POS Walk-In) --}}
    @if ($showSuccessModal && $completedOrderData)
        <div style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
            <div style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:18px; box-shadow:0 25px 50px -12px rgba(102,39,33,0.4); width:100%; max-width:440px; overflow:hidden;">
                <div style="background:#FCF8EE; border-bottom:1.5px solid #E6DAC0; padding:0.85rem 1.1rem; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em; background:#FCF8EE; border:1px solid #E6DAC0; border-radius:4px; padding:0.08rem 0.4rem; display:inline-block;">{{ $receiptFromHistory ? 'CETAK ULANG' : 'TRANSAKSI LUNAS' }}</div>
                        <div style="font-size:1rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">Struk Kasir Pro Shop</div>
                    </div>
                    <button type="button" wire:click="closeSuccessModal" style="background:none; border:none; font-size:1.25rem; color:#78350F; cursor:pointer; line-height:1;">&times;</button>
                </div>

                @include('filament.partials.walkin-receipt', ['receipt' => $completedOrderData])

                <div style="background:#FCF8EE; border-top:1px solid #E6DAC0; padding:0.65rem 1.1rem; display:flex; justify-content:flex-end; gap:0.5rem;">
                    <button type="button" onclick="club61PrintReceipt('#printable-pos-receipt')"
                        style="padding:0.4rem 0.85rem; font-size:0.8125rem; font-weight:800; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:7px; cursor:pointer; color:#4F2F2A;">Cetak Struk</button>
                    <button type="button" wire:click="closeSuccessModal"
                        style="padding:0.4rem 0.85rem; font-size:0.8125rem; font-weight:800; background:#662721; color:#F7F0DB; border:1px solid #662721; border-radius:7px; cursor:pointer;">{{ $receiptFromHistory ? 'Tutup' : 'Transaksi Baru' }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
