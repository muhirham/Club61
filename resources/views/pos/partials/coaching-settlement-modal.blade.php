{{--
    Pelunasan Tagihan Coaching (Modul 31) — App\Livewire\Concerns\SettlesCoachingBills / CoachingBillingService::settle.
    Satu bukti bayar melunasi beberapa transaksi booking academy sekaligus; uangnya masuk shift kasir yang sedang buka.
--}}
@php
    $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $box = 'background:#FCF8EE; border:1px solid #E6DAC0; border-radius:10px; padding:0.7rem 0.8rem;';
    $input = 'width:100%; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.45rem 0.6rem; font-size:0.8125rem; color:#4F2F2A; background:#FFFFFF;';
    $label = 'display:block; font-size:0.6875rem; font-weight:800; color:#7A5A52; margin-bottom:0.25rem;';
@endphp
@if ($showCoachingSettlement)
    <div style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:18px; box-shadow:0 25px 50px -12px rgba(102,39,33,0.4); width:100%; max-width:640px; max-height:92vh; display:flex; flex-direction:column; overflow:hidden;">
            <div style="background:#FCF8EE; border-bottom:1.5px solid #E6DAC0; padding:0.85rem 1.1rem; display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem;">
                <div>
                    <div style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em;">Tagihan Berkala Academy</div>
                    <div style="font-size:1rem; font-weight:900; color:#4F2F2A; margin-top:0.15rem;">Pelunasan Tagihan Coaching</div>
                    <div style="font-size:0.6875rem; color:#7A5A52; margin-top:0.15rem;">Pembayaran diterima di kasir dan tercatat pada shift yang sedang aktif.</div>
                </div>
                <button type="button" wire:click="closeCoachingSettlement" aria-label="Tutup" style="background:none; border:none; font-size:1.25rem; color:#78350F; cursor:pointer; line-height:1;">&times;</button>
            </div>

            <div style="padding:1rem 1.1rem; display:flex; flex-direction:column; gap:0.8rem; overflow-y:auto;">
                @if ($lastCoachingSettlement)
                    @php $done = $lastCoachingSettlement; @endphp
                    <div style="background:#ECFDF5; border:1.5px solid #6EE7B7; border-radius:8px; padding:0.6rem 0.8rem; font-size:0.75rem; color:#065F46; font-weight:700;">
                        Pelunasan {{ $done['number'] }} tersimpan. Seluruh sesi di bawah sudah lunas.
                    </div>
                    <div id="printable-coaching-settlement" style="font-family:monospace; font-size:0.75rem; color:#111827; border:1px solid #E5E7EB; border-radius:8px; padding:1rem; max-width:420px; align-self:center; width:100%;">
                        <div style="text-align:center; border-bottom:1px dashed #000; padding-bottom:0.5rem; margin-bottom:0.5rem;">
                            <div style="font-weight:900; font-size:0.95rem;">CLUB 61 PADEL ARENA</div>
                            <div style="font-size:0.65rem;">{{ \App\Models\Setting\CompanyProfileSetting::receiptAddress() }}</div>
                            <div style="font-size:0.7rem; font-weight:900; margin-top:0.3rem;">BUKTI PELUNASAN TAGIHAN COACHING</div>
                        </div>
                        <div style="line-height:1.45; border-bottom:1px dashed #000; padding-bottom:0.5rem; margin-bottom:0.5rem;">
                            <div>No.: <strong>{{ $done['number'] }}</strong></div>
                            <div>Waktu: {{ $done['paid_at'] }} WIB</div>
                            <div>Kasir: {{ $done['cashier'] }}</div>
                            <div>Academy: <strong>{{ $done['academy'] }}</strong></div>
                            <div>Metode: {{ $done['method'] }}{{ $done['reference'] ? ' · Ref '.$done['reference'] : '' }}</div>
                        </div>
                        @foreach ($done['sessions'] as $session)
                            <div style="display:flex; justify-content:space-between; gap:0.5rem;"><span>{{ $session['schedule'] }}</span><span>{{ $session['court'] }}</span></div>
                        @endforeach
                        <div style="border-top:1px dashed #000; margin-top:0.5rem; padding-top:0.5rem; display:flex; justify-content:space-between; font-weight:900;">
                            <span>TOTAL ({{ count($done['sessions']) }} sesi)</span><span>{{ $rupiah($done['total']) }}</span>
                        </div>
                        <div style="font-size:0.65rem; margin-top:0.3rem;">Status: <strong>LUNAS</strong></div>
                    </div>
                @else
                    <div>
                        <label style="{{ $label }}">Academy</label>
                        <select wire:model.live="settleAcademyId" style="{{ $input }}">
                            <option value="">Pilih academy</option>
                            @foreach ($this->coachingSettlementAcademies as $academy)
                                <option value="{{ $academy->id }}">{{ $academy->name }} — {{ $academy->billingLabel() }}</option>
                            @endforeach
                        </select>
                        @if ($this->coachingSettlementAcademies->isEmpty())
                            <div style="font-size:0.6875rem; color:#7A5A52; margin-top:0.35rem;">Tidak ada tagihan berkala academy yang belum dilunasi.</div>
                        @endif
                    </div>

                    @if ($settleAcademyId)
                        <div style="{{ $box }} display:flex; flex-direction:column; gap:0.35rem;">
                            <div style="font-size:0.6875rem; font-weight:900; color:#4F2F2A;">Sesi belum lunas — centang yang dibayar sekarang</div>
                            @forelse ($this->coachingOpenBills as $bill)
                                <label wire:key="bill-{{ $bill['order_id'] }}" style="display:grid; grid-template-columns:auto 1fr auto; gap:0.55rem; align-items:start; font-size:0.75rem; color:#4F2F2A; background:#FFFFFF; border:1px solid #E6DAC0; border-radius:8px; padding:0.45rem 0.6rem; cursor:pointer;">
                                    <input type="checkbox" value="{{ $bill['order_id'] }}" wire:model.live="settleOrderIds" style="accent-color:#662721; margin-top:0.15rem;">
                                    <span>
                                        <b>{{ $bill['date'] }}</b> &bull; {{ $bill['schedule'] }} &bull; {{ $bill['court'] }}<br>
                                        <span style="font-size:0.6875rem; color:#7A5A52;">{{ $bill['code'] }} &bull; Coach {{ $bill['coach'] }}{{ $bill['due'] ? ' • jatuh tempo '.$bill['due'] : '' }}</span>
                                        @if ($bill['overdue'])
                                            <span style="font-size:0.625rem; font-weight:900; color:#B91C1C;"> &bull; MELEWATI JATUH TEMPO</span>
                                        @endif
                                    </span>
                                    <b style="white-space:nowrap;">{{ $rupiah($bill['amount']) }}</b>
                                </label>
                            @empty
                                <div style="font-size:0.75rem; color:#7A5A52;">Tidak ada sesi yang belum dilunasi.</div>
                            @endforelse
                            <div style="display:flex; justify-content:space-between; font-size:0.875rem; font-weight:900; color:#662721; border-top:1.5px dashed #662721; padding-top:0.4rem; margin-top:0.2rem;">
                                <span>Total dibayar ({{ count($settleOrderIds) }} transaksi)</span><span>{{ $rupiah($this->coachingSettleTotal) }}</span>
                            </div>
                        </div>

                        <div>
                            <label style="{{ $label }}">Metode Pembayaran</label>
                            <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.4rem;">
                                @foreach (\App\Services\Coaching\CoachingBillingService::SETTLEMENT_METHODS as $code)
                                    <button type="button" wire:click="$set('settleMethod', '{{ $code }}')"
                                        style="padding:0.5rem; border-radius:8px; font-size:0.75rem; font-weight:800; cursor:pointer; border:1.5px solid {{ $settleMethod === $code ? '#662721' : '#E6DAC0' }}; background:{{ $settleMethod === $code ? '#662721' : '#FFFFFF' }}; color:{{ $settleMethod === $code ? '#F7F0DB' : '#4F2F2A' }};">
                                        {{ \App\Services\Pos\PosPaymentProof::METHODS[$code] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @if ($settleMethod === 'QRIS')
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:0.5rem;">
                                <div><label style="{{ $label }}">Penyedia QRIS</label>
                                    <select wire:model="settleQrisProvider" style="{{ $input }}">
                                        @foreach (\App\Services\Pos\PosPaymentProof::QRIS_PROVIDERS as $code => $name)
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select></div>
                                <div><label style="{{ $label }}">Nomor RRN *</label><input type="text" wire:model="settleQrisRrn" maxlength="32" style="{{ $input }}" placeholder="Dari bukti bayar"></div>
                                <div><label style="{{ $label }}">Nama Pengirim</label><input type="text" wire:model="settleQrisSender" maxlength="100" style="{{ $input }}"></div>
                            </div>
                        @elseif ($settleMethod === 'EDC_BCA')
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:0.5rem;">
                                <div><label style="{{ $label }}">Jenis Kartu</label>
                                    <select wire:model="settleEdcCardType" style="{{ $input }}"><option value="DEBIT">Kartu Debit</option><option value="CREDIT">Kartu Kredit</option></select></div>
                                <div><label style="{{ $label }}">4 Digit Terakhir *</label><input type="text" wire:model="settleEdcLast4" maxlength="4" inputmode="numeric" style="{{ $input }}"></div>
                                <div><label style="{{ $label }}">Approval Code *</label><input type="text" wire:model="settleEdcApproval" maxlength="12" style="{{ $input }}"></div>
                                <div><label style="{{ $label }}">Trace Number *</label><input type="text" wire:model="settleEdcTrace" maxlength="12" style="{{ $input }}"></div>
                            </div>
                        @else
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:0.5rem;">
                                <div><label style="{{ $label }}">Bank Pengirim *</label><input type="text" wire:model="settleTransferBank" maxlength="30" style="{{ $input }}" placeholder="Contoh: BCA"></div>
                                <div><label style="{{ $label }}">Nomor Referensi *</label><input type="text" wire:model="settleTransferReference" maxlength="40" style="{{ $input }}" placeholder="Dari bukti transfer"></div>
                                <div><label style="{{ $label }}">Nama Pengirim</label><input type="text" wire:model="settleTransferSender" maxlength="100" style="{{ $input }}"></div>
                            </div>
                            <div>
                                @php
                                    $proofReady = $settleTransferProof && ! $errors->has('settleTransferProof')
                                        && method_exists($settleTransferProof, 'isPreviewable') && $settleTransferProof->isPreviewable();
                                @endphp
                                <label style="{{ $label }}">Foto Bukti Transfer * (JPG / PNG / WEBP, maks. 4 MB)</label>
                                <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                                    <label style="position:relative; display:inline-flex; align-items:center; padding:0.45rem 0.8rem; border-radius:8px; border:1.5px solid #662721; background:#FFFFFF; color:#662721; font-size:0.75rem; font-weight:800; cursor:pointer;">
                                        {{ $proofReady ? 'Ganti Foto' : 'Pilih Foto' }}
                                        <input type="file" wire:model="settleTransferProof" accept="image/jpeg,image/png,image/webp" style="position:absolute; width:1px; height:1px; opacity:0; overflow:hidden;">
                                    </label>
                                    <span wire:loading wire:target="settleTransferProof" style="font-size:0.6875rem; color:#7A5A52; font-weight:700;">Mengunggah…</span>
                                    @if (! $proofReady)
                                        <span wire:loading.remove wire:target="settleTransferProof" style="font-size:0.6875rem; color:#9CA3AF;">Belum ada foto dipilih</span>
                                    @endif
                                </div>
                                @if ($proofReady)
                                    <div wire:loading.remove wire:target="settleTransferProof" style="display:flex; align-items:center; gap:0.6rem; margin-top:0.45rem; background:#FFFFFF; border:1px solid #E6DAC0; border-radius:8px; padding:0.4rem;">
                                        <img src="{{ $settleTransferProof->temporaryUrl() }}" alt="Bukti transfer" style="width:64px; height:64px; object-fit:cover; border-radius:6px; border:1px solid #E6DAC0;">
                                        <div style="min-width:0; flex:1;">
                                            <div style="font-size:0.75rem; font-weight:800; color:#4F2F2A; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $settleTransferProof->getClientOriginalName() }}</div>
                                            <div style="font-size:0.6875rem; color:#7A5A52;">{{ number_format($settleTransferProof->getSize() / 1024, 0, ',', '.') }} KB &bull; siap disimpan</div>
                                        </div>
                                        <button type="button" wire:click="$set('settleTransferProof', null)" style="background:none; border:none; color:#B91C1C; font-size:0.6875rem; font-weight:800; cursor:pointer;">Hapus</button>
                                    </div>
                                @endif
                                @error('settleTransferProof') <div style="font-size:0.6875rem; color:#B91C1C; font-weight:700; margin-top:0.25rem;">{{ $message }}</div> @enderror
                                <div style="font-size:0.625rem; color:#9CA3AF; margin-top:0.25rem;">Transfer langsung masuk rekening Club 61, sehingga tidak diketik saat tutup shift; admin mencocokkan dengan mutasi rekening.</div>
                            </div>
                        @endif
                    @endif
                @endif
            </div>

            <div style="border-top:1.5px solid #E6DAC0; padding:0.75rem 1.1rem; display:flex; justify-content:flex-end; gap:0.5rem;">
                @if ($lastCoachingSettlement)
                    <button type="button" onclick="club61PrintReceipt('#printable-coaching-settlement')" class="pos-btn pos-btn-ghost">Cetak Bukti Pelunasan</button>
                    <button type="button" wire:click="closeCoachingSettlement" class="pos-btn pos-btn-primary">Selesai</button>
                @else
                    <button type="button" wire:click="closeCoachingSettlement" class="pos-btn pos-btn-ghost">Batal</button>
                    <button type="button" wire:click="submitCoachingSettlement({{ (float) $this->coachingSettleTotal }})" wire:loading.attr="disabled" wire:target="submitCoachingSettlement, settleTransferProof"
                        class="pos-btn pos-btn-primary" @disabled(! $settleAcademyId || $settleOrderIds === [])
                        wire:confirm="Terima pelunasan {{ $rupiah($this->coachingSettleTotal) }} melalui {{ \App\Services\Pos\PosPaymentProof::METHODS[$settleMethod] ?? $settleMethod }}?">
                        <span wire:loading.remove wire:target="submitCoachingSettlement">Terima Pelunasan</span>
                        <span wire:loading wire:target="submitCoachingSettlement">Memproses...</span>
                    </button>
                @endif
            </div>
        </div>
    </div>
@endif
