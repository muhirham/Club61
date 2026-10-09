<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        .tn-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 1.25rem; }
        .tn-kv { grid-template-columns: 1fr 1fr; }
        .tn-kv .v.money { font-family: var(--font-mono); font-size: 1rem; color: var(--c-terra); }
    </style>

    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span>Modul Turnamen &bull; Event Management</div>
            <div class="c61-hero-title">Kelola Turnamen &amp; Kejuaraan Padel</div>
            <div class="c61-hero-sub">
                Susun bagan pertandingan (bracket), pendaftaran tim ganda (doubles), penetapan prize pool, dan sponsor turnamen.
            </div>
        </div>

        <div class="c61-hero-actions">
            <button type="button" class="c61-btn c61-btn-cream c61-btn-lg">
                <span>+ Buat Turnamen Baru</span>
            </button>
        </div>
    </div>

    <div class="tn-grid">
        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <span class="c61-pill c61-pill-terra">Pendaftaran Dibuka</span>
                    <div class="c61-card-title" style="margin-top: 0.5rem;">Club 61 Open Championship 2026</div>
                    <div class="c61-card-sub">Kategori: Men's Doubles &bull; Open Grade A</div>
                </div>
            </div>
            <div class="c61-card-body">
                <div class="c61-kv tn-kv">
                    <div><div class="k">Total Hadiah (Prize Pool):</div><div class="v money">Rp 25.000.000</div></div>
                    <div><div class="k">Slot Peserta:</div><div class="v">24 / 32 Pasang</div></div>
                    <div><div class="k">Tanggal Tanding:</div><div class="v">15 - 17 Juni 2026</div></div>
                    <div><div class="k">Biaya Registrasi:</div><div class="v">Rp 500.000 / Tim</div></div>
                </div>
            </div>
        </div>

        <div class="c61-card">
            <div class="c61-card-head">
                <div>
                    <span class="c61-pill c61-pill-ok">Segera Hadir</span>
                    <div class="c61-card-title" style="margin-top: 0.5rem;">Club 61 Invitational Mixed Doubles</div>
                    <div class="c61-card-sub">Kategori: Mixed Doubles &bull; Member Only</div>
                </div>
            </div>
            <div class="c61-card-body">
                <div class="c61-kv tn-kv">
                    <div><div class="k">Total Hadiah (Prize Pool):</div><div class="v money">Rp 10.000.000</div></div>
                    <div><div class="k">Slot Peserta:</div><div class="v">16 Pasang</div></div>
                    <div><div class="k">Tanggal Tanding:</div><div class="v">10 Juli 2026</div></div>
                    <div><div class="k">Biaya Registrasi:</div><div class="v">Gratis (Khusus Member VIP)</div></div>
                </div>
            </div>
        </div>
    </div>
</div>
