{{-- Desain bersama halaman admin yang sudah dimigrasi ke brand Club 61 (Terakota #662721 dominan, Cream #F7F0DB).
     Halaman lama masih memakai kelas .adm-* (custom-styles) sampai giliran migrasinya. --}}
<style>
    .c61 {
        --c-terra: #662721; --c-terra-dark: #511D18; --c-brown: #4F2F2A; --c-cream: #F7F0DB; --c-paper: #FCF8EE;
        --c-line: #E6DAC0; --c-line-soft: #EFE6D2; --c-muted: #7A5A52; --c-faint: #A08F86;
        display: flex; flex-direction: column; gap: 1.25rem; padding: 1.25rem 0 2.5rem;
        color: var(--c-brown); font-family: var(--font-sans);
    }
    .c61 *, .c61-modal-backdrop * { box-sizing: border-box; }
    .c61-display { font-family: var(--font-serif); }
    .c61-mono { font-family: var(--font-mono); }

    /* ===== Hero: pita terakota pembuka halaman ===== */
    .c61-hero {
        position: relative; overflow: hidden; border-radius: 20px; background: var(--c-terra); color: var(--c-cream);
        padding: 1.5rem 1.75rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem 1.5rem; flex-wrap: wrap;
    }
    .c61-hero::after {
        content: ''; position: absolute; right: -40px; top: 50%; width: 260px; height: 260px; transform: translateY(-50%);
        background: url('{{ asset('images/identity/monogram-cream.png') }}') center / contain no-repeat; opacity: 0.07; pointer-events: none;
    }
    .c61-hero > * { position: relative; z-index: 1; }
    .c61-eyebrow { display: inline-flex; align-items: center; gap: 0.45rem; font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(247, 240, 219, 0.75); }
    .c61-eyebrow .dot { width: 7px; height: 7px; border-radius: 50%; background: #34D399; box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.25); }
    .c61-hero-title { font-family: var(--font-serif); font-size: 1.75rem; font-weight: 600; line-height: 1.15; margin-top: 0.4rem; color: var(--c-cream); }
    .c61-hero-sub { font-size: 0.8125rem; color: rgba(247, 240, 219, 0.78); margin-top: 0.35rem; max-width: 640px; line-height: 1.5; }
    .c61-hero-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .c61-hero-chip { display: inline-flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.9rem; border-radius: 14px; background: rgba(247, 240, 219, 0.1); border: 1px solid rgba(247, 240, 219, 0.25); }
    .c61-hero-chip .k { font-size: 0.625rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(247, 240, 219, 0.7); }
    .c61-hero-chip .v { font-size: 0.8125rem; font-weight: 700; color: var(--c-cream); }

    /* ===== Tombol ===== */
    .c61-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem; height: 40px; padding: 0 1rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 700; white-space: nowrap; cursor: pointer; text-decoration: none; border: 1px solid transparent; transition: background 0.15s, border-color 0.15s, color 0.15s, transform 0.1s; }
    .c61-btn:active { transform: scale(0.98); }
    .c61-btn:disabled, .c61-btn[disabled] { opacity: 0.45; cursor: not-allowed; transform: none; }
    .c61-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
    .c61-btn-primary { background: var(--c-terra); border-color: var(--c-terra); color: var(--c-cream); }
    .c61-btn-primary:hover { background: var(--c-terra-dark); }
    .c61-btn-ghost { background: #FFFFFF; border-color: var(--c-line); color: var(--c-brown); }
    .c61-btn-ghost:hover { border-color: var(--c-terra); color: var(--c-terra); }
    .c61-btn-cream { background: var(--c-cream); border-color: var(--c-cream); color: var(--c-terra); }
    .c61-btn-cream:hover { background: #FFFFFF; }
    .c61-btn-outline-cream { background: transparent; border-color: rgba(247, 240, 219, 0.4); color: var(--c-cream); }
    .c61-btn-outline-cream:hover { background: rgba(247, 240, 219, 0.1); }
    .c61-btn-success { background: #ECFDF5; border-color: #6EE7B7; color: #065F46; }
    .c61-btn-success:hover { background: #D1FAE5; }
    .c61-btn-danger { background: #B42318; border-color: #B42318; color: #FFFFFF; }
    .c61-btn-danger:hover { background: #912018; }
    .c61-btn-sm { height: 32px; padding: 0 0.75rem; font-size: 0.75rem; border-radius: 8px; }
    .c61-btn-lg { height: 46px; padding: 0 1.25rem; font-size: 0.875rem; }
    .c61-link { color: var(--c-terra); font-weight: 700; text-decoration: underline; text-underline-offset: 2px; }

    /* ===== Kartu KPI ===== */
    .c61-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }
    @media (max-width: 1100px) { .c61-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .c61-kpis { grid-template-columns: 1fr; } }
    .c61-kpi { background: #FFFFFF; border: 1px solid var(--c-line); border-radius: 16px; padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; transition: border-color 0.15s, box-shadow 0.15s; }
    .c61-kpi:hover { border-color: #D8C6A4; box-shadow: 0 10px 24px -14px rgba(79, 47, 42, 0.35); }
    .c61-kpi-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; }
    .c61-kpi-label { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--c-muted); }
    .c61-kpi-icon { width: 36px; height: 36px; border-radius: 10px; background: var(--c-cream); color: var(--c-terra); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .c61-kpi-icon svg { width: 18px; height: 18px; }
    .c61-kpi-icon.is-ok { background: #ECFDF5; color: #047857; }
    .c61-kpi-value { font-family: var(--font-serif); font-size: 1.75rem; font-weight: 600; line-height: 1.1; color: var(--c-brown); font-variant-numeric: tabular-nums; }
    .c61-kpi-value small { font-family: var(--font-sans); font-size: 0.8125rem; font-weight: 600; color: var(--c-muted); }
    .c61-kpi-value.is-terra { color: var(--c-terra); }
    .c61-kpi-value.is-ok { color: #047857; }
    .c61-kpi-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; font-size: 0.6875rem; color: var(--c-muted); margin-top: auto; padding-top: 0.6rem; border-top: 1px dashed var(--c-line); }
    .c61-kpi-foot .ok { color: #047857; font-weight: 700; }
    .c61-bar { height: 6px; border-radius: 999px; background: var(--c-line-soft); overflow: hidden; }
    .c61-bar > i { display: block; height: 100%; border-radius: 999px; background: var(--c-terra); }

    /* ===== Kartu ===== */
    .c61-card { background: #FFFFFF; border: 1px solid var(--c-line); border-radius: 18px; overflow: hidden; }
    .c61-card-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem 1rem; flex-wrap: wrap; padding: 1rem 1.25rem; border-bottom: 1px solid var(--c-line-soft); }
    .c61-card-title { font-family: var(--font-serif); font-size: 1.1875rem; font-weight: 600; color: var(--c-brown); line-height: 1.25; }
    .c61-card-sub { font-size: 0.75rem; color: var(--c-muted); margin-top: 0.2rem; }
    .c61-card-body { padding: 1.25rem; }
    .c61-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; padding: 0.85rem 1.25rem; border-top: 1px solid var(--c-line-soft); background: var(--c-paper); }
    .c61-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; background: #FFFFFF; border: 1px solid var(--c-line); border-radius: 16px; padding: 0.65rem 0.85rem; }
    .c61-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }

    /* ===== Segmented tab ===== */
    .c61-seg { display: inline-flex; align-items: center; gap: 4px; padding: 4px; background: #FFFFFF; border: 1px solid var(--c-line); border-radius: 12px; flex-wrap: wrap; }
    .c61-seg-btn { display: inline-flex; align-items: center; gap: 0.45rem; height: 34px; padding: 0 0.85rem; border-radius: 9px; border: none; background: transparent; color: var(--c-muted); font-size: 0.8125rem; font-weight: 700; cursor: pointer; white-space: nowrap; transition: background 0.15s, color 0.15s; }
    .c61-seg-btn:hover { color: var(--c-terra); background: var(--c-paper); }
    .c61-seg-btn.is-active { background: var(--c-terra); color: var(--c-cream); }
    .c61-seg-btn .count { min-width: 22px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--c-cream); color: var(--c-terra); font-size: 0.6875rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .c61-seg-btn.is-active .count { background: rgba(247, 240, 219, 0.2); color: var(--c-cream); }

    /* ===== Input ===== */
    .c61-input, .c61-select, .c61-textarea { width: 100%; height: 40px; border: 1px solid var(--c-line); border-radius: 10px; background: var(--c-paper); padding: 0 0.85rem; font-size: 0.8125rem; font-weight: 600; color: var(--c-brown); outline: none; transition: border-color 0.15s, box-shadow 0.15s; }
    .c61-textarea { height: auto; padding: 0.6rem 0.85rem; line-height: 1.45; resize: vertical; }
    .c61-select { padding-right: 2rem; cursor: pointer; }
    .c61-input:focus, .c61-select:focus, .c61-textarea:focus { border-color: var(--c-terra); box-shadow: 0 0 0 3px rgba(102, 39, 33, 0.12); background: #FFFFFF; }
    .c61-input::placeholder, .c61-textarea::placeholder { color: var(--c-faint); font-weight: 500; }
    .c61-search { position: relative; width: 100%; max-width: 320px; }
    .c61-search > svg { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--c-faint); pointer-events: none; }
    .c61-search .c61-input { padding-left: 2.25rem; padding-right: 2rem; }
    .c61-search-clear { position: absolute; right: 0.45rem; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; border-radius: 6px; border: none; background: transparent; color: var(--c-faint); font-size: 1rem; line-height: 1; cursor: pointer; }
    .c61-search-clear:hover { background: var(--c-cream); color: var(--c-terra); }
    .c61-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--c-brown); margin-bottom: 0.35rem; }
    .c61-label small { font-weight: 600; color: #B42318; }
    .c61-field { margin-bottom: 1rem; }
    .c61-hint { font-size: 0.6875rem; color: var(--c-muted); margin-top: 0.35rem; line-height: 1.45; }

    /* ===== Tabel ===== */
    .c61-table-wrap { overflow-x: auto; }
    .c61-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; text-align: left; }
    .c61-table th { background: var(--c-paper); color: var(--c-muted); font-size: 0.625rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; padding: 0.7rem 1rem; border-bottom: 1px solid var(--c-line); white-space: nowrap; }
    .c61-table td { padding: 0.8rem 1rem; border-bottom: 1px solid var(--c-line-soft); vertical-align: middle; color: var(--c-brown); }
    .c61-table tbody tr:last-child td { border-bottom: none; }
    .c61-table tbody tr:hover td { background: #FDFAF2; }
    .c61-table .strong { font-weight: 700; color: var(--c-brown); }
    .c61-table .sub { font-size: 0.6875rem; color: var(--c-muted); margin-top: 0.15rem; }
    .c61-table .code { font-family: var(--font-mono); font-weight: 700; color: var(--c-terra); }
    .c61-table .num { text-align: right; font-family: var(--font-mono); font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .c61-empty { padding: 2.5rem 1rem; text-align: center; color: var(--c-muted); font-size: 0.8125rem; }
    .c61-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* ===== Pill status ===== */
    .c61-pill { display: inline-flex; align-items: center; gap: 0.35rem; height: 24px; padding: 0 0.6rem; border-radius: 999px; font-size: 0.6875rem; font-weight: 700; white-space: nowrap; border: 1px solid transparent; }
    .c61-pill-terra { background: #F6EAE7; color: var(--c-terra); border-color: #E8CFC9; }
    .c61-pill-cream { background: var(--c-cream); color: var(--c-brown); border-color: var(--c-line); }
    .c61-pill-ok { background: #ECFDF5; color: #047857; border-color: #A7F3D0; }
    .c61-pill-warn { background: #FEF3C7; color: #92400E; border-color: #FDE68A; }
    .c61-pill-orange { background: #FFF7ED; color: #9A3412; border-color: #FED7AA; }
    .c61-pill-danger { background: #FEE2E2; color: #B42318; border-color: #FECACA; }
    .c61-pill-gray { background: #F3F4F6; color: #374151; border-color: #E5E7EB; }
    .c61-pill-info { background: #EFF6FF; color: #1D4ED8; border-color: #BFDBFE; }

    /* ===== Tombol ikon aksi tabel ===== */
    .c61-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; min-width: 34px; border-radius: 9px; border: 1px solid var(--c-line); background: #FFFFFF; color: var(--c-brown); cursor: pointer; padding: 0; text-decoration: none; transition: border-color 0.15s, background 0.15s, transform 0.1s; }
    .c61-icon-btn:hover { border-color: var(--c-terra); color: var(--c-terra); }
    .c61-icon-btn:active { transform: scale(0.95); }
    .c61-icon-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .c61-icon-btn svg { width: 16px; height: 16px; }
    .c61-icon-btn.is-ok { background: #ECFDF5; border-color: #A7F3D0; color: #047857; }
    .c61-icon-btn.is-warn { background: #FEF3C7; border-color: #FDE68A; color: #92400E; }
    .c61-icon-btn.is-info { background: #EFF6FF; border-color: #BFDBFE; color: #1D4ED8; }
    .c61-icon-btn.is-danger { background: #FEF2F2; border-color: #FECACA; color: #B42318; }
    .c61-icon-btn.is-solid { background: var(--c-terra); border-color: var(--c-terra); color: var(--c-cream); }
    .c61-icon-btn.is-solid:hover { background: var(--c-terra-dark); color: var(--c-cream); }
    .c61-icon-btn.is-muted { background: #F5F2EC; border-color: #E7E0D3; color: #9C928A; cursor: help; }
    .c61-actions { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; flex-wrap: nowrap; }
    @keyframes c61-spin { to { transform: rotate(360deg); } }
    .c61-spin { animation: c61-spin 1s linear infinite; }

    /* ===== Pagination ===== */
    .c61-pages { display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
    .c61-page { min-width: 32px; height: 32px; padding: 0 0.5rem; border-radius: 8px; border: 1px solid var(--c-line); background: #FFFFFF; color: var(--c-brown); font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
    .c61-page:hover:not(:disabled) { border-color: var(--c-terra); color: var(--c-terra); }
    .c61-page.is-active { background: var(--c-terra); border-color: var(--c-terra); color: var(--c-cream); }
    .c61-page:disabled { opacity: 0.4; cursor: not-allowed; }

    /* ===== Kotak info ===== */
    .c61-note { border-radius: 12px; padding: 0.85rem 1rem; background: var(--c-paper); border: 1px solid var(--c-line); font-size: 0.75rem; line-height: 1.5; color: var(--c-brown); }
    .c61-note-ok { background: #F0FDF4; border-color: #A7F3D0; color: #065F46; }
    .c61-note-warn { background: #FFFBEB; border-color: #FDE68A; color: #92400E; }
    .c61-note-danger { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }
    .c61-note-orange { background: #FFF7ED; border-color: #FED7AA; color: #9A3412; }

    /* ===== Modal ===== */
    .c61-modal-backdrop { --c-terra: #662721; --c-terra-dark: #511D18; --c-brown: #4F2F2A; --c-cream: #F7F0DB; --c-paper: #FCF8EE; --c-line: #E6DAC0; --c-line-soft: #EFE6D2; --c-muted: #7A5A52; --c-faint: #A08F86;
        position: fixed; inset: 0; z-index: 9999; background: rgba(42, 20, 16, 0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 1rem; font-family: var(--font-sans); color: var(--c-brown); }
    .c61-modal { background: #FFFFFF; border-radius: 20px; width: 100%; max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 30px 60px -20px rgba(42, 20, 16, 0.5); animation: c61-pop 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
    @keyframes c61-pop { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .c61-modal-head { background: var(--c-terra); color: var(--c-cream); padding: 1.1rem 1.4rem; display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-shrink: 0; }
    .c61-modal-eyebrow { font-size: 0.625rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(247, 240, 219, 0.72); }
    .c61-modal-title { font-family: var(--font-serif); font-size: 1.3125rem; font-weight: 600; line-height: 1.2; margin-top: 0.25rem; color: var(--c-cream); }
    .c61-modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: rgba(247, 240, 219, 0.12); color: var(--c-cream); font-size: 1.25rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
    .c61-modal-close:hover { background: rgba(247, 240, 219, 0.22); }
    .c61-modal-body { padding: 1.4rem; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 1rem; font-size: 0.8125rem; }
    .c61-modal-foot { padding: 0.9rem 1.4rem; border-top: 1px solid var(--c-line-soft); background: var(--c-paper); display: flex; justify-content: flex-end; gap: 0.5rem; flex-wrap: wrap; flex-shrink: 0; }
    .c61-modal.is-danger .c61-modal-head { background: var(--c-brown); }
    .c61-modal.is-danger .c61-modal-eyebrow { color: #FCA5A5; }
    .c61-kv { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; background: var(--c-paper); border: 1px solid var(--c-line); border-radius: 14px; padding: 1rem; }
    .c61-kv .k { font-size: 0.625rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--c-muted); }
    .c61-kv .v { font-weight: 700; color: var(--c-brown); margin-top: 0.15rem; }
    .c61-kv .s { font-size: 0.6875rem; color: var(--c-muted); margin-top: 0.1rem; }
</style>
