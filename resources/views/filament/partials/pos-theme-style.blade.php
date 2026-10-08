{{-- Gaya bersama layar POS frontdesk (Walk-In Booking & Jual Membership). Brand Club 61: Terakota #662721 dominan, Cream #F7F0DB. --}}
<style>
    /* Halaman POS memakai latar cream polos (bukan marmer tema panel) supaya grid & panel terbaca jelas. */
    .fi-layout, .fi-main, .fi-body { background-color: #F7F0DB !important; background-image: none !important; }

    .walkin-pos-root {
        --pos-terra: #662721; --pos-terra-dark: #511D18; --pos-brown: #4F2F2A; --pos-cream: #F7F0DB;
        --pos-paper: #FCF8EE; --pos-line: #E6DAC0; --pos-line-soft: #EFE6D2; --pos-muted: #7A5A52; --pos-faint: #A08F86;
        display: flex; flex-direction: column; gap: 0.75rem;
        height: calc(100vh - 7.5rem); min-height: 620px; overflow: hidden;
        color: var(--pos-brown);
    }
    .walkin-pos-root button { -webkit-tap-highlight-color: transparent; }

    /* ===== BARIS ATAS: tab POS + Kasir/Riwayat + status shift ===== */
    .pos-headbar { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; flex-shrink: 0; }
    .pos-headbar-left { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; min-width: 0; }
    .pos-headbar-right { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .pos-shift-chip { display: inline-flex; align-items: center; gap: 0.45rem; height: 38px; padding: 0 0.8rem; border-radius: 999px; font-size: 0.75rem; font-weight: 800; white-space: nowrap; }
    .pos-shift-chip .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .pos-shift-chip.is-open { background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; }
    .pos-shift-chip.is-open .dot { background: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2); }
    .pos-shift-chip.is-closed { background: #FFF1F2; border: 1px solid #FECDD3; color: #9F1239; }
    .pos-shift-chip.is-closed .dot { background: #EF4444; }
    .pos-shift-chip small { font-size: 0.6875rem; font-weight: 600; opacity: 0.85; }

    .pos-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; height: 38px; padding: 0 0.95rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 800; cursor: pointer; white-space: nowrap; transition: background 0.15s, border-color 0.15s, transform 0.1s; }
    .pos-btn:active { transform: scale(0.97); }
    .pos-btn-primary { background: var(--pos-terra); color: var(--pos-cream); border: 1px solid var(--pos-terra); }
    .pos-btn-primary:hover { background: var(--pos-terra-dark); }
    .pos-btn-ghost { background: #FFFFFF; color: var(--pos-brown); border: 1px solid var(--pos-line); }
    .pos-btn-ghost:hover { border-color: var(--pos-terra); color: var(--pos-terra); }
    .pos-btn-danger { background: #FFFFFF; color: #BE123C; border: 1px solid #FECDD3; }
    .pos-btn-danger:hover { background: #FFF1F2; }

    /* ===== TOOLBAR: tanggal + ringkasan hari ini ===== */
    .pos-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; flex-shrink: 0; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 14px; padding: 0.6rem 0.75rem; }
    .pos-datenav { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    .pos-icon-btn { width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--pos-line); background: #FFFFFF; color: var(--pos-brown); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .pos-icon-btn:hover { border-color: var(--pos-terra); color: var(--pos-terra); }
    .pos-today-btn { height: 38px; padding: 0 0.9rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 800; cursor: pointer; border: 1px solid var(--pos-line); background: #FFFFFF; color: var(--pos-brown); }
    .pos-today-btn.is-active { background: var(--pos-terra); border-color: var(--pos-terra); color: var(--pos-cream); }
    .pos-date-input { height: 38px; border: 1px solid var(--pos-line); border-radius: 10px; padding: 0 0.6rem; font-size: 0.8125rem; font-weight: 700; color: var(--pos-brown); background: var(--pos-paper); outline: none; }
    .pos-date-input:focus { border-color: var(--pos-terra); box-shadow: 0 0 0 3px rgba(102,39,33,0.12); }
    .pos-date-label { font-size: 0.875rem; font-weight: 800; color: var(--pos-brown); margin-left: 0.35rem; white-space: nowrap; }
    .pos-kpis { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    .pos-kpi { display: flex; flex-direction: column; justify-content: center; min-width: 0; height: 38px; padding: 0 0.65rem; border-radius: 10px; background: var(--pos-cream); }
    .pos-kpi-value { font-size: 0.9375rem; font-weight: 900; color: var(--pos-terra); line-height: 1.1; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pos-kpi-label { font-size: 0.625rem; font-weight: 700; color: var(--pos-muted); text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; }

    /* ===== DUA KOLOM: Jadwal (kiri) + Checkout (kanan) ===== */
    .pos-main { display: grid; grid-template-columns: minmax(0, 1fr) clamp(320px, 31%, 390px); gap: 0.75rem; flex: 1; min-height: 0; }

    .pos-grid-card, .pos-panel-card, .pos-terminal-card, .pos-receipt-inpage { background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 14px; display: flex; flex-direction: column; overflow: hidden; min-height: 0; }
    .pos-grid-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem 1rem; flex-wrap: wrap; padding: 0.7rem 1rem; border-bottom: 1px solid var(--pos-line); flex-shrink: 0; }
    .pos-grid-title { font-size: 0.9375rem; font-weight: 900; color: var(--pos-brown); }
    .pos-grid-sub { font-size: 0.6875rem; color: var(--pos-muted); margin-top: 0.1rem; }
    .pos-legend { display: flex; align-items: center; gap: 0.35rem 0.8rem; flex-wrap: wrap; }
    .legend-dot { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.6875rem; font-weight: 700; color: var(--pos-muted); }
    .legend-dot i { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }

    .pos-grid-scroll { flex: 1; min-height: 0; overflow: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
    .pos-slotgrid { display: grid; min-width: 100%; }
    .pos-slotgrid > .hd { position: sticky; top: 0; z-index: 6; background: var(--pos-cream); border-bottom: 1px solid var(--pos-line); padding: 0.6rem 0.4rem; text-align: center; }
    .pos-slotgrid > .hd + .hd { border-left: 1px solid var(--pos-line); }
    .pos-slotgrid > .hd.corner { left: 0; z-index: 8; }
    .pos-court-name { font-size: 0.875rem; font-weight: 900; color: var(--pos-brown); line-height: 1.2; }
    .pos-court-meta { font-size: 0.6875rem; font-weight: 700; color: var(--pos-muted); margin-top: 0.1rem; }
    .pos-slotgrid > .grp { grid-column: 1 / -1; padding: 0.7rem 0.9rem 0.35rem; font-size: 0.6875rem; font-weight: 900; letter-spacing: 0.08em; text-transform: uppercase; color: var(--pos-terra); border-bottom: 1px solid var(--pos-line-soft); background: #FFFFFF; }
    .pos-slotgrid > .tm { position: sticky; left: 0; z-index: 4; background: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 0.875rem; font-weight: 800; color: var(--pos-muted); font-variant-numeric: tabular-nums; border-bottom: 1px solid var(--pos-line-soft); border-right: 1px solid var(--pos-line); }
    .pos-slotgrid > .cell { padding: 0.3rem; border-bottom: 1px solid var(--pos-line-soft); }
    .pos-slotgrid > .cell + .cell { border-left: 1px solid var(--pos-line-soft); }

    /* ===== Kotak slot ===== */
    .slot-btn { position: relative; width: 100%; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 0.4rem; padding: 0 0.75rem; font-size: 0.875rem; font-weight: 800; text-align: left; user-select: none; transition: background 0.12s, border-color 0.12s, transform 0.08s; overflow: hidden; }
    button.slot-btn { cursor: pointer; }
    button.slot-btn:active { transform: scale(0.97); }
    .slot-btn .lbl { font-size: 0.6875rem; font-weight: 700; opacity: 0.85; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .slot-btn .prc { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .slot-btn .prime { position: absolute; top: 6px; right: 6px; width: 6px; height: 6px; border-radius: 50%; background: var(--pos-terra); }
    .slot-available { background: #FFFFFF; border: 1px solid var(--pos-line); color: var(--pos-brown); }
    .slot-available:hover { border-color: var(--pos-terra); background: var(--pos-paper); }
    .slot-selected { background: var(--pos-terra); border: 1px solid var(--pos-terra); color: var(--pos-cream); box-shadow: 0 4px 12px rgba(102,39,33,0.28); }
    .slot-selected .prime { background: var(--pos-cream); }
    .slot-booked { background: #EEE5D3; border: 1px solid #EEE5D3; color: var(--pos-brown); cursor: not-allowed; }
    .slot-locked { background: #FFFBEB; border: 1px dashed #D97706; color: #92400E; cursor: not-allowed; }
    .slot-delta { background: #FEF3C7; border: 1.5px solid #D97706; color: #92400E; }
    .slot-delta.is-active { background: #D97706; color: #FFFFFF; }
    .slot-past { background: #FAF8F3; border: 1px solid #F3EEE2; color: #C9BDB3; cursor: not-allowed; justify-content: center; }

    /* ===== Transaksi walk-in terakhir (di bawah jadwal, ikut scroll) ===== */
    .pos-grid-footer { padding: 0.9rem 1rem 1rem; border-top: 1px solid var(--pos-line); }
    .pos-recent-header { font-size: 0.6875rem; font-weight: 900; color: var(--pos-terra); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.5rem; }
    .pos-recent-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.45rem; }
    .pos-recent-row { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; background: var(--pos-paper); border: 1px solid var(--pos-line-soft); border-radius: 10px; padding: 0.55rem 0.75rem; }
    .pos-recent-name { font-size: 0.8125rem; font-weight: 800; color: var(--pos-brown); }
    .pos-recent-sub { font-size: 0.6875rem; color: var(--pos-muted); margin-top: 0.1rem; }
    .pos-recent-amount { font-size: 0.8125rem; font-weight: 900; color: var(--pos-terra); font-variant-numeric: tabular-nums; }
    .pos-recent-badge { display: inline-block; margin-top: 0.15rem; font-size: 0.5625rem; font-weight: 800; padding: 0.05rem 0.4rem; border-radius: 4px; text-transform: uppercase; }
    .pos-badge-paid { background: #D1FAE5; color: #047857; }
    .pos-badge-partially_paid { background: #FEF3C7; color: #92400E; }
    .pos-badge-unpaid { background: #F3F4F6; color: #6B7280; }
    .pos-badge-cancelled { background: #FEE2E2; color: #B91C1C; }
    .pos-badge-refunded { background: #E0E7FF; color: #3730A3; }
    .pos-recent-empty { color: var(--pos-faint); font-size: 0.75rem; padding: 0.5rem 0; }

    /* ===== Panel checkout (kanan) ===== */
    .pos-panel-header { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; padding: 0.85rem 1rem; background: var(--pos-terra); color: var(--pos-cream); flex-shrink: 0; }
    .pos-panel-eyebrow { font-size: 0.625rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; opacity: 0.75; }
    .pos-panel-title { font-size: 1rem; font-weight: 900; margin-top: 0.1rem; }
    .pos-panel-total { font-size: 1.25rem; font-weight: 900; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pos-panel-count { font-size: 0.6875rem; font-weight: 700; text-align: right; opacity: 0.75; }
    .pos-panel-body { flex: 1; overflow-y: auto; padding: 0.9rem 1rem; min-height: 0; display: flex; flex-direction: column; gap: 0.9rem; overscroll-behavior: contain; }
    .pos-panel-footer { padding: 0.75rem 1rem; background: var(--pos-paper); border-top: 1px solid var(--pos-line); flex-shrink: 0; }

    .pos-section-label { font-size: 0.6875rem; font-weight: 900; color: var(--pos-terra); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.4rem; white-space: nowrap; }
    .pos-past-note { grid-column: 1 / -1; display: flex; align-items: center; gap: 0.4rem; padding: 0.55rem 0.9rem; font-size: 0.75rem; color: var(--pos-muted); background: var(--pos-paper); border-bottom: 1px solid var(--pos-line-soft); }
    /* Teks panjang (nomor shift lengkap, keterangan) hanya di layar lebar; tablet memakai versi ringkas. */
    .pos-only-narrow { display: inline; }
    .pos-only-wide { display: none; }
    @media (min-width: 1680px) { .pos-only-narrow { display: none; } .pos-only-wide { display: inline; } }
    @media (max-width: 1679px) { .pos-hide-md { display: none !important; } }
    @media (max-width: 1279px) { .pos-headbar-left a, .pos-headbar-left button { padding-left: 0.75rem !important; padding-right: 0.75rem !important; } }
    .pos-customer-box { background: var(--pos-paper); border: 1px solid var(--pos-line); border-radius: 12px; padding: 0.75rem; }
    .pos-input { width: 100%; height: 40px; border: 1px solid var(--pos-line); border-radius: 9px; padding: 0 0.7rem; font-size: 0.875rem; font-weight: 600; color: var(--pos-brown); background: #FFFFFF; outline: none; box-sizing: border-box; }
    .pos-input::placeholder { color: var(--pos-faint); font-weight: 500; }
    .pos-input:focus { border-color: var(--pos-terra); box-shadow: 0 0 0 3px rgba(102,39,33,0.12); }
    .pos-input:disabled { background: var(--pos-paper); color: var(--pos-muted); }
    .pos-tab-group { display: flex; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 9px; padding: 3px; gap: 3px; }
    .pos-tab { flex: 1; height: 30px; padding: 0 0.6rem; font-size: 0.75rem; font-weight: 800; border: none; border-radius: 7px; cursor: pointer; background: transparent; color: var(--pos-muted); white-space: nowrap; }
    .pos-tab.active { background: var(--pos-terra); color: var(--pos-cream); }
    .pos-slot-chip { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; background: var(--pos-paper); border: 1px solid var(--pos-line); border-radius: 9px; padding: 0.45rem 0.6rem; font-size: 0.75rem; font-weight: 700; color: var(--pos-brown); }
    .pos-eq-row { display: flex; justify-content: space-between; align-items: center; padding: 0.45rem 0; border-bottom: 1px solid var(--pos-line-soft); font-size: 0.75rem; }
    .pos-eq-row:last-child { border-bottom: none; }
    .pos-qty-btn { width: 32px; height: 32px; border-radius: 8px; background: #FFFFFF; border: 1px solid var(--pos-line); color: var(--pos-terra); font-weight: 900; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .pos-qty-btn:hover { border-color: var(--pos-terra); }
    .pos-total-box { background: var(--pos-cream); border-radius: 12px; padding: 0.75rem 0.85rem; }
    .pos-submit-btn { width: 100%; height: 50px; border-radius: 12px; background: var(--pos-terra); color: var(--pos-cream); border: 1px solid var(--pos-terra); font-weight: 900; font-size: 0.9375rem; cursor: pointer; text-transform: uppercase; letter-spacing: 0.06em; transition: background 0.15s, transform 0.1s; }
    .pos-submit-btn:hover { background: var(--pos-terra-dark); }
    .pos-submit-btn:active { transform: scale(0.99); }
    .pos-submit-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

    /* ===== Banner draf ===== */
    .pos-draft-banner { background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 12px; padding: 0.5rem 0.6rem 0.5rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-shrink: 0; animation: fadeInDown 0.25s ease; }
    .pos-draft-banner > div:first-child { flex: 1; min-width: 0; }
    .pos-draft-banner > div:last-child { flex-shrink: 0; }
    @keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .pos-draft-title { font-size: 0.8125rem; font-weight: 900; color: #92400E; }
    .pos-draft-desc { font-size: 0.75rem; color: #B45309; margin-top: 0.1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pos-draft-resume-btn { height: 38px; padding: 0 1rem; font-size: 0.8125rem; font-weight: 900; background: var(--pos-terra); border: 1px solid var(--pos-terra); color: var(--pos-cream); border-radius: 10px; cursor: pointer; white-space: nowrap; }
    .pos-draft-resume-btn:hover { background: var(--pos-terra-dark); }
    .pos-draft-discard-btn { height: 38px; padding: 0 0.9rem; font-size: 0.8125rem; font-weight: 800; background: #FFFFFF; border: 1px solid #FECACA; color: #DC2626; border-radius: 10px; cursor: pointer; white-space: nowrap; }
    .pos-draft-discard-btn:hover { background: #FEF2F2; }

    /* ===== Layar bayar & struk (kiri, in-page) ===== */
    .pos-terminal-header { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; padding: 0.8rem 1.2rem; border-bottom: 1px solid var(--pos-line); flex-shrink: 0; }
    .pos-terminal-eyebrow { display: inline-block; font-size: 0.625rem; font-weight: 900; letter-spacing: 0.08em; text-transform: uppercase; color: var(--pos-terra); background: var(--pos-cream); border-radius: 6px; padding: 0.15rem 0.5rem; }
    .pos-terminal-eyebrow.is-done { color: #065F46; background: #ECFDF5; }
    .pos-terminal-title { font-size: 1.0625rem; font-weight: 900; color: var(--pos-brown); margin-top: 0.25rem; }
    .pos-terminal-body { flex: 1; overflow-y: auto; padding: 1.1rem 1.3rem; display: flex; flex-direction: column; gap: 1rem; }
    .pos-terminal-actions { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-top: auto; position: sticky; bottom: -1.1rem; z-index: 5; background: #FFFFFF; padding: 0.75rem 0 1.1rem; border-top: 1px solid var(--pos-line-soft); }
    .pos-method-selector-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.65rem; }
    .pos-method-tab { border: 1.5px solid var(--pos-line); background: #FFFFFF; border-radius: 12px; padding: 0.75rem 0.5rem; text-align: center; cursor: pointer; transition: border-color 0.15s; user-select: none; }
    .pos-method-tab:hover { border-color: var(--pos-terra); }
    .pos-method-tab.active { border-color: var(--pos-terra); background: var(--pos-paper); box-shadow: 0 4px 12px rgba(102,39,33,0.12); }
    .pos-method-tab-title { font-size: 0.8125rem; font-weight: 900; color: var(--pos-brown); }
    .pos-method-tab.active .pos-method-tab-title { color: var(--pos-terra); }
    .pos-method-tab-sub { font-size: 0.6875rem; color: var(--pos-muted); margin-top: 0.15rem; }
    .pos-pay-content-card { background: var(--pos-paper); border: 1px solid var(--pos-line); border-radius: 12px; padding: 1.1rem 1.25rem; }
    .quick-cash-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-top: 0.5rem; }
    .quick-cash-btn { background: #FFFFFF; border: 1px solid var(--pos-line); color: var(--pos-brown); font-weight: 800; font-size: 0.8125rem; height: 40px; border-radius: 9px; cursor: pointer; text-align: center; }
    .quick-cash-btn:hover { border-color: var(--pos-terra); color: var(--pos-terra); }
    .pos-receipt-stage { flex: 1; overflow-y: auto; padding: 1.25rem 2rem; background: var(--pos-paper); display: flex; justify-content: center; }

    /* ===== Walk-In: timeline (lapangan = baris, jam = kolom) + dock checkout 3 kolom ===== */
    .walkin-pos-root { overflow-y: auto; container-type: inline-size; container-name: pos; }
    /* Ikuti lebar area konten sebenarnya (sidebar buka/ciut), bukan lebar layar. */
    @container pos (max-width: 980px) { .pos-date-label { display: none; } }
    @container pos (max-width: 760px) { .pos-draft-desc { white-space: normal; } }
    .pos-timeline-card { flex: 0 0 auto; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 14px; overflow: hidden; }
    .pos-timeline-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
    .pos-timeline { display: grid; width: 100%; }
    .pos-timeline .tl-th { height: 30px; display: flex; align-items: center; justify-content: center; font-size: 0.6875rem; font-weight: 800; color: var(--pos-muted); background: var(--pos-cream); border-bottom: 1px solid var(--pos-line); border-left: 1px solid var(--pos-line-soft); font-variant-numeric: tabular-nums; }
    .pos-timeline .tl-th.is-prime { background: #EAD7CF; color: var(--pos-terra); }
    .pos-timeline .tl-th.is-now { background: var(--pos-terra); color: var(--pos-cream); }
    .pos-timeline .tl-corner { position: sticky; left: 0; z-index: 3; justify-content: flex-start; padding-left: 0.85rem; border-left: none; border-right: 1px solid var(--pos-line); text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.625rem; }
    .pos-timeline .tl-court { position: sticky; left: 0; z-index: 2; background: #FFFFFF; padding: 0.45rem 0.6rem 0.45rem 0.85rem; border-right: 1px solid var(--pos-line); border-bottom: 1px solid var(--pos-line-soft); display: flex; flex-direction: column; justify-content: center; min-width: 0; }
    .pos-timeline .tl-court .pos-court-name { font-size: 0.8125rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pos-timeline .tl-court .pos-court-meta { font-size: 0.625rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pos-court-free { font-size: 0.625rem; font-weight: 800; color: #047857; margin-top: 0.1rem; }
    .pos-timeline .tl-cell { height: 54px; padding: 4px 3px; border-left: 1px solid var(--pos-line-soft); border-bottom: 1px solid var(--pos-line-soft); min-width: 0; }
    .pos-timeline .tl-cell.is-now { background: rgba(102,39,33,0.04); }
    .pos-timeline .slot-btn { height: 100%; padding: 0 0.3rem; flex-direction: column; justify-content: center; align-items: center; gap: 0.1rem; font-size: 0.75rem; border-radius: 8px; text-align: center; }
    .pos-timeline .slot-btn .lbl { font-size: 0.6875rem; font-weight: 800; opacity: 1; max-width: 100%; }
    .pos-timeline .slot-btn .sub { font-size: 0.5625rem; font-weight: 700; opacity: 0.75; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pos-timeline .slot-past { background: repeating-linear-gradient(135deg, #FAF8F3 0 6px, #F4EFE5 6px 7px); border-color: #F3EEE2; }

    .pos-link-danger { background: none; border: none; padding: 0.25rem 0; font-size: 0.6875rem; font-weight: 800; color: #BE123C; cursor: pointer; white-space: nowrap; }
    .pos-link-danger:hover { text-decoration: underline; }

    .pos-recent-pop { position: relative; }
    .pos-count-badge { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--pos-terra); color: var(--pos-cream); font-size: 0.6875rem; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; }
    .pos-recent-panel { position: absolute; right: 0; top: calc(100% + 6px); z-index: 40; width: min(420px, 90vw); max-height: 60vh; overflow-y: auto; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 14px; box-shadow: 0 18px 40px rgba(79,47,42,0.2); padding: 0.85rem; }
    .pos-recent-panel .pos-recent-list { grid-template-columns: 1fr; }

    /* ===== Alur kasir bertahap: stepper → panggung geser (Jadwal | Customer | Tambahan) → bar aksi ===== */
    .pos-stepper { display: flex; align-items: center; gap: 0.4rem; flex-shrink: 0; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 14px; padding: 0.4rem 0.5rem; overflow-x: auto; }
    .pos-stepper-item { display: inline-flex; align-items: center; gap: 0.5rem; height: 38px; padding: 0 0.85rem 0 0.45rem; border-radius: 999px; border: none; background: transparent; color: var(--pos-faint); font-size: 0.875rem; font-weight: 800; white-space: nowrap; cursor: pointer; }
    .pos-stepper-item:disabled { cursor: default; }
    .pos-stepper-num { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8125rem; font-weight: 900; background: var(--pos-cream); color: var(--pos-muted); }
    .pos-stepper-item.is-done { color: var(--pos-brown); }
    .pos-stepper-item.is-done .pos-stepper-num { background: var(--pos-line); color: var(--pos-terra); }
    .pos-stepper-item.is-active { background: var(--pos-terra); color: var(--pos-cream); }
    .pos-stepper-item.is-active .pos-stepper-num { background: var(--pos-cream); color: var(--pos-terra); }
    .pos-stepper-line { flex: 1 1 24px; min-width: 16px; height: 2px; border-radius: 2px; background: var(--pos-line); }

    .pos-stage { flex: 1 1 auto; min-height: 300px; overflow: hidden; position: relative; border-radius: 14px; }
    .pos-main.is-selection { display: grid; grid-template-columns: 100% 100% 60% 40%; grid-template-rows: 100%; height: 100%; gap: 0; transition: transform 0.35s cubic-bezier(0.2, 0.8, 0.2, 1); will-change: transform; }
    .pos-main.is-selection > .pos-timeline-card { grid-column: 1; display: flex; flex-direction: column; min-height: 0; }
    .pos-main.is-selection > .pos-timeline-card .pos-timeline-scroll { flex: 1; min-height: 0; overflow: auto; }
    .pos-main.is-selection .tl-cell { height: 68px; }
    .pos-main.is-selection .pos-panel-card, .pos-main.is-selection .pos-panel-body { display: contents; }
    .pos-main.is-selection .pos-panel-header, .pos-main.is-selection .pos-panel-footer { display: none; }
    .pos-main.is-selection .pos-col { min-height: 0; overflow-y: auto; overscroll-behavior: contain; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 14px; padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
    .pos-main.is-selection .pos-col-cust { grid-column: 2; }
    .pos-main.is-selection .pos-col-cust > * { width: 100%; max-width: 720px; margin-inline: auto; }
    .pos-main.is-selection .pos-col-items { grid-column: 3; margin-right: 0.375rem; }
    .pos-main.is-selection .pos-col-sum { grid-column: 4; margin-left: 0.375rem; background: var(--pos-paper); }
    .pos-col { display: flex; flex-direction: column; gap: 0.9rem; }

    /* Langkah Customer: kolom lebar, input & tombol besar. */
    .pos-main.is-selection .pos-col-cust .pos-customer-box { background: transparent; border: none; padding: 0; }
    .pos-main.is-selection .pos-col-cust .pos-section-label { font-size: 0.8125rem; }
    .pos-main.is-selection .pos-col-cust .pos-tab { height: 40px; font-size: 0.875rem; padding: 0 1.1rem; }
    .pos-main.is-selection .pos-col-cust .pos-input { height: 50px; font-size: 1rem; padding: 0 0.9rem; }
    .pos-main.is-selection .pos-col-cust .pos-customer-box > div:last-child { gap: 0.75rem !important; }

    /* Langkah Tambahan: alat sewa jadi kartu dengan tombol +/− besar. */
    .pos-main.is-selection .pos-eq-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 0.6rem; background: transparent !important; border: none !important; padding: 0 !important; }
    .pos-main.is-selection .pos-eq-list .pos-eq-row { border: 1px solid var(--pos-line); border-radius: 12px; padding: 0.7rem 0.8rem; background: var(--pos-paper); gap: 0.5rem; }
    .pos-main.is-selection .pos-eq-list .pos-eq-row:last-child { border-bottom: 1px solid var(--pos-line); }
    .pos-main.is-selection .pos-qty-btn { width: 40px; height: 40px; border-radius: 10px; font-size: 1.125rem; }
    .pos-main.is-selection .pos-col-sum .pos-total-box { background: #FFFFFF; }

    .pos-actionbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-shrink: 0; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 14px; padding: 0.6rem 0.6rem 0.6rem 1rem; box-shadow: 0 -6px 20px rgba(79,47,42,0.06); }
    .pos-actionbar-summary { display: flex; align-items: center; gap: 1.25rem; min-width: 0; flex: 1; }
    .pos-sum-item { display: flex; flex-direction: column; min-width: 0; }
    .pos-sum-item .k { font-size: 0.625rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--pos-faint); }
    .pos-sum-item .v { font-size: 0.875rem; font-weight: 800; color: var(--pos-brown); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }
    .pos-sum-item.is-total .v { font-size: 1.125rem; font-weight: 900; color: var(--pos-terra); font-variant-numeric: tabular-nums; }
    .pos-actionbar-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
    .pos-actionbar-back { height: 50px; padding: 0 1.1rem; }
    .pos-actionbar-next { width: auto; padding: 0 1.4rem; min-width: 230px; }

    /* Konfirmasi pesanan (isi modal Filament). */
    .pos-confirm { display: flex; flex-direction: column; gap: 0.1rem; color: #4F2F2A; }
    .pos-confirm-row { display: grid; grid-template-columns: 120px 1fr; gap: 0.75rem; padding: 0.6rem 0; border-bottom: 1px solid #EFE6D2; font-size: 0.875rem; }
    .pos-confirm-row > span { color: #7A5A52; font-weight: 600; }
    .pos-confirm-row > strong { font-weight: 800; display: flex; flex-direction: column; gap: 0.15rem; }
    .pos-confirm-row small { font-size: 0.75rem; font-weight: 600; color: #7A5A52; }
    .pos-confirm-muted { color: #A08F86; font-weight: 600; }
    .pos-confirm-total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 0.6rem; padding: 0.75rem 0.9rem; border-radius: 12px; background: #F7F0DB; }
    .pos-confirm-total span { font-weight: 800; color: #4F2F2A; }
    .pos-confirm-total strong { font-size: 1.375rem; font-weight: 900; color: #662721; font-variant-numeric: tabular-nums; }
    .pos-confirm-actions { display: flex; justify-content: flex-end; gap: 0.5rem; width: 100%; }
    .pos-confirm-actions .pos-btn { height: 46px; padding: 0 1.2rem; }
    [x-cloak] { display: none !important; }
    /* ===== Jual Membership: data pelanggan, kartu paket, ringkasan ===== */
    .pos-scroll-body { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 1rem; display: flex; flex-direction: column; gap: 1.25rem; }
    .pos-step-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.65rem; }
    .pos-step-title { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9375rem; font-weight: 900; color: var(--pos-brown); }
    .pos-step-num { width: 24px; height: 24px; border-radius: 50%; background: var(--pos-terra); color: var(--pos-cream); font-size: 0.75rem; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .pos-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .pos-field-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--pos-muted); margin-bottom: 0.3rem; }
    .pos-search-results { position: absolute; top: 100%; left: 0; right: 0; z-index: 50; background: #FFFFFF; border: 1px solid var(--pos-line); border-radius: 10px; box-shadow: 0 12px 28px rgba(79,47,42,0.16); margin-top: 0.3rem; max-height: 220px; overflow-y: auto; }
    .pos-search-item { width: 100%; text-align: left; padding: 0.6rem 0.85rem; border: none; border-bottom: 1px solid var(--pos-line-soft); background: #FFFFFF; cursor: pointer; }
    .pos-search-item:hover { background: var(--pos-paper); }
    .pos-picked-customer { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; background: var(--pos-paper); border: 1px solid var(--pos-terra); border-radius: 12px; padding: 0.75rem 1rem; }
    .pos-plan-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 0.75rem; }
    .pos-plan-card { position: relative; display: flex; flex-direction: column; text-align: left; background: #FFFFFF; border: 1.5px solid var(--pos-line); border-radius: 14px; padding: 0.95rem 1rem; cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s, transform 0.08s; }
    .pos-plan-card:hover { border-color: var(--pos-terra); }
    .pos-plan-card:active { transform: scale(0.99); }
    .pos-plan-card.is-selected { border: 2px solid var(--pos-terra); background: var(--pos-paper); box-shadow: 0 6px 18px rgba(102,39,33,0.14); padding: calc(0.95rem - 0.5px) calc(1rem - 0.5px); }
    .pos-plan-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
    .pos-plan-code { font-size: 0.625rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--pos-muted); }
    .pos-plan-name { font-size: 1rem; font-weight: 900; color: var(--pos-brown); line-height: 1.25; margin-top: 0.1rem; }
    .pos-plan-days { flex-shrink: 0; font-size: 0.6875rem; font-weight: 800; padding: 0.2rem 0.5rem; border-radius: 6px; background: var(--pos-cream); color: var(--pos-terra); }
    .pos-plan-card.is-selected .pos-plan-days { background: var(--pos-terra); color: var(--pos-cream); }
    .pos-plan-price { font-size: 1.125rem; font-weight: 900; color: var(--pos-terra); margin-top: 0.55rem; font-variant-numeric: tabular-nums; }
    .pos-plan-benefits { border-top: 1px dashed var(--pos-line); margin-top: 0.65rem; padding-top: 0.55rem; display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem; color: var(--pos-muted); line-height: 1.4; }
    .pos-plan-benefits strong { color: var(--pos-brown); }
    .pos-plan-check { position: absolute; top: -8px; right: -8px; width: 24px; height: 24px; border-radius: 50%; background: var(--pos-terra); color: var(--pos-cream); display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(102,39,33,0.3); }
    .pos-summary-box { background: var(--pos-paper); border: 1px solid var(--pos-line); border-radius: 12px; padding: 0.7rem 0.85rem; font-size: 0.8125rem; }
    .pos-summary-label { font-size: 0.625rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--pos-terra); margin-bottom: 0.2rem; }
    .pos-sum-row { display: flex; justify-content: space-between; gap: 0.75rem; font-size: 0.8125rem; color: var(--pos-muted); }
    .pos-sum-total { display: flex; justify-content: space-between; align-items: baseline; gap: 0.75rem; border-top: 1.5px dashed var(--pos-line); margin-top: 0.4rem; padding-top: 0.5rem; font-weight: 900; color: var(--pos-brown); }
    .pos-sum-total span:last-child { font-size: 1.1875rem; color: var(--pos-terra); font-variant-numeric: tabular-nums; }
    .pos-empty-note { text-align: center; padding: 2rem 1rem; color: var(--pos-faint); font-size: 0.8125rem; line-height: 1.5; }

    /* ===== Tablet tegak / layar sempit: panel checkout di bawah jadwal ===== */
    @media (max-width: 1023px) {
        .walkin-pos-root { height: auto; min-height: 0; overflow: visible; }
        .pos-main { grid-template-columns: 1fr; }
        .pos-grid-card, .pos-terminal-card, .pos-receipt-inpage { max-height: none; }
        .pos-grid-scroll { max-height: 64vh; }
        .pos-panel-body, .pos-scroll-body { overflow: visible; }
        .pos-stage { flex: none; height: 72vh; }
        .pos-actionbar { flex-wrap: wrap; }
        .pos-actionbar-summary { flex-wrap: wrap; gap: 0.75rem; }
        .pos-actionbar-next { min-width: 0; }
    }
    @media (max-width: 640px) {
        .pos-date-label { width: 100%; margin-left: 0; }
        .pos-kpis { width: 100%; }
        .pos-kpi { flex: 1; min-width: 0; }
        .pos-receipt-stage { padding: 1rem; }
        .pos-form-grid { grid-template-columns: 1fr; }
    }
</style>
