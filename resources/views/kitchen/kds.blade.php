<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLUB 61 KDS - Kitchen &amp; Bar Display Monitor</title>
    <link rel="icon" type="image/png" href="{{ asset('images/club61-logo.png') }}">
    <!-- Google Fonts: Luxury Serif, Athletic Sans, and Monospace -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@87.5..125,400..800&family=Source+Serif+4:opsz,wght@8..60,400..700&family=JetBrains+Mono:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-brand antialiased text-[#4F2F2A] bg-[#F7F0DB] selection:bg-[#662721] selection:text-[#F7F0DB] flex flex-col relative overflow-hidden">

    <!-- Top KDS Bar: pita terakota brand (sama dengan header POS kasir) -->
    <header class="relative z-20 bg-[#662721] px-6 py-3 flex items-center justify-between shadow-sm shrink-0">
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/identity/monogram-cream.png') }}" alt="Club 61 Logo" class="w-10 h-10 object-contain">
                <div>
                    <div class="font-display font-bold text-[#F7F0DB] text-lg tracking-wide flex items-center gap-2 whitespace-nowrap">
                        <span>CLUB 61 KDS</span>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-widest shadow-sm"
                              style="background: rgba(247,240,219,0.12); border: 1px solid rgba(247,240,219,0.35); color: #F7F0DB;">
                            Live Monitor
                        </span>
                    </div>
                    <div class="text-[11px] text-[#F7F0DB]/70 font-medium -mt-0.5">Kitchen &amp; Barista Order Dispatch System &bull; Club 61 Medan</div>
                </div>
            </div>

            <!-- Station Filters -->
            <div class="hidden sm:flex items-center gap-2 pl-6 border-l border-[#F7F0DB]/25">
                <button class="px-3.5 py-1.5 rounded-xl text-xs font-black uppercase tracking-wider transition-all shadow-md transform active:scale-95 cursor-pointer"
                        style="background: #F7F0DB; border: 1.5px solid #F7F0DB; color: #662721;">
                    Semua Stasiun (3)
                </button>
                <button class="px-3.5 py-1.5 rounded-xl text-xs font-bold uppercase tracking-wider transition-all shadow-sm hover:bg-white/10 cursor-pointer"
                        style="background: transparent; border: 1.5px solid rgba(247,240,219,0.4); color: #F7F0DB;">
                    Bar Kopi (2)
                </button>
                <button class="px-3.5 py-1.5 rounded-xl text-xs font-bold uppercase tracking-wider transition-all shadow-sm hover:bg-white/10 cursor-pointer"
                        style="background: transparent; border: 1.5px solid rgba(247,240,219,0.4); color: #F7F0DB;">
                    Dapur Masak (1)
                </button>
            </div>
        </div>  </div>

        <!-- Operator & Logout -->
        <div class="flex items-center gap-4">
            <div class="text-right hidden sm:block">
                <div class="text-xs font-bold text-[#F7F0DB]">{{ Auth::user()->name ?? 'Barista Cafe Club 61' }}</div>
                <div class="text-[10px] font-mono font-bold uppercase tracking-wider px-2 py-0.5 rounded-full inline-block mt-0.5 shadow-sm"
                     style="background: rgba(247,240,219,0.12); border: 1px solid rgba(247,240,219,0.35); color: #F7F0DB;">
                    Peran: {{ Auth::user()->role ?? 'KITCHEN' }}
                </div>
            </div>

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

    <!-- Tickets Board (Live Grid) -->
    <main class="relative z-10 flex-1 overflow-y-auto p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            
            <!-- TICKET 1: In Progress (Polished Gold Luxury) -->
            <div id="ticket-1" class="rounded-3xl p-5 flex flex-col justify-between relative overflow-hidden transition-all duration-300"
                 style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.96) 0%, rgba(253, 249, 240, 0.92) 100%);
                        border: 2px solid #662721;
                        border-radius: 26px;
                        box-shadow: 0 16px 36px -10px rgba(160, 120, 30, 0.22), 0 0 15px rgba(212, 175, 55, 0.15);
                        backdrop-filter: blur(16px);">
                <div class="absolute top-0 left-0 right-0 h-1.5" style="background: #662721;"></div>
                
                <div>
                    <!-- Ticket Header -->
                    <div class="flex items-center justify-between pb-3 border-b border-[#E6DAC0]/60">
                        <div>
                            <span class="font-mono text-base font-black text-[#4F2F2A]">#TKT-041</span>
                            <div class="text-xs font-bold text-[#662721] flex items-center gap-1 mt-0.5">
                                <span>Meja 01 (Table 01)</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-mono text-sm font-extrabold text-[#662721] flex items-center justify-end gap-1.5">
                                <span class="w-2 h-2 rounded-full animate-ping" style="background-color: #662721;"></span>
                                <span>03:45</span>
                            </div>
                            <span class="text-[9px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full inline-block shadow-sm"
                                  style="background: #F7F0DB; border: 1px solid #E6DAC0; color: #7A5A52;">
                                Sedang Dibuat
                            </span>
                        </div>
                    </div>

                    <!-- Ticket Items -->
                    <div class="py-4 space-y-3">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-sm font-extrabold text-[#4F2F2A] flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg font-mono text-xs flex items-center justify-center font-bold shadow-sm"
                                          style="background: linear-gradient(135deg, #F7F0DB 0%, #F7F0DB 100%); border: 1px solid #E6DAC0; color: #4F2F2A;">2x</span>
                                    <span>Iced Spanish Latte</span>
                                </div>
                                <div class="text-xs text-[#7A5A52] pl-8 space-y-0.5 font-medium mt-1">
                                    <div>&bull; Less Sugar (50%)</div>
                                    <div>&bull; Normal Ice</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-sm font-extrabold text-[#4F2F2A] flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg font-mono text-xs flex items-center justify-center font-bold shadow-sm"
                                          style="background: linear-gradient(135deg, #F7F0DB 0%, #F7F0DB 100%); border: 1px solid #E6DAC0; color: #4F2F2A;">1x</span>
                                    <span>Ceremonial Oat Matcha</span>
                                </div>
                                <div class="text-xs text-[#7A5A52] pl-8 font-medium mt-1">
                                    <div>&bull; Extra Oatside Milk</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ticket Action Button -->
                <button onclick="serveTicket('ticket-1')" 
                        class="w-full py-3 rounded-2xl font-black text-xs uppercase tracking-wider transition-all duration-200 transform active:scale-95 hover:brightness-105 flex items-center justify-center gap-2 cursor-pointer shadow-lg mt-2"
                        style="background: #662721; border: 1.5px solid #662721; color: #F7F0DB; box-shadow: 0 6px 20px rgba(102,39,33,0.25);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>Selesai &amp; Siap Sajikan</span>
                </button>
            </div>

            <!-- TICKET 2: Kitchen Queued (Urgent Rose Gold) -->
            <div id="ticket-2" class="rounded-3xl p-5 flex flex-col justify-between relative overflow-hidden transition-all duration-300"
                 style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.96) 0%, rgba(255, 245, 245, 0.92) 100%);
                        border: 2px solid #E11D48;
                        border-radius: 26px;
                        box-shadow: 0 16px 36px -10px rgba(225, 29, 72, 0.22), 0 0 15px rgba(225, 29, 72, 0.12);
                        backdrop-filter: blur(16px);">
                <div class="absolute top-0 left-0 right-0 h-1.5" style="background: linear-gradient(90deg, #FB7185, #E11D48, #BE123C);"></div>

                <div>
                    <!-- Ticket Header -->
                    <div class="flex items-center justify-between pb-3 border-b border-rose-200">
                        <div>
                            <span class="font-mono text-base font-black text-[#4F2F2A]">#TKT-042</span>
                            <div class="text-xs font-bold text-rose-700 flex items-center gap-1 mt-0.5">
                                <span>Lapangan 02 (Court 2)</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-mono text-sm font-extrabold text-rose-600 flex items-center justify-end gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                                <span>08:12</span>
                            </div>
                            <span class="text-[9px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full inline-block shadow-sm bg-rose-50 text-rose-700 border border-rose-200">
                                Antrean Masak
                            </span>
                        </div>
                    </div>

                    <!-- Ticket Items -->
                    <div class="py-4 space-y-3">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-sm font-extrabold text-[#4F2F2A] flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-rose-100 text-rose-800 font-mono text-xs flex items-center justify-center font-bold border border-rose-200">2x</span>
                                    <span>Smashed Avocado Toast</span>
                                </div>
                                <div class="text-xs text-[#7A5A52] pl-8 space-y-0.5 font-medium mt-1">
                                    <div>&bull; Telur Poached Setengah Matang</div>
                                    <div>&bull; Extra Feta Cheese</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ticket Action Button -->
                <button onclick="startCook('ticket-2')" 
                        class="w-full py-3 rounded-2xl font-black text-xs uppercase tracking-wider transition-all duration-200 transform active:scale-95 hover:brightness-105 flex items-center justify-center gap-2 cursor-pointer shadow-lg mt-2"
                        style="background: linear-gradient(180deg, #FB7185 0%, #E11D48 100%); border: 1.5px solid #FDA4AF; color: #FFFFFF; box-shadow: 0 6px 20px rgba(225, 29, 72, 0.3);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Mulai Masak Sekarang</span>
                </button>
            </div>

            <!-- TICKET 3: New Order (Polished Gold Accent) -->
            <div id="ticket-3" class="rounded-3xl p-5 flex flex-col justify-between relative overflow-hidden transition-all duration-300"
                 style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.96) 0%, rgba(253, 249, 240, 0.92) 100%);
                        border: 2px solid #C59B46;
                        border-radius: 26px;
                        box-shadow: 0 16px 36px -10px rgba(160, 120, 30, 0.2), 0 0 15px rgba(212, 175, 55, 0.12);
                        backdrop-filter: blur(16px);">
                <div class="absolute top-0 left-0 right-0 h-1.5" style="background: #662721;"></div>

                <div>
                    <!-- Ticket Header -->
                    <div class="flex items-center justify-between pb-3 border-b border-[#E6DAC0]/60">
                        <div>
                            <span class="font-mono text-base font-black text-[#4F2F2A]">#TKT-043</span>
                            <div class="text-xs font-bold text-[#662721] flex items-center gap-1 mt-0.5">
                                <span>VIP Lounge 1</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-mono text-sm font-extrabold text-[#662721] flex items-center justify-end gap-1.5">
                                <span class="w-2 h-2 rounded-full" style="background-color: #662721;"></span>
                                <span>01:15</span>
                            </div>
                            <span class="text-[9px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full inline-block shadow-sm"
                                  style="background: #F7F0DB; border: 1px solid #E6DAC0; color: #7A5A52;">
                                Pesanan Baru
                            </span>
                        </div>
                    </div>

                    <!-- Ticket Items -->
                    <div class="py-4 space-y-3">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-sm font-extrabold text-[#4F2F2A] flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg font-mono text-xs flex items-center justify-center font-bold shadow-sm"
                                          style="background: linear-gradient(135deg, #F7F0DB 0%, #F7F0DB 100%); border: 1px solid #E6DAC0; color: #4F2F2A;">1x</span>
                                    <span>Single Origin Americano</span>
                                </div>
                                <div class="text-xs text-[#7A5A52] pl-8 font-medium mt-1">
                                    <div>&bull; Hot / Chilled Double Shot</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ticket Action Button -->
                <button onclick="serveTicket('ticket-3')" 
                        class="w-full py-3 rounded-2xl font-black text-xs uppercase tracking-wider transition-all duration-200 transform active:scale-95 hover:brightness-105 flex items-center justify-center gap-2 cursor-pointer shadow-lg mt-2"
                        style="background: #662721; border: 1.5px solid #662721; color: #F7F0DB; box-shadow: 0 6px 20px rgba(102,39,33,0.25);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>Kerjakan &amp; Sajikan</span>
                </button>
            </div>

        </div>
    </main>

    <!-- KDS Interactive Script -->
    <script>
        function serveTicket(id) {
            const ticket = document.getElementById(id);
            ticket.style.opacity = '0.5';
            ticket.style.transform = 'scale(0.97)';
            ticket.innerHTML = `
                <div class="p-8 text-center my-auto">
                    <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-emerald-100 border border-emerald-300 flex items-center justify-center text-emerald-700 font-bold text-lg">OK</div>
                    <div class="font-display font-black text-base text-[#4F2F2A]">Pesanan Selesai Disajikan!</div>
                    <div class="text-xs text-[#7A5A52] font-medium mt-1">Status KDS terupdate otomatis ke kasir POS.</div>
                </div>
            `;
        }

        function startCook(id) {
            const ticket = document.getElementById(id);
            ticket.style.border = '2px solid #662721';
            ticket.style.boxShadow = '0 16px 36px -10px rgba(160, 120, 30, 0.22), 0 0 15px rgba(212, 175, 55, 0.15)';
            ticket.innerHTML = `
                <div class="absolute top-0 left-0 right-0 h-1.5" style="background: #662721;"></div>
                <div class="pb-3 border-b border-[#E6DAC0]/60 flex items-center justify-between">
                    <div>
                        <span class="font-mono text-base font-black text-[#4F2F2A]">#TKT-042</span>
                        <div class="text-xs font-bold text-[#662721] mt-0.5">Lapangan 02</div>
                    </div>
                    <span class="text-[9px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full inline-block shadow-sm"
                          style="background: #F7F0DB; border: 1px solid #E6DAC0; color: #7A5A52;">
                        Sedang Dimasak
                    </span>
                </div>
                <div class="py-5 text-sm text-[#4F2F2A] font-extrabold flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg font-mono text-xs flex items-center justify-center font-bold shadow-sm"
                          style="background: linear-gradient(135deg, #F7F0DB 0%, #F7F0DB 100%); border: 1px solid #E6DAC0; color: #4F2F2A;">2x</span>
                    <span>Smashed Avocado Toast</span>
                </div>
                <button onclick="serveTicket('${id}')" 
                        class="w-full py-3 rounded-2xl font-black text-xs uppercase tracking-wider transition-all duration-200 transform active:scale-95 hover:brightness-105 flex items-center justify-center gap-2 cursor-pointer shadow-lg"
                        style="background: #662721; border: 1.5px solid #662721; color: #F7F0DB; box-shadow: 0 6px 20px rgba(102,39,33,0.25);">
                    <span>Selesai &amp; Siap Sajikan</span>
                </button>
            `;
        }
    </script>
</body>
</html>