<x-filament-panels::page>
    <div class="c61" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        <div class="c61-card" style="border-left: 4px solid var(--c-terra);">
            <div class="c61-card-body" style="font-size: 0.8125rem; line-height: 1.6; color: var(--c-muted); padding: 1rem 1.25rem;">
                <div class="c61-card-title" style="font-size: 1.0625rem; margin-bottom: 0.2rem;">Shift yang ditutup dengan selisih</div>
                Kasir mengetik angka struk settlement EDC / aplikasi QRIS tanpa melihat angka sistem. Selisih berapa pun membuat shift
                <b style="color: var(--c-brown);">Perlu Review</b>. Buka shift-nya: cek penjelasan kasir, koreksi metode bayar yang salah klik
                (mis. tercatat QRIS padahal kartu debit) dan koreksi angka struk yang salah ketik sesuai struk / aplikasi. Setelah semua baris
                cocok, <b style="color: var(--c-brown);">Setujui</b>. Selisih nyata yang tidak bisa dibetulkan, <b style="color: var(--c-brown);">Tolak</b>.
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
