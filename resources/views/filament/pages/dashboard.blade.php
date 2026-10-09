{{-- Dashboard admin — semua dari data asli (App\Filament\Pages\Dashboard). Angka uang = Buku Transaksi, hanya untuk yang boleh membuka Analytics. --}}
@php
    $rp = fn ($v) => \App\Filament\Pages\Dashboard::rupiah($v);
    $num = fn ($v) => number_format((float) $v, 0, ',', '.');
    $pillStyle = [
        'green' => 'background:#ECFDF5; color:#047857; border:1px solid #A7F3D0;',
        'gold' => 'background:#F6EAE7; color:#662721; border:1px solid #E8CFC9;',
        'amber' => 'background:#FEF3C7; color:#92400E; border:1px solid #FDE68A;',
        'red' => 'background:#FEE2E2; color:#B42318; border:1px solid #FECACA;',
        'gray' => 'background:#F3F4F6; color:#374151; border:1px solid #D1D5DB;',
    ];
    $change = function (?float $pct) {
        if ($pct === null) {
            return null;
        }

        return [($pct >= 0 ? '↗ +' : '↘ ').number_format($pct, 1, ',', '.').'%', $pct >= 0 ? 'adm-pill-green' : ''];
    };

    // Grafik garis uang masuk bersih per hari.
    if ($trend) {
        $pts = $trend['points'];
        $n = max(1, count($pts) - 1);
        $values = array_column($pts, 'money_net');
        $maxV = max(1, max($values ?: [0]));
        $minV = min(0, min($values ?: [0]));
        $W = 1000; $H = 220; $L = 70; $R = 985; $T = 15; $B = 190;
        $x = fn ($i) => $L + ($R - $L) * ($n ? $i / $n : 0);
        $y = fn ($v) => $B - ($B - $T) * (($v - $minV) / ($maxV - $minV ?: 1));
        $line = collect($pts)->map(fn ($p, $i) => round($x($i), 1).','.round($y($p['money_net']), 1))->implode(' ');
        $area = 'M '.round($x(0), 1).','.$B.' L '.str_replace(' ', ' L ', $line).' L '.round($x(count($pts) - 1), 1).','.$B.' Z';
        $labelEvery = (int) ceil(count($pts) / 10);
        $hasTrend = collect($pts)->contains(fn ($p) => $p['money_in'] != 0 || $p['refunds'] != 0);
    }
@endphp

