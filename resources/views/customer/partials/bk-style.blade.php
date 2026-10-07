{{-- Gaya bersama halaman customer alur booking (Book Court → Cart → Checkout): efek tap, animasi, bar ringkasan bawah. --}}
@once
    <style>
        .bk-scroll { scrollbar-width: none; -webkit-overflow-scrolling: touch; scroll-snap-type: x proximity; }
        .bk-scroll::-webkit-scrollbar { display: none; }
        .bk-snap { scroll-snap-align: center; }
        .bk-tap { -webkit-tap-highlight-color: transparent; touch-action: manipulation; user-select: none; }
        @keyframes bk-pop { 0% { transform: scale(.92); } 60% { transform: scale(1.04); } 100% { transform: scale(1); } }
        .bk-pop { animation: bk-pop .22s ease-out; }
        @keyframes bk-fade-up { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        .bk-fade-up { animation: bk-fade-up .28s ease-out both; }
        @keyframes bk-shimmer { 0% { background-position: -200px 0; } 100% { background-position: 200px 0; } }
        .bk-skeleton { background: linear-gradient(90deg, #F7F0DB 0px, #FCF8EE 80px, #F7F0DB 160px); background-size: 400px 100%; animation: bk-shimmer 1.2s linear infinite; }
        .bk-bar { bottom: calc(62px + env(safe-area-inset-bottom, 0px) + 10px); }
        @media (min-width: 768px) { .bk-bar { bottom: 24px; } }
        /* Ruang bawah = setinggi bar ringkasan saja (<main> layout sudah menyisakan tempat untuk navigasi bawah). */
        .bk-page { padding-bottom: calc(var(--bk-bar-h, 84px) + 4px + env(safe-area-inset-bottom, 0px)); }
        @media (min-width: 768px) { .bk-page { padding-bottom: calc(var(--bk-bar-h, 96px) + 8px); } }
        /* Cart & checkout: di layar lebar ringkasan ada di kolom kanan, bar bawah tidak dipakai. */
        @media (min-width: 1024px) {
            .bk-bar.bk-bar-compact-only { display: none; }
            .bk-page.bk-bar-compact-only { padding-bottom: 2rem; }
        }
        @media (prefers-reduced-motion: reduce) { .bk-pop, .bk-fade-up, .bk-skeleton { animation: none; } }
    </style>
    <script>
        // Ruang bawah halaman mengikuti tinggi bar ringkasan (tingginya berubah saat isinya berubah).
        window.bkWatchBar = function (root, bar) {
            if (!window.ResizeObserver || !root || !bar) return;
            new ResizeObserver(() => root.style.setProperty('--bk-bar-h', bar.offsetHeight + 'px')).observe(bar);
        };
    </script>
@endonce
