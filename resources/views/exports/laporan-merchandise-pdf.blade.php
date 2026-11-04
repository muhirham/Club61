{{--
    Export PDF Laporan Merchandise — dirender dompdf (CSS terbatas: tabel & inline style, tanpa flex/grid).
    Data: MerchReportExport::pdf() — angka sama dengan halaman Laporan Merchandise.
--}}
@php
    $rp = fn ($v) => \App\Filament\Pages\BukuTransaksi::rupiah($v);
    $pct = fn ($part, $whole) => $whole != 0 ? round($part / $whole * 100) : 0;
    $logoPath = public_path('images/club61-logo.png');
    $logo = is_file($logoPath) && filesize($logoPath) < 400000 ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Merchandise</title>
    <style>
        @page { margin: 24px 28px 36px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1F170D; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #7A643E; }
        table { width: 100%; border-collapse: collapse; }
        .cards td { border: 1px solid #E2D3AE; padding: 7px 8px; width: 25%; vertical-align: top; }
        .cards .lbl { font-size: 7px; font-weight: bold; text-transform: uppercase; color: #8C754E; letter-spacing: 0.5px; }
        .cards .val { font-size: 12.5px; font-weight: bold; margin-top: 2px; }
        .grid th { background: #F5E5BE; color: #5C410F; font-size: 7.5px; text-align: left; padding: 4px 6px; border-bottom: 1px solid #D9BE84; }
        .grid td { padding: 4px 6px; border-bottom: 1px solid #EFE4C8; vertical-align: top; }
        .grid tr.total td { font-weight: bold; border-top: 1.5px solid #D9BE84; background: #FAF2DE; }
        .num, .grid th.num { text-align: right; white-space: nowrap; }
        .neg { color: #B42318; }
        .section td { font-size: 9px; font-weight: bold; color: #5C410F; padding: 14px 0 5px 0; text-transform: uppercase; letter-spacing: 0.6px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 7px; color: #8C754E; }
        .pagenum:before { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        Club 61 · Laporan Merchandise · dicetak {{ $printedAt }} oleh {{ $printedBy ?? '-' }} · halaman <span class="pagenum"></span>
    </div>

    <table>
        <tr>
            <td style="width:60%;">
                @if ($logo)
                    <img src="{{ $logo }}" style="height:34px; vertical-align:middle; margin-right:8px;">
                @endif
                <span style="vertical-align:middle;"><h1 style="display:inline;">Laporan Merchandise</h1></span>
                <div class="muted" style="margin-top:3px;">Club 61 Padel &amp; Wellness</div>
            </td>
            <td style="width:40%; text-align:right;" class="muted">
                <div style="font-size:10px; font-weight:bold; color:#1F170D;">{{ $periodLabel }}</div>
                <div>Penjualan = Buku Transaksi kategori Merchandise</div>
                <div>Laba memakai HPP produk pada saat penjualan</div>
            </td>
        </tr>
    </table>

    <table class="cards" style="margin-top:10px;">
        <tr>
            <td><div class="lbl">Penjualan</div><div class="val">{{ $rp($s['sales']) }}</div><div class="muted">Tanpa pajak &amp; biaya layanan</div></td>
            <td><div class="lbl">HPP Terjual</div><div class="val">{{ $rp($s['hpp']) }}</div><div class="muted">{{ $s['qty_sold'] }} unit</div></td>
            <td><div class="lbl">Laba Kotor</div><div class="val">{{ $rp($s['gross_profit']) }}</div><div class="muted">Margin {{ $pct($s['gross_profit'], $s['sales']) }}%</div></td>
            <td><div class="lbl">Setelah Kerugian Stok</div><div class="val {{ $s['net_profit'] < 0 ? 'neg' : '' }}">{{ $rp($s['net_profit']) }}</div><div class="muted">Kerugian stok {{ $rp($s['stock_loss']) }}</div></td>
        </tr>
    </table>

    @if ($s['missing_cost_qty'] > 0)
        <div style="margin-top:8px; padding:6px 8px; border:1px solid #FCD34D; background:#FFFBEB;">
            {{ $s['missing_cost_qty'] }} unit terjual belum memiliki HPP (dihitung Rp0) — laba kotor bisa terlihat lebih besar dari sebenarnya.
        </div>
    @endif

    <table><tr class="section"><td>Rekap per Varian</td></tr></table>
    <table class="grid">
        <thead>
            <tr><th>Produk · Varian</th><th class="num">Terjual</th><th class="num">Penjualan</th><th class="num">HPP</th><th class="num">Laba Kotor</th><th class="num">Margin</th></tr>
        </thead>
        <tbody>
            @forelse ($s['items'] as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td class="num">{{ $item['qty'] }}</td>
                    <td class="num">{{ $rp($item['sales']) }}</td>
                    <td class="num">{{ $rp($item['hpp']) }}</td>
                    <td class="num {{ $item['profit'] < 0 ? 'neg' : '' }}">{{ $rp($item['profit']) }}</td>
                    <td class="num">{{ $pct($item['profit'], $item['sales']) }}%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Belum ada penjualan merchandise di periode ini.</td></tr>
            @endforelse
            @if ($s['items']->isNotEmpty())
                <tr class="total">
                    <td>Total</td>
                    <td class="num">{{ $s['qty_sold'] }}</td>
                    <td class="num">{{ $rp($s['items']->sum('sales')) }}</td>
                    <td class="num">{{ $rp($s['hpp']) }}</td>
                    <td class="num">{{ $rp($s['items']->sum('profit')) }}</td>
                    <td class="num"></td>
                </tr>
            @endif
        </tbody>
    </table>

    <table><tr class="section"><td>Informasi Arus Kas</td></tr></table>
    <table class="grid">
        <tr><td>Uang masuk penjualan (termasuk pajak &amp; biaya layanan)</td><td class="num">{{ $rp($s['money_in']) }}</td></tr>
        <tr><td>Pembelian barang (Barang Masuk)</td><td class="num">{{ $rp($s['purchases']) }}</td></tr>
    </table>

    <table><tr class="section"><td>Stok Habis / Menipis</td></tr></table>
    <table class="grid">
        <thead><tr><th>Produk · Varian</th><th>SKU</th><th class="num">Stok</th><th class="num">Stok Minimum</th></tr></thead>
        <tbody>
            @forelse ($alerts as $v)
                <tr>
                    <td>{{ $v->product?->name }} · {{ $v->label() }}</td>
                    <td>{{ $v->sku }}</td>
                    <td class="num {{ $v->stock_quantity <= 0 ? 'neg' : '' }}">{{ $v->stock_quantity }}</td>
                    <td class="num">{{ $v->min_stock ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Tidak ada stok yang habis atau menipis.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
