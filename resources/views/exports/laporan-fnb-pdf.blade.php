{{--
    Export PDF Laporan Keuangan F&B (Modul 29) — dirender dompdf (CSS terbatas: tabel & inline style, tanpa flex/grid).
    Data: FnbReportExport::pdf() — angka sama dengan halaman Laporan F&B.
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
    <title>Laporan Keuangan F&amp;B</title>
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
        .grid tr.sub td { color: #7A643E; padding-left: 16px; font-size: 8px; }
        .num, .grid th.num { text-align: right; white-space: nowrap; }
        .neg { color: #B42318; }
        .section td { font-size: 9px; font-weight: bold; color: #5C410F; padding: 14px 0 5px 0; text-transform: uppercase; letter-spacing: 0.6px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 7px; color: #8C754E; }
        .pagenum:before { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        Club 61 · Laporan Keuangan F&amp;B · dicetak {{ $printedAt }} oleh {{ $printedBy ?? '-' }} · halaman <span class="pagenum"></span>
    </div>

    <table>
        <tr>
            <td style="width:60%;">
                @if ($logo)
                    <img src="{{ $logo }}" style="height:34px; vertical-align:middle; margin-right:8px;">
                @endif
                <span style="vertical-align:middle;"><h1 style="display:inline;">Laporan Keuangan F&amp;B</h1></span>
                <div class="muted" style="margin-top:3px;">Club 61 Padel &amp; Wellness</div>
            </td>
            <td style="width:40%; text-align:right;" class="muted">
                <div style="font-size:10px; font-weight:bold; color:#1F170D;">{{ $periodLabel }}</div>
                <div>Pemasukan = penjualan kasir F&amp;B (Riwayat Transaksi)</div>
                <div>Laba memakai HPP menu pada saat penjualan</div>
            </td>
        </tr>
    </table>

    <table class="cards" style="margin-top:10px;">
        <tr>
            <td><div class="lbl">Penjualan</div><div class="val">{{ $rp($s['sales']) }}</div><div class="muted">Tanpa pajak &amp; biaya layanan</div></td>
            <td><div class="lbl">Laba Kotor</div><div class="val">{{ $rp($s['gross_profit']) }}</div><div class="muted">Margin {{ $pct($s['gross_profit'], $s['sales']) }}%</div></td>
            <td style="background:#FAF2DE;"><div class="lbl">Laba Bersih</div><div class="val {{ $s['net_profit'] < 0 ? 'neg' : '' }}">{{ $rp($s['net_profit']) }}</div></td>
            <td><div class="lbl">Arus Kas</div><div class="val {{ $s['cash_flow'] < 0 ? 'neg' : '' }}">{{ $rp($s['cash_flow']) }}</div><div class="muted">Uang masuk − semua pengeluaran</div></td>
        </tr>
    </table>

    <table style="margin-top:4px;">
        <tr>
            <td style="width:49%; vertical-align:top; padding-right:8px;">
                <table class="section"><tr><td>Laporan Laba</td></tr></table>
                <table class="grid">
                    <tr><td>Penjualan</td><td class="num">{{ $rp($s['sales']) }}</td></tr>
                    <tr><td>HPP terjual</td><td class="num neg">− {{ $rp($s['hpp']) }}</td></tr>
                    <tr class="total"><td>Laba Kotor</td><td class="num">{{ $rp($s['gross_profit']) }}</td></tr>
                    <tr><td>Kerugian stok</td><td class="num neg">− {{ $rp($s['stock_loss']) }}</td></tr>
                    <tr><td>Biaya operasional</td><td class="num neg">− {{ $rp($s['operational']) }}</td></tr>
                    <tr class="total"><td>Laba Bersih</td><td class="num">{{ $rp($s['net_profit']) }}</td></tr>
                </table>
            </td>
            <td style="width:51%; vertical-align:top; padding-left:8px;">
                <table class="section"><tr><td>Arus Kas</td></tr></table>
                <table class="grid">
                    <tr><td>Uang masuk penjualan (termasuk pajak &amp; layanan)</td><td class="num">{{ $rp($s['money_in']) }}</td></tr>
                    <tr><td>Pembelian persediaan &amp; bahan</td><td class="num neg">− {{ $rp($s['stock_purchases']) }}</td></tr>
                    <tr><td>Biaya operasional</td><td class="num neg">− {{ $rp($s['operational']) }}</td></tr>
                    @if (count($s['expense_by_category']))
                        <tr class="sub"><td colspan="2" style="font-weight:bold;">Rincian pengeluaran per kategori:</td></tr>
                    @endif
                    @foreach ($s['expense_by_category'] as $name => $amount)
                        <tr class="sub"><td>{{ $name }}</td><td class="num">{{ $rp($amount) }}</td></tr>
                    @endforeach
                    <tr class="total"><td>Selisih Arus Kas</td><td class="num">{{ $rp($s['cash_flow']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($s['missing_cost_qty'] > 0)
        <div class="muted" style="margin-top:6px;">Catatan: {{ $s['missing_cost_qty'] }} item terjual belum memiliki HPP (HPP menu belum diisi, penjualan sebelum fitur ini, atau data impor), dihitung HPP Rp0. Menu: {{ $s['menus']->where('missing_cost_qty', '>', 0)->pluck('name')->implode(', ') }}.</div>
    @endif

    <table class="section"><tr><td>Rekap per menu</td></tr></table>
    <table class="grid">
        <thead><tr>
            <th style="width:40%;">Menu</th><th class="num" style="width:10%;">Terjual</th><th class="num" style="width:15%;">Penjualan</th>
            <th class="num" style="width:13%;">HPP</th><th class="num" style="width:13%;">Laba Kotor</th><th class="num" style="width:9%;">Margin</th>
        </tr></thead>
        <tbody>
            @forelse ($menus as $m)
                <tr>
                    <td>{{ $m['name'] }}</td><td class="num">{{ $m['qty'] }}</td><td class="num">{{ $rp($m['sales']) }}</td>
                    <td class="num">{{ $rp($m['hpp']) }}</td><td class="num">{{ $rp($m['profit']) }}</td><td class="num">{{ $pct($m['profit'], $m['sales']) }}%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Belum ada penjualan pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="section"><tr><td>Stok menipis / habis / minus (saat dicetak)</td></tr></table>
    <table class="grid" style="width:60%;">
        <thead><tr><th>Menu</th><th class="num">Stok</th><th class="num">Minimum</th></tr></thead>
        <tbody>
            @forelse ($alerts as $menu)
                <tr><td>{{ $menu->name }}</td><td class="num {{ $menu->stock_qty < 0 ? 'neg' : '' }}">{{ $menu->stock_qty }}</td><td class="num">{{ $menu->min_stock ?? '-' }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">Semua stok aman.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
