{{--
    Export PDF Rekap Jam Main Tagihan Bulanan (Modul 34) — dirender dompdf (CSS terbatas: tabel & inline style).
    Data: MonthlyBillingRecapExport::data() — sama dengan halaman Tagihan Bulanan. Tanpa harga (ditentukan Finance).
--}}
@php
    $hours = fn ($v) => \App\Services\Membership\MonthlyBilling::hoursLabel((float) $v);
    $t = $recap['totals'];
    $logoPath = public_path('images/club61-logo.png');
    $logo = is_file($logoPath) && filesize($logoPath) < 400000 ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 24px 28px 36px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1F170D; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #7A643E; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 0; vertical-align: top; }
        .info td.lbl { width: 22%; color: #7A643E; }
        .cards td { border: 1px solid #E2D3AE; padding: 7px 8px; width: 25%; vertical-align: top; }
        .cards .lbl { font-size: 7px; font-weight: bold; text-transform: uppercase; color: #8C754E; letter-spacing: 0.5px; }
        .cards .val { font-size: 12.5px; font-weight: bold; margin-top: 2px; }
        .grid th { background: #F5E5BE; color: #5C410F; font-size: 7.5px; text-align: left; padding: 4px 6px; border-bottom: 1px solid #D9BE84; }
        .grid td { padding: 4px 6px; border-bottom: 1px solid #EFE4C8; vertical-align: top; }
        .grid tr.total td { font-weight: bold; border-top: 1.5px solid #D9BE84; background: #FAF2DE; }
        .num, .grid th.num { text-align: right; white-space: nowrap; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 7px; color: #8C754E; }
        .pagenum:before { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        Club 61 · {{ $title }} · dicetak {{ $printedAt }} oleh {{ $printedBy ?? '-' }} · halaman <span class="pagenum"></span>
    </div>

    <table>
        <tr>
            <td style="width:60%;">
                @if ($logo)
                    <img src="{{ $logo }}" style="height:34px; vertical-align:middle; margin-right:8px;">
                @endif
                <span style="vertical-align:middle;"><h1 style="display:inline;">{{ $title }}</h1></span>
                <div class="muted" style="margin-top:3px;">Club 61 Padel &amp; Wellness</div>
            </td>
            <td style="width:40%; text-align:right;" class="muted">
                <div style="font-size:10px; font-weight:bold; color:#1F170D;">Periode {{ $month }}</div>
                <div>Menurut tanggal main</div>
            </td>
        </tr>
    </table>

    <table class="info" style="margin-top:10px;">
        <tr><td class="lbl">Perusahaan</td><td><b>{{ $company }}</b></td></tr>
        <tr><td class="lbl">Nomor Kartu</td><td>{{ $card ?? '-' }}</td></tr>
        <tr><td class="lbl">PIC</td><td>{{ $pic ?? '-' }}</td></tr>
        <tr><td class="lbl">Status Pembayaran</td><td>
            @if ($payment)
                Lunas — Rp {{ number_format($payment['amount'], 0, ',', '.') }}{{ $payment['date'] ? ', transfer '.\Carbon\Carbon::parse($payment['date'])->locale('id')->translatedFormat('d M Y') : '' }}{{ $payment['reference'] ? ' (Ref. '.$payment['reference'].')' : '' }}
            @else
                Belum dibayar
            @endif
        </td></tr>
    </table>

    <table class="cards" style="margin-top:10px;">
        <tr>
            <td><div class="lbl">Jumlah Booking</div><div class="val">{{ $t['bookings'] }}</div></td>
            <td><div class="lbl">Total Jam</div><div class="val">{{ $hours($t['hours']) }} jam</div></td>
            <td><div class="lbl">Jam Reguler</div><div class="val">{{ $hours($t['regular_hours']) }} jam</div><div class="muted">Di luar jam peak</div></td>
            <td><div class="lbl">Jam Peak</div><div class="val">{{ $hours($t['peak_hours']) }} jam</div><div class="muted">Mengikuti Master Data</div></td>
        </tr>
    </table>
    <div class="muted" style="margin-top:4px;">
        Sudah main {{ $hours($t['played_hours']) }} jam · tidak hadir {{ $hours($t['no_show_hours']) }} jam (tetap dihitung) · belum main {{ $hours($t['scheduled_hours']) }} jam.
    </div>

    <table class="grid" style="margin-top:12px;">
        <thead>
            <tr>
                <th>Kode Booking</th><th>Tanggal</th><th>Jam</th><th>Lapangan</th>
                <th class="num">Durasi</th><th class="num">Reguler</th><th class="num">Peak</th><th>Status</th><th>Sumber</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($recap['rows'] as $r)
                <tr>
                    <td>{{ $r['code'] }}</td><td>{{ $r['date'] }}</td><td>{{ $r['time'] }}</td><td>{{ $r['court'] }}</td>
                    <td class="num">{{ $hours($r['hours']) }}</td><td class="num">{{ $hours($r['regular_hours']) }}</td><td class="num">{{ $hours($r['peak_hours']) }}</td>
                    <td>{{ $r['status_label'] }}</td><td>{{ $r['source'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted" style="text-align:center; padding:10px;">Tidak ada booking pada periode ini.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="4">Total</td>
                <td class="num">{{ $hours($t['hours']) }}</td><td class="num">{{ $hours($t['regular_hours']) }}</td><td class="num">{{ $hours($t['peak_hours']) }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    <div class="muted" style="margin-top:10px;">
        Rekap ini tidak memuat harga. Harga per jam dan tagihan ditentukan serta diterbitkan oleh Finance Club 61.
    </div>
</body>
</html>
