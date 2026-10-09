<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        .kk-person { display: flex; gap: 0.75rem; align-items: center; }
        .kk-avatar { width: 42px; height: 42px; border-radius: 12px; background: var(--c-terra); color: var(--c-cream); display: flex; align-items: center; justify-content: center; font-family: var(--font-serif); font-weight: 600; font-size: 1rem; flex-shrink: 0; }
        .kk-name { font-weight: 700; color: var(--c-brown); }
        .kk-role { font-size: 0.6875rem; color: var(--c-terra); font-weight: 700; margin-top: 0.1rem; }
        .kk-meta { font-size: 0.75rem; color: var(--c-muted); margin-top: 0.4rem; }
        .kk-meta strong { color: var(--c-brown); }
    </style>

    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span>Modul Kelola Karyawan &bull; Staff Directory</div>
            <div class="c61-hero-title">Kelola Karyawan &amp; Pelatih</div>
            <div class="c61-hero-sub">
                Manajemen pelatih padel profesional (WPT Certified), hair stylist salon, barista cafe, staf kasir frontdesk, dan komisi jasa.
            </div>
        </div>

        <div class="c61-hero-actions">
            <button type="button" class="c61-btn c61-btn-cream c61-btn-lg">
                <span>+ Rekrut Staf / Coach</span>
            </button>
        </div>
    </div>

    <div class="c61-kpis">
        <div class="c61-kpi">
            <div class="kk-person">
                <div class="kk-avatar">CB</div>
                <div>
                    <div class="kk-name">Coach Budi Santoso</div>
                    <div class="kk-role">Head Coach Padel (WPT)</div>
                </div>
            </div>
            <div class="kk-meta">Tarif: <strong>Rp 150.000 / Jam</strong> &bull; Komisi 15%</div>
            <div class="c61-kpi-foot"><span class="c61-pill c61-pill-ok">Tersedia Melatih</span></div>
        </div>

        <div class="c61-kpi">
            <div class="kk-person">
                <div class="kk-avatar">SS</div>
                <div>
                    <div class="kk-name">Siti Hair Stylist</div>
                    <div class="kk-role">Senior Hair Specialist</div>
                </div>
            </div>
            <div class="kk-meta">Tarif: <strong>Rp 100.000 / Sesi</strong> &bull; Komisi 10%</div>
            <div class="c61-kpi-foot"><span class="c61-pill c61-pill-ok">Aktif di Salon</span></div>
        </div>

        <div class="c61-kpi">
            <div class="kk-person">
                <div class="kk-avatar">BK</div>
                <div>
                    <div class="kk-name">Barista Cafe Club 61</div>
                    <div class="kk-role">Operator KDS Dapur &amp; Kopi</div>
                </div>
            </div>
            <div class="kk-meta">Shift Pagi &bull; Station Coffee Bar</div>
            <div class="c61-kpi-foot"><span class="c61-pill c61-pill-terra">Online KDS</span></div>
        </div>

        <div class="c61-kpi">
            <div class="kk-person">
                <div class="kk-avatar">KF</div>
                <div>
                    <div class="kk-name">Kasir Frontdesk POS</div>
                    <div class="kk-role">Front Office &bull; Kasir Terminal</div>
                </div>
            </div>
            <div class="kk-meta">Terminal 01 &bull; Resepsionis Utama</div>
            <div class="c61-kpi-foot"><span class="c61-pill c61-pill-ok">Standby POS</span></div>
        </div>
    </div>
</div>
