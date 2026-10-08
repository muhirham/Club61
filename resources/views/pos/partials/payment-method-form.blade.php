{{--
    Form pilih metode pembayaran + detail slip (Debit / Kredit via EDC, QRIS) yang dipakai
    BERSAMA oleh POS Walk-In Booking (BookOfflineCourt) dan Kasir F&B (FnbCashierTerminal),
    supaya tampilan & field yang dicatat kasir selalu identik di semua loket.
    Butuh properti Livewire: paymentMethod, edcCardType, edcTerminal, edcBank, edcCardNetwork,
    edcLast4, edcApprovalCode, edcTraceNumber, qrisProvider, qrisRrn, qrisSenderName,
    method setPaymentMethod(), dan variabel $grandTotal dari pemanggil.
--}}
@once
    <style>
        .pos-input { width: 100%; border: 1px solid #E6DAC0; border-radius: 7px; padding: 0.35rem 0.6rem; font-size: 0.75rem; font-weight: 700; color: #4F2F2A; background: #FFFFFF; outline: none; box-sizing: border-box; }
        .pos-input:focus { border-color: #662721; box-shadow: 0 0 0 2px rgba(102,39,33, 0.15); }
        .pos-method-selector-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.65rem; }
        .pos-method-tab { border: 2px solid #E5E7EB; background: #F9FAFB; border-radius: 10px; padding: 0.7rem 0.5rem; text-align: center; cursor: pointer; transition: all 0.15s ease; user-select: none; }
        .pos-method-tab:hover { border-color: #E6DAC0; background: #FCF8EE; }
        .pos-method-tab.active { border-color: #662721; background: linear-gradient(180deg, #FCF8EE 0%, #FCF8EE 100%); box-shadow: 0 4px 12px rgba(102,39,33, 0.18); }
        .pos-method-tab-title { font-size: 0.8125rem; font-weight: 900; color: #4F2F2A; }
        .pos-method-tab.active .pos-method-tab-title { color: #662721; }
        .pos-method-tab-sub { font-size: 0.625rem; color: #6B7280; margin-top: 0.15rem; }
        .pos-pay-content-card { background: #FCF8EE; border: 1.5px solid #E6DAC0; border-radius: 12px; padding: 1.1rem 1.25rem; }
    </style>
@endonce

                    <div>
                        <div style="font-size:0.75rem; font-weight:900; color:#4F2F2A; margin-bottom:0.45rem;">Pilih
                            Metode Pembayaran:</div>
                        <div class="pos-method-selector-grid">
                            <div wire:click="setPaymentMethod('DEBIT_CARD')"
                                class="pos-method-tab {{ in_array($paymentMethod, ['DEBIT_CARD', 'DEBIT']) || ($paymentMethod === 'EDC_BCA' && $edcCardType === 'DEBIT') ? 'active' : '' }}">
                                <div class="pos-method-tab-title">KARTU DEBIT</div>
                                <div class="pos-method-tab-sub">Semua Bank (Via EDC)</div>
                            </div>
                            <div wire:click="setPaymentMethod('CREDIT_CARD')"
                                class="pos-method-tab {{ in_array($paymentMethod, ['CREDIT_CARD', 'CREDIT']) || ($paymentMethod === 'EDC_BCA' && $edcCardType === 'CREDIT') || $paymentMethod === 'EDC_MANDIRI' ? 'active' : '' }}">
                                <div class="pos-method-tab-title">KARTU KREDIT</div>
                                <div class="pos-method-tab-sub">Visa, MC, JCB, Amex</div>
                            </div>
                            <div wire:click="setPaymentMethod('QRIS')"
                                class="pos-method-tab {{ in_array($paymentMethod, ['QRIS', 'QRIS_STATIS']) ? 'active' : '' }}">
                                <div class="pos-method-tab-title">QRIS</div>
                                <div class="pos-method-tab-sub">QR Code / E-Wallet</div>
                            </div>
                        </div>
                    </div>

                    {{-- Detail Form Metode Bayar --}}
                    {{-- Form KARTU DEBIT --}}
                    @if (in_array($paymentMethod, ['DEBIT_CARD', 'DEBIT']) || ($paymentMethod === 'EDC_BCA' && $edcCardType === 'DEBIT'))
                        <div class="pos-pay-content-card">
                            <div
                                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #E6DAC0; padding-bottom:0.75rem;">
                                <div>
                                    <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A;">
                                        Pembayaran Kartu Debit (Debit Card)
                                    </div>
                                    <div style="font-size:0.6875rem; color:#7A5A52;">
                                        Gesek, dip, atau tap kartu debit pada mesin EDC fisik kasir lalu catat rincian
                                        slip transaksi di bawah ini.
                                    </div>
                                </div>
                                <div style="text-align:right;">
                                    <div
                                        style="font-size:0.625rem; font-weight:800; color:#662721; text-transform:uppercase;">
                                        Nominal Charge EDC</div>
                                    <div style="font-size:1.25rem; font-weight:900; color:#662721;">Rp
                                        {{ number_format($grandTotal, 0, ',', '.') }}</div>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem;">
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Mesin
                                        EDC Fisik *</label>
                                    <select wire:model="edcTerminal" class="pos-input"
                                        style="background:#FFFFFF; font-weight:700;">
                                        <option value="EDC_BCA">Mesin EDC BCA</option>
                                        <option value="EDC_MANDIRI">Mesin EDC Mandiri</option>
                                        <option value="EDC_LAINNYA">Mesin EDC Lainnya</option>
                                    </select>
                                </div>
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Bank
                                        Penerbit *</label>
                                    <select wire:model="edcBank" class="pos-input"
                                        style="background:#FFFFFF; font-weight:700;">
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
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Jaringan
                                        Kartu (Scheme)</label>
                                    <select wire:model="edcCardNetwork" class="pos-input"
                                        style="background:#FFFFFF; font-weight:700;">
                                        <option value="GPN">GPN (Gerbang Pembayaran Nasional)</option>
                                        <option value="MASTERCARD">Mastercard Debit</option>
                                        <option value="VISA">Visa Debit</option>
                                        <option value="LAINNYA">Debit Lainnya</option>
                                    </select>
                                </div>
                            </div>

                            <div
                                style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem; margin-top:0.85rem;">
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">4
                                        Digit Terakhir Kartu *</label>
                                    <input type="text" wire:model="edcLast4" maxlength="4"
                                        placeholder="4 digit, contoh: 8842" class="pos-input"
                                        style="background:#FFFFFF; font-weight:800; letter-spacing:0.1em;"
                                        autocomplete="off">
                                </div>
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No.
                                        Approval / Auth Code *</label>
                                    <input type="text" wire:model="edcApprovalCode"
                                        placeholder="Tertera di slip EDC, contoh: 128941" class="pos-input"
                                        style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                                </div>
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No.
                                        Trace / Audit Slip EDC *</label>
                                    <input type="text" wire:model="edcTraceNumber"
                                        placeholder="Tertera di slip EDC, contoh: 004812" class="pos-input"
                                        style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                                </div>
                            </div>

                            <div
                                style="margin-top:0.85rem; background:#FCF8EE; border:1px dashed #E6DAC0; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.65rem; color:#7A5A52;">
                                Keamanan PCI-DSS: Sistem hanya mencatat 4 digit terakhir kartu fisik sebagai bukti
                                rekonsiliasi slip audit perbankan. Dilarang mencatat atau meminta nomor kartu lengkap
                                maupun kode CVV.
                            </div>
                        </div>

                        {{-- Form KARTU KREDIT --}}
                    @elseif(in_array($paymentMethod, ['CREDIT_CARD', 'CREDIT']) ||
                            ($paymentMethod === 'EDC_BCA' && $edcCardType === 'CREDIT') ||
                            $paymentMethod === 'EDC_MANDIRI')
                        <div class="pos-pay-content-card">
                            <div
                                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #E6DAC0; padding-bottom:0.75rem;">
                                <div>
                                    <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A;">
                                        Pembayaran Kartu Kredit (Credit Card)
                                    </div>
                                    <div style="font-size:0.6875rem; color:#7A5A52;">
                                        Gesek, dip, atau tap kartu kredit pada mesin EDC fisik kasir lalu catat rincian
                                        slip transaksi di bawah ini.
                                    </div>
                                </div>
                                <div style="text-align:right;">
                                    <div
                                        style="font-size:0.625rem; font-weight:800; color:#662721; text-transform:uppercase;">
                                        Nominal Charge EDC</div>
                                    <div style="font-size:1.25rem; font-weight:900; color:#662721;">Rp
                                        {{ number_format($grandTotal, 0, ',', '.') }}</div>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem;">
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Mesin
                                        EDC Fisik *</label>
                                    <select wire:model="edcTerminal" class="pos-input"
                                        style="background:#FFFFFF; font-weight:700;">
                                        <option value="EDC_BCA">Mesin EDC BCA</option>
                                        <option value="EDC_MANDIRI">Mesin EDC Mandiri</option>
                                        <option value="EDC_LAINNYA">Mesin EDC Lainnya</option>
                                    </select>
                                </div>
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Bank
                                        Penerbit *</label>
                                    <select wire:model="edcBank" class="pos-input"
                                        style="background:#FFFFFF; font-weight:700;">
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
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Brand
                                        Jaringan Kartu *</label>
                                    <select wire:model="edcCardNetwork" class="pos-input"
                                        style="background:#FFFFFF; font-weight:700;">
                                        <option value="VISA">Visa</option>
                                        <option value="MASTERCARD">Mastercard</option>
                                        <option value="JCB">JCB</option>
                                        <option value="AMEX">American Express (Amex)</option>
                                        <option value="UNIONPAY">UnionPay</option>
                                        <option value="LAINNYA">Brand Lainnya</option>
                                    </select>
                                </div>
                            </div>

                            <div
                                style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.85rem; margin-top:0.85rem;">
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">4
                                        Digit Terakhir Kartu *</label>
                                    <input type="text" wire:model="edcLast4" maxlength="4"
                                        placeholder="4 digit, contoh: 8842" class="pos-input"
                                        style="background:#FFFFFF; font-weight:800; letter-spacing:0.1em;"
                                        autocomplete="off">
                                </div>
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No.
                                        Approval / Auth Code *</label>
                                    <input type="text" wire:model="edcApprovalCode"
                                        placeholder="Tertera di slip EDC, contoh: 128941" class="pos-input"
                                        style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                                </div>
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">No.
                                        Trace / Audit Slip EDC *</label>
                                    <input type="text" wire:model="edcTraceNumber"
                                        placeholder="Tertera di slip EDC, contoh: 004812" class="pos-input"
                                        style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                                </div>
                            </div>

                            <div
                                style="margin-top:0.85rem; background:#FCF8EE; border:1px dashed #E6DAC0; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.65rem; color:#7A5A52;">
                                Keamanan PCI-DSS: Sistem hanya mencatat 4 digit terakhir kartu fisik sebagai bukti
                                rekonsiliasi slip audit perbankan. Dilarang mencatat atau meminta nomor kartu lengkap
                                maupun kode CVV.
                            </div>
                        </div>

                        {{-- Form QRIS --}}
                    @elseif(in_array($paymentMethod, ['QRIS', 'QRIS_STATIS']))
                        <div class="pos-pay-content-card">
                            <div
                                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #E6DAC0; padding-bottom:0.75rem;">
                                <div>
                                    <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A;">Pembayaran QRIS
                                        (QR Code)</div>
                                    <div style="font-size:0.6875rem; color:#7A5A52;">Pelanggan memindai QRIS kasir
                                        frontdesk dan pastikan transaksi berhasil di aplikasi customer.</div>
                                </div>
                                <div style="text-align:right;">
                                    <div
                                        style="font-size:0.625rem; font-weight:800; color:#662721; text-transform:uppercase;">
                                        Total Bayar QRIS</div>
                                    <div style="font-size:1.25rem; font-weight:900; color:#662721;">Rp
                                        {{ number_format($grandTotal, 0, ',', '.') }}</div>
                                </div>
                            </div>

                            @if($qrisMidtrans ?? false)
                                @include('pos.partials.qris-mode-toggle')
                            @endif

                            @if(! (($qrisMidtrans ?? false) && $qrisMode === 'MIDTRANS' && \App\Services\Pos\PosMidtransQrisService::resolveMethod($posOnlineMethod, (float) $grandTotal) !== null))
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.85rem;">
                                <div>
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Penyedia
                                        / Acquirer QRIS *</label>
                                    <select wire:model="qrisProvider" class="pos-input"
                                        style="background:#FFFFFF; font-weight:700;">
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
                                    <label
                                        style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Nomor
                                        RRN (Retrieval Reference Number) *</label>
                                    <input type="text" wire:model="qrisRrn"
                                        placeholder="Min. 6 digit di mutasi / resi app customer" class="pos-input"
                                        style="background:#FFFFFF; font-weight:800;" autocomplete="off">
                                </div>
                            </div>

                            <div style="margin-top:0.85rem;">
                                <label
                                    style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Nama
                                    Pengirim di Resi QRIS (Opsional)</label>
                                <input type="text" wire:model="qrisSenderName"
                                    placeholder="Contoh: Budi Santoso / BCA Mobile" class="pos-input"
                                    style="background:#FFFFFF;" autocomplete="off">
                            </div>
                            @endif
                        </div>
                    @endif