<div class="c61">
    @include('filament.partials.c61-admin-style')

    {{-- 1. Hero sapaan + status venue --}}
    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span>Club 61 Padel Court &bull; {{ $now->translatedFormat('l, d F Y') }}</div>
            <div class="c61-hero-title">Selamat datang, {{ auth()->user()?->name }}</div>
            <div class="c61-hero-sub">Ringkasan operasional hari ini. Data diperbarui setiap halaman dibuka.</div>
        </div>

        <div class="c61-hero-chip">
            <span style="width:10px; height:10px; border-radius:50%; flex-shrink:0; background:{{ $occupancy['courts'] > 0 ? '#34D399' : '#A08F86' }};"></span>
            <div>
                <div class="k">Status Venue</div>
                <div class="v">{{ $occupancy['courts'] }} lapangan aktif &bull; {{ $playingNow }} sedang dipakai</div>
            </div>
        </div>
    </div>

    {{-- 2. Kartu ringkasan --}}
    <div class="c61-kpis">
        @if ($canSeeMoney)
            @php $c = $change($money['change']); @endphp
            <div class="c61-kpi">
                <div class="c61-kpi-top">
                    <div class="c61-kpi-label">Uang Masuk Hari Ini</div>
                    @if ($c)
                        <span class="c61-pill {{ $c[1] ? 'c61-pill-ok' : 'c61-pill-danger' }}" title="Dibanding kemarin">{{ $c[0] }}</span>
                    @else
                        <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                    @endif
                </div>
                <div class="c61-kpi-value is-terra">{{ $rp($money['today']) }}</div>
                <div class="c61-kpi-foot">
                    <span>{{ $num($money['today_count']) }} pembayaran &bull; kemarin {{ $rp($money['yesterday']) }}</span>
                    <span>Bulan ini {{ $rp($money['month']) }}</span>
                </div>
            </div>
        @endif

        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Booking Lapangan Hari Ini</div>
                <span class="c61-pill c61-pill-cream">{{ $num($checkedInToday) }} check-in</span>
            </div>
            <div class="c61-kpi-value">{{ $num($bookingsToday) }} booking</div>
            <div class="c61-bar" title="Okupansi {{ $occupancy['rate'] }}%"><i style="width:{{ min(100, max(0, (float) $occupancy['rate'])) }}%;"></i></div>
            <div class="c61-kpi-foot">
                <span>Okupansi {{ $occupancy['rate'] }}%</span>
                <span>{{ number_format($occupancy['hours_booked'], 1, ',', '.') }} dari {{ $num($occupancy['capacity_hours']) }} jam</span>
            </div>
        </div>

        @php $cc = $change($customers['change']); @endphp
        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Customer Baru Bulan Ini</div>
                @if ($cc)
                    <span class="c61-pill {{ $cc[1] ? 'c61-pill-ok' : 'c61-pill-danger' }}" title="Dibanding periode yang sama bulan lalu">{{ $cc[0] }}</span>
                @else
                    <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg></span>
                @endif
            </div>
            <div class="c61-kpi-value">{{ $num($customers['this_month']) }} akun</div>
            <div class="c61-kpi-foot">
                <span>Periode sama bulan lalu: {{ $num($customers['last_month']) }}</span>
            </div>
        </div>

        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Member Aktif</div>
                <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg></span>
            </div>
            <div class="c61-kpi-value is-terra">{{ $num($activeMembers) }} member</div>
            <div class="c61-kpi-foot">
                <span>Kartu membership berstatus aktif</span>
            </div>
        </div>
    </div>

    {{-- 3. Perlu tindakan --}}
    @if ($attention !== [])
        <div class="c61-card" style="border-left:4px solid var(--c-terra);">
            <div class="c61-card-body" style="display:flex; align-items:flex-start; gap:1rem; padding:1rem 1.25rem;">
                <span class="c61-kpi-icon" style="background:#F6EAE7;"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg></span>
                <div style="flex:1; min-width:0;">
                    <div class="c61-card-title" style="font-size:1.0625rem;">Perlu Ditindaklanjuti</div>
                    <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.6rem;">
                        @foreach ($attention as $item)
                            <span style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.4rem 0.75rem; border-radius:10px; background:var(--c-paper); border:1px solid var(--c-line); font-size:0.8125rem;">
                                <b style="color:var(--c-brown);">{{ $item['label'] }}</b>
                                @if ($canSeeMoney && $item['amount'] !== null) <span style="color:var(--c-muted);">({{ $rp($item['amount']) }})</span> @endif
                                @if ($item['url'])
                                    <a href="{{ $item['url'] }}" class="c61-link">Buka</a>
                                @endif
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- 4. Grafik uang masuk --}}
    @if ($canSeeMoney)
        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <div class="c61-card-title">Uang Masuk Harian (Bersih)</div>
                    <div class="c61-card-sub">
                        <strong style="color:var(--c-brown);">{{ $num($trend['payments']) }} pembayaran</strong> &bull;
                        <span style="color:var(--c-terra); font-weight:700;">{{ $rp($trend['total']) }} dalam {{ $trendDays }} hari terakhir</span>
                        &bull; <a href="{{ \App\Filament\Pages\Analytics::getUrl() }}" class="c61-link">Analytics lengkap</a>
                    </div>
                </div>
                <div class="c61-seg">
                    @foreach (\App\Filament\Pages\Dashboard::TREND_OPTIONS as $days => $label)
                        <button type="button" wire:click="setTrendDays({{ $days }})" class="c61-seg-btn {{ $trendDays === $days ? 'is-active' : '' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($hasTrend)
                <div class="c61-card-body" style="padding-top:1rem;">
                    <svg viewBox="0 0 {{ $W }} {{ $H }}" style="width:100%; height:auto; display:block; overflow:visible;" role="img" aria-label="Grafik uang masuk harian">
                        <defs>
                            <linearGradient id="dashTerraArea" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#662721" stop-opacity="0.22" />
                                <stop offset="100%" stop-color="#F7F0DB" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        @foreach ([0, 0.5, 1] as $f)
                            @php $gv = $minV + ($maxV - $minV) * $f; $gy = $y($gv); @endphp
                            <line x1="{{ $L }}" y1="{{ $gy }}" x2="{{ $R }}" y2="{{ $gy }}" stroke="#E6DAC0" stroke-width="1" stroke-dasharray="4 4" />
                            <text x="{{ $L - 8 }}" y="{{ $gy + 4 }}" text-anchor="end" font-size="11" fill="#A08F86" font-family="monospace">{{ $gv >= 1000000 ? number_format($gv / 1000000, 1, ',', '.').' jt' : ($gv >= 1000 ? number_format($gv / 1000, 0, ',', '.').' rb' : $num($gv)) }}</text>
                        @endforeach
                        <path d="{{ $area }}" fill="url(#dashTerraArea)" />
                        <polyline points="{{ $line }}" fill="none" stroke="#662721" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                        @foreach ($pts as $i => $p)
                            <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($p['money_net']), 1) }}" r="{{ count($pts) > 40 ? 2.5 : 4 }}" fill="#FFFFFF" stroke="#662721" stroke-width="2">
                                <title>{{ $p['label'] }}: {{ $rp($p['money_net']) }}{{ $p['refunds'] != 0 ? ' (refund '.$rp($p['refunds']).')' : '' }}</title>
                            </circle>
                            @if ($i % $labelEvery === 0 || $i === count($pts) - 1)
                                <text x="{{ round($x($i), 1) }}" y="{{ $H - 4 }}" text-anchor="middle" font-size="11" fill="#7A5A52" font-family="monospace">{{ $p['label'] }}</text>
                            @endif
                        @endforeach
                    </svg>
                </div>
            @else
                <div class="c61-empty">Belum ada uang masuk dalam {{ $trendDays }} hari terakhir.</div>
            @endif
        </div>
    @endif

    {{-- 5. Jadwal lapangan hari ini --}}
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">Jadwal Lapangan Hari Ini</div>
                <div class="c61-card-sub">{{ $schedule->count() }} booking &bull; urut jam mulai</div>
            </div>
            <div class="c61-row">
                <div class="c61-search" style="width:280px;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Cari kode booking, nama, HP..." class="c61-input" />
                </div>
                @if (\App\Filament\Pages\KelolaPemesanan::canAccess())
                    <a href="{{ \App\Filament\Pages\KelolaPemesanan::getUrl() }}" class="c61-btn c61-btn-ghost">Kelola Pemesanan &rarr;</a>
                @endif
            </div>
        </div>

        <div class="c61-table-wrap">
            <table class="c61-table">
                <thead>
                    <tr>
                        <th>Kode Booking</th>
                        <th>Customer</th>
                        <th>Lapangan</th>
                        <th>Jam (WIB)</th>
                        <th>Status</th>
                        <th style="text-align:right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($schedule as $b)
                        @php
                            [$label, $color] = \App\Filament\Pages\Dashboard::statusLabel($b->status);
                            $start = $b->start_time?->copy()->timezone(\App\Services\Finance\LedgerReport::TIMEZONE);
                            $end = $b->end_time?->copy()->timezone(\App\Services\Finance\LedgerReport::TIMEZONE);
                            $isNow = $b->status === 'CHECKED_IN' && $b->start_time?->lte(now()) && $b->end_time?->gt(now());
                        @endphp
                        <tr wire:key="dash-booking-{{ $b->id }}">
                            <td class="code">{{ $b->booking_code }}</td>
                            <td>
                                <div class="strong">{{ $b->user?->name ?? 'Walk-in' }}</div>
                                <div class="sub">{{ $b->user?->phone ?? '-' }}</div>
                            </td>
                            <td class="strong">{{ $b->court?->name ?? '-' }}</td>
                            <td>
                                <div class="strong">{{ $start?->format('H:i') }} &ndash; {{ $end?->format('H:i') }}</div>
                                @if ($isNow)
                                    <div class="sub" style="color:#047857; font-weight:700;">Sedang main</div>
                                @elseif ($start && $start->isFuture())
                                    <div class="sub">Mulai {{ $start->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td><span class="c61-pill" style="{{ $pillStyle[$color] }}">{{ $label }}</span></td>
                            <td class="num">
                                @if ($canSeeMoney)
                                    {{ $rp($b->total_amount) }}
                                    @if ((float) $b->member_hours_consumed > 0)
                                        <div class="sub" style="font-family:var(--font-sans); font-weight:500;">kuota member</div>
                                    @endif
                                @else
                                    <span style="color:var(--c-faint);">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="c61-empty">
                                {{ trim($search) !== '' ? 'Tidak ada booking hari ini yang cocok dengan pencarian.' : 'Belum ada booking lapangan untuk hari ini.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
