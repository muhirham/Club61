<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLUB 61 POS - Frontdesk & Cashier Terminal</title>
    <link rel="icon" type="image/png" href="{{ asset('images/club61-logo.png') }}">
    {{-- Brand guideline: Cheltenham Classic (judul) & Acumin Variable Concept (teks) — selama webfont berlisensinya
         belum dipasang, tampil dengan padanan terdekat: Source Serif 4 & Archivo (lihat tailwind.config.js). --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@87.5..125,400..800&family=Source+Serif+4:opsz,wght@8..60,400..700&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-brand antialiased text-[#4F2F2A] bg-[#F7F0DB] selection:bg-[#662721] selection:text-[#F7F0DB] flex flex-col overflow-hidden">

    <!-- Top POS Navigation Header -->
    <header class="relative z-20 bg-[#662721] text-[#F7F0DB] px-5 py-3 flex items-center justify-between shadow-sm shrink-0">
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/identity/monogram-cream.png') }}" alt="Club 61 POS" class="w-10 h-10 object-contain">
                <div>
                    <div class="font-display font-bold text-[#F7F0DB] text-lg tracking-wide flex items-center gap-2 whitespace-nowrap">
                        <span>Club 61 POS</span>
                        <span class="font-brand px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-widest"
                              style="background: rgba(247,240,219,0.12); border: 1px solid rgba(247,240,219,0.35); color: #F7F0DB;">
                            Terminal 01
                        </span>
                    </div>
                    <div class="text-[11px] text-[#F7F0DB]/70 font-medium -mt-0.5 whitespace-nowrap">Club 61 Padel Court &bull; Gedung Indosat Medan</div>
                </div>
            </div>

            <!-- Quick Status Badge -->
            <div class="hidden lg:flex items-center gap-2 pl-4 border-l border-[#F7F0DB]/25 text-xs font-semibold text-[#F7F0DB]/90">
                <span class="w-2 h-2 rounded-full animate-pulse" style="background-color: #34D399; box-shadow: 0 0 8px #34D399;"></span>
                <span>Kasir Siap &bull; Printer Kasir Terhubung</span>
            </div>
        </div>

        <!-- Cashier Profile & Logout -->
        <div class="flex items-center gap-3">
            <button type="button" 
                    onclick="openPosCheckInModal()" 
                    class="px-3.5 py-2 rounded-xl font-bold text-xs whitespace-nowrap flex items-center gap-1.5 transition-all cursor-pointer shadow-sm active:scale-95"
                    style="background: #F7F0DB; color: #662721; border: 1px solid #F7F0DB;">
                <span>Check-In Tiket</span>
            </button>

            <div class="text-right hidden sm:block">
                <div class="text-xs font-bold text-[#F7F0DB]">{{ Auth::user()->name ?? 'Kasir Frontdesk POS' }}</div>
                <div class="text-[10px] font-mono font-bold uppercase tracking-wider px-2 py-0.5 rounded-full inline-block mt-0.5 whitespace-nowrap"
                     style="background: rgba(247,240,219,0.12); border: 1px solid rgba(247,240,219,0.35); color: #F7F0DB;">
                    Peran: {{ Auth::user()->role ?? 'CASHIER' }}
                </div>
            </div>

            <!-- Switch / Logout Form -->
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" 
                        class="p-2 rounded-xl bg-white/10 hover:bg-white/20 border border-[#F7F0DB]/30 text-[#F7F0DB] text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer" 
                        title="Keluar">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span class="hidden sm:inline font-bold">Keluar</span>
                </button>
            </form>
        </div>
    </header>

    <!-- Main POS Interface (Split-screen) — konten interaktif (katalog menu F&B, keranjang,
         pembayaran, shift kasir) sekarang komponen Livewire nyata (App\Livewire\Pos\FnbCashierTerminal),
         bukan lagi HTML/JS statis. Checkout beneran menyimpan Order/OrderItem/Payment lewat
         PaymentOrchestratorService — skema sama persis dengan walk-in booking BookOfflineCourt. -->
    {{-- z-30 (di atas header z-20): notifikasi & modal fixed di dalam komponen Livewire
         ikut stacking context <main>, jadi kalau <main> lebih rendah dari header, banner
         error kepotong/ketutup header dan kasir tidak pernah melihat pesannya. --}}
    <main class="relative z-30 flex-1 flex overflow-hidden">
        @livewire('pos.fnb-cashier-terminal')
    </main>

    <!-- Quick Cart Script -->
    <script>
        // Semua nilai dari server (nama customer, lapangan, alat, pesan error) di-escape sebelum masuk innerHTML:
        // nama customer diisi bebas oleh customer, jadi tanpa escape bisa menyisipkan skrip ke sesi kasir.
        function escHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
        }

        function openPosCheckInModal() {
            document.getElementById('pos-checkin-modal').classList.remove('hidden');
            document.getElementById('pos-checkin-result').classList.add('hidden');
            const input = document.getElementById('pos-ticket-input');
            input.value = '';
            setTimeout(() => input.focus(), 100);
        }

        function closePosCheckInModal() {
            document.getElementById('pos-checkin-modal').classList.add('hidden');
        }

        async function submitPosCheckIn() {
            const input = document.getElementById('pos-ticket-input');
            const code = input.value.trim();
            if (!code) {
                alert('Silakan scan barcode atau masukkan kode tiket.');
                return;
            }

            const resultContainer = document.getElementById('pos-checkin-result');
            resultContainer.classList.remove('hidden');
            resultContainer.className = 'rounded-2xl p-4 border text-xs space-y-2 bg-[#F7F0DB] border-[#E6DAC0] text-[#4F2F2A]';
            resultContainer.innerHTML = 'Memverifikasi tiket ke server...';

            try {
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const response = await fetch('/pos/check-in', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ code: code })
                });

                const data = await response.json();
                if (data.success) {
                    const res = data.data;
                    let equipmentsHtml = '';
                    if (res.equipments && res.equipments.length > 0) {
                        equipmentsHtml = '<div class="mt-2 pt-2 border-t border-emerald-200"><div class="font-bold text-emerald-900 mb-1">Serah-Terima Alat:</div>' +
                            res.equipments.map(e => `<div class="flex justify-between py-0.5"><span>${escHtml(e.name)}</span><span class="font-bold font-mono">${escHtml(e.quantity)} Pcs</span></div>`).join('') +
                            '<div class="mt-1 text-[11px] text-emerald-700 font-semibold">Wajib serahkan raket &amp; bola ke pemain.</div></div>';
                    } else {
                        equipmentsHtml = '<div class="text-[11px] text-gray-500 italic mt-1">Tidak ada sewa raket/bola tambahan.</div>';
                    }

                    resultContainer.className = 'rounded-2xl p-4 border text-xs space-y-2 bg-emerald-50 border-emerald-300 text-emerald-900';
                    resultContainer.innerHTML = `
                        <div class="flex justify-between items-center">
                            <span class="px-2 py-0.5 rounded-md font-bold text-[10px] bg-emerald-200 text-emerald-900 uppercase">
                                ${res.already_checked_in ? 'Sudah Pernah Check-In' : 'Check-In Berhasil'}
                            </span>
                            <span class="font-mono text-[10px] text-emerald-700">${escHtml(res.booking_code)}</span>
                        </div>
                        <div class="font-bold text-sm text-gray-900">${escHtml(res.player_name)}</div>
                        <div class="text-xs text-gray-700"><strong>${escHtml(res.court_name)}</strong> &bull; ${escHtml(res.schedule)}</div>
                        ${equipmentsHtml}
                    `;
                } else {
                    resultContainer.className = 'rounded-2xl p-4 border text-xs space-y-2 bg-rose-50 border-rose-300 text-rose-900';
                    resultContainer.innerHTML = `<strong>Gagal Check-In:</strong><br>${escHtml(data.message || 'Tiket tidak ditemukan.')}`;
                }
            } catch (err) {
                resultContainer.className = 'rounded-2xl p-4 border text-xs space-y-2 bg-rose-50 border-rose-300 text-rose-900';
                resultContainer.innerHTML = `<strong>Terjadi Kesalahan:</strong><br>${escHtml(err.message)}`;
            }
        }
    </script>

    <!-- MODAL CHECK-IN GATE CLUB 61 POS -->
    <div id="pos-checkin-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl border border-[#E6DAC0] shadow-2xl w-full max-w-lg overflow-hidden animate-fadeIn">
            <!-- Header -->
            <div class="p-5 border-b border-[#E6DAC0] flex items-center justify-between"
                 style="background: #F7F0DB;">
                <div>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]">
                        Frontdesk Scanner &bull; Club 61 Medan
                    </span>
                    <div class="font-display font-black text-lg text-[#4F2F2A] mt-1">Check-In Tiket Lapangan</div>
                </div>
                <button type="button" onclick="closePosCheckInModal()" class="text-2xl text-[#662721] hover:text-black leading-none cursor-pointer">&times;</button>
            </div>

            <!-- Body -->
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-[#4F2F2A] mb-1.5">Scan Barcode / Input Kode Tiket (BK-PAD-XXXX):</label>
                    <div class="flex gap-2">
                        <input type="text" id="pos-ticket-input" 
                               placeholder="Tembak barcode gun atau ketik kode tiket..." 
                               class="flex-1 px-3.5 py-2.5 rounded-xl border border-[#E6DAC0] text-sm font-mono font-bold text-[#4F2F2A] bg-[#FCF8EE] outline-none focus:border-[#662721] focus:ring-2 focus:ring-[#662721]/20"
                               onkeydown="if(event.key === 'Enter') submitPosCheckIn();" />
                        <button type="button" onclick="submitPosCheckIn()" 
                                 class="px-4 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-[#F7F0DB] cursor-pointer active:scale-95 transition-all shadow-md"
                                 style="background: #662721; border: 1px solid #662721;">
                            Check-In
                        </button>
                    </div>
                </div>

                <!-- Alert Result Container -->
                <div id="pos-checkin-result" class="hidden rounded-2xl p-4 border text-xs space-y-2"></div>
            </div>

            <!-- Footer -->
            <div class="p-4 bg-[#F7F0DB] border-t border-[#E6DAC0] flex justify-end gap-2">
                <button type="button" onclick="closePosCheckInModal()" class="px-4 py-2 rounded-xl text-xs font-bold text-[#4F2F2A] bg-white border border-[#E6DAC0]">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>