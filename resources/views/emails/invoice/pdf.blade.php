{{--
    PDF invoice lunas, A4 (lampiran OrderInvoiceMail) — App\Services\Mail\InvoicePdf. Logo, identitas usaha, judul,
    kalimat & warna aksen dari Keuangan → Template Invoice (App\Models\Setting\InvoiceTemplateSetting).
    dompdf: tabel + CSS sederhana saja (tanpa flex / grid), ukuran dalam pt.
    Modul 31: invoice tagihan berkala coaching (belum lunas) memakai template yang sama lewat isian opsional
    document_title / status_label / date_label / total_label / due_note — tanpa isian itu tampilan invoice lunas tidak berubah.
--}}
@php
    $setting = \App\Models\Setting\InvoiceTemplateSetting::current();
    $t = $template ?? $setting->resolved();
    $logo = isset($logo) ? $logo : $setting->logoDataUri();
    $accent = $t['accent_hex'];
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $reach = implode('  ·  ', array_filter([$t['phone'], $t['email']]));
    $docTitle = $invoice['document_title'] ?? $t['document_title'];
    $hasAdjustments = ($invoice['discount'] ?? 0) > 0 || ($invoice['service_charge'] ?? 0) > 0 || ($invoice['tax'] ?? 0) > 0;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $docTitle }} {{ $invoice['number'] }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 9pt; line-height: 1.45; color: #2A1A17; }
        .bar { height: 5pt; background: {{ $accent }}; }
        .page { padding: 28pt 42pt 70pt; }
        table { border-collapse: collapse; }
        .w100 { width: 100%; }
        .top { vertical-align: top; }
        .right { text-align: right; }
        .muted { color: #7A6A62; }
        .label { font-size: 6.5pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; color: #8E7B70; }

        .head td { vertical-align: top; }
        .logo { max-height: 50pt; max-width: 180pt; }
        .brand { font-size: 15pt; font-weight: bold; letter-spacing: 1pt; color: {{ $accent }}; }
        .doc-title { font-size: 24pt; font-weight: bold; letter-spacing: 3pt; text-transform: uppercase; color: {{ $accent }}; line-height: 1; }
        .doc-no { margin-top: 6pt; font-size: 9.5pt; color: #2A1A17; }
        .status { display: inline-block; margin-top: 8pt; padding: 3pt 10pt; border: 0.8pt solid #2F7D4F; border-radius: 10pt; background: #EEF7F1; color: #2F7D4F; font-size: 7pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; }

        .rule { border-top: 0.8pt solid #E8DFD4; margin: 16pt 0 14pt; }

        .party td { width: 50%; vertical-align: top; }
        .party .name { margin-top: 5pt; font-size: 10.5pt; font-weight: bold; }
        .party .line { color: #4A3A33; }

        .meta { width: 100%; margin-top: 16pt; background: #F8F4EE; border-radius: 6pt; }
        .meta td { padding: 10pt 12pt; vertical-align: top; border-left: 0.8pt solid #EDE4D8; }
        .meta td.first { border-left: none; }
        .meta .value { margin-top: 3pt; font-size: 9pt; font-weight: bold; color: #2A1A17; }

        .intro { margin-top: 16pt; color: #4A3A33; }

        .items { width: 100%; margin-top: 18pt; }
        .items th { padding: 0 0 7pt; border-bottom: 1.2pt solid #2A1A17; font-size: 6.5pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; color: #8E7B70; text-align: left; }
        .items td { padding: 9pt 0; border-bottom: 0.6pt solid #EADFD2; vertical-align: top; }
        .items .num { text-align: right; white-space: nowrap; }
        .items .qty { text-align: center; }
        .items .item-name { font-weight: bold; }

        .detail { width: 100%; margin-top: 14pt; }
        .detail td { padding: 3pt 0; vertical-align: top; }
        .detail .code { color: #7A6A62; }

        .summary { width: 100%; margin-top: 16pt; }
        .summary > tbody > tr > td { vertical-align: top; }
        .paid-note { padding: 11pt 12pt; border: 0.8pt solid #E8DFD4; border-radius: 6pt; color: #4A3A33; }
        .paid-note .title { font-weight: bold; color: #2A1A17; margin-bottom: 3pt; }
        .totals { width: 100%; }
        .totals td { padding: 4pt 0; }
        .totals .num { text-align: right; white-space: nowrap; }
        .totals .minus { color: #2F7D4F; }
        .grand { margin-top: 8pt; width: 100%; background: {{ $accent }}; border-radius: 6pt; }
        .grand td { padding: 10pt 12pt; color: #FFFFFF; }
        .grand .k { font-size: 7pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; }
        .grand .v { font-size: 14pt; font-weight: bold; text-align: right; white-space: nowrap; }

        .notes { margin-top: 18pt; padding-top: 12pt; border-top: 0.8pt solid #E8DFD4; color: #4A3A33; }
        .notes .label { margin-bottom: 5pt; }

        .foot { position: fixed; left: 42pt; right: 42pt; bottom: 24pt; padding-top: 9pt; border-top: 0.8pt solid #E8DFD4; font-size: 7.5pt; color: #8E7B70; }
        .foot td { vertical-align: top; }
    </style>
</head>
<body>
    <div class="bar"></div>

    <div class="foot">
        <table class="w100">
            <tr>
                <td style="width:68%; padding-right:14pt;">{{ $t['footer_text'] }}</td>
                <td class="right"><strong style="color:#4A3A33;">{{ $t['website'] ?: $t['business_name'] }}</strong></td>
            </tr>
        </table>
    </div>

    <div class="page">
        {{-- HEADER --}}
        <table class="w100 head">
            <tr>
                <td style="width:55%;">
                    @if ($logo)
                        <img class="logo" src="{{ $logo }}" alt="{{ $t['business_name'] }}">
                    @else
                        <div class="brand">{{ $t['business_name'] }}</div>
                    @endif
                </td>
                <td class="right">
                    <div class="doc-title">{{ $docTitle }}</div>
                    <div class="doc-no">No. {{ $invoice['number'] }}</div>
                    <span class="status">{{ $invoice['status_label'] ?? $t['status_label'] }}</span>
                </td>
            </tr>
        </table>

        <div class="rule"></div>

        {{-- PIHAK --}}
        <table class="w100 party">
            <tr>
                <td style="padding-right:18pt;">
                    <div class="label">Ditagihkan kepada</div>
                    <div class="name">{{ $invoice['customer']['name'] }}</div>
                    @if ($invoice['customer']['email'])<div class="line">{{ $invoice['customer']['email'] }}</div>@endif
                    @if ($invoice['customer']['phone'])<div class="line">{{ $invoice['customer']['phone'] }}</div>@endif
                </td>
                <td class="right">
                    <div class="label">Diterbitkan oleh</div>
                    <div class="name">{{ $t['business_name'] }}</div>
                    @if ($t['address'])<div class="line">{{ $t['address'] }}</div>@endif
                    @if ($reach)<div class="line">{{ $reach }}</div>@endif
                    @if ($t['tax_id'])<div class="line">NPWP {{ $t['tax_id'] }}</div>@endif
                </td>
            </tr>
        </table>

        {{-- RINGKASAN PEMBAYARAN --}}
        <table class="meta">
            <tr>
                <td class="first" style="width:32%;">
                    <div class="label">{{ $invoice['date_label'] ?? 'Tanggal bayar' }}</div>
                    <div class="value">{{ $invoice['paid_at'] }}</div>
                </td>
                <td style="width:18%;">
                    <div class="label">Metode</div>
                    <div class="value">{{ $invoice['method'] }}@if (! empty($invoice['va_number']))<br><span style="font-weight:normal;">VA {{ $invoice['va_number'] }}</span>@endif</div>
                </td>
                <td style="width:25%;">
                    <div class="label">Jenis transaksi</div>
                    <div class="value">{{ $invoice['type'] }}</div>
                </td>
                <td>
                    <div class="label">Referensi</div>
                    <div class="value" style="font-weight:normal; word-wrap:break-word;">{{ $invoice['reference'] ?? $invoice['number'] }}</div>
                </td>
            </tr>
        </table>

        @if ($t['intro_text'])
            <div class="intro">{!! nl2br(e($t['intro_text'])) !!}</div>
        @endif

        {{-- ITEM --}}
        <table class="items">
            <thead>
                <tr>
                    <th style="width:54%;">Deskripsi</th>
                    <th class="qty" style="width:10%; text-align:center;">Qty</th>
                    <th class="num" style="width:18%; text-align:right;">Harga satuan</th>
                    <th class="num" style="width:18%; text-align:right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice['lines'] as $line)
                    <tr>
                        <td class="item-name" style="padding-right:12pt;">{{ $line['name'] }}</td>
                        <td class="qty">{{ $line['quantity'] }}</td>
                        <td class="num">{{ $rp($line['unit_price']) }}</td>
                        <td class="num">{{ $rp($line['subtotal']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (! empty($invoice['bookings']))
            <table class="detail">
                <tr><td colspan="3" class="label" style="padding-bottom:5pt;">Jadwal main</td></tr>
                @foreach ($invoice['bookings'] as $booking)
                    <tr>
                        <td style="width:32%;"><strong>{{ $booking['court'] }}</strong></td>
                        <td style="width:48%;">{{ $booking['date'] }} &nbsp;·&nbsp; {{ $booking['time'] }}</td>
                        <td class="right code">{{ $booking['code'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if (! empty($invoice['membership']))
            <table class="detail">
                <tr><td colspan="2" class="label" style="padding-bottom:5pt;">Membership</td></tr>
                <tr>
                    <td style="width:38%;"><strong>{{ $invoice['membership']['plan'] }}</strong></td>
                    <td>
                        @if ($invoice['membership']['period'])Masa aktif {{ $invoice['membership']['period'] }}@endif
                        @if ($invoice['membership']['code'])<span class="code"> · {{ $invoice['membership']['code'] }}</span>@endif
                    </td>
                </tr>
            </table>
        @endif

        {{-- KONFIRMASI + TOTAL --}}
        <table class="summary">
            <tr>
                <td style="width:50%; padding-right:24pt;">
                    <div class="paid-note">
                        @if (! empty($invoice['due_note']))
                            <div class="title">Menunggu pembayaran</div>
                            {{ $invoice['due_note'] }}
                        @else
                            <div class="title">Pembayaran diterima</div>
                            Dibayar lunas pada {{ $invoice['paid_at'] }} melalui {{ $invoice['method'] }}.
                        @endif
                    </div>
                </td>
                <td style="width:50%;">
                    <table class="totals">
                        @if ($hasAdjustments)
                            <tr><td class="muted">Subtotal</td><td class="num">{{ $rp($invoice['subtotal']) }}</td></tr>
                        @endif
                        @if (($invoice['discount'] ?? 0) > 0)
                            <tr><td class="muted">Diskon{{ $invoice['voucher_code'] ? ' ('.$invoice['voucher_code'].')' : '' }}</td><td class="num minus">-{{ $rp($invoice['discount']) }}</td></tr>
                        @endif
                        @if (($invoice['service_charge'] ?? 0) > 0)
                            <tr><td class="muted">{{ $invoice['service_charge_name'] }}</td><td class="num">{{ $rp($invoice['service_charge']) }}</td></tr>
                        @endif
                        @if (($invoice['tax'] ?? 0) > 0)
                            <tr><td class="muted">{{ $invoice['tax_name'] }}</td><td class="num">{{ $rp($invoice['tax']) }}</td></tr>
                        @endif
                    </table>
                    <table class="grand">
                        <tr>
                            <td class="k">{{ $invoice['total_label'] ?? 'Total dibayar' }}</td>
                            <td class="v">{{ $rp($invoice['grand_total']) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        @if ($t['notes_text'])
            <div class="notes">
                <div class="label">Catatan</div>
                {!! nl2br(e($t['notes_text'])) !!}
            </div>
        @endif
    </div>
</body>
</html>
