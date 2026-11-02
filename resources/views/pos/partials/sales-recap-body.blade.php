{{--
    Isi Rekap Penjualan Harian / X-Report (#printable-sales-recap) — $recap dari App\Services\Pos\DailySalesRecapService.
    Dicetak lewat club61PrintReceipt (pos.partials.receipt-print-script). Setiap baris dijaga muat satu baris kertas
    (ReceiptPaper::PC_COLUMNS = 28 karakter): label metode dipendekkan di service, nominal ada di baris sendiri
    ("3 transaksi ......... Rp 250.000") supaya tidak ada yang turun.
--}}
@php
    $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $line = 'display:flex; justify-content:space-between; gap:0.5rem;';
    $section = 'border-top:1px dashed #9CA3AF; padding-top:0.4rem; display:flex; flex-direction:column; gap:0.15rem;';
@endphp
<div id="printable-sales-recap" style="font-family:monospace; font-size:0.72rem; color:#111827; display:flex; flex-direction:column; gap:0.5rem;">
    <div style="text-align:center;">
        <div style="font-weight:900;">CLUB 61</div>
        <div style="font-weight:900; font-size:0.85rem; margin-top:0.2rem;">REKAP PENJUALAN HARIAN</div>
        <div>(X-REPORT)</div>
        <div>Loket: {{ $recap['counter_label'] }}</div>
    </div>

    <div style="{{ $section }}">
        <div>Tanggal: {{ $recap['date_label'] }}</div>
        <div>Periode: {{ $recap['opened_at'] }} - {{ $recap['until'] }}</div>
        <div>Status: {{ $recap['still_open'] ? $recap['open_shift_count'].' shift masih buka' : 'Semua shift tutup' }}</div>
        <div>Jumlah shift: {{ $recap['shift_count'] }}</div>
        @foreach ($recap['carried_over'] ?? [] as $carried)
            <div>Shift sejak {{ $carried['since'] }}</div>
            @if ($carried['still_open'])
                <div style="font-weight:900;">BELUM DITUTUP</div>
            @endif
        @endforeach
        <div>Dicetak: {{ $recap['printed_at'] }}</div>
        <div>Oleh: {{ $recap['printed_by'] }}</div>
    </div>

    <div style="{{ $section }}">
        <div style="font-weight:900;">PENJUALAN DI KASIR</div>
        <div style="{{ $line }}"><span>Transaksi</span><span>{{ $recap['transactions'] }}</span></div>
        <div style="{{ $line }} font-weight:900;"><span>TOTAL</span><span>{{ $rupiah($recap['total']) }}</span></div>
    </div>

    <div style="{{ $section }}">
        <div style="font-weight:900;">METODE BAYAR</div>
        @forelse ($recap['methods'] as $method)
            <div style="margin-top:0.2rem;">
                <div style="font-weight:700;">{{ $method['label'] }}</div>
                <div style="{{ $line }}"><span>{{ $method['count'] }} transaksi</span><span>{{ $rupiah($method['amount']) }}</span></div>
            </div>
        @empty
            <div>Belum ada transaksi.</div>
        @endforelse
    </div>

    {{-- Shift lupa ditutup dari hari sebelumnya: total seluruh shift untuk dicocokkan saat Tutup Shift. --}}
    @foreach ($recap['running_shifts'] ?? [] as $running)
        <div style="{{ $section }}">
            <div style="font-weight:900;">SHIFT BELUM DITUTUP</div>
            <div>{{ $running['shift_number'] }}</div>
            <div>Sejak {{ $running['since'] }} s/d skrg</div>
            <div style="{{ $line }}"><span>Transaksi</span><span>{{ $running['transactions'] }}</span></div>
            <div style="{{ $line }} font-weight:900;"><span>TOTAL</span><span>{{ $rupiah($running['total']) }}</span></div>
            @foreach ($running['methods'] as $method)
                <div style="margin-top:0.2rem;">
                    <div style="font-weight:700;">{{ $method['label'] }}</div>
                    <div style="{{ $line }}"><span>{{ $method['count'] }} transaksi</span><span>{{ $rupiah($method['amount']) }}</span></div>
                </div>
            @endforeach
        </div>
    @endforeach

    @if ($recap['online'] !== null)
        <div style="{{ $section }}">
            <div style="font-weight:900;">PEMBAYARAN ONLINE</div>
            <div>Dibayar customer lewat</div>
            <div>website & aplikasi Club 61</div>
            @forelse ($recap['online']['methods'] as $method)
                <div style="margin-top:0.2rem;">
                    <div style="font-weight:700;">{{ $method['label'] }}</div>
                    <div style="{{ $line }}"><span>{{ $method['count'] }} transaksi</span><span>{{ $rupiah($method['amount']) }}</span></div>
                </div>
            @empty
                <div style="margin-top:0.2rem;">Belum ada pembayaran online.</div>
            @endforelse
            @if ($recap['online']['transactions'] > 0)
                <div style="{{ $line }} font-weight:700; margin-top:0.2rem;"><span>Jumlah online</span><span>{{ $rupiah($recap['online']['total']) }}</span></div>
            @endif
            <div style="margin-top:0.2rem;">Dana masuk lewat payment</div>
            <div>gateway, tidak dicocokkan</div>
            <div>dengan settlement EDC.</div>
        </div>
    @endif

    <div style="{{ $section }} text-align:center;">
        <div style="font-weight:900;">BUKAN LAPORAN PENUTUPAN</div>
        <div>Transaksi setelah jam cetak</div>
        <div>belum termasuk.</div>
    </div>
</div>
