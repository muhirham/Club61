<nav x-data="customerNav()" x-init="init()" class="bg-[#662721] border-b border-[#511D18] sticky top-0 z-30">
    <!-- Primary Navigation Menu -->
    <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16">
        <div class="flex justify-between h-16 lg:h-[72px]">
            <div class="flex items-center gap-4">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                        <img src="{{ asset('images/identity/monogram-cream.png') }}" alt="Club 61 Padel Court" class="h-10 lg:h-11 w-auto shrink-0">
                        <span class="leading-none whitespace-nowrap">
                            <span class="block font-bold text-[16px] sm:text-lg tracking-[0.18em] text-[#F7F0DB]" style="font-stretch: 125%;">CLUB 61</span>
                            <span class="block text-[9px] font-semibold tracking-[0.32em] text-[#F7F0DB]/70 mt-1">PADEL COURT</span>
                        </span>
                    </a>
                </div>

                <!-- Navigation Links (Desktop) -->
                <div class="hidden md:flex space-x-4 lg:space-x-5 -my-px ms-2 lg:ms-6">
                    <a href="{{ route('dashboard') }}" 
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('dashboard') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        Home
                    </a>
                    <a href="{{ route('customer.booking') }}" 
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.booking') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        Book Court
                    </a>
                    {{-- "Membership" sengaja tidak lagi jadi menu navbar terpisah yang langsung ke halaman
                         penjualan/katalog. Status & detail membership customer (kode, masa aktif, sisa
                         benefit per fasilitas) sudah ditampilkan di atas katalog pada halaman "My Club" itu
                         sendiri — dan dari situ pula customer klik untuk membeli/upgrade paket. --}}
                    <a href="{{ route('customer.my-club') }}"
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.my-club') || request()->routeIs('customer.membership') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        My Club
                    </a>
                    <a href="{{ route('customer.invoice') }}"
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.invoice') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        Invoice
                    </a>
                    @if(Auth::check() && \App\Models\Sponsor\SponsorOrganization::where('sponsor_admin_user_id', Auth::id())->exists())
                        <a href="{{ route('customer.corporate') }}"
                           class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.corporate') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                            Sponsor Team
                        </a>
                    @endif
                </div>
            </div>

            <!-- Right Actions (Cart, Notifications, Profile) -->
            <div class="flex items-center gap-2 sm:gap-4">
                
                <!-- Notification Bell with Interactive Dropdown (Screen 1 header) -->
                <div class="relative" @click.outside="notifOpen = false">
                    <button type="button" 
                            @click="toggleNotif()"
                            class="p-2 rounded-xl text-[#F7F0DB] hover:text-white hover:bg-white/10 relative transition-colors focus:outline-none cursor-pointer"
                            title="Notifications & Activity">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <span x-show="unreadNotifs" 
                              x-cloak
                              class="absolute top-1.5 right-1.5 w-2.5 h-2.5 bg-amber-500 rounded-full ring-2 ring-[#662721] animate-pulse"
                              style="display: none;"></span>
                    </button>

                    <!-- Luxury Notification Dropdown Panel -->
                    <div x-show="notifOpen"
                         x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                         x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                         class="absolute right-0 mt-3 w-80 sm:w-96 bg-white/95 rounded-xl border border-[#E6DAC0] shadow-[0_20px_50px_rgba(79,47,42,0.2)] p-4 text-[#4F2F2A] z-50 space-y-3"
                         style="display: none;">
                        
                        <!-- Panel Header -->
                        <div class="flex items-center justify-between border-b border-[#E6DAC0]/50 pb-2.5 px-1">
                            <div>
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">Notifications &amp; Activity</h4>
                                <span class="text-[10px] text-[#662721] font-bold uppercase tracking-wider">Club 61 Concierge</span>
                            </div>
                            <button type="button" 
                                    @click="markAllRead()"
                                    class="text-[10px] font-bold text-[#662721] hover:text-[#4F2F2A] hover:underline px-2 py-1 rounded-lg hover:bg-[#F7F0DB] transition-colors cursor-pointer">
                                Mark as Read
                            </button>
                        </div>

                        <!-- Notification Cards List -->
                        <div class="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">

                            <!-- 1. Jadwal Pertandingan Aktif (Jika Ada di Database) -->
                            <template x-if="activeBooking">
                                <a :href="'{{ route('customer.invoice') }}?booking_id=' + activeBooking.id"
                                   class="block p-3 rounded-lg bg-emerald-50/90 border border-emerald-300 hover:bg-emerald-100 transition-all">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0 mt-0.5 shadow-sm">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-black text-emerald-900">Confirmed Match Schedule</span>
                                                <span class="text-[9px] font-mono font-bold text-emerald-700 bg-emerald-200/70 px-1.5 py-0.5 rounded">Confirmed</span>
                                            </div>
                                            <p class="text-[11px] text-emerald-800 mt-1 leading-snug">
                                                <span class="font-bold" x-text="activeBooking.court_name || 'Padel Court'"></span>
                                                &bull; <span x-text="formatDate(activeBooking.booking_date)"></span>
                                                (<span x-text="formatTime(activeBooking.start_time)"></span> WIB)
                                            </p>
                                            <span class="text-[10px] font-bold text-emerald-700 underline mt-1 inline-block">View E-Ticket &amp; QR Code &rarr;</span>
                                        </div>
                                    </div>
                                </a>
                            </template>

                            <!-- 2. Slot di Keranjang Sedang Di-Hold (Jika Ada di Session Storage) -->
                            <template x-if="cartCount > 0">
                                <a href="{{ route('customer.cart') }}" 
                                   class="block p-3 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] hover:bg-[#F7F0DB] transition-all">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] flex items-center justify-center shrink-0 mt-0.5 shadow-sm">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-black text-[#662721]">Reserved Court Slots</span>
                                                <span class="text-[9px] font-bold text-amber-800 bg-amber-200/80 px-1.5 py-0.5 rounded animate-pulse">Hold 10 Mins</span>
                                            </div>
                                            <p class="text-[11px] text-[#7A5A52] mt-1 leading-snug">
                                                You have <strong class="text-[#4F2F2A]" x-text="cartCount"></strong> slot(s) reserved in your cart. Complete checkout before reservation expires!
                                            </p>
                                            <span class="text-[10px] font-bold text-[#662721] underline mt-1 inline-block">View Booking Cart &rarr;</span>
                                        </div>
                                    </div>
                                </a>
                            </template>

                            <!-- 3. Sambutan & Info Venue Club 61 Medan -->
                            <div class="p-3 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0] transition-all">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-[#4F2F2A] text-[#662721] flex items-center justify-center shrink-0 mt-0.5 shadow-sm font-display font-black text-xs">
                                        61
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-black text-[#4F2F2A]">Welcome to Club 61</span>
                                            <span class="text-[9px] text-[#7A5A52]">Indosat Building</span>
                                        </div>
                                        <p class="text-[11px] text-[#7A5A52] mt-1 leading-snug">
                                            Experience 3 panoramic WPT-standard courts at Indosat Building Medan, complete with an exclusive lounge &amp; cafe.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. Fasilitas Wellness Suite VIP -->
                            <div class="p-3 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0] transition-all">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] flex items-center justify-center shrink-0 mt-0.5 shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-black text-[#4F2F2A]">Wellness &amp; Recovery Suite</span>
                                            <span class="text-[9px] font-mono text-emerald-700 font-bold bg-emerald-100 px-1 py-0.5 rounded">VIP Plat</span>
                                        </div>
                                        <p class="text-[11px] text-[#7A5A52] mt-1 leading-snug">
                                            Finnish cedarwood sauna facilities available complimentarily for Platinum members post-match.
                                        </p>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- Panel Footer -->
                        <div class="pt-2.5 border-t border-[#E6DAC0]/50 text-center">
                            <a href="https://wa.me/6281261617233?text=Hello%20Club%2061%20Padel,%20I%20need%20assistance" 
                               target="_blank" 
                               class="inline-flex items-center justify-center gap-1.5 text-[11px] font-bold text-[#662721] hover:text-[#4F2F2A] transition-colors">
                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.669-.699c.969.54 1.771.82 2.791.82 3.181 0 5.767-2.587 5.767-5.766.001-3.181-2.585-5.766-5.767-5.766zm9.969 5.766c0 5.514-4.486 10-10 10-1.823 0-3.528-.49-4.996-1.344l-5.004 1.309 1.334-4.877c-.958-1.517-1.503-3.308-1.503-5.088 0-5.514 4.486-10 10-10s10 4.486 10 10z"/>
                                </svg>
                                <span>Contact WhatsApp Concierge</span>
                            </a>
                        </div>

                    </div>
                </div>

                <!-- Cart Button with Dynamic Counter Badge (Screen 2 & 3) -->
                <a href="{{ route('customer.cart') }}" 
                   class="p-2 rounded-xl text-[#F7F0DB] hover:text-white hover:bg-white/10 relative transition-colors"
                   title="Booking Cart">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <span x-show="cartCount > 0" 
                          x-text="cartCount"
                          x-cloak
                          id="nav-cart-badge" 
                          class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 bg-[#F7F0DB] text-[#662721] text-[10px] font-black rounded-full flex items-center justify-center shadow-sm border border-[#662721] transition-all animate-pulse"
                          style="display: none;">
                    </span>
                </a>

                @if(Auth::check() && Auth::user()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))
                    <a href="/admin" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[#F7F0DB] hover:bg-white text-[#662721] font-black text-xs uppercase tracking-wider shadow-sm  transition-all border border-transparent">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        Admin
                    </a>
                @endif

                <!-- User Info & Logout (Desktop) -->
                @php
                    // Badge membership REAL dari database (bukan label statis) — menunjukkan paket aktif
                    // customer saat ini, tautannya menuju /my-club untuk rincian lengkap per fasilitas.
                    $navActiveMembership = null;
                    if (Auth::check()) {
                        $navActiveMembership = \App\Models\Membership\UserMembership::where('user_id', Auth::id())
                            ->where('status', 'ACTIVE')
                            ->where(function ($q) {
                                $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
                            })
                            ->with('plan')
                            ->orderByRaw('end_date IS NULL, end_date ASC')
                            ->first();
                    }
                @endphp
                <div class="hidden sm:flex items-center gap-3 ms-2">
                    {{-- Tablet: avatar saja (nama + status member membuat menu turun baris). --}}
                    <a href="{{ route('profile.edit') }}" title="{{ Auth::user()->name }}"
                       class="lg:hidden w-9 h-9 rounded-full bg-[#F7F0DB] border border-transparent flex items-center justify-center text-xs font-bold text-[#662721] shadow-sm">
                        {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                    </a>
                    <div class="hidden lg:block text-right whitespace-nowrap max-w-[180px]">
                        <div class="text-xs font-bold text-[#F7F0DB] truncate">{{ Auth::user()->name }}</div>
                        @if($navActiveMembership && $navActiveMembership->plan)
                            <a href="{{ route('customer.my-club') }}"
                               class="text-[9px] text-[#662721] font-mono uppercase bg-[#F7F0DB] px-2 py-0.5 rounded-full border border-transparent inline-flex items-center gap-1 hover:bg-[#F7F0DB] transition-colors"
                               title="Lihat detail membership & sisa kuota">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                {{ $navActiveMembership->plan->name }}
                            </a>
                        @else
                            <a href="{{ route('customer.membership') }}"
                               class="text-[9px] text-[#F7F0DB]/80 font-mono uppercase bg-white/10 px-2 py-0.5 rounded-full border border-[#F7F0DB]/25 inline-block hover:bg-white/15 transition-colors">
                                Belum Member
                            </a>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" 
                                class="p-2 rounded-xl bg-white/10 hover:bg-white/15 border border-[#F7F0DB]/25 text-[#F7F0DB] text-xs font-bold transition-all flex items-center gap-1 cursor-pointer shadow-sm" 
                                title="Log Out">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                        </button>
                    </form>
                </div>

                <!-- User Avatar Quick Menu (Mobile) -->
                <div class="sm:hidden flex items-center">
                    <a href="{{ route('profile.edit') }}" class="w-8 h-8 rounded-full bg-[#F7F0DB] border border-transparent flex items-center justify-center text-xs font-bold text-[#662721] shadow-sm">
                        {{ substr(Auth::user()->name, 0, 1) }}
                    </a>
                </div>

            </div>
        </div>
    </div>
</nav>

<script>
    function customerNav() {
        return {
            open: false,
            notifOpen: false,
            cartCount: 0,
            unreadNotifs: true,
            activeBooking: null,

            init() {
                this.syncCart();
                this.syncNotifications();

                // Listener realtime saat ada mutasi di session/local storage atau event window
                window.addEventListener('storage', () => this.syncCart());
                window.addEventListener('cart-updated', () => this.syncCart());

                // Cek status apakah user sudah pernah menandai notifikasi dibaca dalam 1 jam terakhir
                const lastRead = localStorage.getItem('club61_notifs_read_time');
                if (lastRead && (Date.now() - parseInt(lastRead)) < 3600000) {
                    this.unreadNotifs = false;
                }
            },

            syncCart() {
                try {
                    const raw = localStorage.getItem('club61_cart') || sessionStorage.getItem('club61_cart') || sessionStorage.getItem('vantage_cart');
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            this.cartCount = parsed.length;
                            return;
                        }
                    }
                } catch(e) {}
                this.cartCount = 0;
            },

            async syncNotifications() {
                try {
                    const res = await fetch('/api/v1/padel/my-bookings');
                    const json = await res.json();
                    if (json.success && Array.isArray(json.data)) {
                        const active = json.data.find(b => b.status === 'PAID' || b.status === 'CONFIRMED');
                        if (active) {
                            this.activeBooking = active;
                            if (!localStorage.getItem('club61_notifs_read_time')) {
                                this.unreadNotifs = true;
                            }
                        }
                    }
                } catch(e) {}
            },

            toggleNotif() {
                this.notifOpen = !this.notifOpen;
            },

            markAllRead() {
                this.unreadNotifs = false;
                localStorage.setItem('club61_notifs_read_time', Date.now().toString());
            },

            formatTime(isoString) {
                if (!isoString) return '--:--';
                try {
                    const d = new Date(isoString);
                    if (!isNaN(d.getTime())) {
                        return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
                    }
                } catch(e) {}
                return (isoString.substring(11, 16) || isoString);
            },

            formatDate(val) {
                if (!val) return '-';
                try {
                    const d = new Date(val);
                    if (!isNaN(d.getTime())) {
                        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        return `${String(d.getDate()).padStart(2, '0')} ${months[d.getMonth()]} ${d.getFullYear()}`;
                    }
                } catch(e) {}
                return String(val).substring(0, 10);
            }
        };
    }
</script>