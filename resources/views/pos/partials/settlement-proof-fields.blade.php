{{--
    Metode bayar + bukti Pelunasan Tagihan (coaching Modul 31 & Tagihan Bulanan Modul 34). Properti Livewire dari
    App\Livewire\Concerns\SettlesCoachingBills (settleMethod, settleQris*, settleEdc*, settleTransfer*); $label & $input dari
    modal pemanggil.
--}}
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
