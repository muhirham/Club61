<x-filament-panels::page>
    <div class="c61 ar-root" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        <div class="c61-card" style="border-left: 4px solid var(--c-terra);">
            <div class="c61-card-body" style="display: flex; gap: 1rem; align-items: flex-start; padding: 1rem 1.25rem;">
                <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg></span>
                <div style="font-size: 0.8125rem; line-height: 1.6; color: var(--c-muted);">
                    <div class="c61-card-title" style="font-size: 1.0625rem; margin-bottom: 0.2rem;">Uang customer yang harus dikembalikan</div>
                    Pengajuan pembatalan dari Kelola Pemesanan, kelebihan bayar, pembayaran ganda, dan uang yang masuk setelah booking dibatalkan / hangus.
                    <b style="color: var(--c-brown);">Setujui:</b> kembalikan uangnya dulu (transfer, void EDC, atau refund di Dashboard Midtrans), lalu tekan <b style="color: var(--c-brown);">Proses</b> dan isi nomor referensinya —
                    refund otomatis tercatat di Buku Transaksi sebagai uang keluar. <b style="color: var(--c-brown);">Tolak:</b> uangnya jadi voucher saldo customer (lihat Daftar Voucher).
                </div>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
