<style>
    /* Brand guideline Club 61: Cheltenham Classic (judul) & Acumin Variable Concept (teks) — selama webfont berlisensinya
       belum dipasang, tampil dengan padanan terdekat: Source Serif 4 & Archivo (sama dengan compro, customer & POS kasir). */
    @import url('https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@87.5..125,400..800&family=Source+Serif+4:opsz,wght@8..60,400..700&family=JetBrains+Mono:wght@500;600;700&display=swap');

    :root {
        --font-serif: 'Cheltenham Classic', 'Source Serif 4', Georgia, serif;
        --font-sans: 'Acumin Variable Concept', 'Archivo', ui-sans-serif, system-ui, sans-serif;
        --font-mono: 'JetBrains Mono', monospace;
        --c61-terra: #662721;
        --c61-terra-dark: #511D18;
        --c61-brown: #4F2F2A;
        --c61-cream: #F7F0DB;
        --c61-paper: #FCF8EE;
        --c61-line: #E6DAC0;
        --c61-muted: #7A5A52;
    }

    /* Latar panel: cream polos (brand), tanpa marmer emas. */
    body,
    .fi-layout,
    .fi-main,
    .fi-simple-layout {
        background-color: var(--c61-cream) !important;
        background-image: none !important;
        font-family: var(--font-sans) !important;
        color: var(--c61-brown) !important;
    }

    /* Sidebar: kertas krem terang, item aktif terakota solid. */
    .fi-sidebar {
        background: var(--c61-paper) !important;
        border-right: 1px solid var(--c61-line) !important;
        box-shadow: none !important;
    }

    .fi-sidebar-header {
        border-bottom: 1px solid var(--c61-line) !important;
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
    }

    /* Logo panel (filament/brand-logo.blade.php). */
    .c61-brand { display: inline-flex; align-items: center; gap: 0.6rem; height: 2.25rem; }
    .c61-brand-mark { height: 2.1rem; width: auto; }
    .c61-brand-text { display: flex; flex-direction: column; line-height: 1; }
    .c61-brand-name { font-family: var(--font-serif); font-size: 1.125rem; font-weight: 600; letter-spacing: 0.02em; }
    .c61-brand-sub { font-size: 0.5625rem; font-weight: 700; letter-spacing: 0.22em; text-transform: uppercase; margin-top: 0.2rem; opacity: 0.75; }
    .fi-topbar .c61-brand { color: var(--c61-cream); }
    .fi-topbar .c61-brand-mark.is-dark { display: none; }
    .fi-sidebar .c61-brand { color: var(--c61-terra); }
    .fi-sidebar .c61-brand-mark.is-light { display: none; }

    /* Fade-in label bawaan Filament (opacity 0 → 1) kadang tertahan di opacity 0 sampai ada repaint — label menu
       tampak kosong. Label & badge langsung tampil tanpa animasi. */
    .fi-sidebar-item-label,
    .fi-sidebar-item-badge-ctn {
        opacity: 1 !important;
        transition: none !important;
    }

    .fi-sidebar-item-label {
        font-size: 0.8125rem !important;
        font-weight: 600 !important;
        color: var(--c61-brown) !important;
    }

    .fi-sidebar-item-btn > .fi-icon {
        color: var(--c61-muted) !important;
    }

    /* Item menu (Filament 5: .fi-sidebar-item-btn). Latar eksplisit sewarna sidebar — tanpa latar, label menu
       tidak ter-render di Chrome setelah sidebar tidak lagi memakai backdrop-filter. */
    .fi-sidebar-item-btn {
        background-color: var(--c61-paper) !important;
        border-radius: 10px !important;
        transition: background-color 150ms ease;
    }

    .fi-sidebar-item-btn:hover {
        background-color: var(--c61-cream) !important;
    }

    .fi-sidebar-item-btn:hover .fi-sidebar-item-label,
    .fi-sidebar-item-btn:hover .fi-icon {
        color: var(--c61-terra) !important;
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn,
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn:hover {
        background-color: var(--c61-terra) !important;
        box-shadow: 0 6px 14px -8px rgba(102, 39, 33, 0.6) !important;
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn .fi-sidebar-item-label,
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon {
        color: var(--c61-cream) !important;
        font-weight: 700 !important;
    }

    .fi-sidebar-group-label {
        font-size: 0.6875rem !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.14em !important;
        color: #A08F86 !important;
    }

    /* Grup menu sidebar (dropdown): bawaan Filament memberi jarak 1.75rem antar grup — terlalu renggang
       saat grup ditutup. Jarak antar grup dirapatkan; grup yang terbuka diberi sedikit ruang di bawah
       item-itemnya supaya tetap terpisah dari judul grup berikutnya. */
    .fi-sidebar-nav {
        padding-block: 1.25rem !important;
        row-gap: 0.75rem !important;
    }

    .fi-sidebar-nav-groups {
        row-gap: 0.125rem !important;
    }

    .fi-sidebar-group-btn {
        padding-block: 0.5rem !important;
        border-radius: 10px !important;
        transition: background-color 150ms ease;
    }

    .fi-sidebar-group-btn:hover {
        background: var(--c61-cream) !important;
    }

    .fi-sidebar-group-collapse-btn {
        color: #A08F86 !important;
    }

    .fi-sidebar-group:not(.fi-collapsed) .fi-sidebar-group-items {
        padding-bottom: 0.5rem;
    }

    /* Topbar: pita terakota dengan logo cream (sama dengan header POS kasir). */
    .fi-topbar {
        background: var(--c61-terra) !important;
        border-bottom: none !important;
        box-shadow: 0 4px 16px -10px rgba(42, 20, 16, 0.6) !important;
    }

    .fi-topbar .fi-icon-btn,
    .fi-topbar .fi-icon-btn .fi-icon {
        color: var(--c61-cream) !important;
    }

    .fi-topbar .fi-icon-btn:hover {
        background: rgba(247, 240, 219, 0.12) !important;
    }

    .fi-topbar .fi-user-avatar,
    .fi-topbar .fi-avatar {
        background: var(--c61-cream) !important;
        color: var(--c61-terra) !important;
        box-shadow: 0 0 0 2px rgba(247, 240, 219, 0.35) !important;
    }

    /* ===== Halaman resource Filament bawaan yang sudah dimigrasi ke brand =====
       Daftar halaman ada di :is(...) — tambah class halaman di sini saat resource berikutnya dimigrasi. */
    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header {
        position: relative;
        overflow: hidden;
        background: var(--c61-terra);
        border-radius: 20px;
        padding: 1.5rem 1.75rem;
        color: var(--c61-cream);
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header::after {
        content: '';
        position: absolute;
        right: -40px;
        top: 50%;
        width: 240px;
        height: 240px;
        transform: translateY(-50%);
        background: url('{{ asset('images/identity/monogram-cream.png') }}') center / contain no-repeat;
        opacity: 0.07;
        pointer-events: none;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header > * {
        position: relative;
        z-index: 1;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header-heading {
        font-family: var(--font-serif) !important;
        font-weight: 600 !important;
        font-size: 1.75rem !important;
        letter-spacing: 0 !important;
        color: var(--c61-cream) !important;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header-subheading,
    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-breadcrumbs,
    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-breadcrumbs * {
        color: rgba(247, 240, 219, 0.75) !important;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header .fi-btn {
        background: var(--c61-cream) !important;
        color: var(--c61-terra) !important;
        box-shadow: none !important;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header .fi-btn:hover {
        background: #FFFFFF !important;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-header .fi-btn .fi-icon {
        color: var(--c61-terra) !important;
    }

    /* Tabel & kartu form: border krem tipis, header kolom kecil kapital (sama dengan tabel .c61-table). */
    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-ta-ctn,
    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-section {
        border: 1px solid var(--c61-line) !important;
        border-radius: 18px !important;
        box-shadow: none !important;
        overflow: hidden;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-ta-header-cell,
    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-ta-selection-cell:is(th) {
        background: var(--c61-paper) !important;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-ta-header-cell,
    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-ta-header-cell .fi-ta-header-cell-sort-btn {
        font-size: 0.625rem !important;
        font-weight: 800 !important;
        letter-spacing: 0.08em !important;
        text-transform: uppercase !important;
        color: var(--c61-muted) !important;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-ta-row:hover {
        background: #FDFAF2 !important;
    }

    :is(.fi-resource-membership-membership-plans, .fi-resource-membership-facilities-membership-facilities) .fi-section-header-heading {
        font-family: var(--font-serif) !important;
        font-size: 1.1875rem !important;
        font-weight: 600 !important;
        color: var(--c61-brown) !important;
    }

    /* Hide default filament page header so custom banner takes its place seamlessly */
    .fi-page-header {
        display: none !important;
    }

    /* ========================================================
       CUSTOM DASHBOARD SCOPED CSS (Bulletproof against Tailwind uncompiled rules)
       ======================================================== */
    .adm-wrap {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        padding-bottom: 3rem;
        font-family: var(--font-sans);
    }

    .adm-banner {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.96) 0%, rgba(253, 249, 240, 0.94) 100%);
        border: 1.5px solid #D4AF37;
        border-radius: 20px;
        box-shadow: 0 12px 30px -10px rgba(160, 120, 30, 0.15);
        backdrop-filter: blur(16px);
        padding: 1.5rem 1.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .adm-banner-title {
        font-family: var(--font-serif);
        font-weight: 800;
        font-size: 1.5rem;
        color: #1F170D;
        line-height: 1.2;
    }

    .adm-banner-sub {
        font-size: 0.8125rem;
        color: #7A643E;
        margin-top: 0.35rem;
        font-weight: 500;
    }

    .adm-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .adm-pill-gold {
        background: #FAF2DE;
        border: 1px solid #D9BE84;
        color: #7A5818;
    }

    .adm-pill-green {
        background: #EAF7EC;
        border: 1px solid #85D497;
        color: #1E7E34;
    }

    /* 4 Metrics Grid */
    .adm-metrics-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
    }

    @media (max-width: 1024px) {
        .adm-metrics-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .adm-metrics-grid {
            grid-template-columns: 1fr;
        }
    }

    .adm-metric-card {
        background: rgba(255, 255, 255, 0.94);
        border: 1.5px solid #DFC387;
        border-radius: 16px;
        padding: 1.25rem;
        box-shadow: 0 8px 24px -8px rgba(160, 120, 30, 0.12);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: transform 0.2s ease;
    }

    .adm-metric-card:hover {
        transform: translateY(-2px);
    }

    .adm-metric-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #7A5818;
    }

    .adm-metric-val {
        font-family: var(--font-serif);
        font-size: 1.625rem;
        font-weight: 900;
        color: #1F170D;
        margin-top: 0.35rem;
        line-height: 1.1;
    }

    .adm-metric-foot {
        font-size: 0.6875rem;
        color: #8C7A58;
        margin-top: 0.75rem;
        padding-top: 0.5rem;
        border-top: 1px solid rgba(223, 195, 135, 0.4);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Chart & Table Cards */
    .adm-card {
        background: rgba(255, 255, 255, 0.95);
        border: 1.5px solid #D4AF37;
        border-radius: 20px;
        box-shadow: 0 12px 30px -10px rgba(160, 120, 30, 0.14);
        padding: 1.5rem;
    }

    .adm-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid rgba(223, 195, 135, 0.5);
    }

    .adm-card-title {
        font-family: var(--font-serif);
        font-weight: 800;
        font-size: 1.125rem;
        color: #1F170D;
    }

    .adm-card-sub {
        font-size: 0.75rem;
        color: #7A643E;
        font-weight: 500;
        margin-top: 0.15rem;
    }

    /* Tabs Filter */
    .adm-tabs {
        display: inline-flex;
        align-items: center;
        background: #FAF5E8;
        border: 1px solid #DFC387;
        border-radius: 12px;
        padding: 3px;
        gap: 3px;
    }

    .adm-tab-btn {
        padding: 0.35rem 0.75rem;
        font-size: 0.6875rem;
        font-weight: 700;
        border-radius: 9px;
        color: #6B5738;
        background: transparent;
        border: none;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .adm-tab-btn.active {
        background: linear-gradient(180deg, #F0DB9D 0%, #D4AF37 35%, #B38622 100%);
        color: #281A05;
        font-weight: 900;
        border: 1px solid #FBF0CE;
        box-shadow: 0 2px 8px rgba(184, 134, 11, 0.3);
    }

    /* Table */
    .adm-table-wrap {
        overflow-x: auto;
        border-radius: 14px;
        border: 1px solid rgba(223, 195, 135, 0.6);
        margin-top: 1rem;
    }

    .adm-table {
        width: 100%;
        text-align: left;
        font-size: 0.75rem;
        border-collapse: collapse;
    }

    .adm-table th {
        background: linear-gradient(90deg, #FBF6EB 0%, #EEDBB0 100%);
        color: #5C410F;
        text-transform: uppercase;
        font-size: 0.625rem;
        font-weight: 800;
        letter-spacing: 0.05em;
        padding: 0.75rem 1rem;
        border-bottom: 1.5px solid #DFC387;
    }

    .adm-table td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid rgba(223, 195, 135, 0.35);
        color: #241A0B;
        vertical-align: middle;
        background: rgba(255, 255, 255, 0.85);
    }

    .adm-table tr:hover td {
        background: rgba(253, 248, 235, 0.95);
    }

    /* Strict SVG Constraints */
    .adm-svg-icon {
        width: 15px !important;
        height: 15px !important;
        max-width: 15px !important;
        max-height: 15px !important;
        flex-shrink: 0 !important;
        display: inline-block !important;
    }

    .adm-search-input {
        background: #FAF5E8;
        border: 1.5px solid #DFC387;
        border-radius: 10px;
        padding: 0.45rem 0.85rem 0.45rem 2.2rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: #1C150B;
        outline: none;
        width: 100%;
        max-width: 280px;
    }

    .adm-btn-sec {
        background: rgba(255, 255, 255, 0.95);
        border: 1.5px solid #DFC387;
        border-radius: 10px;
        padding: 0.45rem 0.85rem;
        font-size: 0.75rem;
        font-weight: 700;
        color: #5C410F;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .adm-btn-sec:hover {
        background: #FAF2DE;
    }
</style>
