<div class="c61 bp-wrap">
    @include('filament.partials.c61-admin-style')
    {{-- Gaya khusus halaman Biaya & Pajak (toggle, segmented metode, simulator struk). --}}
    <style>
        .bp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; align-items: start; }
        @media (max-width: 1024px) { .bp-grid { grid-template-columns: 1fr; } }
        .bp-card-head { align-items: flex-start; flex-wrap: nowrap; }
        .bp-card-head > div:first-child { min-width: 0; }
        .bp-body { display: flex; flex-direction: column; gap: 1.1rem; }
        .bp-body .c61-label { margin-bottom: 0.4rem; }

        /* Toggle aktif/nonaktif */
        .bp-toggle { display: inline-flex; align-items: center; gap: 0.6rem; cursor: pointer; user-select: none; flex-shrink: 0; padding: 0.3rem 0.35rem 0.3rem 0.7rem; border-radius: 999px; border: 1px solid var(--c-line); background: var(--c-paper); }
        .bp-toggle:hover { border-color: #D8C6A4; }
        .bp-toggle-label { font-size: 0.6875rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
        .bp-toggle-on { color: #047857; }
        .bp-toggle-off { color: var(--c-faint); }
        .bp-switch { position: relative; width: 44px; height: 24px; border-radius: 999px; background: #E7DFD0; border: 1px solid #D8CDB8; transition: background-color 0.2s ease, border-color 0.2s ease; }
        .bp-switch.on { background: #059669; border-color: #047857; }
        .bp-switch-knob { position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: #FFFFFF; box-shadow: 0 1px 3px rgba(42, 20, 16, 0.25); transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .bp-switch.on .bp-switch-knob { transform: translateX(20px); }

        /* Pilihan metode (2 kolom sama lebar) */
        .bp-seg { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px; padding: 4px; background: var(--c-paper); border: 1px solid var(--c-line); border-radius: 12px; }
        .bp-seg-btn { height: 36px; padding: 0 0.6rem; border-radius: 9px; border: none; background: transparent; color: var(--c-muted); font-size: 0.8125rem; font-weight: 700; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: background 0.15s, color 0.15s; }
        .bp-seg-btn:hover { color: var(--c-terra); background: #FFFFFF; }
        .bp-seg-btn.active { background: var(--c-terra); color: var(--c-cream); }

        /* Input angka dengan satuan */
        .bp-affix { position: relative; }
        .bp-affix .c61-input { height: 46px; padding-right: 3.5rem; font-family: var(--font-mono); font-size: 1.05rem; font-weight: 800; color: var(--c-terra); }
        .bp-affix-unit { position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); min-width: 40px; height: 28px; padding: 0 0.45rem; border-radius: 7px; background: var(--c-cream); color: var(--c-terra); font-size: 0.75rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; pointer-events: none; }

        /* Kotak saat nonaktif */
        .bp-off { border: 1px dashed var(--c-line); border-radius: 14px; background: var(--c-paper); padding: 2rem 1.25rem; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 0.4rem; }
        .bp-off-title { font-family: var(--font-serif); font-size: 1rem; font-weight: 600; color: var(--c-brown); }
        .bp-off-text { font-size: 0.75rem; color: var(--c-muted); max-width: 340px; line-height: 1.5; }

        /* Simulator */
        .bp-sim-head { align-items: flex-end; }
        .bp-sim-head .c61-pill { margin-bottom: 0.45rem; }
        .bp-sim-ctrl { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
        .bp-sim-ctrl-label { font-size: 0.75rem; font-weight: 700; color: var(--c-muted); margin-right: 0.15rem; }
        .bp-sim-input { display: inline-flex; align-items: center; height: 32px; border: 1px solid var(--c-line); border-radius: 8px; background: var(--c-paper); overflow: hidden; padding-left: 0.6rem; }
        .bp-sim-input:focus-within { border-color: var(--c-terra); box-shadow: 0 0 0 3px rgba(102, 39, 33, 0.12); background: #FFFFFF; }
        .bp-sim-input span { font-size: 0.6875rem; font-weight: 800; color: var(--c-terra); }
        .bp-sim-input input { width: 110px; height: 100%; padding: 0 0.5rem; border: none; outline: none; background: transparent; box-shadow: none; font-family: var(--font-mono); font-weight: 800; font-size: 0.8125rem; color: var(--c-brown); }
        .bp-sim-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (max-width: 900px) { .bp-sim-grid { grid-template-columns: 1fr; } }

        .bp-slip { background: var(--c-paper); border: 1px solid var(--c-line); border-radius: 14px; padding: 1.1rem 1.15rem; display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }
        .bp-slip-head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .bp-slip-title { font-family: var(--font-serif); font-size: 1rem; font-weight: 600; color: var(--c-brown); }
        .bp-slip-row { display: flex; justify-content: space-between; align-items: baseline; gap: 0.75rem; font-size: 0.8125rem; color: var(--c-brown); padding: 0.5rem 0; border-bottom: 1px dashed var(--c-line); }
        .bp-slip-row > span:first-child { min-width: 0; overflow-wrap: anywhere; }
        .bp-rate { font-size: 0.6875rem; font-weight: 700; }
        .bp-rate-tax { color: #047857; }
        .bp-rate-fee { color: var(--c-terra); }
        .bp-amt { font-family: var(--font-mono); font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--c-brown); }
        .bp-amt.is-tax { color: #047857; }
        .bp-amt.is-fee { color: var(--c-terra); }
        .bp-amt.is-off { color: var(--c-faint); font-weight: 700; }
        .bp-slip-total { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-top: auto; padding: 0.85rem 1rem; border-radius: 12px; background: #FFFFFF; border: 1px solid var(--c-line); }
        .bp-slip-total-k { font-size: 0.75rem; font-weight: 800; letter-spacing: 0.04em; color: var(--c-brown); }
        .bp-slip-total-s { font-size: 0.6875rem; color: var(--c-muted); margin-top: 0.15rem; }
        .bp-slip-total-v { font-family: var(--font-serif); font-size: 1.4rem; font-weight: 600; line-height: 1.1; white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--c-terra); }
        .bp-slip-total-v.is-pos { color: #1D4ED8; }

        .bp-precision { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem 1rem; flex-wrap: wrap; }
        .bp-precision > div { flex: 1 1 320px; min-width: 0; }

        /* Bar simpan bawah */
        .bp-savebar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 1.1rem 1.25rem; background: var(--c-paper); }
        .bp-savebar-title { font-family: var(--font-serif); font-size: 1.0625rem; font-weight: 600; color: var(--c-brown); }
        .bp-savebar-sub { font-size: 0.75rem; color: var(--c-muted); margin-top: 0.2rem; }
    </style>

    {{-- HEADER BANNER --}}
    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span>Konfigurasi Keuangan &bull; Sentral Finansial</div>
            <div class="c61-hero-title">Pengaturan Biaya Layanan &amp; Pajak Daerah</div>
            <div class="c61-hero-sub">
                Atur tarif Pajak Daerah (PB1 / PPh) dan Biaya Layanan Transaksi (Admin Fee). Perubahan di sini otomatis berlaku untuk seluruh transaksi booking online (Midtrans), kasir walk-in POS, dan modul klub lainnya.
            </div>
        </div>

        <div class="c61-hero-actions">
            <button type="button" wire:click="saveSettings" class="c61-btn c61-btn-cream c61-btn-lg">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7"/></svg>
                <span>Simpan Pengaturan</span>
            </button>
        </div>
    </div>

    {{-- DUA KARTU PENGATURAN UTAMA --}}
    <div class="bp-grid">

        {{-- ================= KARTU 1: PAJAK DAERAH ================= --}}
        <div class="c61-card">
            <div class="c61-card-head bp-card-head">
                <div>
                    <div class="c61-card-title">1. Pajak (PB1 / PPh / PPN)</div>
                    <div class="c61-card-sub">Pajak resmi atas fasilitas sewa lapangan padel &amp; layanan klub.</div>
                </div>

                {{-- Toggle Switch --}}
                <div class="bp-toggle" wire:click="toggleTax">
                    <span class="bp-toggle-label {{ $isTaxEnabled ? 'bp-toggle-on' : 'bp-toggle-off' }}">
                        {{ $isTaxEnabled ? 'AKTIF' : 'NONAKTIF' }}
                    </span>
                    <div class="bp-switch {{ $isTaxEnabled ? 'on' : '' }}">
                        <div class="bp-switch-knob"></div>
                    </div>
                </div>
            </div>

            <div class="c61-card-body bp-body">
            @if($isTaxEnabled)
                {{-- Nama Label Pajak --}}
                <div>
                    <label class="c61-label">Label Nama Pajak di Struk &amp; Invoice</label>
                    <input type="text" wire:model="taxName" class="c61-input" placeholder="Contoh: PB1 Pajak Daerah (10%)">
                    <div class="c61-hint">Nama ini akan tercetak jelas di struk kasir termal dan invoice digital pelanggan.</div>
                </div>

                {{-- Metode Hitung: Persentase vs Nominal Tetap --}}
                <div>
                    <label class="c61-label">Metode Perhitungan Pajak</label>
                    <div class="bp-seg">
                        <button type="button" wire:click="setTaxType('PERCENTAGE')"
                            class="bp-seg-btn {{ $taxType === 'PERCENTAGE' ? 'active' : '' }}">
                            Persentase (%)
                        </button>
                        <button type="button" wire:click="setTaxType('FIXED')"
                            class="bp-seg-btn {{ $taxType === 'FIXED' ? 'active' : '' }}">
                            Nominal Tetap (Rp)
                        </button>
                    </div>
                </div>

                {{-- Nilai Tarif --}}
                <div>
                    <label class="c61-label">
                        {{ $taxType === 'PERCENTAGE' ? 'Besar Tarif Pajak (%)' : 'Nominal Pajak per Transaksi (Rp)' }}
                    </label>
                    <div class="bp-affix">
                        <input type="number" step="{{ $taxType === 'PERCENTAGE' ? '0.5' : '1000' }}" wire:model.live="taxRate" class="c61-input">
                        <span class="bp-affix-unit">
                            {{ $taxType === 'PERCENTAGE' ? '%' : 'IDR' }}
                        </span>
                    </div>
                    <div class="c61-hint">
                        {{ $taxType === 'PERCENTAGE' ? 'Pajak dihitung otomatis dari total subtotal sewa lapangan dan peralatan.' : 'Nominal tetap yang ditambahkan ke setiap transaksi tanpa melihat durasi main.' }}
                    </div>
                </div>

                {{-- Target Kanal --}}
                <div>
                    <label class="c61-label">Saluran Transaksi yang Dikenakan Pajak</label>
                    <select wire:model.live="taxChannels" class="c61-select">
                        <option value="ALL">Semua Transaksi (Booking Online &amp; Kasir Frontdesk POS)</option>
                        <option value="ONLINE_ONLY">Hanya Booking Online (Website / Mobile via Midtrans)</option>
                        <option value="POS_ONLY">Hanya Transaksi Langsung di Kasir POS Venue</option>
                    </select>
                </div>
            @else
                <div class="bp-off">
                    <div class="bp-off-title">Pajak Sedang Dinonaktifkan</div>
                    <div class="bp-off-text">
                        Pelanggan tidak akan dikenakan biaya pajak. Klik toggle di pojok kanan atas untuk mengaktifkan tarif PB1 / PPh.
                    </div>
                </div>
            @endif
            </div>
        </div>

        {{-- ================= KARTU 2: BIAYA LAYANAN / ADMIN ================= --}}
        <div class="c61-card">
            <div class="c61-card-head bp-card-head">
                <div>
                    <div class="c61-card-title">2. Biaya Layanan / Admin Fee</div>
                    <div class="c61-card-sub">Biaya pemrosesan transaksi, sistem administrasi, atau payment gateway.</div>
                </div>

                {{-- Toggle Switch --}}
                <div class="bp-toggle" wire:click="toggleAdminFee">
                    <span class="bp-toggle-label {{ $isAdminFeeEnabled ? 'bp-toggle-on' : 'bp-toggle-off' }}">
                        {{ $isAdminFeeEnabled ? 'AKTIF' : 'NONAKTIF' }}
                    </span>
                    <div class="bp-switch {{ $isAdminFeeEnabled ? 'on' : '' }}">
                        <div class="bp-switch-knob"></div>
                    </div>
                </div>
            </div>

            <div class="c61-card-body bp-body">
            @if($isAdminFeeEnabled)
                {{-- Nama Label Biaya --}}
                <div>
                    <label class="c61-label">Label Nama Biaya di Struk &amp; Invoice</label>
                    <input type="text" wire:model="adminFeeName" class="c61-input" placeholder="Contoh: Biaya Layanan / Admin">
                    <div class="c61-hint">Nama item rincian biaya yang akan terlihat oleh pelanggan saat checkout.</div>
                </div>

                {{-- Metode Hitung: Nominal Tetap vs Persentase --}}
                <div>
                    <label class="c61-label">Metode Perhitungan Biaya</label>
                    <div class="bp-seg">
                        <button type="button" wire:click="setAdminFeeType('FIXED')"
                            class="bp-seg-btn {{ $adminFeeType === 'FIXED' ? 'active' : '' }}">
                            Nominal Tetap (Rp)
                        </button>
                        <button type="button" wire:click="setAdminFeeType('PERCENTAGE')"
                            class="bp-seg-btn {{ $adminFeeType === 'PERCENTAGE' ? 'active' : '' }}">
                            Persentase (%)
                        </button>
                    </div>
                </div>

                {{-- Nilai Biaya --}}
                <div>
                    <label class="c61-label">
                        {{ $adminFeeType === 'FIXED' ? 'Nominal Biaya Admin per Transaksi (Rp)' : 'Persentase Biaya Admin (%)' }}
                    </label>
                    <div class="bp-affix">
                        <input type="number" step="{{ $adminFeeType === 'FIXED' ? '500' : '0.5' }}" wire:model.live="adminFeeAmount" class="c61-input">
                        <span class="bp-affix-unit">
                            {{ $adminFeeType === 'FIXED' ? 'IDR' : '%' }}
                        </span>
                    </div>
                    <div class="c61-hint">
                        {{ $adminFeeType === 'FIXED' ? 'Contoh: Rp 2.500 per booking untuk menutupi biaya payment gateway online.' : 'Biaya admin dihitung proporsional dari nilai transaksi.' }}
                    </div>
                </div>

                {{-- Target Kanal --}}
                <div>
                    <label class="c61-label">Saluran Transaksi yang Dikenakan Biaya</label>
                    <select wire:model.live="adminFeeChannels" class="c61-select">
                        <option value="ONLINE_ONLY">Hanya Booking Online (Midtrans QRIS / Virtual Account)</option>
                        <option value="POS_ONLY">Hanya Transaksi di Kasir Frontdesk POS</option>
                        <option value="ALL">Semua Transaksi (Online &amp; Kasir POS)</option>
                    </select>
                </div>
            @else
                <div class="bp-off">
                    <div class="bp-off-title">Biaya Layanan Sedang Dinonaktifkan</div>
                    <div class="bp-off-text">
                        Pelanggan tidak dibebankan biaya layanan/admin tambahan. Aktifkan toggle di atas jika ingin menambahkan biaya per transaksi.
                    </div>
                </div>
            @endif
            </div>
        </div>

    </div>

    {{-- ================= KARTU 3: SIMULATOR TRANSAKSI REAL-TIME ================= --}}
    @php
        $sim = $this->simulationResult;
    @endphp

    <div class="c61-card">
        <div class="c61-card-head bp-sim-head">
            <div>
                <span class="c61-pill c61-pill-terra">Pratinjau Hasil Nyata</span>
                <div class="c61-card-title">
                    Simulasi Rincian Tagihan Pelanggan (Live Calculator)
                </div>
                <div class="c61-card-sub">
                    Lihat persis bagaimana angka tagihan dihitung pada layar pelanggan dan kasir dengan pengaturan saat ini.
                </div>
            </div>

            {{-- Pengontrol Nominal Uji Coba --}}
            <div class="bp-sim-ctrl">
                <span class="bp-sim-ctrl-label">Pilih Contoh Sewa:</span>
                <button type="button" wire:click="setSimulationSubtotal(200000)" class="c61-btn c61-btn-ghost c61-btn-sm">Rp 200.000</button>
                <button type="button" wire:click="setSimulationSubtotal(300000)" class="c61-btn c61-btn-ghost c61-btn-sm">Rp 300.000</button>
                <button type="button" wire:click="setSimulationSubtotal(500000)" class="c61-btn c61-btn-ghost c61-btn-sm">Rp 500.000</button>
                <div class="bp-sim-input">
                    <span>Rp</span>
                    <input type="number" step="10000" wire:model.live="simulationSubtotal">
                </div>
            </div>
        </div>

        <div class="c61-card-body bp-body">
            {{-- 2 Komparasi Kartu Struk: Online vs Kasir POS --}}
            <div class="bp-sim-grid">

                {{-- Kolom Kiri: Booking Online --}}
                <div class="bp-slip">
                    <div class="bp-slip-head">
                        <div class="bp-slip-title">Kanal Booking Online</div>
                        <span class="c61-pill c61-pill-warn">Website &bull; Mobile &bull; Midtrans</span>
                    </div>

                    <div>
                        <div class="bp-slip-row">
                            <span>Sewa Lapangan &amp; Peralatan</span>
                            <span class="bp-amt">Rp {{ number_format($sim['subtotal'], 0, ',', '.') }}</span>
                        </div>
                        <div class="bp-slip-row">
                            <span>
                                {{ $isTaxEnabled ? $taxName : 'Pajak Daerah' }}
                                @if($isTaxEnabled && $sim['online']['tax'] > 0)
                                    <span class="bp-rate bp-rate-tax">({{ $taxType === 'PERCENTAGE' ? $taxRate.'%' : 'Tetap' }})</span>
                                @endif
                            </span>
                            <span class="bp-amt {{ $sim['online']['tax'] > 0 ? 'is-tax' : 'is-off' }}">
                                {{ $sim['online']['tax'] > 0 ? '+ Rp '.number_format($sim['online']['tax'], 0, ',', '.') : 'Rp 0 (Nonaktif)' }}
                            </span>
                        </div>
                        <div class="bp-slip-row">
                            <span>
                                {{ $isAdminFeeEnabled ? $adminFeeName : 'Biaya Layanan' }}
                                @if($isAdminFeeEnabled && $sim['online']['admin'] > 0)
                                    <span class="bp-rate bp-rate-fee">({{ $adminFeeType === 'PERCENTAGE' ? $adminFeeAmount.'%' : 'Tetap' }})</span>
                                @endif
                            </span>
                            <span class="bp-amt {{ $sim['online']['admin'] > 0 ? 'is-fee' : 'is-off' }}">
                                {{ $sim['online']['admin'] > 0 ? '+ Rp '.number_format($sim['online']['admin'], 0, ',', '.') : 'Rp 0 (Nonaktif)' }}
                            </span>
                        </div>
                    </div>

                    <div class="bp-slip-total">
                        <div>
                            <div class="bp-slip-total-k">TOTAL BAYAR CUSTOMER:</div>
                            <div class="bp-slip-total-s">Nominal yang dipotong dari saldo / QRIS Midtrans</div>
                        </div>
                        <span class="bp-slip-total-v">
                            Rp {{ number_format($sim['online']['grand_total'], 0, ',', '.') }}
                        </span>
                    </div>
                </div>

                {{-- Kolom Kanan: Kasir Frontdesk POS --}}
                <div class="bp-slip">
                    <div class="bp-slip-head">
                        <div class="bp-slip-title">Kanal Kasir Frontdesk POS</div>
                        <span class="c61-pill c61-pill-info">Walk-In &bull; Struk Termal POS</span>
                    </div>

                    <div>
                        <div class="bp-slip-row">
                            <span>Sewa Lapangan &amp; Peralatan</span>
                            <span class="bp-amt">Rp {{ number_format($sim['subtotal'], 0, ',', '.') }}</span>
                        </div>
                        <div class="bp-slip-row">
                            <span>
                                {{ $isTaxEnabled ? $taxName : 'Pajak Daerah' }}
                                @if($isTaxEnabled && $sim['pos']['tax'] > 0)
                                    <span class="bp-rate bp-rate-tax">({{ $taxType === 'PERCENTAGE' ? $taxRate.'%' : 'Tetap' }})</span>
                                @endif
                            </span>
                            <span class="bp-amt {{ $sim['pos']['tax'] > 0 ? 'is-tax' : 'is-off' }}">
                                {{ $sim['pos']['tax'] > 0 ? '+ Rp '.number_format($sim['pos']['tax'], 0, ',', '.') : 'Rp 0 (Nonaktif)' }}
                            </span>
                        </div>
                        <div class="bp-slip-row">
                            <span>
                                {{ $isAdminFeeEnabled ? $adminFeeName : 'Biaya Layanan' }}
                                @if($isAdminFeeEnabled && $sim['pos']['admin'] > 0)
                                    <span class="bp-rate bp-rate-fee">({{ $adminFeeType === 'PERCENTAGE' ? $adminFeeAmount.'%' : 'Tetap' }})</span>
                                @endif
                            </span>
                            <span class="bp-amt {{ $sim['pos']['admin'] > 0 ? 'is-fee' : 'is-off' }}">
                                {{ $sim['pos']['admin'] > 0 ? '+ Rp '.number_format($sim['pos']['admin'], 0, ',', '.') : 'Rp 0 (Nonaktif)' }}
                            </span>
                        </div>
                    </div>

                    <div class="bp-slip-total">
                        <div>
                            <div class="bp-slip-total-k">TOTAL DITERIMA KASIR:</div>
                            <div class="bp-slip-total-s">Nominal yang wajib dibayar di mesin EDC / Tunai</div>
                        </div>
                        <span class="bp-slip-total-v is-pos">
                            Rp {{ number_format($sim['pos']['grand_total'], 0, ',', '.') }}
                        </span>
                    </div>
                </div>

            </div>

            {{-- Info Banner Garansi Pembulatan Eksak --}}
            <div class="c61-note bp-precision">
                <div>
                    <strong>Garansi Presisi Rupiah:</strong> Seluruh perhitungan menggunakan pembulatan bilangan bulat Rupiah murni tanpa desimal sen, sehingga nilai item Midtrans dan kasir dipastikan cocok 100%.
                </div>
                <span class="c61-pill c61-pill-cream">
                    Mata Uang: IDR (Rupiah)
                </span>
            </div>
        </div>
    </div>

    {{-- BOTTOM SAVE BAR --}}
    <div class="c61-card">
        <div class="bp-savebar">
            <div>
                <div class="bp-savebar-title">Simpan dan Terapkan Konfigurasi</div>
                <div class="bp-savebar-sub">
                    Pastikan seluruh tarif sudah sesuai sebelum mengaktifkan ke sistem operasional venue.
                </div>
            </div>

            <button type="button" wire:click="saveSettings" class="c61-btn c61-btn-primary c61-btn-lg">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7"/></svg>
                <span>Simpan Pengaturan Finansial</span>
            </button>
        </div>
    </div>

</div>
