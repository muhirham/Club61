{{--
    Tab "Kasir | Riwayat Transaksi" untuk halaman POS (Walk-In Padel & Jual Membership).
    Param: $isHistory (bool), $canShowHistory (bool), $inline (opsional, tanpa jarak bawah).
--}}
@if ($canShowHistory)
    @php
        $histTabBase = 'height:36px; padding:0 0.95rem; border-radius:8px; font-size:0.8125rem; font-weight:800; cursor:pointer; border:none; white-space:nowrap; transition:background 0.15s, color 0.15s;';
    @endphp
    <div style="display:inline-flex; gap:3px; padding:3px; background:#FFFFFF; border:1px solid #E6DAC0; border-radius:11px; {{ ($inline ?? false) ? '' : 'margin-bottom:0.75rem;' }}">
        <button type="button" wire:click="showCashier"
            style="{{ $histTabBase }} {{ ! $isHistory ? 'background:#4F2F2A; color:#F7F0DB;' : 'background:transparent; color:#7A5A52;' }}">
            Kasir
        </button>
        <button type="button" wire:click="showHistory"
            style="{{ $histTabBase }} {{ $isHistory ? 'background:#4F2F2A; color:#F7F0DB;' : 'background:transparent; color:#7A5A52;' }}">
            Riwayat Transaksi
        </button>
    </div>
@endif
