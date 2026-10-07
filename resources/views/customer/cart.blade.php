<x-app-layout>
    @include('customer.partials.bk-style')

    <div x-data="cartApp()" x-init="init()" class="bk-page bk-bar-compact-only text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 pt-4 sm:pt-6 space-y-4 sm:space-y-5">

            {{-- Header --}}
            <div class="flex items-center gap-3">
                <a href="{{ route('customer.booking') }}" title="Back to Book Court"
                   class="bk-tap w-10 h-10 shrink-0 flex items-center justify-center rounded-lg bg-white/90 border border-[#E6DAC0] text-[#662721] hover:bg-[#F7F0DB] transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                </a>
                <div class="min-w-0">
                    <h1 class="font-display font-black text-xl sm:text-2xl leading-tight">Cart</h1>
                    <p class="hidden sm:block text-xs text-[#7A5A52] mt-0.5">Review your reserved slots before checkout.</p>
                </div>
            </div>

            {{-- Slot ditahan + sisa waktu --}}
            <div x-show="items.length > 0 && !isExpired" style="display: none;"
                 class="rounded-lg bg-[#662721] text-[#F7F0DB] shadow-[0_8px_24px_rgba(79,47,42,0.25)] overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3">
                    <span class="relative flex w-2.5 h-2.5 shrink-0">
                        <span class="absolute inline-flex w-full h-full rounded-full bg-amber-300 opacity-75 motion-safe:animate-ping"></span>
                        <span class="relative inline-flex w-2.5 h-2.5 rounded-full bg-amber-300"></span>
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-bold leading-tight">Slots held for you</div>
                        <div class="text-[11px] text-[#F7F0DB] leading-snug">Finish checkout before the timer runs out.</div>
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="font-black text-xl tabular-nums leading-none whitespace-nowrap" x-text="timerDisplay">--:--</div>
                        <div class="text-[10px] uppercase tracking-wider text-[#F7F0DB] mt-0.5">left</div>
                    </div>
                </div>
                <div class="h-1 bg-white/10"><div class="h-full bg-amber-300 transition-[width] duration-1000 ease-linear" :style="'width:' + holdProgress + '%'"></div></div>
            </div>

            {{-- Kosong --}}
            <div x-show="items.length === 0" style="display: none;" class="bk-fade-up bg-white/90 rounded-xl border border-[#E6DAC0] p-8 sm:p-12 text-center space-y-3">
                <div class="w-16 h-16 mx-auto rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center text-[#662721]">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                </div>
                <h3 class="font-display font-black text-lg">Your cart is empty</h3>
                <p class="text-xs text-[#7A5A52] max-w-xs mx-auto">Pick a date and start time on Book Court — your slots will show up here.</p>
                <a href="{{ route('customer.booking') }}"
                   class="bk-tap inline-flex items-center gap-2 h-11 px-6 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-[0_8px_20px_rgba(79,47,42,0.18)] active:scale-95 transition-transform">
                    Book a Court
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                </a>
            </div>

            <div x-show="items.length > 0" style="display: none;" class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5 items-start">

                {{-- Daftar slot --}}
                <section class="lg:col-span-7 xl:col-span-8 bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5">
                    <div class="flex items-center justify-between gap-3 mb-1">
                        <div class="min-w-0">
                            <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Reserved slots</div>
                            <div class="text-sm font-bold truncate" x-text="bookingDateFormatted"></div>
                        </div>
                        <span class="shrink-0 whitespace-nowrap px-2.5 py-1 rounded-full text-[11px] font-bold bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]"
                              x-text="items.length + (items.length === 1 ? ' session' : ' sessions')"></span>
                    </div>

                    <div class="divide-y divide-[#E6DAC0]">
                        <template x-for="(item, index) in items" :key="(item.court_id || '') + (item.start_time || index)">
                            <div class="bk-fade-up flex items-center gap-3 py-3.5">
                                <div class="w-11 h-11 shrink-0 rounded-lg bg-[#662721] text-[#F7F0DB] flex items-center justify-center">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2" stroke-width="1.8" /><path stroke-width="1.8" stroke-linecap="round" d="M4 12h16M12 3v18" /></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-extrabold text-sm leading-snug line-clamp-2" x-text="item.court || 'Court'"></div>
                                    <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-[#7A5A52] whitespace-nowrap">
                                        <span class="font-semibold tabular-nums" x-text="timeRange(item)"></span>
                                        <span x-show="item.duration_hours" class="px-1.5 py-px rounded-md bg-[#F7F0DB] border border-[#E6DAC0] font-bold text-[10px] text-[#662721]"
                                              x-text="item.duration_hours + (item.duration_hours === 1 ? ' hr' : ' hrs')"></span>
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="font-black text-sm tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(item.price)"></div>
                                    <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Held</div>
                                </div>
                                <button type="button" @click="removeItem(index)" title="Remove slot"
                                        class="bk-tap w-9 h-9 shrink-0 rounded-xl flex items-center justify-center text-rose-500 hover:bg-rose-50 hover:text-rose-700 transition-colors">
                                    <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                </button>
                            </div>
                        </template>
                    </div>

                    <a href="{{ route('customer.booking') }}" class="bk-tap mt-1 flex items-center justify-center gap-1.5 h-10 rounded-lg border border-dashed border-[#E6DAC0] text-xs font-bold text-[#662721] hover:bg-[#F7F0DB] transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 5v14M5 12h14" /></svg>
                        Add another slot
                    </a>
                </section>

                {{-- Ringkasan --}}
                <aside class="lg:col-span-5 xl:col-span-4 lg:sticky lg:top-24 bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.10)] p-4 sm:p-5 space-y-4">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Order summary</div>

                    <div class="space-y-2.5 text-xs">
                        <div class="flex items-center justify-between gap-3 text-[#662721]">
                            <span class="min-w-0">Court subtotal</span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(subtotal)"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3 text-[#662721]">
                            <span class="min-w-0">Tax &amp; service fee</span>
                            <span class="shrink-0 text-[11px] text-[#7A5A52] whitespace-nowrap">At checkout</span>
                        </div>
                        <div class="pt-3 border-t border-[#E6DAC0] flex items-center justify-between gap-3">
                            <span class="font-bold text-sm">Estimated total</span>
                            <span class="shrink-0 font-black text-lg tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(subtotal)"></span>
                        </div>
                    </div>

                    <button type="button" :disabled="isExpired" @click="proceedToCheckout()"
                            class="bk-tap hidden lg:flex w-full h-12 rounded-lg items-center justify-center gap-2 text-xs font-black uppercase tracking-wider transition-all bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-[0_8px_20px_rgba(79,47,42,0.18)]  active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed">
                        Proceed to Checkout
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                    </button>

                    <template x-if="canCancelBooking">
                        <button type="button" @click="clearAll()"
                                class="bk-tap w-full h-10 rounded-lg text-xs font-bold text-[#7A5A52] hover:text-rose-600 hover:bg-rose-50 transition-colors">
                            Empty cart
                        </button>
                    </template>

                    <p class="text-[11px] leading-relaxed text-[#7A5A52] bg-[#F7F0DB] border border-[#E6DAC0] rounded-lg p-3">
                        Slots are held for {{ app(\App\Services\Padel\BookingTimeService::class)->holdMinutes() }} minutes. If the timer runs out they go back to the public schedule.
                    </p>
                </aside>
            </div>
        </div>

        {{-- Bar bawah (HP & tablet) --}}
        <div x-show="items.length > 0" style="display: none;" class="bk-bar bk-bar-compact-only fixed inset-x-0 z-30 px-3 sm:px-8 lg:px-12 2xl:px-16 pointer-events-none">
            <div x-ref="bar" class="w-full pointer-events-auto bg-white/95 rounded-xl border border-[#E6DAC0] shadow-[0_18px_44px_rgba(79,47,42,0.22)] p-3 sm:p-4 flex items-center gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-[11px] font-bold text-[#7A5A52] truncate" x-text="items.length + (items.length === 1 ? ' session' : ' sessions') + ' · ' + timerDisplay + ' left'"></div>
                    <div class="font-black text-lg sm:text-xl leading-tight tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(subtotal)"></div>
                </div>
                <button type="button" :disabled="isExpired" @click="proceedToCheckout()"
                        class="bk-tap shrink-0 h-12 px-5 sm:px-7 rounded-lg flex items-center gap-2 text-xs sm:text-sm font-black uppercase tracking-wider whitespace-nowrap bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-[0_8px_20px_rgba(79,47,42,0.18)] active:scale-95 transition-transform disabled:opacity-50">
                    Checkout
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                </button>
            </div>
        </div>

        {{-- Waktu habis --}}
        <div x-show="showExpiredModal" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div x-show="showExpiredModal" x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
                <div class="w-14 h-14 mx-auto rounded-lg bg-rose-50 border border-rose-200 text-rose-600 flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div class="space-y-1.5">
                    <h3 class="font-display font-black text-xl">Time's up</h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed">The {{ app(\App\Services\Padel\BookingTimeService::class)->holdMinutes() }}-minute hold has ended and your slots are back on the public schedule.</p>
                </div>
                <a href="{{ route('customer.booking') }}" @click="handleExpiredRedirect()"
                   class="bk-tap flex items-center justify-center w-full h-12 rounded-lg text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform">
                    Pick a new time
                </a>
            </div>
        </div>

        {{-- Konfirmasi kosongkan keranjang --}}
        <div x-show="showClearCartModal" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
            <div x-show="showClearCartModal" x-transition.opacity.duration.200ms @click="if (!isClearingCart) showClearCartModal = false" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div x-show="showClearCartModal" x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
                <div class="w-14 h-14 mx-auto rounded-lg bg-rose-50 border border-rose-200 text-rose-600 flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                </div>
                <div class="space-y-1.5">
                    <h3 class="font-display font-black text-xl">Empty your cart?</h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed">All held slots will be released right away so other players can book them.</p>
                </div>
                <div class="rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] p-3 text-xs space-y-1.5 text-left">
                    <div class="flex justify-between gap-3"><span class="text-[#7A5A52]">Sessions</span><span class="font-bold whitespace-nowrap" x-text="items.length"></span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#7A5A52]">Total</span><span class="font-bold tabular-nums whitespace-nowrap text-[#662721]" x-text="'Rp ' + formatNumber(subtotal)"></span></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="showClearCartModal = false" :disabled="isClearingCart"
                            class="bk-tap h-12 rounded-lg border border-[#E6DAC0] bg-[#F7F0DB] text-[#662721] text-xs font-bold disabled:opacity-50">
                        Keep slots
                    </button>
                    <button type="button" @click="confirmClearAll()" :disabled="isClearingCart"
                            class="bk-tap h-12 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-black uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-60 transition-colors">
                        <span x-show="isClearingCart" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                        <span x-text="isClearingCart ? 'Releasing…' : 'Empty cart'"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Pemberitahuan --}}
        <div x-show="noticeModal.show" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
            <div x-show="noticeModal.show" x-transition.opacity.duration.200ms @click="handleNoticeClose()" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div x-show="noticeModal.show" x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
                <div class="w-14 h-14 mx-auto rounded-lg flex items-center justify-center"
                     :class="noticeModal.type === 'error' || noticeModal.type === 'danger' ? 'bg-rose-50 border border-rose-200 text-rose-600' : (noticeModal.type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-600' : 'bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721]')">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div class="space-y-1.5">
                    <h3 class="font-display font-black text-xl" x-text="noticeModal.title"></h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed" x-text="noticeModal.message"></p>
                </div>
                <button type="button" @click="handleNoticeClose()"
                        class="bk-tap w-full h-12 rounded-lg text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform">
                    <span x-text="noticeModal.buttonText || 'Got It'"></span>
                </button>
            </div>
        </div>
    </div>

    <script>
        function cartApp() {
            return {
                canCancelBooking: @json(Auth::check() && Auth::user()->canCancelBooking()),
                holdTotalMs: @js(app(\App\Services\Padel\BookingTimeService::class)->holdMinutes()) * 60 * 1000,
                noticeModal: { show: false, title: '', message: '', type: 'info', buttonText: 'Got It', onClose: null },

                showNotice(title, message, type = 'info', buttonText = 'Got It', onClose = null) {
                    this.noticeModal = { show: true, title, message, type, buttonText, onClose };
                },

                handleNoticeClose() {
                    this.noticeModal.show = false;
                    if (typeof this.noticeModal.onClose === 'function') {
                        const cb = this.noticeModal.onClose;
                        this.noticeModal.onClose = null;
                        cb();
                    }
                },

                items: [],
                bookingDateFormatted: 'Today',
                holdData: null,
                expiresAtTime: null,
                remainingMs: 0,
                timerDisplay: '--:--',
                timerInterval: null,
                isExpired: false,
                showExpiredModal: false,
                showClearCartModal: false,
                isClearingCart: false,

                init() {
                    this.syncFromStorage();
                    this.$nextTick(() => window.bkWatchBar && window.bkWatchBar(this.$root, this.$refs.bar));

                    // Multi-tab synchronizer: automatically syncs when other tabs mutate cart
                    window.addEventListener('storage', (e) => {
                        if (!e.key || e.key.includes('cart') || e.key.includes('hold_data')) {
                            this.syncFromStorage();
                        }
                    });

                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'visible') {
                            this.syncFromStorage();
                            this.checkExpiry();
                        }
                    });
                },

                get holdProgress() {
                    if (!this.holdTotalMs) return 0;
                    return Math.max(0, Math.min(100, (this.remainingMs / this.holdTotalMs) * 100));
                },

                syncFromStorage() {
                    const saved = localStorage.getItem('club61_cart') || sessionStorage.getItem('club61_cart') || sessionStorage.getItem('vantage_cart');
                    const holdSaved = localStorage.getItem('club61_hold_data') || sessionStorage.getItem('club61_hold_data') || sessionStorage.getItem('vantage_hold_data');

                    if (saved) {
                        try {
                            const parsed = JSON.parse(saved);
                            if (Array.isArray(parsed) && parsed.length > 0) {
                                this.items = parsed;
                                if (parsed[0].booking_date) {
                                    this.bookingDateFormatted = this.formatDate(parsed[0].booking_date);
                                }
                            } else {
                                this.items = [];
                            }
                        } catch(e) {
                            this.items = [];
                        }
                    } else {
                        this.items = [];
                    }

                    if (holdSaved) {
                        try {
                            this.holdData = JSON.parse(holdSaved);
                            if (this.holdData && this.holdData.expires_at) {
                                this.expiresAtTime = new Date(this.holdData.expires_at).getTime();
                            }
                        } catch(e) {}
                    }

                    // Fallback kalau expires_at tidak ada: waktu tahan slot dari pengaturan admin.
                    if (!this.expiresAtTime && this.items.length > 0) {
                        this.expiresAtTime = Date.now() + this.holdTotalMs;
                    }

                    // GUARDRAIL 1: Run Timer
                    if (this.items.length > 0) {
                        this.startCountdown();
                    } else if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                        this.timerInterval = null;
                    }
                },

                startCountdown() {
                    if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                        this.timerInterval = null;
                    }
                    this.checkExpiry();
                    this.timerInterval = setInterval(() => {
                        this.checkExpiry();
                    }, 1000);
                },

                async checkExpiry() {
                    if (!this.expiresAtTime) return;

                    const diffMs = this.expiresAtTime - Date.now();
                    this.remainingMs = Math.max(0, diffMs);

                    if (diffMs <= 0) {
                        this.isExpired = true;
                        this.timerDisplay = '00:00';
                        if (this.timerInterval) clearInterval(this.timerInterval);
                        await this.handleSessionExpired();
                        return;
                    }

                    const totalSeconds = Math.floor(diffMs / 1000);
                    const minutes = Math.floor(totalSeconds / 60);
                    const seconds = totalSeconds % 60;

                    this.timerDisplay = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                },

                async handleSessionExpired() {
                    this.showExpiredModal = true;

                    const bookingIds = (this.holdData && this.holdData.bookings)
                        ? this.holdData.bookings.map(b => b.id)
                        : [];

                    if (bookingIds.length > 0) {
                        try {
                            await fetch('/api/v1/padel/release-slot', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify({ booking_ids: bookingIds, only_locked: true })
                            });
                        } catch(e) {
                            console.error('Error auto-releasing expired cart slots:', e);
                        }
                    }

                    this.clearStorage();
                },

                handleExpiredRedirect() {
                    this.handleSessionExpired();
                },

                clearStorage() {
                    ['club61_cart', 'club61_hold_data', 'vantage_cart', 'vantage_hold_data'].forEach(k => {
                        try { localStorage.removeItem(k); sessionStorage.removeItem(k); } catch (e) {}
                    });
                    window.dispatchEvent(new CustomEvent('cart-updated'));
                },

                get subtotal() {
                    return this.items.reduce((sum, item) => sum + (Number(item.price) || 0), 0);
                },

                timeRange(item) {
                    if (item.start_time && item.end_time) return `${item.start_time}–${item.end_time}`;
                    return String(item.time || '').replace(/\s*\(.*\)\s*$/, '');
                },

                // Booking hold milik item keranjang. Server mengurutkan booking per lapangan lalu jam (bukan urutan
                // keranjang), jadi dicocokkan lewat lapangan + jam mulai; indeks hanya cadangan untuk data lama.
                holdBookingFor(item, idx) {
                    const bookings = (this.holdData && Array.isArray(this.holdData.bookings)) ? this.holdData.bookings : [];
                    const fmt = new Intl.DateTimeFormat('en-GB', { timeZone: @js(config('app.timezone')), hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
                    const match = bookings.find(b => {
                        if (!b || b.court_id !== item.court_id || !b.start_time) return false;
                        const d = new Date(b.start_time);
                        return !isNaN(d.getTime()) && fmt.format(d) === item.start_time;
                    });
                    return match || bookings[idx] || null;
                },

                async removeItem(idx) {
                    // Booking hold-nya ikut dibuang dari data hold: kalau tidak, checkout tetap mengirim booking yang
                    // sudah dilepas dan hapus slot berikutnya bisa melepas booking yang salah.
                    const bookingMatch = this.holdBookingFor(this.items[idx], idx);
                    this.items.splice(idx, 1);
                    if (bookingMatch && this.holdData && Array.isArray(this.holdData.bookings)) {
                        this.holdData.bookings = this.holdData.bookings.filter(b => b !== bookingMatch);
                    }

                    if (this.items.length === 0) {
                        this.clearStorage();
                        this.holdData = null;
                        this.expiresAtTime = null;
                        if (this.timerInterval) { clearInterval(this.timerInterval); this.timerInterval = null; }
                    } else {
                        localStorage.setItem('club61_cart', JSON.stringify(this.items));
                        sessionStorage.setItem('club61_cart', JSON.stringify(this.items));
                        if (this.holdData) {
                            localStorage.setItem('club61_hold_data', JSON.stringify(this.holdData));
                            sessionStorage.setItem('club61_hold_data', JSON.stringify(this.holdData));
                        }
                        window.dispatchEvent(new CustomEvent('cart-updated'));
                    }

                    // Release slot on backend via API
                    if (bookingMatch && bookingMatch.id) {
                        try {
                            await fetch('/api/v1/padel/release-slot', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify({ booking_ids: [bookingMatch.id], only_locked: true })
                            });
                        } catch(e) {}
                    }
                },

                clearAll() {
                    if (!this.canCancelBooking) {
                        this.showNotice('Access Denied', 'Your account role does not have permission to cancel this booking.', 'error', 'Close');
                        return;
                    }
                    this.showClearCartModal = true;
                },

                async confirmClearAll() {
                    if (!this.canCancelBooking) {
                        this.showNotice('Access Denied', 'Your account role does not have permission to cancel this booking.', 'error', 'Close');
                        return;
                    }
                    this.isClearingCart = true;
                    const bookingIds = (this.holdData && this.holdData.bookings)
                        ? this.holdData.bookings.map(b => b.id)
                        : [];

                    if (bookingIds.length > 0) {
                        try {
                            await fetch('/api/v1/padel/release-slot', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify({ booking_ids: bookingIds, only_locked: true })
                            });
                        } catch(e) {}
                    }

                    this.items = [];
                    this.holdData = null;
                    this.expiresAtTime = null;
                    if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                        this.timerInterval = null;
                    }

                    this.clearStorage();

                    this.isClearingCart = false;
                    this.showClearCartModal = false;
                },

                formatNumber(val) {
                    if (!val) return '0';
                    return Math.round(Number(val)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
                },

                // "2026-10-05" → "Mon, 5 Oct 2026" (diurai manual supaya tidak bergeser zona waktu).
                formatDate(val) {
                    if (!val) return '-';
                    const m = String(val).match(/^(\d{4})-(\d{2})-(\d{2})/);
                    if (!m) return String(val).substring(0, 10);
                    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
                    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return `${days[d.getDay()]}, ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
                },

                proceedToCheckout() {
                    if (this.isExpired) {
                        this.showExpiredModal = true;
                        return;
                    }
                    localStorage.setItem('club61_cart', JSON.stringify(this.items));
                    sessionStorage.setItem('club61_cart', JSON.stringify(this.items));
                    window.location.href = "{{ route('customer.checkout') }}";
                }
            }
        }
    </script>
</x-app-layout>
