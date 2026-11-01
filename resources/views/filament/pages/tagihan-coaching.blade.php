<x-filament-panels::page>
    <div class="c61" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')
        @php $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp

        <div class="c61-card">
            <div class="c61-card-body" style="font-size: 0.8125rem; line-height: 1.55; color: var(--c-muted); padding: 0.9rem 1.25rem;">
                Sesi coaching academy dengan metode <b style="color: var(--c-brown);">Tagihan Berkala</b>. Pelunasan diterima di kasir melalui
                <b style="color: var(--c-brown);">POS Walk-In → Pelunasan Tagihan Coaching</b>; halaman ini untuk memantau tagihan, riwayat pelunasan,
                dan mengunduh invoice untuk dikirim ke academy.
                @if ($canOpenPos)
                    <a href="{{ \App\Filament\Pages\BookOfflineCourt::getUrl() }}" style="color: var(--c-terra); font-weight: 700;">Buka POS Walk-In →</a>
                @endif
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem;">
            <div class="c61-card"><div class="c61-card-body" style="padding: 0.9rem 1.1rem;">
                <div class="c61-label">Belum Lunas</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: var(--c-brown);">{{ $rupiah($kpi['unpaid']['amount']) }}</div>
                <div style="font-size: 0.75rem; color: var(--c-muted);">{{ $kpi['unpaid']['sessions'] }} sesi</div>
            </div></div>
            <div class="c61-card"><div class="c61-card-body" style="padding: 0.9rem 1.1rem;">
                <div class="c61-label">Melewati Jatuh Tempo</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #B42318;">{{ $rupiah($kpi['overdue']['amount']) }}</div>
                <div style="font-size: 0.75rem; color: var(--c-muted);">{{ $kpi['overdue']['sessions'] }} sesi</div>
            </div></div>
            <div class="c61-card"><div class="c61-card-body" style="padding: 0.9rem 1.1rem;">
                <div class="c61-label">Dilunasi Bulan Ini</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #047857;">{{ $rupiah($kpi['settled_month']) }}</div>
                <div style="font-size: 0.75rem; color: var(--c-muted);">dari Pelunasan Tagihan Coaching</div>
            </div></div>
        </div>

        {{ $this->table }}

        <div class="c61-card">
            <div class="c61-card-head" style="padding: 0.9rem 1.25rem;">
                <div class="c61-card-title">Riwayat Pelunasan</div>
                <div style="font-size: 0.75rem; color: var(--c-muted);">30 pelunasan terakhir</div>
            </div>
            <div style="overflow-x: auto;">
                <table class="c61-table" style="width: 100%;">
                    <thead>
                        <tr><th>Nomor</th><th>Waktu</th><th>Academy</th><th>Metode</th><th>Referensi</th><th style="text-align:right;">Sesi</th><th style="text-align:right;">Total</th><th>Kasir</th><th>Bukti</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($settlements as $settlement)
                            <tr wire:key="settlement-{{ $settlement->id }}">
                                <td class="code">{{ $settlement->settlement_number }}</td>
                                <td>{{ $settlement->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
                                <td>{{ $settlement->academy_name }}</td>
                                <td>{{ $methods[$settlement->payment_method] ?? $settlement->payment_method }}</td>
                                <td>{{ $settlement->reference ?? '-' }}{{ $settlement->sender_name ? ' · '.$settlement->sender_name : '' }}</td>
                                <td style="text-align:right;">{{ $settlement->session_count }}</td>
                                <td style="text-align:right; font-weight: 700;">{{ $rupiah($settlement->total_amount) }}</td>
                                <td>{{ $settlement->creator?->name ?? '-' }}</td>
                                <td>
                                    @if ($settlement->proof_path)
                                        <button type="button" wire:click="downloadProof('{{ $settlement->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Unduh</button>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" style="text-align: center; color: var(--c-muted); padding: 1.25rem;">Belum ada pelunasan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
