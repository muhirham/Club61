<x-filament-panels::page>
    <div class="c61 la-root" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        <div class="c61-card" style="border-left: 4px solid var(--c-terra);">
            <div class="c61-card-body" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 1rem 1.5rem; font-size: 0.8125rem; line-height: 1.6; color: var(--c-muted);">
                <div>
                    <div class="c61-card-title" style="font-size: 1.0625rem; margin-bottom: 0.2rem;">Jejak semua aktivitas staf, customer & sistem</div>
                    Transaksi lunas, refund, reschedule, check-in, buka/tutup shift, perubahan harga & menu, perubahan user/role, dan login.
                    Klik baris untuk melihat detail perubahan <b style="color: var(--c-brown);">sebelum &rarr; sesudah</b>.
                </div>
                <div class="c61-note" style="font-size: 0.75rem;">
                    Log bersifat <b>permanen &amp; hanya-baca</b> — tidak bisa diedit atau dihapus dari aplikasi oleh siapa pun.
                    Disimpan {{ (int) config('audit.retention_months', 24) }} bulan.
                </div>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
