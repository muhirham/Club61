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

    /* ===== Halaman Filament bawaan (resource & halaman tabel) — semua sudah dimigrasi ke brand ===== */
    .fi-page .fi-header {
        position: relative;
        overflow: hidden;
        background: var(--c61-terra);
        border-radius: 20px;
        padding: 1.5rem 1.75rem;
        color: var(--c61-cream);
    }

    .fi-page .fi-header::after {
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

    .fi-page .fi-header > * {
        position: relative;
        z-index: 1;
    }

    .fi-page .fi-header-heading {
        font-family: var(--font-serif) !important;
        font-weight: 600 !important;
        font-size: 1.75rem !important;
        letter-spacing: 0 !important;
        color: var(--c61-cream) !important;
    }

    .fi-page .fi-header-subheading,
    .fi-page .fi-breadcrumbs,
    .fi-page .fi-breadcrumbs * {
        color: rgba(247, 240, 219, 0.75) !important;
    }

    .fi-page .fi-header .fi-btn {
        background: var(--c61-cream) !important;
        color: var(--c61-terra) !important;
        box-shadow: none !important;
    }

    .fi-page .fi-header .fi-btn:hover {
        background: #FFFFFF !important;
    }

    .fi-page .fi-header .fi-btn .fi-icon {
        color: var(--c61-terra) !important;
    }

    /* Tombol header selain warna utama (mis. aksi khusus super admin) = garis cream, supaya satu tombol utama menonjol. */
    .fi-page .fi-header .fi-btn:not(.fi-color-primary) {
        background: transparent !important;
        color: var(--c61-cream) !important;
        box-shadow: inset 0 0 0 1px rgba(247, 240, 219, 0.45) !important;
    }

    .fi-page .fi-header .fi-btn:not(.fi-color-primary):hover {
        background: rgba(247, 240, 219, 0.1) !important;
    }

    .fi-page .fi-header .fi-btn:not(.fi-color-primary) .fi-icon {
        color: var(--c61-cream) !important;
    }

    /* Tabel & kartu form: border krem tipis, header kolom kecil kapital (sama dengan tabel .c61-table). */
    .fi-page .fi-ta-ctn,
    .fi-page .fi-section {
        border: 1px solid var(--c61-line) !important;
        border-radius: 18px !important;
        box-shadow: none !important;
        overflow: hidden;
    }

    .fi-page .fi-ta-header-cell,
    .fi-page .fi-ta-selection-cell:is(th) {
        background: var(--c61-paper) !important;
    }

    .fi-page .fi-ta-header-cell,
    .fi-page .fi-ta-header-cell .fi-ta-header-cell-sort-btn {
        font-size: 0.625rem !important;
        font-weight: 800 !important;
        letter-spacing: 0.08em !important;
        text-transform: uppercase !important;
        color: var(--c61-muted) !important;
    }

    .fi-page .fi-ta-row:hover {
        background: #FDFAF2 !important;
    }

    .fi-page .fi-section-header-heading {
        font-family: var(--font-serif) !important;
        font-size: 1.1875rem !important;
        font-weight: 600 !important;
        color: var(--c61-brown) !important;
    }

    /* Hide default filament page header so custom banner takes its place seamlessly */
    .fi-page-header {
        display: none !important;
    }

    /* Kelas lama .adm-* (tema emas) sudah dihapus — semua halaman memakai filament/partials/c61-admin-style.blade.php. */
</style>
