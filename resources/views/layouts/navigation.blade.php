<nav x-data="customerNav()" x-init="init()" class="bg-[#662721] border-b border-[#511D18] sticky top-0 z-30">
    <!-- Primary Navigation Menu -->
    <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16">
        <div class="flex justify-between h-16 lg:h-[72px]">
            <div class="flex items-center gap-4">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                        <img src="{{ asset('images/identity/monogram-cream.png') }}" alt="Club 61 Padel &amp; Wellness" class="h-10 lg:h-11 w-auto shrink-0">
                        {{-- Tablet (md): tulisan disembunyikan, cukup monogram — 5 menu + ikon kanan tidak muat di 768–1023px. --}}
                        <span class="leading-none whitespace-nowrap md:hidden lg:block">
                            <span class="block font-bold text-[16px] sm:text-lg tracking-[0.18em] text-[#F7F0DB]" style="font-stretch: 125%;">CLUB 61</span>
                            <span class="block text-[9px] font-semibold tracking-[0.32em] text-[#F7F0DB]/70 mt-1">PADEL &amp; WELLNESS</span>
                        </span>
                    </a>
                </div>

                <!-- Navigation Links (Desktop) -->
                <div class="hidden md:flex space-x-4 lg:space-x-5 -my-px ms-2 lg:ms-6">
                    <a href="{{ route('dashboard') }}" 
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('dashboard') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        {{ __('nav.home') }}
                    </a>
                    <a href="{{ route('customer.booking') }}" 
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.booking') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        {{ __('nav.book_court') }}
                    </a>
                    {{-- Modul 23: My Club = profil klub, Membership = beli paket (menu sendiri). Kartu member &
                         voucher milik customer ada di Profile / Voucher Saya (dropdown akun). --}}
                    <a href="{{ route('customer.my-club') }}"
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.my-club') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        {{ __('nav.my_club') }}
                    </a>
                    <a href="{{ route('customer.membership') }}"
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.membership') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        {{ __('nav.membership') }}
                    </a>
                    <a href="{{ route('customer.coaching') }}"
                       class="hidden xl:inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.coaching') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        {{ __('nav.coaching') }}
                    </a>
                    <a href="{{ route('customer.invoice') }}"
                       class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.invoice') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                        {{ __('nav.invoice') }}
                    </a>
                    @if(Auth::check() && \App\Models\Sponsor\SponsorOrganization::where('sponsor_admin_user_id', Auth::id())->exists())
                        <a href="{{ route('customer.corporate') }}"
                           class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('customer.corporate') ? 'border-[#F7F0DB] text-[#F7F0DB]' : 'border-transparent text-[#F7F0DB]/70 hover:text-[#F7F0DB] hover:border-[#F7F0DB]/40' }} text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-colors">
                            {{ __('nav.sponsor_team') }}
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
                            title="{{ __('nav.notif.title') }}">
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
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">{{ __('nav.notif.title') }}</h4>
                                <span class="text-[10px] text-[#662721] font-bold uppercase tracking-wider">{{ __('nav.notif.concierge') }}</span>
                            </div>
                            <button type="button" 
                                    @click="markAllRead()"
                                    class="text-[10px] font-bold text-[#662721] hover:text-[#4F2F2A] hover:underline px-2 py-1 rounded-lg hover:bg-[#F7F0DB] transition-colors cursor-pointer">
                                {{ __('nav.notif.mark_read') }}
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
                                                <span class="text-xs font-black text-emerald-900">{{ __('nav.notif.match_title') }}</span>
                                                <span class="text-[9px] font-mono font-bold text-emerald-700 bg-emerald-200/70 px-1.5 py-0.5 rounded">{{ __('nav.notif.confirmed') }}</span>
                                            </div>
                                            <p class="text-[11px] text-emerald-800 mt-1 leading-snug">
                                                <span class="font-bold" x-text="activeBooking.court_name || @js(__('nav.notif.default_court'))"></span>
                                                &bull; <span x-text="formatDate(activeBooking.booking_date)"></span>
                                                (<span x-text="formatTime(activeBooking.start_time)"></span> WIB)
                                            </p>
                                            <span class="text-[10px] font-bold text-emerald-700 underline mt-1 inline-block">{{ __('nav.notif.view_ticket') }} &rarr;</span>
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
                                                <span class="text-xs font-black text-[#662721]">{{ __('nav.notif.cart_title') }}</span>
                                                <span class="text-[9px] font-bold text-amber-800 bg-amber-200/80 px-1.5 py-0.5 rounded animate-pulse">{{ __('nav.notif.hold') }}</span>
                                            </div>
                                            <p class="text-[11px] text-[#7A5A52] mt-1 leading-snug">
                                                {{ __('nav.notif.cart_body_before') }} <strong class="text-[#4F2F2A]" x-text="cartCount"></strong> {{ __('nav.notif.cart_body_after') }}
                                            </p>
                                            <span class="text-[10px] font-bold text-[#662721] underline mt-1 inline-block">{{ __('nav.notif.view_cart') }} &rarr;</span>
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
                                            <span class="text-xs font-black text-[#4F2F2A]">{{ __('nav.notif.welcome_title') }}</span>
                                        </div>
                                        <p class="text-[11px] text-[#7A5A52] mt-1 leading-snug">
                                            {{ __('nav.notif.welcome_body', ['address' => \App\Models\Setting\CompanyProfileSetting::receiptAddress()]) }}
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
                                            <span class="text-xs font-black text-[#4F2F2A]">{{ __('nav.notif.wellness_title') }}</span>
                                            <span class="text-[9px] font-mono text-emerald-700 font-bold bg-emerald-100 px-1 py-0.5 rounded">{{ __('nav.notif.wellness_badge') }}</span>
                                        </div>
                                        <p class="text-[11px] text-[#7A5A52] mt-1 leading-snug">
                                            {{ __('nav.notif.wellness_body') }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                        </div>

                        {{-- Tombol "Contact WhatsApp Concierge" dihapus — kontak lewat tombol WhatsApp melayang. --}}
                    </div>
                </div>

                <!-- Cart Button with Dynamic Counter Badge (Screen 2 & 3) -->
                <a href="{{ route('customer.cart') }}" 
                   class="p-2 rounded-xl text-[#F7F0DB] hover:text-white hover:bg-white/10 relative transition-colors"
                   title="{{ __('nav.cart') }}">
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
                        {{ __('nav.admin') }}
                    </a>
                @endif

                {{-- Tombol bahasa ID / EN di navbar: hanya layar lebar (>=1280px) supaya menu tidak berdesakan di tablet;
                     di layar lebih kecil tombolnya ada di menu akun. --}}
                <div class="c61-nav-lang items-center">
                    <x-lang-switch />
                </div>

                <!-- Akun: satu tombol (avatar + nama + status member) → dropdown Profile / Voucher Saya / Logout (Modul 23) -->
                @php
                    $navAccount = app(\App\Services\Customer\CustomerAccountService::class);
                    $navActiveMembership = $navAccount->activeMembership(Auth::user());
                    $navVoucherCount = $navAccount->availableVoucherCount(Auth::user());
                    $navInitial = mb_strtoupper(mb_substr(Auth::user()->name, 0, 1));
                    $navMemberLabel = $navActiveMembership && $navActiveMembership->plan
                        ? ($navActiveMembership->end_date
                            ? __('nav.member_until', ['plan' => $navActiveMembership->plan->name, 'date' => \Carbon\Carbon::parse($navActiveMembership->end_date)->translatedFormat('d M Y')])
                            : $navActiveMembership->plan->name)
                        : __('nav.not_member');
                @endphp
                <div class="relative" @click.outside="accountOpen = false" @keydown.escape.window="accountOpen = false">
                    <button type="button" @click="accountOpen = ! accountOpen" :aria-expanded="accountOpen.toString()" aria-haspopup="menu"
                            class="flex items-center gap-2.5 ms-1 sm:ms-2 rounded-xl p-0.5 lg:pe-2 hover:bg-white/10 transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#F7F0DB]/60"
                            title="{{ Auth::user()->name }}">
                        <span class="relative w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-[#F7F0DB] flex items-center justify-center text-xs font-bold text-[#662721] shadow-sm shrink-0">
                            {{ $navInitial }}
                            @if($navVoucherCount > 0)
                                <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-amber-500 ring-2 ring-[#662721]"></span>
                            @endif
                        </span>
                        <span class="hidden lg:block text-left whitespace-nowrap max-w-[170px]">
                            <span class="block text-xs font-bold text-[#F7F0DB] truncate">{{ Auth::user()->name }}</span>
                            <span class="block text-[9px] font-mono uppercase truncate {{ $navActiveMembership ? 'text-[#E6DAC0]' : 'text-[#F7F0DB]/60' }}">{{ $navActiveMembership?->plan?->name ?? __('nav.not_member') }}</span>
                        </span>
                        <svg class="hidden lg:block w-3.5 h-3.5 text-[#F7F0DB]/70 transition-transform" :class="accountOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" /></svg>
                    </button>

                    <!-- HP: panel lebar penuh di bawah header. Tablet / desktop: dropdown kecil di kanan. -->
                    <div x-show="accountOpen" x-cloak role="menu"
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="fixed sm:absolute left-0 right-0 sm:left-auto top-16 sm:top-auto sm:right-0 sm:mt-3 sm:w-80 bg-white sm:rounded-xl border-b sm:border border-[#E6DAC0] shadow-[0_20px_50px_rgba(79,47,42,0.25)] text-[#4F2F2A] z-50 overflow-hidden"
                         style="display: none;">
                        <div class="flex items-center gap-3 p-4 bg-[#FCF8EE] border-b border-[#E6DAC0]">
                            <span class="w-11 h-11 rounded-full bg-[#662721] text-[#F7F0DB] flex items-center justify-center font-bold shrink-0">{{ $navInitial }}</span>
                            <div class="min-w-0">
                                <div class="text-sm font-black truncate">{{ Auth::user()->name }}</div>
                                <div class="text-[11px] text-[#7A5A52] truncate">{{ \App\Support\PlaceholderEmail::is(Auth::user()->email) ? (Auth::user()->phone ?? '') : Auth::user()->email }}</div>
                                <div class="mt-1 inline-flex items-center gap-1.5 text-[10px] font-bold {{ $navActiveMembership ? 'text-emerald-700' : 'text-[#7A5A52]' }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $navActiveMembership ? 'bg-emerald-500' : 'bg-[#C9B99A]' }}"></span>
                                    {{ $navMemberLabel }}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-2 border-b border-[#E6DAC0]">
                            <span class="text-xs font-bold text-[#7A5A52]">{{ __('common.language') }}</span>
                            <x-lang-switch variant="light" />
                        </div>
                        <div class="p-2">
                            <a href="{{ route('profile.edit') }}" role="menuitem" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold hover:bg-[#F7F0DB] transition-colors">
                                <svg class="w-4 h-4 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                {{ __('nav.profile') }}
                            </a>
                            <a href="{{ route('profile.vouchers') }}" role="menuitem" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold hover:bg-[#F7F0DB] transition-colors">
                                <svg class="w-4 h-4 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" /></svg>
                                <span class="flex-1">{{ __('nav.my_vouchers') }}</span>
                                @if($navVoucherCount > 0)
                                    <span class="min-w-[22px] h-[22px] px-1.5 rounded-full bg-[#662721] text-[#F7F0DB] text-[11px] font-black flex items-center justify-center">{{ $navVoucherCount }}</span>
                                @endif
                            </a>
                            {{-- Bottom nav HP penuh (5 menu) & navbar tab (768–1279px) tidak muat → Coaching lewat dropdown akun di bawah 1280px. --}}
                            <a href="{{ route('customer.coaching') }}" role="menuitem" class="xl:hidden flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold hover:bg-[#F7F0DB] transition-colors">
                                <svg class="w-4 h-4 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                {{ __('nav.coaching') }}
                            </a>
                            {{-- Tim Sponsor (PIC corporate): di HP menu atas disembunyikan & bottom nav sudah penuh → lewat dropdown akun. --}}
                            @if(\App\Models\Sponsor\SponsorOrganization::where('sponsor_admin_user_id', Auth::id())->exists())
                                <a href="{{ route('customer.corporate') }}" role="menuitem" data-nav-sponsor-mobile class="md:hidden flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold hover:bg-[#F7F0DB] transition-colors">
                                    <svg class="w-4 h-4 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                    {{ __('nav.sponsor_team') }}
                                </a>
                            @endif
                            @if(Auth::user()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))
                                <a href="/admin" role="menuitem" class="sm:hidden flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold hover:bg-[#F7F0DB] transition-colors">
                                    <svg class="w-4 h-4 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    {{ __('nav.admin_panel') }}
                                </a>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="p-2 border-t border-[#E6DAC0]">
                            @csrf
                            <button type="submit" role="menuitem" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold text-rose-700 hover:bg-rose-50 transition-colors cursor-pointer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                                {{ __('nav.logout') }}
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</nav>

<style>
    .c61-nav-lang { display: none; }
    @media (min-width: 1280px) { .c61-nav-lang { display: flex; } }
</style>

<script>
    function customerNav() {
        return {
            open: false,
            notifOpen: false,
            accountOpen: false,
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
                        const months = @js(app()->getLocale() === 'id'
                            ? ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
                            : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
                        return `${String(d.getDate()).padStart(2, '0')} ${months[d.getMonth()]} ${d.getFullYear()}`;
                    }
                } catch(e) {}
                return String(val).substring(0, 10);
            }
        };
    }
</script>