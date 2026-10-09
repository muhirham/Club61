<x-filament-panels::page>
    @php
        $s = $this->summary();
        $rp = fn ($v) => \App\Filament\Pages\BukuTransaksi::rupiah($v);
        $canRefundQueue = auth()->user()?->can('process_refund_queue');
    @endphp

    <div class="c61 bt-root" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')
        <style>
            .ledger-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
            .ledger-cards .c61-kpi-value { font-size: 1.375rem; }
            .ledger-cards .c61-kpi-foot { padding-top: 0.45rem; }
            .ledger-card-main { background: var(--c-terra); border-color: var(--c-terra); }
            .ledger-card-main .c61-kpi-label, .ledger-card-main .c61-kpi-foot { color: rgba(247, 240, 219, 0.75); border-top-color: rgba(247, 240, 219, 0.25); }
            .ledger-card-main .c61-kpi-value { color: var(--c-cream); }
            .ledger-info { display: flex; flex-wrap: wrap; gap: 0.4rem 1.25rem; }
            .ledger-info b { color: var(--c-brown); }
        </style>

        <div class="c61-note" style="background: #FFFFFF; color: var(--c-muted); line-height: 1.6;">
            <span class="c61-pill c61-pill-terra" style="margin-right: 0.35rem;">{{ $s['period_label'] }}</span>
            dihitung dari <b style="color: var(--c-brown);">tanggal uang diterima</b> (WIB), <b style="color: var(--c-brown);">setelah refund</b>, mengikuti filter tabel.
            {{ number_format($s['payments_count'], 0, ',', '.') }} pembayaran (uang diterima {{ $rp($s['money_in']) }}).
            Pendapatan = penjualan bersih <b style="color: var(--c-brown);">sebelum pajak</b>; pajak adalah titipan, bukan pendapatan.
            Tarif / pajak / biaya layanan yang diubah belakangan <b style="color: var(--c-brown);">tidak</b> mengubah transaksi lama.
        </div>

        <div class="ledger-cards">
            <div class="c61-kpi">
                <div class="c61-kpi-label">Penjualan Bersih</div>
                <div class="c61-kpi-value">{{ $rp($s['net']) }}</div>
                <div class="c61-kpi-foot"><span>Harga item {{ $rp($s['gross']) }} − diskon {{ $rp($s['discount']) }}</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Biaya Layanan</div>
                <div class="c61-kpi-value">{{ $rp($s['service']) }}</div>
                <div class="c61-kpi-foot"><span>Ditampilkan terpisah</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Pajak Terkumpul</div>
                <div class="c61-kpi-value">{{ $rp($s['tax']) }}</div>
                <div class="c61-kpi-foot"><span>Disetor, bukan pendapatan</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Refund</div>
                <div class="c61-kpi-value" style="color: #B42318;">{{ $rp($s['refunds']) }}</div>
                <div class="c61-kpi-foot"><span>{{ number_format($s['refunds_count'], 0, ',', '.') }} refund · sudah dikurangkan</span></div>
            </div>
            <div class="c61-kpi ledger-card-main">
                <div class="c61-kpi-label">Total Uang Masuk (Bersih)</div>
                <div class="c61-kpi-value">{{ $rp($s['money_net']) }}</div>
                <div class="c61-kpi-foot"><span>= Bersih + Layanan + Pajak</span></div>
            </div>
        </div>

        <div class="c61-note ledger-info">
            <span>Nilai kuota member / voucher sponsor terpakai: <b>{{ $rp($s['benefit']) }}</b> (non-tunai)</span>
            <span>Selisih reschedule hangus: <b>{{ $rp($s['forfeited']) }}</b> (sudah tercatat saat bayar awal)</span>
            @if ($s['overpayments'] > 0)
                <span>Kelebihan bayar masuk: <b>{{ $rp($s['overpayments']) }}</b></span>
            @endif
            <span>
                Refund menunggu diproses: <b>{{ $s['pending_refund_count'] }} ({{ $rp($s['pending_refund_amount']) }})</b>
                @if ($canRefundQueue && $s['pending_refund_count'] > 0)
                    · <a href="{{ \App\Filament\Pages\AntrianRefund::getUrl() }}" class="c61-link">Buka Antrian Refund</a>
                @endif
            </span>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
