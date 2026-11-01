<div class="c61 cmd-wrap">
    {{-- Realtime: refresh saat ada booking / check-in di tanggal yang dibuka; tanpa Reverb → tiap 10 dtk seperti dulu. 60 dtk jaga-jaga untuk status per jam. --}}
    <div hidden x-data="club61LiveRefresh({ dateProp: 'selectedDate', fallback: 10, safety: 60 })"></div>
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
        /* Kolom jam berbagi lebar layar; jam yang sudah lewat dikecilkan (min-width tabel diatur inline). */
        .cmd-table { --cmd-court-w: 200px; table-layout: fixed; width: 100%; border-collapse: separate; border-spacing: 0; }
        .cmd-th-court, .cmd-th-hour { background: var(--c-paper); border-bottom: 1px solid var(--c-line); font-size: 0.6875rem; font-weight: 800; color: var(--c-muted); text-transform: uppercase; letter-spacing: 0.06em; }
        .cmd-th-court { position: sticky; left: 0; z-index: 20; text-align: left; padding: 0.75rem 1rem; width: var(--cmd-court-w); min-width: var(--cmd-court-w); max-width: var(--cmd-court-w); border-right: 1px solid var(--c-line); }
        .cmd-th-hour { padding: 0.6rem 0.4rem; text-align: center; border-right: 1px solid var(--c-line-soft); font-variant-numeric: tabular-nums; }
        .cmd-th-hour.is-dead { width: 34px; padding: 0.6rem 0; }
        .cmd-th-hour.is-live { background: var(--c-terra); color: var(--c-cream); border-bottom-color: var(--c-terra); }
        .cmd-th-hour .live-tag { font-size: 0.5625rem; font-weight: 800; letter-spacing: 0.08em; margin-top: 0.1rem; color: rgba(247, 240, 219, 0.85); }
        .cmd-td-court { position: sticky; left: 0; z-index: 10; background: #FFFFFF; padding: 0.75rem 1rem; width: var(--cmd-court-w); min-width: var(--cmd-court-w); max-width: var(--cmd-court-w); border-right: 1px solid var(--c-line); border-bottom: 1px solid var(--c-line-soft); }
        /* Laptop kecil / tablet: kolom lapangan dirampingkan supaya muat lebih banyak jam (tipe lapangan tetap di baris keterangan). */
        @media (max-width: 1100px) {
            .cmd-table { --cmd-court-w: 140px; }
            .cmd-th-court, .cmd-td-court { padding-left: 0.75rem; padding-right: 0.75rem; }
            .cmd-court-type-pill { display: none; }
        }
        .cmd-court-name { font-family: var(--font-serif); font-size: 1rem; font-weight: 600; color: var(--c-brown); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cmd-court-meta { font-size: 0.6875rem; color: var(--c-muted); font-weight: 600; margin-top: 0.15rem; }
        .cmd-td-slot { padding: 0.35rem; height: 92px; vertical-align: top; background: #FFFFFF; border-right: 1px solid var(--c-line-soft); border-bottom: 1px solid var(--c-line-soft); }
        .cmd-td-slot.is-live-col { background: rgba(102, 39, 33, 0.035); }
        .cmd-td-slot.is-dead { padding: 0.35rem 0.15rem; }
        .cmd-td-slot.is-dead .cmd-slot-past > * { display: none; }

        .cmd-slot-box { width: 100%; height: 100%; border-radius: 10px; padding: 0.45rem 0.5rem; display: flex; flex-direction: column; justify-content: space-between; font-size: 0.6875rem; cursor: pointer; overflow: hidden; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .cmd-slot-box:hover { transform: translateY(-1px); box-shadow: 0 8px 18px -10px rgba(79, 47, 42, 0.45); }
        .cmd-slot-box .st { font-size: 0.5625rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; }
        .cmd-slot-box .tm { font-size: 0.5625rem; font-family: var(--font-mono); opacity: 0.8; }
        .cmd-slot-box .nm { font-weight: 800; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin: 0.15rem 0; }
        .cmd-slot-box .ft { display: flex; justify-content: space-between; align-items: center; font-size: 0.5625rem; }
        .cmd-slot-box .ft code { font-family: var(--font-mono); opacity: 0.8; }
        .cmd-slot-box .eq { padding: 0.05rem 0.35rem; border-radius: 4px; background: rgba(0, 0, 0, 0.06); font-weight: 700; }
        /* Sesi coaching: baris coach (utama + jumlah tambahan), warna ikut status sel. */
        .cmd-slot-box .co { font-size: 0.5625rem; font-weight: 700; padding: 0.1rem 0.35rem; border-radius: 4px; background: rgba(0, 0, 0, 0.07); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 0.15rem; }
        .cmd-slot-paid .co { background: rgba(247, 240, 219, 0.18); }
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

        /* Pilihan tampilan Harian / Mingguan / Bulanan. */
        .cmd-modes { display: inline-flex; border: 1px solid var(--c-line); border-radius: 10px; overflow: hidden; background: #FFFFFF; }
        .cmd-modes button { height: 40px; padding: 0 0.95rem; font-size: 0.8125rem; font-weight: 700; color: var(--c-brown); background: transparent; border: 0; cursor: pointer; }
        .cmd-modes button + button { border-left: 1px solid var(--c-line); }
        .cmd-modes button.is-active { background: var(--c-terra); color: var(--c-cream); }
        .cmd-search { width: 220px; height: 40px; }

        /* Jenis booking (tampilan mingguan / bulanan): warna latar + garis kiri. */
        .cmd-chip { display: block; width: 100%; text-align: left; font-size: 0.6875rem; font-weight: 700; line-height: 1.3; padding: 0.2rem 0.4rem; margin-bottom: 0.2rem; border: 0; border-left: 3px solid; border-radius: 6px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cmd-chip small { font-size: 0.5625rem; font-weight: 800; text-transform: uppercase; opacity: 0.75; margin-right: 0.15rem; }
        .cmd-chip.is-cont { opacity: 0.5; }
        .cmd-chip.is-pending { border: 1px dashed; border-left: 3px solid; }
        .cmd-chip.is-done { opacity: 0.65; }
        .t-MEMBER { background: #F6EAE7; border-color: #662721; color: #4F2F2A; }
        .t-CORPORATE { background: #E8EEFB; border-color: #1E40AF; color: #1E3A8A; }
        .t-COACHING { background: #E7F6EF; border-color: #047857; color: #065F46; }
        .t-RECURRING { background: #F1EBFB; border-color: #6D28D9; color: #5B21B6; }
        .t-ONLINE { background: #FDF3E4; border-color: #B45309; color: #92400E; }
        .t-WALK_IN { background: #F1F0EE; border-color: #57534E; color: #44403C; }
        /* Legenda: kotak penuh berwarna jenis (garis kiri selebar kotak). */
        .cmd-legend i[class^="t-"] { border-left: 12px solid; background: transparent; }

        /* Mingguan: baris jam × kolom hari. */
        /* Kolom hari berbagi lebar layar (min. 105px) supaya Senin–Minggu muat di laptop 14" / tablet landscape. */
        .cmd-week { table-layout: fixed; width: 100%; min-width: calc(64px + 7 * 105px); border-collapse: separate; border-spacing: 0; }
        .cmd-week th, .cmd-week td { border-right: 1px solid var(--c-line-soft); border-bottom: 1px solid var(--c-line-soft); }
        .cmd-week-hr { position: sticky; left: 0; z-index: 10; width: 64px; min-width: 64px; background: var(--c-paper); font-size: 0.6875rem; font-weight: 800; color: var(--c-muted); text-align: center; font-variant-numeric: tabular-nums; padding: 0.45rem 0.3rem; vertical-align: top; }
        .cmd-week-day { background: var(--c-paper); padding: 0.5rem 0.4rem; }
        .cmd-week-day button { font-size: 0.75rem; font-weight: 800; color: var(--c-brown); background: none; border: 0; cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em; }
        .cmd-week-day.is-today { background: var(--c-terra); }
        .cmd-week-day.is-today button { color: var(--c-cream); }
        .cmd-week-cell { padding: 0.25rem; vertical-align: top; height: 40px; background: #FFFFFF; }
        .cmd-week-cell.is-today { background: rgba(102, 39, 33, 0.035); }

        /* Bulanan: kalender. */
        .cmd-month { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.4rem; }
        .cmd-month-dow { font-size: 0.6875rem; font-weight: 800; color: var(--c-muted); text-transform: uppercase; letter-spacing: 0.06em; text-align: center; padding-bottom: 0.2rem; }
        .cmd-mday { display: flex; flex-direction: column; gap: 0.2rem; min-height: 86px; padding: 0.5rem; text-align: left; background: #FFFFFF; border: 1px solid var(--c-line-soft); border-radius: 10px; cursor: pointer; color: var(--c-brown); }
        .cmd-mday:hover { border-color: var(--c-terra); }
        .cmd-mday.is-out { opacity: 0.45; }
        .cmd-mday.is-today { border: 1.5px solid var(--c-terra); }
        .cmd-mday.is-selected { background: var(--c-paper); border: 1.5px solid var(--c-terra); }
        .cmd-mday-num { font-size: 0.875rem; font-weight: 800; }
        .cmd-mday-count { font-size: 0.6875rem; font-weight: 700; }
        .cmd-mday-bar { display: block; height: 5px; border-radius: 999px; background: var(--c-line-soft); overflow: hidden; }
        .cmd-mday-bar i { display: block; height: 100%; background: var(--c-terra); }
        .cmd-mday-rate, .cmd-mday-empty { font-size: 0.625rem; color: var(--c-muted); }
        .cmd-daylist { margin-top: 1rem; border: 1px solid var(--c-line); border-radius: 12px; overflow: hidden; }
        .cmd-daylist-head { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; padding: 0.65rem 0.9rem; background: var(--c-paper); border-bottom: 1px solid var(--c-line); font-size: 0.8125rem; color: var(--c-brown); }
        .cmd-daylist-head span { color: var(--c-muted); font-size: 0.75rem; }
        .cmd-daylist-head .c61-btn { margin-left: auto; }
        .cmd-daylist-row { display: grid; grid-template-columns: 96px 90px minmax(0, 1fr) auto 110px; gap: 0.75rem; align-items: center; width: 100%; padding: 0.55rem 0.9rem; background: #FFFFFF; border: 0; border-bottom: 1px solid var(--c-line-soft); text-align: left; font-size: 0.8125rem; color: var(--c-brown); cursor: pointer; }
        .cmd-daylist-row:hover { background: var(--c-paper); }
        .cmd-daylist-name { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cmd-daylist-status { font-size: 0.6875rem; color: var(--c-muted); text-align: right; }
        @media (max-width: 720px) {
            .cmd-month { gap: 0.2rem; }
            .cmd-mday { min-height: 64px; padding: 0.35rem; }
            .cmd-mday-rate, .cmd-mday-bar { display: none; }
            .cmd-daylist-row { grid-template-columns: 80px minmax(0, 1fr) auto; }
            .cmd-daylist-row > span:nth-child(2), .cmd-daylist-status { display: none; }
            .cmd-search { width: 100%; }
        }
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
    @if ($viewMode !== 'day')
        <div class="c61-kpis">
            <div class="c61-kpi">
                <div class="c61-kpi-top"><div class="c61-kpi-label">Total Booking {{ $viewMode === 'month' ? 'Bulan Ini' : 'Minggu Ini' }}</div></div>
                <div class="c61-kpi-value is-terra">{{ $periodBookings }} <small>Booking</small></div>
                <div class="c61-kpi-foot"><span>{{ $periodLabel }}</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-top"><div class="c61-kpi-label">Total Jam Terisi</div></div>
                <div class="c61-kpi-value">{{ rtrim(rtrim(number_format($periodHours, 1, ',', '.'), '0'), ',') }} <small>Jam</small></div>
                <div class="c61-kpi-foot"><span>Semua lapangan sesuai filter</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-top"><div class="c61-kpi-label">Pemain</div></div>
                <div class="c61-kpi-value is-ok">{{ count($players) }} <small>Orang</small></div>
                <div class="c61-kpi-foot"><span class="ok">Lihat Rekap Pemain di bawah</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-top"><div class="c61-kpi-label">Menunggu Bayar</div></div>
                <div class="c61-kpi-value">{{ $periodPending }} <small>Booking</small></div>
                <div class="c61-kpi-foot"><span>Ditandai garis putus-putus</span></div>
            </div>
        </div>
    @else
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
    @endif

    <!-- 3. Navigasi tanggal & filter -->
    <div class="c61-toolbar">
        <div class="cmd-datebar">
            <div class="cmd-modes" role="tablist" aria-label="Tampilan jadwal">
                @foreach (['day' => 'Harian', 'week' => 'Mingguan', 'month' => 'Bulanan'] as $mode => $modeLabel)
                    <button type="button" wire:click="setViewMode('{{ $mode }}')" class="{{ $viewMode === $mode ? 'is-active' : '' }}">{{ $modeLabel }}</button>
                @endforeach
            </div>

            @if ($viewMode === 'day')
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
            @else
            <button type="button" wire:click="shiftPeriod(-1)" class="c61-btn c61-btn-ghost" title="Sebelumnya">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
            </button>
            <button type="button" wire:click="today()" class="c61-btn c61-btn-ghost cmd-nav-btn {{ $isCurrentPeriod ? 'is-active' : '' }}">
                <span>{{ $viewMode === 'month' ? 'Bulan Ini' : 'Minggu Ini' }}</span>
            </button>
            <button type="button" wire:click="shiftPeriod(1)" class="c61-btn c61-btn-ghost" title="Berikutnya">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
            </button>
            <input type="date" wire:model.live="selectedDate" class="c61-input cmd-date-input" title="Pilih tanggal di minggu / bulan yang ingin dilihat">
            <span class="cmd-date-label">{{ $periodLabel }}</span>
            @endif
        </div>

        <div class="c61-row" style="gap: 1rem; flex-wrap: wrap;">
            <select wire:model.live="courtFilter" class="c61-select" style="width: auto;">
                <option value="all">Semua Lapangan ({{ $courtOptions->count() }} Court)</option>
                @foreach ($courtOptions as $courtId => $courtName)
                    <option value="{{ $courtId }}">{{ $courtName }}</option>
                @endforeach
            </select>

            @if ($viewMode !== 'day')
            <select wire:model.live="typeFilter" class="c61-select" style="width: auto;">
                <option value="all">Semua Jenis</option>
                @foreach (\App\Services\Padel\ScheduleOverview::TYPES as $typeKey => $typeName)
                    <option value="{{ $typeKey }}">{{ $typeName }}</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" class="c61-input cmd-search" placeholder="Cari nama / no. HP / kode">

            <div class="cmd-legend">
                @foreach (\App\Services\Padel\ScheduleOverview::TYPES as $typeKey => $typeName)
                    <span><i class="t-{{ $typeKey }}"></i>{{ $typeName }}</span>
                @endforeach
                <span><i style="border: 1px dashed #7A5A52; background: #FFFFFF;"></i>Menunggu Bayar</span>
            </div>
            @else
            <div class="cmd-legend">
                <span><i style="background: #10B981;"></i>Sedang Main</span>
                <span><i style="background: #662721;"></i>Terjadwal Lunas</span>
                <span><i style="background: #F97316;"></i>Menunggu Bayar</span>
                <span><i style="border: 1.5px dashed #E6DAC0; background: #FFFFFF;"></i>Slot Kosong (+)</span>
                <span><i style="background: repeating-linear-gradient(135deg, #FAF8F3 0 3px, #E8E1D4 3px 4px);"></i>Lewat (-)</span>
            </div>
            @endif
        </div>
    </div>

    <!-- 4. Matriks jadwal (harian) / tampilan mingguan & bulanan -->
    @if ($viewMode !== 'day')
        @include('filament.pages.partials.monitoring-overview')
    @else
    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">
                    Timetable Jadwal Lapangan Padel
                    ({{ !empty($operationalHours) ? $operationalHours[0]['label'] . ' - ' . end($operationalHours)['next_label'] . ' WIB' : '06:00 - 23:00 WIB' }})
                </div>
                <div class="c61-card-sub">Klik blok slot untuk melihat detail pemain, check-in gate, atau booking instan. Kolom jam yang sudah lewat dikecilkan.</div>
            </div>
        </div>

        @php $deadCount = collect($operationalHours)->where('is_dead', true)->count(); @endphp
        {{-- Saat dibuka (atau ganti tanggal / lapangan), tabel langsung digeser ke kolom LIVE NOW; refresh otomatis tidak mengubah posisi geser. --}}
        <div class="cmd-matrix-scroll" wire:key="matrix-{{ $selectedDate }}-{{ $courtFilter }}"
            x-data x-init="$nextTick(() => { const live = $el.querySelector('th.is-live'), court = $el.querySelector('.cmd-th-court'); if (live && court) $el.scrollLeft = live.offsetLeft - court.offsetWidth; })">
            <table class="cmd-table" style="min-width: calc(var(--cmd-court-w) + {{ $deadCount * 34 + (count($operationalHours) - $deadCount) * 90 }}px);">
                <thead>
                    <tr>
                        <th class="cmd-th-court">Lapangan Padel</th>
                        @foreach ($operationalHours as $opHour)
                            <th class="cmd-th-hour {{ $opHour['is_current'] ? 'is-live' : '' }} {{ $opHour['is_dead'] ? 'is-dead' : '' }}"
                                title="{{ $opHour['label'] }} – {{ $opHour['next_label'] }}">
                                <div>{{ $opHour['is_dead'] ? substr($opHour['label'], 0, 2) : $opHour['label'] }}</div>
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
                                    <span class="c61-pill c61-pill-cream cmd-court-type-pill" style="height: 20px; font-size: 0.5625rem; padding: 0 0.45rem;">
                                        INDOOR
                                    </span>
                                </div>
                            </td>

                            @foreach ($operationalHours as $opHour)
                                @php
                                    $h = $opHour['hour'];
                                    $slot = $row['slots'][$h];
                                    $isLiveCol = $opHour['is_current'];
                                @endphp
                                <td class="cmd-td-slot {{ $isLiveCol ? 'is-live-col' : '' }} {{ $opHour['is_dead'] ? 'is-dead' : '' }}">
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
                                                    @elseif($slot['is_tab'] ?? false)
                                                        TAGIHAN BERKALA
                                                    @elseif($slot['is_pending'])
                                                        HOLD
                                                    @elseif($slot['is_completed'])
                                                        SELESAI
                                                    @endif
                                                </span>
                                                <span class="tm">{{ $booking->start_time->format('H:i') }}</span>
                                            </div>

                                            <div class="nm" title="{{ $slot['player_name'] }}">{{ $slot['player_name'] }}</div>

                                            @if ($slot['coaching_label'])
                                                <div class="co" title="Coaching · {{ $slot['coaching_title'] }}">Coach {{ $slot['coaching_label'] }}</div>
                                            @endif

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
    @endif

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

                        @if (!empty($inspectData['coaching']))
                            @php $coaching = $inspectData['coaching']; @endphp
                            <div class="c61-note" style="background: #FFFFFF;" data-inspector-coaching>
                                <div style="font-size: 0.625rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--c-muted); margin-bottom: 0.5rem;">
                                    Sesi Coaching{{ $coaching['academy'] ? ' · ' . $coaching['academy'] : '' }}
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 0.45rem;">
                                    @foreach ($coaching['coaches'] as $coach)
                                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem;">
                                            <div style="min-width: 0;">
                                                <div style="font-weight: 800; font-size: 0.8125rem;">{{ $coach['name'] }}</div>
                                                <div style="font-size: 0.6875rem; color: var(--c-muted);">{{ $coach['role'] }}</div>
                                            </div>
                                            @if ($coach['wa_url'])
                                                <a href="{{ $coach['wa_url'] }}" target="_blank" rel="noopener noreferrer" class="c61-btn c61-btn-ghost c61-btn-sm" style="flex-shrink: 0;">
                                                    <span>Ingatkan WA</span>
                                                </a>
                                            @else
                                                <span style="flex-shrink: 0; font-size: 0.6875rem; color: #B42318;">No. WA belum diisi</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if (!empty($coaching['students']))
                                        <div style="font-size: 0.8125rem; padding-top: 0.45rem; border-top: 1px dashed var(--c-line);">
                                            <span style="color: var(--c-muted);">Murid:</span> <b>{{ implode(', ', $coaching['students']) }}</b>
                                        </div>
                                    @endif
                                    @if (filled($coaching['notes']))
                                        <div style="font-size: 0.8125rem; white-space: pre-line;"><span style="color: var(--c-muted);">Catatan:</span> {{ $coaching['notes'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif

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
                        <div class="c61-modal-eyebrow">Gate Access &bull; Club 61 Padel &amp; Wellness</div>
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
