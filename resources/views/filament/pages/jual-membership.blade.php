{{-- POS Jual Membership – frontdesk (tablet first), gaya sama dengan POS Walk-In Booking. --}}
<div class="walkin-pos-root">
    @include('filament.partials.pos-theme-style')
    @include('pos.partials.receipt-print-style', ['selectors' => ['#printable-membership-receipt']])

    {{-- ============================
         BARIS ATAS: tab POS + Kasir/Riwayat + status shift
         ============================ --}}
    <div class="pos-headbar">
        <div class="pos-headbar-left">
            @include('filament.partials.pos-subnav', ['activePos' => 'membership', 'inline' => true])
            @include('filament.partials.pos-history-tabs', ['isHistory' => $posStep === 'history', 'canShowHistory' => $this->canShowHistoryTab, 'inline' => true])
        </div>
        <div class="pos-headbar-right">
            {{-- Penjualan membership masuk shift meja frontdesk yang sama dengan POS Walk-In Padel --}}
            @if ($this->activeShift)
                <span class="pos-shift-chip is-open">
                    <span class="dot"></span>
                    Shift {{ $this->activeShift->shift_number }} aktif
                </span>
            @else
                <a href="{{ route('filament.admin.pages.book-offline-court') }}" class="pos-shift-chip is-closed" style="text-decoration:none;">
                    <span class="dot"></span>
                    Shift kasir belum dibuka <span class="pos-hide-md">&mdash; buka di POS Walk-In Booking</span> &rarr;
                </a>
            @endif
        </div>
    </div>

    {{-- ============================
         TOOLBAR: Judul loket + jumlah paket
         ============================ --}}
    @if ($posStep !== 'history')
    <div class="pos-toolbar">
        <div style="min-width:0;">
            <div style="font-size:0.9375rem; font-weight:900; color:#4F2F2A;">Kasir Penjualan Membership</div>
            <div style="font-size:0.75rem; color:#7A5A52; margin-top:0.1rem;">Terbitkan keanggotaan multi-fasilitas (Padel, Gym, Sauna) untuk pelanggan secara instan.</div>
        </div>
        <div class="pos-kpis">
            <div class="pos-kpi"><span class="pos-kpi-value">{{ $this->plans->count() }}</span><span class="pos-kpi-label">Paket Aktif Tersedia</span></div>
        </div>
    </div>
    @endif

    @if ($posStep === 'history')
        @include('filament.partials.pos-history-table', [
            'rows' => $this->transactionHistory,
            'title' => 'Riwayat Penjualan Membership (Loket)',
            'receiptAction' => 'viewTransactionReceipt',
            'idKey' => 'order_id',
        ])
    @else
    <!-- POS Grid (2 Columns) -->
    <div class="pos-main">

        @if ($posStep === 'selection')
        {{-- ==================== KIRI: PILIH PELANGGAN & PAKET ==================== --}}
        <div class="pos-grid-card">
            <div class="pos-scroll-body">
                {{-- 1. Data pelanggan. Dropdown hasil pencarian = position:absolute di dalam area scroll. --}}
                <section>
                    <div class="pos-step-head">
                        <span class="pos-step-title"><span class="pos-step-num">1</span>Data Pelanggan / Member</span>
                        <div class="pos-tab-group" style="min-width:260px;">
                            <button type="button" wire:click="$set('customerMode', 'quick_create')"
                                class="pos-tab {{ $customerMode === 'quick_create' ? 'active' : '' }}">
                                Walk-In Baru
                            </button>
                            <button type="button" wire:click="$set('customerMode', 'search')"
                                class="pos-tab {{ $customerMode === 'search' ? 'active' : '' }}">
                                Cari Member Lama
                            </button>
                        </div>
                    </div>

                    @if ($selectedCustomerId)
                        <div class="pos-picked-customer">
                            <div style="min-width:0;">
                                <div style="font-weight:900; color:#4F2F2A; font-size:0.9375rem;">
                                    {{ $selectedCustomerName }}</div>
                                <div style="font-size:0.8125rem; color:#7A5A52;">WhatsApp:
                                    {{ $selectedCustomerPhone ?? '-' }}</div>
                            </div>
                            <button type="button" wire:click="clearCustomer" class="pos-btn pos-btn-danger">
                                Ganti
                            </button>
                        </div>
                    @elseif($customerMode === 'search')
                        <div style="position: relative;">
                            <input type="text" wire:model.live.debounce.300ms="customerSearch"
                                placeholder="Ketik nama, no whatsapp, atau email customer..."
                                class="pos-input" autocomplete="off">
                            @if (count($this->searchResults) > 0)
                                <div class="pos-search-results">
                                    @foreach ($this->searchResults as $res)
                                        <button type="button" wire:click="selectCustomer('{{ $res['id'] }}')" class="pos-search-item">
                                            <div style="font-weight: 800; color: #4F2F2A; font-size: 0.875rem;">
                                                {{ $res['name'] }}</div>
                                            <div style="font-size: 0.75rem; color: #7A5A52;">{{ $res['phone'] ?? '-' }}
                                                &bull; {{ $res['email'] }}</div>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="pos-form-grid">
                            <div>
                                <label class="pos-field-label">Nomor WhatsApp *</label>
                                <input type="text" wire:model="walkInPhone" placeholder="0812xxxxxxxx" class="pos-input" autocomplete="off">
                            </div>
                            <div>
                                <label class="pos-field-label">Nama Lengkap</label>
                                <input type="text" wire:model="walkInName" placeholder="Nama Pelanggan" class="pos-input" autocomplete="off">
                            </div>
                        </div>
                    @endif
                </section>

                {{-- 2. Pilih paket --}}
                <section>
                    <div class="pos-step-head">
                        <span class="pos-step-title"><span class="pos-step-num">2</span>Pilih Paket Membership Club 61</span>
                    </div>
                    <div class="pos-plan-grid">
                        @foreach ($this->plans as $plan)
                            @php $isSelected = $selectedPlanId === $plan->id; @endphp
                            <div wire:click="selectPlan('{{ $plan->id }}')" wire:key="plan-card-{{ $plan->id }}"
                                class="pos-plan-card {{ $isSelected ? 'is-selected' : '' }}" role="button" aria-pressed="{{ $isSelected ? 'true' : 'false' }}">
                                @if ($isSelected)
                                    <span class="pos-plan-check"><svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg></span>
                                @endif
                                <div class="pos-plan-top">
                                    <div style="min-width:0;">
                                        <div class="pos-plan-code">{{ $plan->code }}</div>
                                        <div class="pos-plan-name">{{ $plan->name }}</div>
                                    </div>
                                    <span class="pos-plan-days">{{ $plan->duration_days }} Hari</span>
                                </div>

                                <div class="pos-plan-price">Rp {{ number_format($plan->price, 0, ',', '.') }}</div>

                                <div class="pos-plan-benefits">
                                    {{-- Teks benefit dari Master Fasilitas (sama dengan yang dilihat customer). --}}
                                    @foreach (app(\App\Services\Membership\MembershipFacilityService::class)->presentPlan($plan) as $card)
                                        <div>
                                            <strong>{{ $card['badge'] }}:</strong> {{ $card['title'] }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        </div>

        @elseif ($posStep === 'payment')
        {{-- ==================== KIRI: TERMINAL PEMBAYARAN KASIR IN-PAGE (sama persis dengan
             POS Walk-In Booking) ==================== --}}
        <div class="pos-terminal-card">
            <div class="pos-terminal-header">
                <div>
                    <div style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em; background:#FCF8EE; border:1px solid #E6DAC0; border-radius:5px; padding:0.1rem 0.45rem; display:inline-block;">TERMINAL KASIR LOKET</div>
                    <div style="font-size:1.0625rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">Layar Pembayaran &amp; Penyelesaian Transaksi</div>
                </div>
                <button type="button" wire:click="backToSelection"
                    style="padding:0.4rem 0.85rem; font-size:0.75rem; font-weight:800; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:8px; cursor:pointer; color:#4F2F2A; display:flex; align-items:center; gap:0.3rem;">
                    &larr; Ubah Pilihan Paket
                </button>
            </div>

            <div class="pos-terminal-body">
                {{-- Tabs Metode Bayar --}}
                <div>
                    <div style="font-size:0.75rem; font-weight:900; color:#4F2F2A; margin-bottom:0.45rem;">Pilih Metode Pembayaran:</div>
                    <div class="pos-method-selector-grid">
                        <div wire:click="setPaymentMethod('DEBIT_CARD')" class="pos-method-tab {{ in_array($paymentMethod, ['DEBIT_CARD', 'DEBIT']) || ($paymentMethod === 'EDC_BCA' && $edcCardType === 'DEBIT') ? 'active' : '' }}">
                            <div class="pos-method-tab-title">KARTU DEBIT</div>
                            <div class="pos-method-tab-sub">Semua Bank (Via EDC)</div>
                        </div>
                        <div wire:click="setPaymentMethod('CREDIT_CARD')" class="pos-method-tab {{ in_array($paymentMethod, ['CREDIT_CARD', 'CREDIT']) || ($paymentMethod === 'EDC_BCA' && $edcCardType === 'CREDIT') || ($paymentMethod === 'EDC_MANDIRI') ? 'active' : '' }}">
                            <div class="pos-method-tab-title">KARTU KREDIT</div>
                            <div class="pos-method-tab-sub">Visa, MC, JCB, Amex</div>
                        </div>
                        <div wire:click="setPaymentMethod('QRIS')" class="pos-method-tab {{ in_array($paymentMethod, ['QRIS', 'QRIS_STATIS']) ? 'active' : '' }}">
                            <div class="pos-method-tab-title">QRIS</div>
                            <div class="pos-method-tab-sub">QR Code / E-Wallet</div>
                        </div>
                    </div>
                </div>

                {{-- Detail Form Metode Bayar --}}
                {{-- Form KARTU DEBIT --}}
                @if(in_array($paymentMethod, ['DEBIT_CARD', 'DEBIT']) || ($paymentMethod === 'EDC_BCA' && $edcCardType === 'DEBIT'))
                    <div class="pos-pay-content-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #E6DAC0; padding-bottom:0.75rem;">
                            <div>
                                <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A;">
                                    Pembayaran Kartu Debit (Debit Card)
                                </div>
                                <div style="font-size:0.6875rem; color:#7A5A52;">
                                    Gesek, dip, atau tap kartu debit pada mesin EDC fisik kasir lalu catat rincian slip transaksi di bawah ini.
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:0.625rem; font-weight:800; color:#662721; text-transform:uppercase;">Nominal Charge EDC</div>
                                <div style="font-size:1.25rem; font-weight:900; color:#662721;">Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</div>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem;">
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Mesin EDC Fisik *</label>
                                <select wire:model="edcTerminal" class="pos-input" style="background:#FFFFFF; font-weight:700;">
                                    <option value="EDC_BCA">Mesin EDC BCA</option>
                                    <option value="EDC_MANDIRI">Mesin EDC Mandiri</option>
                                    <option value="EDC_LAINNYA">Mesin EDC Lainnya</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Bank Penerbit *</label>
                                <select wire:model="edcBank" class="pos-input" style="background:#FFFFFF; font-weight:700;">
                                    <option value="BCA">BCA</option>
                                    <option value="MANDIRI">Bank Mandiri</option>
                                    <option value="BNI">BNI</option>
                                    <option value="BRI">BRI</option>
                                    <option value="CIMB">CIMB Niaga</option>
                                    <option value="PERMATA">Bank Permata</option>
                                    <option value="DANAMON">Bank Danamon</option>
                                    <option value="BSI">BSI (Bank Syariah Indonesia)</option>
                                    <option value="LAINNYA">Bank Lainnya</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Jaringan Kartu (Scheme)</label>
                                <select wire:model="edcCardNetwork" class="pos-input" style="background:#FFFFFF; font-weight:700;">
                                    <option value="GPN">GPN (Gerbang Pembayaran Nasional)</option>
                                    <option value="MASTERCARD">Mastercard Debit</option>
                                    <option value="VISA">Visa Debit</option>
                                    <option value="LAINNYA">Debit Lainnya</option>
                                </select>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem; margin-top:0.85rem;">
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">4 Digit Terakhir Kartu *</label>
                                <input type="text" wire:model="edcLast4" maxlength="4" placeholder="4 digit, contoh: 8842" class="pos-input" style="background:#FFFFFF; font-weight:800; letter-spacing:0.1em;" autocomplete="off">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No. Approval / Auth Code *</label>
                                <input type="text" wire:model="edcApprovalCode" placeholder="Tertera di slip EDC, contoh: 128941" class="pos-input" style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No. Trace / Audit Slip EDC *</label>
                                <input type="text" wire:model="edcTraceNumber" placeholder="Tertera di slip EDC, contoh: 004812" class="pos-input" style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                            </div>
                        </div>

                        <div style="margin-top:0.85rem; background:#FCF8EE; border:1px dashed #E6DAC0; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.65rem; color:#7A5A52;">
                            Keamanan PCI-DSS: Sistem hanya mencatat 4 digit terakhir kartu fisik sebagai bukti rekonsiliasi slip audit perbankan. Dilarang mencatat atau meminta nomor kartu lengkap maupun kode CVV.
                        </div>
                    </div>

                {{-- Form KARTU KREDIT --}}
                @elseif(in_array($paymentMethod, ['CREDIT_CARD', 'CREDIT']) || ($paymentMethod === 'EDC_BCA' && $edcCardType === 'CREDIT') || ($paymentMethod === 'EDC_MANDIRI'))
                    <div class="pos-pay-content-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #E6DAC0; padding-bottom:0.75rem;">
                            <div>
                                <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A;">
                                    Pembayaran Kartu Kredit (Credit Card)
                                </div>
                                <div style="font-size:0.6875rem; color:#7A5A52;">
                                    Gesek, dip, atau tap kartu kredit pada mesin EDC fisik kasir lalu catat rincian slip transaksi di bawah ini.
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:0.625rem; font-weight:800; color:#662721; text-transform:uppercase;">Nominal Charge EDC</div>
                                <div style="font-size:1.25rem; font-weight:900; color:#662721;">Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</div>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem;">
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Mesin EDC Fisik *</label>
                                <select wire:model="edcTerminal" class="pos-input" style="background:#FFFFFF; font-weight:700;">
                                    <option value="EDC_BCA">Mesin EDC BCA</option>
                                    <option value="EDC_MANDIRI">Mesin EDC Mandiri</option>
                                    <option value="EDC_LAINNYA">Mesin EDC Lainnya</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Bank Penerbit *</label>
                                <select wire:model="edcBank" class="pos-input" style="background:#FFFFFF; font-weight:700;">
                                    <option value="BCA">BCA</option>
                                    <option value="MANDIRI">Bank Mandiri</option>
                                    <option value="BNI">BNI</option>
                                    <option value="BRI">BRI</option>
                                    <option value="CIMB">CIMB Niaga</option>
                                    <option value="MEGA">Bank Mega</option>
                                    <option value="PERMATA">Bank Permata</option>
                                    <option value="OVERSEAS">Bank Internasional / Luar Negeri</option>
                                    <option value="LAINNYA">Bank Lainnya</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Brand Jaringan Kartu *</label>
                                <select wire:model="edcCardNetwork" class="pos-input" style="background:#FFFFFF; font-weight:700;">
                                    <option value="VISA">Visa</option>
                                    <option value="MASTERCARD">Mastercard</option>
                                    <option value="JCB">JCB</option>
                                    <option value="AMEX">American Express (Amex)</option>
                                    <option value="UNIONPAY">UnionPay</option>
                                    <option value="LAINNYA">Brand Lainnya</option>
                                </select>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem; margin-top:0.85rem;">
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">4 Digit Terakhir Kartu *</label>
                                <input type="text" wire:model="edcLast4" maxlength="4" placeholder="4 digit, contoh: 8842" class="pos-input" style="background:#FFFFFF; font-weight:800; letter-spacing:0.1em;" autocomplete="off">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No. Approval / Auth Code *</label>
                                <input type="text" wire:model="edcApprovalCode" placeholder="Tertera di slip EDC, contoh: 128941" class="pos-input" style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No. Trace / Audit Slip EDC *</label>
                                <input type="text" wire:model="edcTraceNumber" placeholder="Tertera di slip EDC, contoh: 004812" class="pos-input" style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                            </div>
                        </div>

                        <div style="margin-top:0.85rem; background:#FCF8EE; border:1px dashed #E6DAC0; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.65rem; color:#7A5A52;">
                            Keamanan PCI-DSS: Sistem hanya mencatat 4 digit terakhir kartu fisik sebagai bukti rekonsiliasi slip audit perbankan. Dilarang mencatat atau meminta nomor kartu lengkap maupun kode CVV.
                        </div>
                    </div>

                {{-- Form QRIS --}}
                @elseif(in_array($paymentMethod, ['QRIS', 'QRIS_STATIS']))
                    <div class="pos-pay-content-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #E6DAC0; padding-bottom:0.75rem;">
                            <div>
                                <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A;">Pembayaran QRIS (QR Code)</div>
                                <div style="font-size:0.6875rem; color:#7A5A52;">Pelanggan memindai QRIS kasir frontdesk dan pastikan transaksi berhasil di aplikasi customer.</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:0.625rem; font-weight:800; color:#662721; text-transform:uppercase;">Total Bayar QRIS</div>
                                <div style="font-size:1.25rem; font-weight:900; color:#662721;">Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</div>
                            </div>
                        </div>

                        @include('pos.partials.qris-mode-toggle', ['grandTotal' => $this->grandTotal])

                        @if($qrisMode !== 'MIDTRANS' || \App\Services\Pos\PosMidtransQrisService::resolveMethod($posOnlineMethod, (float) $this->grandTotal) === null)
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.85rem;">
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Penyedia / Acquirer QRIS *</label>
                                <select wire:model="qrisProvider" class="pos-input" style="background:#FFFFFF; font-weight:700;">
                                    <option value="BCA_QRIS">QRIS BCA Frontdesk</option>
                                    <option value="MANDIRI_QRIS">QRIS Bank Mandiri</option>
                                    <option value="GOPAY_QRIS">GoPay / Midtrans QRIS</option>
                                    <option value="OVO">OVO</option>
                                    <option value="SHOPEEPAY">ShopeePay</option>
                                    <option value="DANA">DANA</option>
                                    <option value="LIVIN">Livin Mandiri</option>
                                    <option value="LAINNYA">Lainnya / Bank Lain</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Nomor RRN (Retrieval Reference Number) *</label>
                                <input type="text" wire:model="qrisRrn" placeholder="Min. 6 digit di mutasi / resi app customer" class="pos-input" style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                            </div>
                        </div>

                        <div style="margin-top:0.85rem;">
                            <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Nama Pengirim di Resi QRIS (Opsional)</label>
                            <input type="text" wire:model="qrisSenderName" placeholder="Contoh: Budi Santoso / BCA Mobile" class="pos-input" style="background:#FFFFFF;" autocomplete="off">
                        </div>
                        @endif
                    </div>
                @endif

                {{-- Action Buttons --}}
                <div style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem; margin-top:0.5rem;">
                    <button type="button" wire:click="backToSelection"
                        style="padding:0.7rem 1.2rem; border-radius:10px; border:1.5px solid #E6DAC0; background:#FFFFFF; color:#4F2F2A; font-weight:800; font-size:0.8125rem; cursor:pointer;">
                        &larr; Kembali ke Pilih Paket
                    </button>
                    <button type="button" wire:click="submitSale" wire:loading.attr="disabled" wire:target="submitSale"
                        style="flex:1; padding:0.75rem 1.5rem; border-radius:10px; background:#662721; color:#F7F0DB; border:1px solid #662721; font-weight:900; font-size:0.9375rem; cursor:pointer; box-shadow:0 4px 14px rgba(102,39,33,0.35); text-transform:uppercase; letter-spacing:0.05em;">
                        <span wire:loading.remove wire:target="submitSale">Terbitkan &amp; Lunaskan</span>
                        <span wire:loading wire:target="submitSale">Memproses Transaksi...</span>
                    </button>
                </div>
            </div>
        </div>
        @endif

        <!-- Right: Order Summary (persisten di kedua langkah, sama seperti panel kanan Walk-In) -->
        <div class="pos-panel-card">
            <div class="pos-panel-header">
                <div>
                    @if ($posStep === 'payment')
                        <div class="pos-panel-eyebrow">LANGKAH 2 DARI 2</div>
                        <div class="pos-panel-title">Ringkasan Tagihan</div>
                    @else
                        <div class="pos-panel-eyebrow">POS Kasir Loket</div>
                        <div class="pos-panel-title">3. Rincian &amp; Pembayaran</div>
                    @endif
                </div>
                @if ($this->selectedPlan)
                    <div class="pos-panel-total">Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</div>
                @endif
            </div>

            <div class="pos-panel-body">
                @if ($this->selectedPlan)
                    {{-- Data Customer (ringkasan, persisten di kedua langkah) --}}
                    <div class="pos-summary-box">
                        <div class="pos-summary-label">Data Customer</div>
                        @if ($selectedCustomerId)
                            <div style="font-weight:900; color:#4F2F2A;">{{ $selectedCustomerName }}</div>
                            <div style="color:#7A5A52;">{{ $selectedCustomerPhone ?? '-' }}</div>
                        @elseif (trim($walkInName) !== '' || trim($walkInPhone) !== '')
                            <div style="font-weight:900; color:#4F2F2A;">{{ $walkInName ?: '(Nama belum diisi)' }}</div>
                            <div style="color:#7A5A52;">{{ $walkInPhone ?: '(Nomor belum diisi)' }}</div>
                        @else
                            <div style="color:#A08F86; font-style:italic;">Belum diisi</div>
                        @endif
                    </div>

                    <div>
                        <div class="pos-section-label">Paket Terpilih</div>
                        <div style="font-size: 1rem; font-weight: 900; color: #4F2F2A;">
                            {{ $this->selectedPlan->name }}</div>
                        <div style="font-size: 0.8125rem; color: #7A5A52; margin-top:0.1rem;">Rp
                            {{ number_format($this->selectedPlan->price, 0, ',', '.') }} &bull; Masa Aktif
                            {{ $this->selectedPlan->duration_days }} Hari</div>
                    </div>

                    <!-- Ringkasan Finansial -->
                    <div class="pos-total-box" style="display:flex; flex-direction:column; gap:0.35rem;">
                        <div class="pos-sum-row">
                            <span>Subtotal</span>
                            <span>Rp {{ number_format($this->subtotal, 0, ',', '.') }}</span>
                        </div>
                        @if ($this->financeCalculation['tax_amount'] > 0)
                            <div class="pos-sum-row">
                                <span>{{ $this->financeCalculation['tax_name'] ?: 'Pajak PB1' }}</span>
                                <span>Rp
                                    {{ number_format($this->financeCalculation['tax_amount'], 0, ',', '.') }}</span>
                            </div>
                        @endif
                        @if ($this->financeCalculation['admin_fee_amount'] > 0)
                            <div class="pos-sum-row">
                                <span>Biaya Layanan</span>
                                <span>Rp
                                    {{ number_format($this->financeCalculation['admin_fee_amount'], 0, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="pos-sum-total">
                            <span>Grand Total</span>
                            <span>Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</span>
                        </div>
                    </div>

                    @if ($posStep === 'payment')
                        <div class="pos-summary-box">
                            <div class="pos-summary-label">Metode Pembayaran</div>
                            <div style="font-weight:900; color:#4F2F2A;">{{ str_replace('_', ' ', $paymentMethod) }}</div>
                        </div>
                    @endif
                @else
                    <div class="pos-empty-note">
                        Pilih paket membership di kolom kiri untuk melanjutkan pembayaran.
                    </div>
                @endif
            </div>

            {{-- Footer: Action Button --}}
            @if ($this->selectedPlan)
                <div class="pos-panel-footer">
                    @if ($posStep === 'selection')
                        <button type="button" wire:click="proceedToPayment" class="pos-submit-btn">
                            Lanjut ke Pembayaran &rarr;
                        </button>
                    @elseif ($posStep === 'payment')
                        <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost" style="width:100%; height:46px;">
                            &larr; Ubah Pilihan Paket
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>


    @endif

    <!-- Success Modal & Thermal Struk -->
    {{-- QR Midtrans menunggu dibayar customer --}}
    @include('pos.partials.midtrans-qris-modal', ['pendingQris' => $pendingQris, 'pollAction' => 'pollPendingQris', 'cancelAction' => 'cancelPendingQris', 'simulateAction' => 'simulatePendingQrisPaid'])

    @if ($showSuccessModal && $completedMembershipData)
        <div
            style="position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem;">
            @include('pos.receipts.membership', ['receipt' => $completedMembershipData, 'withActions' => true, 'receiptFromHistory' => $receiptFromHistory])
        </div>
    @endif
</div>
