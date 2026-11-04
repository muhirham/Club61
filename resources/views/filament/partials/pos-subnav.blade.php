{{--
    Tab navigasi atas buat pindah antar-halaman POS (Walk-In Booking / Jual Membership / Kasir Pro Shop) 1 klik,
    tanpa melebur logic/form halaman-halamannya jadi satu file. Tiap tab = link biasa ke route Filament
    page masing-masing (bukan Livewire component gabungan), jadi masing-masing tetap 100% independen.
    Label dibuat pendek supaya baris atas (tab + status shift) muat sebaris di laptop; nama lengkap ada di tooltip.

    Parameter:
    - $activePos: 'walkin' | 'membership' | 'proshop' (tab Kasir Pro Shop hanya tampil untuk yang punya akses)
    - $inline (opsional): true = tanpa jarak bawah (dipakai di baris atas yang memuat elemen lain)
--}}
@php
    $posTabBase = 'display:inline-flex; align-items:center; justify-content:center; gap:0.45rem; height:36px; padding:0 0.95rem; border-radius:8px; font-size:0.8125rem; font-weight:800; text-decoration:none; white-space:nowrap; transition:background 0.15s, color 0.15s;';
    $posTabActive = 'background:#662721; color:#F7F0DB;';
    $posTabIdle = 'background:transparent; color:#7A5A52;';
@endphp
<div style="display:inline-flex; gap:3px; padding:3px; background:#FFFFFF; border:1px solid #E6DAC0; border-radius:11px; flex-wrap:wrap; {{ ($inline ?? false) ? '' : 'margin-bottom:0.75rem;' }}">
    <a href="{{ route('filament.admin.pages.book-offline-court') }}" wire:navigate title="POS Walk-In Booking" aria-label="POS Walk-In Booking"
        style="{{ $posTabBase }} {{ $activePos === 'walkin' ? $posTabActive : $posTabIdle }}">
        <svg style="width:16px; height:16px; flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        Walk-In
    </a>
    <a href="{{ route('filament.admin.pages.jual-membership') }}" wire:navigate title="POS Jual Membership" aria-label="POS Jual Membership"
        style="{{ $posTabBase }} {{ $activePos === 'membership' ? $posTabActive : $posTabIdle }}">
        <svg style="width:16px; height:16px; flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
        Membership
    </a>
    @if (\App\Filament\Pages\KasirProShop::canAccess())
        <a href="{{ route('filament.admin.pages.kasir-pro-shop') }}" wire:navigate title="Kasir Pro Shop" aria-label="Kasir Pro Shop"
            style="{{ $posTabBase }} {{ $activePos === 'proshop' ? $posTabActive : $posTabIdle }}">
            <svg style="width:16px; height:16px; flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
            Pro Shop
        </a>
    @endif
</div>
