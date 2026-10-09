{{-- Modul 17: semua angka uang dari Buku Transaksi (ledger_entries) — lihat App\Filament\Pages\Analytics. --}}
@php
    $rp = fn ($v) => \App\Filament\Pages\Analytics::rupiah($v);
    $num = fn ($v) => number_format((float) $v, 0, ',', '.');
    $s = $summary;
    $refundTotal = abs($s['refunds']);
@endphp

<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        .an-period { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; font-size: 0.8125rem; font-weight: 700; color: var(--c-muted); }
        .an-dates { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .an-dates .c61-input { width: auto; height: 36px; }
        .an-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); gap: 1.25rem; }
        .an-list { display: flex; flex-direction: column; gap: 0.6rem; }
        .an-line { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.8rem 1rem; background: var(--c-paper); border-radius: 12px; border: 1px solid var(--c-line); color: inherit; text-decoration: none; transition: border-color 0.15s, background 0.15s; }
        a.an-line:hover { border-color: var(--c-terra); background: #FFFFFF; }
        .an-line-title { font-weight: 700; font-size: 0.875rem; color: var(--c-brown); display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
        .an-line-sub { font-size: 0.6875rem; color: var(--c-muted); margin-top: 0.1rem; }
        .an-line-val { font-family: var(--font-mono); font-weight: 800; font-size: 0.9375rem; color: var(--c-terra); white-space: nowrap; text-align: right; font-variant-numeric: tabular-nums; }
        .an-line-val small { display: block; font-family: var(--font-sans); font-size: 0.6875rem; font-weight: 600; color: var(--c-faint); }
        .an-line.is-lead .an-line-val { color: var(--c-brown); }
        .an-line.muted { background: #FFFFFF; }
        .an-line.muted .an-line-val { color: var(--c-muted); }
        .an-soon { font-size: 0.625rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.1rem 0.45rem; border-radius: 999px; background: #F3F4F6; color: #4B5563; border: 1px solid #E5E7EB; }
        .an-methods { margin-top: 0.4rem; border-top: 1px dashed var(--c-line); padding-top: 0.75rem; }
        .an-methods-title { font-size: 0.6875rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--c-muted); margin-bottom: 0.35rem; }
        .an-method { display: flex; justify-content: space-between; gap: 1rem; padding: 0.45rem 0.4rem; border-radius: 8px; font-size: 0.8125rem; color: var(--c-brown); text-decoration: none; border-bottom: 1px solid var(--c-line-soft); }
        .an-method:hover { background: var(--c-paper); color: var(--c-terra); }
        .an-method span:last-child { font-family: var(--font-mono); font-weight: 700; white-space: nowrap; }
        .an-status { display: inline-flex; align-items: center; height: 24px; font-size: 0.6875rem; font-weight: 700; padding: 0 0.6rem; border-radius: 999px; white-space: nowrap; border: 1px solid transparent; }
        .an-status.success { background: #ECFDF5; color: #047857; border-color: #A7F3D0; }
        .an-status.warning { background: #FEF3C7; color: #92400E; border-color: #FDE68A; }
        .an-status.danger { background: #FEE2E2; color: #B42318; border-color: #FECACA; }
        .an-status.gray { background: #F3F4F6; color: #374151; border-color: #E5E7EB; }
        .an-kpi-main { background: var(--c-terra); border-color: var(--c-terra); color: var(--c-cream); }
        .an-kpi-main .c61-kpi-label { color: rgba(247, 240, 219, 0.75); }
        .an-kpi-main .c61-kpi-value { color: var(--c-cream); }
        .an-kpi-main .c61-kpi-foot { color: rgba(247, 240, 219, 0.75); border-top-color: rgba(247, 240, 219, 0.25); }
    </style>

    {{-- Header --}}
    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span>Financial Business Intelligence &bull; Club 61 Padel Court</div>
            <div class="c61-hero-title">Laporan Uang Masuk &amp; Analisis Finansial</div>
            <div class="c61-hero-sub">
                Rekapitulasi uang masuk dari kasir &amp; Midtrans, refund, dan pendapatan bersih semua lini (padel, add-on, F&amp;B, membership). Angkanya sama dengan Buku Transaksi.
            </div>
        </div>
    </div>

    {{-- Periode --}}
    <div class="c61-toolbar">
        <div class="c61-seg">
            @foreach (\App\Services\Finance\LedgerReport::PRESETS as $key => $label)
                <button type="button" wire:click="setPreset('{{ $key }}')" class="c61-seg-btn {{ $preset === $key ? 'is-active' : '' }}">
                    {{ $key === 'kustom' ? 'Pilih Tanggal' : $label }}
                </button>
            @endforeach
        </div>

        <div class="an-period">
            <span>Menampilkan data periode:</span>
            <span class="c61-pill c61-pill-terra">{{ $periodLabel }}</span>
            @if ($preset === 'kustom')
                <div class="an-dates">
                    <span>Dari</span><input type="date" wire:model.live="dari" class="c61-input">
                    <span>sampai</span><input type="date" wire:model.live="sampai" class="c61-input">
                </div>
            @endif
        </div>
    </div>

    {{-- 4 KPI utama --}}
    <div class="c61-kpis">
        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Total Uang Masuk Kotor (Gross)</div>
                <span class="c61-pill c61-pill-ok">Cash In</span>
            </div>
            <div class="c61-kpi-value">{{ $rp($s['money_in']) }}</div>
            <div class="c61-kpi-foot">
                <span>{{ $num($s['payments_count']) }} Transaksi Lunas / Settled</span>
                @if ($s['overpayments'] > 0)
                    <span style="color:#B45309; font-weight:700;">Termasuk lebih bayar {{ $rp($s['overpayments']) }}</span>
                @else
                    <span>Sesuai Buku Transaksi</span>
                @endif
            </div>
        </div>

        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Total Refund Dikeluarkan</div>
                <span class="c61-pill c61-pill-danger">Refund</span>
            </div>
            <div class="c61-kpi-value" style="color:#B42318;">{{ $rp($refundTotal) }}</div>
            <div class="c61-kpi-foot">
                <span>{{ $num($s['refunds_count']) }} refund diproses</span>
                @if ($s['pending_refund_count'] > 0)
                    <span style="color:#B42318; font-weight:700;">
                        @if ($canSeeRefundQueue)
                            <a href="{{ \App\Filament\Pages\AntrianRefund::getUrl() }}" style="color:inherit;">{{ $s['pending_refund_count'] }} menunggu ({{ $rp($s['pending_refund_amount']) }})</a>
                        @else
                            {{ $s['pending_refund_count'] }} menunggu ({{ $rp($s['pending_refund_amount']) }})
                        @endif
                    </span>
                @else
                    <span>Tidak ada antrian</span>
                @endif
            </div>
        </div>

        <div class="c61-kpi an-kpi-main">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Pendapatan Bersih (Net Revenue)</div>
                <span class="c61-pill" style="background:rgba(247,240,219,0.15); border-color:rgba(247,240,219,0.35); color:var(--c-cream);">Net Income</span>
            </div>
            <div class="c61-kpi-value">{{ $rp($s['money_net']) }}</div>
            <div class="c61-kpi-foot">
                <span>Gross dikurangi Total Refund</span>
                @if ($s['money_net'] >= 0)
                    <span style="color:#6EE7B7; font-weight:800;">Arus Kas Positif</span>
                @else
                    <span style="color:#FCA5A5; font-weight:800;">Arus Kas Negatif</span>
                @endif
            </div>
        </div>

        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Tingkat Okupansi Lapangan</div>
                <span class="c61-pill c61-pill-cream">Lapangan</span>
            </div>
            <div class="c61-kpi-value is-terra">{{ $occupancy['rate'] }}%</div>
            <div class="c61-kpi-foot">
                <span>{{ number_format($occupancy['hours_booked'], 1, ',', '.') }} jam sewa terpakai</span>
                <span>Kapasitas {{ $occupancy['courts'] }} Court</span>
            </div>
        </div>
    </div>

    {{-- Kanal pembayaran & lini layanan --}}
    <div class="an-grid">
        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Distribusi Kanal Pembayaran</div>
                    <div class="c61-card-sub">Rekap uang masuk bersih berdasarkan saluran transaksi</div>
                </div>
                <span class="c61-pill c61-pill-cream">Payment Channel</span>
            </div>

            <div class="c61-card-body an-list">
                <div class="an-line is-lead">
                    <div>
                        <div class="an-line-title">Kasir Frontdesk (POS)</div>
                        <div class="an-line-sub">Tunai, EDC, QRIS &amp; transfer di POS Walk-In, F&amp;B, membership</div>
                    </div>
                    <div class="an-line-val">{{ $rp($channels['cashier']['money_net']) }}<small>{{ $num($channels['cashier']['transactions']) }} transaksi</small></div>
                </div>
                <div class="an-line">
                    <div>
                        <div class="an-line-title">Midtrans Gateway (QRIS, GoPay, VA)</div>
                        <div class="an-line-sub">Pembayaran online booking &amp; membership dari aplikasi</div>
                    </div>
                    <div class="an-line-val">{{ $rp($channels['online']['money_net']) }}<small>{{ $num($channels['online']['transactions']) }} transaksi</small></div>
                </div>

                @if (count($byMethod) > 0)
                    <div class="an-methods">
                        <div class="an-methods-title">Rincian per metode bayar</div>
                        @foreach ($byMethod as $m)
                            <a class="an-method" href="{{ $m['method_code'] ? $this->bukuUrl(['metode' => [$m['method_code']]]) : $this->bukuUrl() }}">
                                <span>{{ $m['label'] }} <span style="color:var(--c-faint); font-family:inherit; font-weight:600;">&middot; {{ $num($m['transactions']) }} trx</span></span>
                                <span>{{ $rp($m['money_net']) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Rincian Pendapatan per Lini Layanan</div>
                    <div class="c61-card-sub">Kontribusi sewa lapangan, add-on, F&amp;B, wellness, dan coaching</div>
                </div>
                <span class="c61-pill c61-pill-cream">Revenue Mix</span>
            </div>

            <div class="c61-card-body an-list">
                @foreach ($serviceLines as $line)
                    @php $tag = $line['filter'] ? 'a' : 'div'; @endphp
                    <{{ $tag }} class="an-line {{ $line['soon'] ? 'muted' : '' }} {{ $loop->first ? 'is-lead' : '' }}" @if ($line['filter']) href="{{ $this->bukuUrl(['kategori' => $line['filter']]) }}" @endif>
                        <div>
                            <div class="an-line-title">
                                {{ $line['label'] }}
                                @if ($line['soon'])
                                    <span class="an-soon">Menyusul</span>
                                @endif
                            </div>
                            <div class="an-line-sub">{{ $line['sub'] }}</div>
                        </div>
                        <div class="an-line-val">
                            {{ $rp($line['money_net']) }}
                            @unless ($line['soon'])
                                <small>{{ $num($line['transactions']) }} transaksi</small>
                            @endunless
                        </div>
                    </{{ $tag }}>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Membership: uang riil penjualan paket vs nilai benefit yang dipakai (dua hal berbeda) --}}
    <div class="an-grid">
        <div class="c61-card" style="border-left: 4px solid var(--c-terra);">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Pemasukan Penjualan Paket Membership</div>
                    <div class="c61-card-sub">Uang riil diterima saat paket dibeli (Padel/Gym/Sauna) &mdash; kanal terpisah dari sewa lapangan</div>
                </div>
                <span class="c61-pill c61-pill-ok">Cash In</span>
            </div>

            <div class="c61-card-body an-list">
                <a class="an-line is-lead" href="{{ $this->bukuUrl(['kategori' => ['MEMBERSHIP']]) }}">
                    <div>
                        <div class="an-line-title">Omzet Penjualan Membership</div>
                        <div class="an-line-sub">Paket membership yang lunas (kasir &amp; online), setelah refund</div>
                    </div>
                    <div class="an-line-val">{{ $rp($membership['sales']) }}</div>
                </a>
                <div class="an-line">
                    <div>
                        <div class="an-line-title">Omzet Gabungan Venue</div>
                        <div class="an-line-sub">Lini layanan {{ $rp($membership['others']) }} + membership {{ $rp($membership['sales']) }} &middot; termasuk pajak {{ $rp($s['tax']) }}</div>
                    </div>
                    <div class="an-line-val">{{ $rp($s['money_net']) }}</div>
                </div>
            </div>
        </div>

        <div class="c61-card" style="background: var(--c-paper);">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Nilai Benefit Member Terpakai (Informasional)</div>
                    <div class="c61-card-sub">Bukan pendapatan baru &mdash; uangnya sudah diakui saat paket dibeli. Ini cuma indikator utilisasi.</div>
                </div>
                <span class="c61-pill c61-pill-gray">Bukan Omzet</span>
            </div>

            <div class="c61-card-body an-list">
                <div class="an-line muted">
                    <div>
                        <div class="an-line-title">Nilai Diskon/Kuota yang Dipakai</div>
                        <div class="an-line-sub">Setara tarif reguler yang "dibayar" pakai kuota member / voucher sponsor</div>
                    </div>
                    <div class="an-line-val">{{ $rp($s['benefit']) }}</div>
                </div>
                <div class="an-line muted">
                    <div>
                        <div class="an-line-title">Jam Padel Terpakai via Kuota</div>
                        <div class="an-line-sub">{{ $num($memberUsage['bookings']) }} booking menggunakan benefit membership</div>
                    </div>
                    <div class="an-line-val">{{ number_format($memberUsage['hours'], 1, ',', '.') }} Jam</div>
                </div>
                @if ($s['forfeited'] > 0)
                    <div class="an-line muted">
                        <div>
                            <div class="an-line-title">Selisih Reschedule Hangus</div>
                            <div class="an-line-sub">Sudah tercatat saat pembayaran awal</div>
                        </div>
                        <div class="an-line-val">{{ $rp($s['forfeited']) }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- 10 mutasi terbaru --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">Riwayat Mutasi Uang Masuk Terkini</div>
                <div class="c61-card-sub">10 pembayaran &amp; refund terbaru dari semua kasir dan online pada periode ini</div>
            </div>
            <a href="{{ $this->bukuUrl() }}" class="c61-btn c61-btn-ghost">Buka Buku Transaksi &rarr;</a>
        </div>

        <div class="c61-table-wrap">
            <table class="c61-table">
                <thead>
                    <tr>
                        <th>WAKTU TRANSAKSI</th>
                        <th>NO. ORDER</th>
                        <th>MEMBER / CUSTOMER</th>
                        <th>LAYANAN &amp; SUMBER</th>
                        <th>METODE</th>
                        <th>STATUS</th>
                        <th style="text-align:right;">UANG MASUK</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($latest as $row)
                        @php
                            $status = \App\Services\Finance\LedgerReport::statusLabel($row);
                            $services = collect(explode(',', (string) $row->categories))->filter()->unique()
                                ->map(fn ($c) => \App\Models\Finance\LedgerEntry::categoryLabel($c))->implode(', ');
                            $amount = (float) $row->total_amount;
                        @endphp
                        <tr>
                            <td style="color:var(--c-muted); font-size:0.75rem; white-space:nowrap;">{{ $row->occurred_at ? \Carbon\Carbon::parse($row->occurred_at)->timezone(\App\Services\Finance\LedgerReport::TIMEZONE)->format('d M Y, H:i') : '-' }} WIB</td>
                            <td class="code">{{ $row->order_number ?? '-' }}</td>
                            <td class="strong">{{ $row->customer_name ?? 'Customer' }}</td>
                            <td>
                                {{ $services ?: '-' }}
                                <div class="sub">{{ \App\Models\Finance\LedgerEntry::sourceLabel($row->source) }}</div>
                            </td>
                            <td>{{ $row->payment_method_label ?? $row->payment_method ?? '-' }}</td>
                            <td><span class="an-status {{ \App\Services\Finance\LedgerReport::statusColor($status) }}">{{ $status }}</span></td>
                            <td class="num" style="color:{{ $amount < 0 ? '#B42318' : '#047857' }};">{{ $amount < 0 ? '- '.$rp(abs($amount)) : '+ '.$rp($amount) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="c61-empty">Belum ada transaksi masuk pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
