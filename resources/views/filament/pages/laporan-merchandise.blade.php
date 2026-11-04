<div>
    @php
        $rp = fn ($v) => \App\Filament\Pages\BukuTransaksi::rupiah($v);
        $pct = fn ($part, $whole) => $whole != 0 ? round($part / $whole * 100) : 0;
    @endphp

    <div class="c61 lm-root" style="gap: 1rem;">
        @include('filament.partials.c61-admin-style')
        <style>
            .lm-root .lm-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
            .lm-root .lm-lines { padding: 0.5rem 1.25rem 1rem; }
            .lm-root .lm-line { display: flex; justify-content: space-between; gap: 1rem; padding: 0.55rem 0; border-bottom: 1px dashed var(--c-line-soft); font-size: 0.8125rem; }
            .lm-root .lm-line span:last-child { font-family: var(--font-mono); font-weight: 700; white-space: nowrap; }
            .lm-root .lm-line.is-total { border-bottom: none; border-top: 2px solid var(--c-line); font-weight: 800; font-size: 0.9375rem; padding-top: 0.75rem; }
            .lm-root .lm-minus { color: #B42318; }
            .lm-root .lm-table th:first-child, .lm-root .lm-table td:first-child { padding-left: 1.25rem; }
            .lm-root .lm-table th:last-child, .lm-root .lm-table td:last-child { padding-right: 1.25rem; }
            .lm-root .lm-num { text-align: right; white-space: nowrap; font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
            @media (max-width: 1000px) { .lm-root .lm-grid-2 { grid-template-columns: 1fr; } }
        </style>

        <div class="c61-hero">
            <div>
                <div class="c61-eyebrow">Merchandise · Laporan</div>
                <div class="c61-hero-title">Laporan Merchandise</div>
                <div class="c61-hero-sub">Penjualan diambil dari Buku Transaksi kategori Merchandise. Laba dihitung menggunakan HPP produk pada saat penjualan; pembelian barang hanya informasi arus kas karena biayanya telah diperhitungkan melalui HPP.</div>
            </div>
            <div class="c61-hero-actions">
                <select wire:model.live="preset" class="c61-select" style="width: auto; background: #FFFFFF;">
                    @foreach (\App\Services\Finance\LedgerReport::PRESETS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if ($preset === 'kustom')
                    <input type="date" wire:model.live="from" class="c61-input" style="width: auto; background: #FFFFFF;">
                    <input type="date" wire:model.live="until" class="c61-input" style="width: auto; background: #FFFFFF;">
                @endif
                @if (\App\Filament\Pages\LaporanMerchandise::canExport())
                    <x-filament::dropdown placement="bottom-end" teleport>
                        <x-slot name="trigger">
                            <button type="button" class="c61-btn c61-btn-cream">
                                Unduh Laporan
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2.5" d="m6 9 6 6 6-6"/></svg>
                            </button>
                        </x-slot>
                        <x-filament::dropdown.list>
                            <x-filament::dropdown.list.item icon="heroicon-o-table-cells" wire:click="exportExcel" x-on:click="close()">Excel (lengkap, 4 sheet)</x-filament::dropdown.list.item>
                            <x-filament::dropdown.list.item icon="heroicon-o-document-text" wire:click="exportPdf" x-on:click="close()">PDF (ringkasan siap cetak)</x-filament::dropdown.list.item>
                        </x-filament::dropdown.list>
                    </x-filament::dropdown>
                @endif
            </div>
        </div>

        <div class="c61-kpis">
            <div class="c61-kpi">
                <div class="c61-kpi-label">Penjualan Merchandise</div>
                <div class="c61-kpi-value is-terra" style="font-size: 1.4rem;">{{ $rp($s['sales']) }}</div>
                <div class="c61-kpi-foot"><span>{{ $periodLabel }} · {{ $s['qty_sold'] }} unit</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Laba Kotor</div>
                <div class="c61-kpi-value" style="font-size: 1.4rem;">{{ $rp($s['gross_profit']) }}</div>
                <div class="c61-kpi-foot"><span>Margin {{ $pct($s['gross_profit'], $s['sales']) }}% dari penjualan</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Setelah Kerugian Stok</div>
                <div class="c61-kpi-value {{ $s['net_profit'] >= 0 ? 'is-ok' : '' }}" style="font-size: 1.4rem; {{ $s['net_profit'] < 0 ? 'color:#B42318;' : '' }}">{{ $rp($s['net_profit']) }}</div>
                <div class="c61-kpi-foot"><span>Kerugian stok {{ $rp($s['stock_loss']) }}</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Pembelian Barang</div>
                <div class="c61-kpi-value" style="font-size: 1.4rem;">{{ $rp($s['purchases']) }}</div>
                <div class="c61-kpi-foot"><span>Total harga beli Barang Masuk</span></div>
            </div>
        </div>

        @if ($s['missing_cost_qty'] > 0)
            <div class="c61-note c61-note-warn">{{ $s['missing_cost_qty'] }} unit terjual di periode ini belum punya HPP (HPP produk belum diisi saat terjual), dihitung HPP Rp0. Laba kotornya bisa terlihat lebih besar dari sebenarnya. Isi HPP di Katalog Merchandise untuk penjualan berikutnya.</div>
        @endif

        <div class="lm-grid-2">
            <div class="c61-card">
                <div class="c61-card-head"><div><div class="c61-card-title">Laporan Laba</div><div class="c61-card-sub">{{ $periodLabel }}</div></div></div>
                <div class="lm-lines">
                    <div class="lm-line"><span>Penjualan (bersih, tanpa pajak &amp; biaya layanan)</span><span>{{ $rp($s['sales']) }}</span></div>
                    <div class="lm-line"><span>HPP terjual</span><span class="lm-minus">− {{ $rp($s['hpp']) }}</span></div>
                    <div class="lm-line is-total"><span>Laba Kotor</span><span>{{ $rp($s['gross_profit']) }}</span></div>
                    <div class="lm-line"><span>Kerugian stok (rusak, hilang, selisih)</span><span class="lm-minus">− {{ $rp($s['stock_loss']) }}</span></div>
                    <div class="lm-line is-total"><span>Laba setelah kerugian stok</span><span>{{ $rp($s['net_profit']) }}</span></div>
                </div>
            </div>
            <div class="c61-card">
                <div class="c61-card-head"><div><div class="c61-card-title">Stok Habis / Menipis</div><div class="c61-card-sub">Varian aktif yang perlu diisi ulang</div></div></div>
                <div class="c61-table-wrap">
                    <table class="c61-table lm-table">
                        <thead><tr><th>Produk · Varian</th><th class="lm-num">Stok</th><th class="lm-num">Minimum</th></tr></thead>
                        <tbody>
                            @forelse ($alerts as $v)
                                <tr wire:key="lm-alert-{{ $v->id }}">
                                    <td>{{ $v->product?->name }} · {{ $v->label() }}</td>
                                    <td class="lm-num" style="{{ $v->stock_quantity <= 0 ? 'color:#B42318;' : 'color:#B45309;' }}">{{ $v->stock_quantity }}</td>
                                    <td class="lm-num">{{ $v->min_stock ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="c61-empty">Tidak ada stok yang habis atau menipis.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="c61-card">
            <div class="c61-card-head"><div><div class="c61-card-title">Rekap per Varian</div><div class="c61-card-sub">Transaksi yang pembayarannya masuk di periode ini</div></div></div>
            <div class="c61-table-wrap">
                <table class="c61-table lm-table" style="min-width:720px;">
                    <thead>
                        <tr><th>Produk · Varian</th><th class="lm-num">Terjual</th><th class="lm-num">Penjualan</th><th class="lm-num">HPP</th><th class="lm-num">Laba Kotor</th><th class="lm-num">Margin</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($s['items'] as $item)
                            <tr>
                                <td>{{ $item['name'] }}</td>
                                <td class="lm-num">{{ $item['qty'] }}</td>
                                <td class="lm-num">{{ $rp($item['sales']) }}</td>
                                <td class="lm-num">{{ $rp($item['hpp']) }}</td>
                                <td class="lm-num" style="{{ $item['profit'] < 0 ? 'color:#B42318;' : '' }}">{{ $rp($item['profit']) }}</td>
                                <td class="lm-num">{{ $pct($item['profit'], $item['sales']) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="c61-empty">Belum ada penjualan merchandise di periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
