{{-- POS Walk-In → Tambah Alat ke Booking: booking yang ditambah alat (customer otomatis dari booking). --}}
<div class="pos-addon-card" data-addon-booking="{{ $addon['code'] }}">
    <div class="pos-addon-card-head">
        <span>Tambah Alat ke Booking</span>
        @if ($canCancel)
            <button type="button" wire:click="cancelBookingAddon" class="pos-mini-btn is-danger">Batal</button>
        @endif
    </div>
    <div class="pos-addon-card-name">{{ $addon['customer'] }}</div>
    @if (! empty($addon['phone']))
        <div class="pos-addon-card-sub">{{ $addon['phone'] }}</div>
    @endif
    <div class="pos-addon-card-sub" style="margin-top:0.35rem;">
        <span style="font-family:var(--font-mono, monospace); font-weight:800;">#{{ $addon['code'] }}</span>
        &bull; {{ $addon['court'] }} &bull; {{ $addon['schedule'] }}
    </div>
    <div class="pos-addon-card-note">
        @if (($addon['mode'] ?? null) === \App\Services\Padel\BookingAddonService::MODE_TAB)
            Sesi coaching Tagihan Berkala {{ $addon['academy'] }} — alat ditambahkan ke tagihan academy, tanpa pembayaran sekarang.
        @else
            Alat dibayar sebagai transaksi baru di kasir. Transaksi booking ini tidak berubah.
        @endif
    </div>
</div>
