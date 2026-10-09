<x-filament-panels::page>
    @php $rp = fn ($v) => \App\Filament\Pages\BukuTransaksi::rupiah($v); @endphp

    <div class="c61 dv-root" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        <div class="c61-kpis">
            <div class="c61-kpi" style="background: var(--c-terra); border-color: var(--c-terra);">
                <div class="c61-kpi-label" style="color: rgba(247,240,219,0.75);">Sisa saldo voucher aktif</div>
                <div class="c61-kpi-value" style="color: var(--c-cream);">{{ $rp($summary['outstanding']) }}</div>
                <div class="c61-kpi-foot" style="color: rgba(247,240,219,0.75); border-top-color: rgba(247,240,219,0.25);"><span>{{ $summary['active_count'] }} voucher &middot; uang customer yang masih disimpan klub</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Total voucher saldo terbit</div>
                <div class="c61-kpi-value">{{ $rp($summary['issued_amount']) }}</div>
                <div class="c61-kpi-foot"><span>{{ $summary['issued_count'] }} voucher dari refund yang ditolak</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Sudah dipakai customer</div>
                <div class="c61-kpi-value is-ok">{{ $rp($summary['used_amount']) }}</div>
                <div class="c61-kpi-foot"><span>Jadi potongan booking (bukan uang masuk baru)</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Saldo hangus (kedaluwarsa)</div>
                <div class="c61-kpi-value" style="color: #B45309;">{{ $rp($summary['expired_balance']) }}</div>
                <div class="c61-kpi-foot"><span>Tidak dipakai sampai masa berlaku habis</span></div>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
