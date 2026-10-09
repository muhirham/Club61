<div class="c61 cmd-wrap" wire:poll.10s>
    @include('filament.partials.c61-admin-style')
    {{-- Gaya khusus papan monitoring (matriks lapangan × jam). Status slot memakai warna yang konsisten dengan POS Walk-In. --}}
    <style>
        .cmd-live { display: inline-flex; align-items: center; gap: 0.4rem; height: 26px; padding: 0 0.6rem; border-radius: 999px; background: rgba(247, 240, 219, 0.12); border: 1px solid rgba(247, 240, 219, 0.3); font-size: 0.6875rem; font-weight: 700; color: var(--c-cream); }
        .cmd-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34D399; animation: cmd-pulse 2s infinite; }
        @keyframes cmd-pulse { 0% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.7); } 70% { box-shadow: 0 0 0 6px rgba(52, 211, 153, 0); } 100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); } }

        .cmd-datebar { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
        .cmd-nav-btn.is-active { background: var(--c-terra); border-color: var(--c-terra); color: var(--c-cream); }
        .cmd-date-input { width: auto; height: 40px; }
        .cmd-date-label { font-size: 0.875rem; font-weight: 700; color: var(--c-brown); margin-left: 0.35rem; white-space: nowrap; }
        .cmd-legend { display: flex; align-items: center; gap: 0.4rem 0.9rem; flex-wrap: wrap; font-size: 0.6875rem; font-weight: 700; color: var(--c-muted); }
        .cmd-legend span { display: inline-flex; align-items: center; gap: 0.35rem; }
        .cmd-legend i { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }

        .cmd-matrix-scroll { overflow-x: auto; position: relative; }
        .cmd-table { table-layout: fixed; width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; }
        .cmd-th-court, .cmd-th-hour { background: var(--c-paper); border-bottom: 1px solid var(--c-line); font-size: 0.6875rem; font-weight: 800; color: var(--c-muted); text-transform: uppercase; letter-spacing: 0.06em; }
        .cmd-th-court { position: sticky; left: 0; z-index: 20; text-align: left; padding: 0.75rem 1rem; width: 200px; min-width: 200px; max-width: 200px; border-right: 1px solid var(--c-line); }
        .cmd-th-hour { padding: 0.6rem 0.4rem; text-align: center; width: 112px; min-width: 112px; max-width: 112px; border-right: 1px solid var(--c-line-soft); font-variant-numeric: tabular-nums; }
        .cmd-th-hour.is-live { background: var(--c-terra); color: var(--c-cream); border-bottom-color: var(--c-terra); }
        .cmd-th-hour .live-tag { font-size: 0.5625rem; font-weight: 800; letter-spacing: 0.08em; margin-top: 0.1rem; color: rgba(247, 240, 219, 0.85); }
        .cmd-td-court { position: sticky; left: 0; z-index: 10; background: #FFFFFF; padding: 0.75rem 1rem; width: 200px; min-width: 200px; max-width: 200px; border-right: 1px solid var(--c-line); border-bottom: 1px solid var(--c-line-soft); }
        .cmd-court-name { font-family: var(--font-serif); font-size: 1rem; font-weight: 600; color: var(--c-brown); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cmd-court-meta { font-size: 0.6875rem; color: var(--c-muted); font-weight: 600; margin-top: 0.15rem; }
        .cmd-td-slot { padding: 0.35rem; height: 92px; vertical-align: top; background: #FFFFFF; width: 112px; min-width: 112px; max-width: 112px; border-right: 1px solid var(--c-line-soft); border-bottom: 1px solid var(--c-line-soft); }
        .cmd-td-slot.is-live-col { background: rgba(102, 39, 33, 0.035); }

        .cmd-slot-box { width: 100%; height: 100%; border-radius: 10px; padding: 0.45rem 0.5rem; display: flex; flex-direction: column; justify-content: space-between; font-size: 0.6875rem; cursor: pointer; overflow: hidden; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .cmd-slot-box:hover { transform: translateY(-1px); box-shadow: 0 8px 18px -10px rgba(79, 47, 42, 0.45); }
        .cmd-slot-box .st { font-size: 0.5625rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; }
        .cmd-slot-box .tm { font-size: 0.5625rem; font-family: var(--font-mono); opacity: 0.8; }
        .cmd-slot-box .nm { font-weight: 800; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin: 0.15rem 0; }
        .cmd-slot-box .ft { display: flex; justify-content: space-between; align-items: center; font-size: 0.5625rem; }
        .cmd-slot-box .ft code { font-family: var(--font-mono); opacity: 0.8; }
        .cmd-slot-box .eq { padding: 0.05rem 0.35rem; border-radius: 4px; background: rgba(0, 0, 0, 0.06); font-weight: 700; }
        /* Sedang main: hijau (semantik "aktif"). */
        .cmd-slot-active { background: #ECFDF5; border: 1.5px solid #10B981; color: #065F46; }
        /* Terjadwal lunas: terakota brand. */
        .cmd-slot-paid { background: var(--c-terra); border: 1.5px solid var(--c-terra); color: var(--c-cream); }
        .cmd-slot-paid .eq { background: rgba(247, 240, 219, 0.18); }
        /* Menunggu bayar: oranye bergaris. */
        .cmd-slot-pending { background: repeating-linear-gradient(45deg, #FFF7ED, #FFF7ED 8px, #FFEDD5 8px, #FFEDD5 16px); border: 1.5px solid #F97316; color: #9A3412; }
        /* Selesai: krem redup. */
        .cmd-slot-completed { background: var(--c-cream); border: 1px solid var(--c-line); color: var(--c-muted); }
        /* Kosong: putih bergaris putus, + saat disentuh. */
        .cmd-slot-available { background: #FFFFFF; border: 1.5px dashed var(--c-line); color: var(--c-faint); align-items: center; justify-content: center; gap: 0.2rem; }
        .cmd-slot-available svg { width: 14px; height: 14px; }
        .cmd-slot-available:hover { border-style: solid; border-color: var(--c-terra); color: var(--c-terra); background: var(--c-paper); }
        /* Lewat / tutup: arsir, tidak bisa diklik (sama dengan POS). */
        .cmd-slot-past { background: repeating-linear-gradient(135deg, #FAF8F3 0 6px, #F4EFE5 6px 7px); border: 1px solid #F0EADF; color: #B5AAA0; cursor: not-allowed; align-items: center; justify-content: center; gap: 0.1rem; user-select: none; }
        .cmd-slot-past:hover { transform: none; box-shadow: none; }
        .cmd-slot-past b { font-size: 0.75rem; }
        .cmd-slot-past span { font-size: 0.5625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
    </style>

    <!-- 1. Header -->
    <div class="c61-hero">
        <div>
            <div class="c61-row">
                <span class="cmd-live"><span class="cmd-live-dot"></span>Live &bull; Auto-Sync 10s</span>
                <span class="c61-eyebrow">Venue Command Board</span>
            </div>
            <div class="c61-hero-title">Booking System &amp; Monitoring Lapangan</div>
            <div class="c61-hero-sub">Pantau jadwal semua lapangan secara realtime, temukan slot kosong dengan cepat, dan kontrol check-in pemain.</div>
        </div>

        <div class="c61-hero-actions">
            <button type="button" wire:click="openCheckInModal()" class="c61-btn c61-btn-cream c61-btn-lg">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                        d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z">
                    </path>
                </svg>
                <span>Scan QR / Check-In Gate</span>
            </button>
            <a href="/admin/book-offline-court" class="c61-btn c61-btn-outline-cream c61-btn-lg">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                <span>Walk-In Booking</span>
            </a>
            <a href="/admin/kelola-pemesanan" class="c61-btn c61-btn-outline-cream c61-btn-lg">
                <span>Kelola Semua Booking</span>
            </a>
        </div>
    </div>

    <!-- 2. KPI -->
    <div class="c61-kpis">
        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Tingkat Okupansi Hari Ini</div>
                <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg></span>
            </div>
            <div class="c61-kpi-value is-terra">{{ $occupancyRate }}%</div>
            <div class="c61-bar"><i style="width: {{ min(100, $occupancyRate) }}%;"></i></div>
        </div>

        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Sedang Main Saat Ini</div>
                <span class="c61-kpi-icon is-ok"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></span>
            </div>
            <div class="c61-kpi-value is-ok">{{ $activeCourtsNow }} <small>/ {{ $totalCourtsCount }} Court</small></div>
            <div class="c61-kpi-foot"><span class="ok">{{ $isToday ? 'Live di venue sekarang' : 'Melihat jadwal tanggal lain' }}</span></div>
        </div>

        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Total Sesi Terisi</div>
                <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></span>
            </div>
            <div class="c61-kpi-value">{{ $occupiedSlotsCount }} <small>Sesi</small></div>
            <div class="c61-kpi-foot"><span>{{ $paidUpcomingCount }} lunas terjadwal</span></div>
        </div>

        <div class="c61-kpi">
            <div class="c61-kpi-top">
                <div class="c61-kpi-label">Sisa Slot Kosong Hari Ini</div>
                <span class="c61-kpi-icon is-ok"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></span>
            </div>
            <div class="c61-kpi-value is-ok">{{ $freeSlotsCount }} <small>Jam</small></div>
            <div class="c61-kpi-foot"><span class="ok">Siap dipesan / walk-in</span></div>
        </div>
    </div>

    <!-- 3. Navigasi tanggal & filter -->
    <div class="c61-toolbar">
        <div class="cmd-datebar">
            <button type="button" wire:click="prevDay()" class="c61-btn c61-btn-ghost" title="Hari Sebelumnya">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
                <span>Kemarin</span>
            </button>
            <button type="button" wire:click="today()" class="c61-btn c61-btn-ghost cmd-nav-btn {{ $isToday ? 'is-active' : '' }}">
                <span>Hari Ini</span>
            </button>
            <button type="button" wire:click="nextDay()" class="c61-btn c61-btn-ghost" title="Hari Berikutnya">
                <span>Besok</span>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
            </button>
            <input type="date" wire:model.live="selectedDate" class="c61-input cmd-date-input">
            <span class="cmd-date-label">{{ \Carbon\Carbon::parse($selectedDate)->translatedFormat('l, d F Y') }}</span>
        </div>

        <div class="c61-row" style="gap: 1rem;">
            <select wire:model.live="courtFilter" class="c61-select" style="width: auto;">
                <option value="all">Semua Lapangan (4 Court)</option>
                <option value="indoor">Indoor Only</option>
                <option value="outdoor">Outdoor Only</option>
            </select>

            <div class="cmd-legend">
                <span><i style="background: #10B981;"></i>Sedang Main</span>
                <span><i style="background: #662721;"></i>Terjadwal Lunas</span>
                <span><i style="background: #F97316;"></i>Menunggu Bayar</span>
                <span><i style="border: 1.5px dashed #E6DAC0; background: #FFFFFF;"></i>Slot Kosong (+)</span>
                <span><i style="background: repeating-linear-gradient(135deg, #FAF8F3 0 3px, #E8E1D4 3px 4px);"></i>Lewat (-)</span>
            </div>
        </div>
    </div>

    <!-- 4. Matriks jadwal -->
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">
                    Timetable Jadwal Lapangan Padel
                    ({{ !empty($operationalHours) ? $operationalHours[0]['label'] . ' - ' . end($operationalHours)['next_label'] . ' WIB' : '06:00 - 23:00 WIB' }})
                </div>
                <div class="c61-card-sub">Klik blok slot untuk melihat detail pemain, check-in gate, atau booking instan.</div>
            </div>
        </div>

        <div class="cmd-matrix-scroll">
            <table class="cmd-table">
                <thead>
                    <tr>
                        <th class="cmd-th-court">Lapangan Padel</th>
                        @foreach ($operationalHours as $opHour)
                            <th class="cmd-th-hour {{ $opHour['is_current'] ? 'is-live' : '' }}">
                                <div>{{ $opHour['label'] }}</div>
                                @if ($opHour['is_current'])
                                    <div class="live-tag">LIVE NOW</div>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrix as $row)
                        @php $court = $row['court']; @endphp
                        <tr>
                            <td class="cmd-td-court">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                                    <div style="min-width: 0;">
                                        <div class="cmd-court-name">{{ $court->name }}</div>
                                        <div class="cmd-court-meta">
                                            {{ $court->type ?? 'Indoor' }} &bull; Rp
                                            {{ number_format($court->hourly_rate_regular, 0, ',', '.') }}/jam
                                        </div>
                                    </div>
                                    <span class="c61-pill c61-pill-cream" style="height: 20px; font-size: 0.5625rem; padding: 0 0.45rem;">
                                        {{ $court->type === 'INDOOR' ? 'INDOOR' : 'OUTDOOR' }}
                                    </span>
                                </div>
                            </td>

                            @foreach ($operationalHours as $opHour)
                                @php
                                    $h = $opHour['hour'];
                                    $slot = $row['slots'][$h];
                                    $isLiveCol = $opHour['is_current'];
                                @endphp
                                <td class="cmd-td-slot {{ $isLiveCol ? 'is-live-col' : '' }}">
                                    @if ($slot['type'] === 'booked')
                                        @php
                                            $booking = $slot['booking'];
                                            $cardClass = 'cmd-slot-paid';
                                            if ($slot['is_playing']) {
                                                $cardClass = 'cmd-slot-active';
                                            } elseif ($slot['is_pending']) {
                                                $cardClass = 'cmd-slot-pending';
                                            } elseif ($slot['is_completed']) {
                                                $cardClass = 'cmd-slot-completed';
                                            }
                                        @endphp
                                        <div wire:click="inspectBooking('{{ $booking->id }}')"
                                            class="cmd-slot-box {{ $cardClass }}" title="Klik untuk rincian sesi">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <span class="st">
                                                    @if ($slot['is_playing'])
                                                        &bull; MAIN
                                                    @elseif($slot['is_paid'])
                                                        LUNAS
                                                    @elseif($slot['is_pending'])
                                                        HOLD
                                                    @elseif($slot['is_completed'])
                                                        SELESAI
                                                    @endif
                                                </span>
                                                <span class="tm">{{ $booking->start_time->format('H:i') }}</span>
                                            </div>

                                            <div class="nm" title="{{ $slot['player_name'] }}">{{ $slot['player_name'] }}</div>

                                            <div class="ft">
                                                <code>{{ substr($slot['booking_code'], -6) }}</code>
                                                @if ($slot['equipment_count'] > 0)
                                                    <span class="eq">+{{ $slot['equipment_count'] }} alat</span>
                                                @endif
                                            </div>
                                        </div>
                                    @elseif($slot['type'] === 'closed')
                                        <div class="cmd-slot-box cmd-slot-past" title="Di luar jam operasional lapangan">
                                            <b>-</b>
                                            <span>TUTUP</span>
                                        </div>
                                    @else
                                        @if ($slot['is_past'])
                                            <div class="cmd-slot-box cmd-slot-past" title="Jam operasional telah lewat">
                                                <b>-</b>
                                                <span>LEWAT</span>
                                            </div>
                                        @else
                                            <div wire:click="inspectEmptySlot('{{ $court->id }}', '{{ $h }}')"
                                                class="cmd-slot-box cmd-slot-available"
                                                title="Slot Kosong - Klik untuk booking instan">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                </svg>
                                                <span style="font-size: 0.625rem; font-weight: 800;">KOSONG</span>
                                                <span style="font-size: 0.5625rem; opacity: 0.8;">{{ sprintf('%02d:00', $h) }}</span>
                                            </div>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- 5. Inspector detail slot -->
    @if ($showInspectorDrawer && $inspectData)
        <div class="c61-modal-backdrop" wire:click.self="closeInspector()">
            <div class="c61-modal">
                @if ($inspectData['type'] === 'booking')
                    <div class="c61-modal-head">
                        <div>
                            <div class="c61-modal-eyebrow">
                                {{ $inspectData['court_name'] }} &bull;
                                <span style="color: {{ $inspectData['is_checked_in'] ? '#6EE7B7' : 'inherit' }};">
                                    {{ $inspectData['is_checked_in'] ? 'SEDANG MAIN' : ($inspectData['status'] === 'PAID' ? 'TERJADWAL LUNAS' : $inspectData['status']) }}
                                </span>
                            </div>
                            <div class="c61-modal-title">{{ $inspectData['customer_name'] }}</div>
                        </div>
                        <button type="button" wire:click="closeInspector()" class="c61-modal-close">&times;</button>
                    </div>

                    <div class="c61-modal-body">
                        <div class="c61-kv">
                            <div>
                                <div class="k">Jadwal Bermain</div>
                                <div class="v">{{ $inspectData['start_time'] }} - {{ $inspectData['end_time'] }} WIB</div>
                                <div class="s">{{ $inspectData['date_formatted'] }}</div>
                            </div>
                            <div>
                                <div class="k">Kode Tiket</div>
                                <div class="v c61-mono">{{ $inspectData['booking_code'] }}</div>
                                <div class="s" style="color: #047857; font-weight: 700;">Status: {{ $inspectData['payment_status'] }}</div>
                            </div>
                        </div>

                        <div class="c61-note" style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; background: #FFFFFF;">
                            <div>
                                <div style="font-size: 0.625rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--c-muted);">Kontak Pemain</div>
                                <div style="font-weight: 700; font-size: 0.875rem;">{{ $inspectData['customer_phone'] }}</div>
                            </div>
                            @if ($inspectData['customer_phone'] && $inspectData['customer_phone'] !== '-')
                                @php
                                    $cleanPhone = preg_replace('/[^0-9]/', '', $inspectData['customer_phone']);
                                    if (str_starts_with($cleanPhone, '0')) {
                                        $cleanPhone = '62' . substr($cleanPhone, 1);
                                    }
                                @endphp
                                <a href="https://wa.me/{{ $cleanPhone }}" target="_blank" class="c61-btn c61-btn-ghost c61-btn-sm">
                                    <span>Hubungi WA</span>
                                </a>
                            @endif
                        </div>

                        @if (!empty($inspectData['equipments']))
                            <div class="c61-note" style="background: #FFFFFF;">
                                <div style="font-size: 0.625rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--c-muted); margin-bottom: 0.5rem;">
                                    Serah Terima Alat Sewa
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                                    @foreach ($inspectData['equipments'] as $eq)
                                        <div style="display: flex; justify-content: space-between; font-size: 0.8125rem;">
                                            <span style="font-weight: 700;">{{ $eq['quantity'] }}x {{ $eq['name'] }}</span>
                                            <span style="color: var(--c-terra); font-weight: 700;">Rp {{ number_format($eq['price'], 0, ',', '.') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="c61-modal-foot">
                        <button type="button" wire:click="closeInspector()" class="c61-btn c61-btn-ghost">
                            <span>Tutup</span>
                        </button>
                        @if (!$inspectData['is_checked_in'] && $inspectData['status'] === 'PAID')
                            <button type="button"
                                wire:click="quickCheckInFromInspector('{{ $inspectData['id'] }}')"
                                class="c61-btn c61-btn-primary" style="flex: 1;">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                <span>Check-In Pemain Sekarang</span>
                            </button>
                        @elseif($inspectData['is_checked_in'])
                            <button type="button" wire:click="executeComplete('{{ $inspectData['id'] }}')"
                                class="c61-btn c61-btn-success" style="flex: 1;">
                                <span>Tandai Sesi Selesai (Completed)</span>
                            </button>
                        @endif
                    </div>
                @else
                    <div class="c61-modal-head">
                        <div>
                            <div class="c61-modal-eyebrow">{{ $inspectData['court_name'] }}</div>
                            <div class="c61-modal-title">Slot Kosong Tersedia</div>
                        </div>
                        <button type="button" wire:click="closeInspector()" class="c61-modal-close">&times;</button>
                    </div>

                    <div class="c61-modal-body">
                        <div class="c61-note c61-note-ok">
                            <div style="font-weight: 800; font-size: 0.9375rem;">Jadwal: {{ $inspectData['time_label'] }}</div>
                            <div style="margin-top: 0.2rem;">{{ $inspectData['date_formatted'] }}</div>
                            <div style="font-weight: 700; margin-top: 0.5rem;">
                                Tarif Sewa: Rp {{ number_format($inspectData['rate'], 0, ',', '.') }} / jam
                            </div>
                        </div>

                        <div style="font-size: 0.8125rem; color: var(--c-muted);">
                            Slot ini belum memiliki reservasi. Anda dapat langsung mengarahkan pelanggan walk-in atau
                            memesan untuk anggota.
                        </div>
                    </div>

                    <div class="c61-modal-foot">
                        <button type="button" wire:click="closeInspector()" class="c61-btn c61-btn-ghost">
                            <span>Tutup</span>
                        </button>
                        <a href="/admin/book-offline-court" class="c61-btn c61-btn-primary" style="flex: 1;">
                            <span>Buka Form Walk-In Booking</span>
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- 6. Scanner check-in gate -->
    @if ($showCheckInModal)
        <div class="c61-modal-backdrop" wire:click.self="closeCheckInModal()">
            <div class="c61-modal">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Gate Access &bull; Club 61 Padel Court</div>
                        <div class="c61-modal-title">Scan QR / Verifikasi Check-In Gate</div>
                    </div>
                    <button type="button" wire:click="closeCheckInModal()" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body">
                    <form wire:submit.prevent="executeCheckIn">
                        <label class="c61-label">Masukkan Kode Tiket / Scan Hash Barcode:</label>
                        {{-- autofocus diabaikan browser di modal yang muncul belakangan; scanner barcode
                             USB "mengetik" ke elemen yang sedang fokus, jadi fokus wajib dipaksa. --}}
                        <input type="text" wire:model="checkInQuery"
                            placeholder="Contoh: BK-PAD-VRK0QHPJ atau Hash QR" autocomplete="off"
                            x-init="$nextTick(() => $el.focus())"
                            class="c61-input c61-mono" style="height: 48px; font-size: 0.9375rem; font-weight: 700;">

                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                            <button type="button" wire:click="closeCheckInModal()" class="c61-btn c61-btn-ghost c61-btn-lg">
                                <span>Batal</span>
                            </button>
                            <button type="submit" class="c61-btn c61-btn-primary c61-btn-lg" style="flex: 1;">
                                <span>Verifikasi &amp; Check-In Gate</span>
                            </button>
                        </div>
                    </form>

                    @if ($checkInResult)
                        <div class="c61-note {{ $checkInResult['already_checked_in'] ?? false ? 'c61-note-warn' : 'c61-note-ok' }}" style="font-size: 0.8125rem;">
                            <div style="font-weight: 800;">
                                {{ $checkInResult['message'] ?? 'Check-in berhasil.' }}
                            </div>
                            @if (!empty($checkInResult['booking']))
                                @php $resB = $checkInResult['booking']; @endphp
                                <div style="margin-top: 0.35rem;">
                                    Pemain: <strong>{{ $resB->user?->name ?? 'Guest' }}</strong> &bull;
                                    {{ $resB->court?->name }} ({{ $resB->start_time->format('H:i') }} -
                                    {{ $resB->end_time->format('H:i') }})
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
