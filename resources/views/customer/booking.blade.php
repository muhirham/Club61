<x-app-layout>
    @include('customer.partials.bk-style')
    <style>
        /* Grid jadwal: lapangan = kolom, jam = baris. Kolom jam & judul lapangan menempel saat digeser. */
        .bk-grid { scroll-snap-type: x proximity; scroll-padding-left: 64px; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
        .bk-grid::-webkit-scrollbar { height: 6px; width: 6px; }
        .bk-grid::-webkit-scrollbar-thumb { background: #CDBB9C; border-radius: 999px; }
        .bk-col { scroll-snap-align: start; }
        @media (min-width: 640px) { .bk-grid { scroll-padding-left: 80px; } }
    </style>

    <div x-data="bookingCourtApp()" x-init="init()" class="bk-page text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 pt-4 sm:pt-6 space-y-4 sm:space-y-5">

            {{-- Header --}}
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('dashboard') }}" title="{{ __('booking.back_home') }}"
                   class="bk-tap hidden sm:flex w-10 h-10 shrink-0 items-center justify-center rounded-lg bg-white/90 border border-[#E6DAC0] text-[#662721] hover:bg-[#F7F0DB] transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                </a>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="font-display font-black text-xl sm:text-2xl">{{ __('booking.title') }}</h1>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 motion-safe:animate-pulse"></span>{{ __('booking.live') }}
                        </span>
                    </div>
                    <p class="hidden sm:block text-xs text-[#7A5A52] mt-0.5">{{ __('booking.subtitle') }}</p>
                </div>
            </div>

            <div class="flex flex-col lg:flex-row gap-4 sm:gap-5 items-start">
                {{-- Jadwal: tanggal + grid lapangan --}}
                <section class="w-full lg:flex-1 min-w-0 bg-white/95 rounded-xl border border-[#E6DAC0] shadow-[0_12px_36px_rgba(79,47,42,0.10)] overflow-hidden">

                    {{-- Tanggal --}}
                    <div class="flex items-stretch gap-2 p-2.5 sm:p-3 border-b border-[#E6DAC0]">
                        {{-- Input tanggal asli transparan DI ATAS ikon & bisa disentuh: iPhone / iPad (semua browser iOS = WebKit)
                             hanya membuka pemilih tanggal bila input disentuh langsung — showPicker() / focus() dari tombol lain
                             tidak membukanya. Desktop: klik tetap membuka kalender lewat showPicker(). --}}
                        <div title="{{ __('booking.calendar_title') }}" data-calendar-picker
                             class="bk-tap relative shrink-0 w-11 sm:w-12 rounded-lg bg-[#F7F0DB] text-[#662721] flex items-center justify-center transition-colors">
                            <svg class="w-5 h-5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                            <input type="date" x-ref="calendarInput" :min="minDate" :max="maxDate" :value="activeDate" aria-label="{{ __('booking.calendar_open') }}"
                                   @click="openCalendarPicker()" @change="onCalendarSelect($event.target.value)"
                                   class="absolute inset-0 block w-full h-full min-w-0 opacity-0 cursor-pointer" style="font-size:16px;">
                        </div>
                        <div id="date-tabs-slider" class="bk-scroll flex-1 min-w-0 flex gap-1.5 overflow-x-auto">
                            <template x-for="tab in dateTabs" :key="tab.date">
                                <button type="button" :id="'date-tab-' + tab.date" @click="selectDate(tab.date)" :aria-pressed="activeDate === tab.date"
                                        :class="activeDate === tab.date
                                            ? 'bg-[#662721] text-[#F7F0DB] shadow-[0_6px_16px_rgba(79,47,42,0.28)]'
                                            : 'text-[#7A5A52] hover:bg-[#F7F0DB]'"
                                        class="bk-tap bk-snap shrink-0 w-[60px] sm:w-[66px] py-2 rounded-lg text-center transition-all duration-200 active:scale-95">
                                    <span class="block text-[10px] font-bold uppercase tracking-wide opacity-80" x-text="tab.day"></span>
                                    <span class="block text-[15px] font-black leading-tight" x-text="tab.dayNum + ' ' + tab.month"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Legenda --}}
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5 border-b border-[#E6DAC0] text-[11px] text-[#7A5A52]">
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-md bg-white border border-[#CDBB9C]"></span>{{ __('booking.legend_available') }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-md bg-[#662721]"></span>{{ __('booking.legend_selected') }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-md bg-amber-100 border border-amber-300"></span>{{ __('booking.legend_locked') }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-md bg-[#EEE5D3]"></span>{{ __('booking.legend_booked') }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-[#662721] hover:bg-[#511D18]"></span>{{ __('booking.legend_prime') }}</span>
                    </div>

                    {{-- Tanggal tutup / belum dibuka (App\Support\BookingWindow) --}}
                    <div x-show="!isLoading && notice" style="display:none; padding:0.75rem 1rem; border-bottom:1px solid #FECACA; background:#FEF2F2; color:#991B1B; font-size:13px; font-weight:600;" x-text="notice"></div>

                    {{-- Loading --}}
                    <div x-show="isLoading" class="p-4">
                        <div class="grid grid-cols-4 gap-2">
                            <template x-for="i in 16" :key="i"><div class="bk-skeleton h-[52px] rounded-xl"></div></template>
                        </div>
                    </div>

                    {{-- Kosong --}}
                    <div x-show="!isLoading && courts.length === 0" class="p-8 text-center text-sm text-[#7A5A52]">
                        {{ __('booking.no_courts') }}
                    </div>

                    {{-- Grid lapangan x jam --}}
                    <div x-show="!isLoading && courts.length > 0" class="bk-grid overflow-auto max-h-[62vh] sm:max-h-[64vh] lg:max-h-[calc(100vh-260px)] min-h-[320px]">
                        <div :style="'min-width: ' + gridMinWidth + 'px'">
                            {{-- Judul lapangan --}}
                            <div class="grid sticky top-0 z-20 bg-[#F7F0DB] border-b border-[#E6DAC0]" :style="gridColumns">
                                <div class="sticky left-0 z-10 bg-[#F7F0DB] border-r border-[#E6DAC0]"></div>
                                <template x-for="court in courts" :key="court.court_id">
                                    <div class="bk-col relative px-1.5 py-2.5 text-center border-r border-[#E6DAC0] last:border-r-0" x-data="{ info: false }">
                                        <button type="button" @click="info = !info" @click.outside="info = false"
                                                class="bk-tap inline-flex items-center justify-center gap-1 max-w-full text-xs sm:text-[13px] font-extrabold text-[#4F2F2A] leading-tight"
                                                :aria-label="t('court_details', { court: court.court_name })">
                                            <span class="line-clamp-2 break-words" x-text="court.court_name"></span>
                                            <svg class="w-3.5 h-3.5 shrink-0 text-[#A08F86]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-width="2" /><path stroke-linecap="round" stroke-width="2" d="M12 11v5m0-8h.01" /></svg>
                                        </button>
                                        <div class="text-[10px] font-bold" :class="availableCount(court) > 0 ? 'text-emerald-700' : 'text-rose-600'"
                                             x-text="availableCount(court) > 0 ? t('open_count', { count: availableCount(court) }) : T.full"></div>
                                        <div x-show="info" x-transition.opacity style="display: none;"
                                             class="absolute left-1/2 -translate-x-1/2 top-full mt-1 z-30 w-52 p-3 rounded-lg bg-white border border-[#E6DAC0] shadow-[0_12px_30px_rgba(79,47,42,0.18)] text-left">
                                            <div class="text-xs font-extrabold" x-text="court.court_name"></div>
                                            <div class="text-[11px] text-[#7A5A52] mt-1 leading-relaxed" x-text="court.description || T.court_fallback_desc"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div x-show="pastNote" class="sticky left-0 flex items-center gap-2 px-4 py-2 bg-[#FCF8EE] border-b border-[#E6DAC0] text-[11px] text-[#7A5A52]">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                <span x-text="pastNote"></span>
                            </div>

                            {{-- Jam dikelompokkan: siang 06–17 WIB & malam 17–00 WIB --}}
                            <template x-for="group in rowGroups" :key="activeDate + '-' + group.key">
                                <div>
                                    <div class="px-3 sm:px-4 py-1.5 bg-[#F7F0DB] border-b border-[#E6DAC0]">
                                        <span class="sticky left-3 inline-block text-[10px] font-extrabold uppercase tracking-wider text-[#662721]" x-text="group.label"></span>
                                    </div>
                                    <template x-for="row in group.rows" :key="activeDate + '-' + row.time">
                                        <div class="grid border-b border-[#E6DAC0]" :style="gridColumns">
                                            <div class="sticky left-0 z-10 bg-white border-r border-[#E6DAC0] flex items-center justify-center text-xs sm:text-[13px] font-bold tabular-nums text-[#7A5A52]" x-text="row.time"></div>
                                            <template x-for="(cell, ci) in row.cells" :key="ci">
                                                <div class="p-1 border-r border-[#E6DAC0] last:border-r-0">
                                                    <template x-if="cell && cell.status === 'AVAILABLE'">
                                                        <button type="button" @click="handleSlotClick(ci, row.index)"
                                                                :aria-pressed="isSlotSelected(cell.court_id, cell.local_start)"
                                                                :aria-label="cell.court_name + ' ' + cell.local_start + ' Rp ' + formatNumber(cell.price)"
                                                                :class="slotButtonClass(cell.court_id, cell.local_start)"
                                                                class="bk-tap relative w-full h-12 sm:h-[52px] rounded-xl border text-left px-2.5 text-[13px] sm:text-sm font-extrabold tabular-nums transition-all duration-150 active:scale-95">
                                                            <span x-show="cell.is_prime_time" class="absolute top-1.5 right-1.5 w-1.5 h-1.5 rounded-full bg-[#662721] hover:bg-[#511D18]"></span>
                                                            <span x-text="formatK(cell.price)"></span>
                                                        </button>
                                                    </template>
                                                    <template x-if="cell && cell.status !== 'AVAILABLE'">
                                                        <div :class="cell.status === 'LOCKED' ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-[#EEE5D3] border-transparent text-[#A08F86]'"
                                                             class="w-full h-12 sm:h-[52px] rounded-xl border px-2.5 pt-1.5 text-[11px] font-semibold cursor-not-allowed select-none"
                                                             x-text="statusLabel(cell.status)"></div>
                                                    </template>
                                                    <template x-if="!cell"><div class="w-full h-12 sm:h-[52px]"></div></template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div x-show="rowGroups.length === 0" class="py-8 text-center text-sm text-[#7A5A52]">{{ __('booking.no_open_hours') }}</div>
                        </div>
                    </div>
                </section>

            </div>
        </div>

        {{-- Ringkasan & lanjut (menempel di atas navigasi bawah) --}}
        <div class="bk-bar fixed inset-x-0 z-30 px-3 sm:px-8 lg:px-12 2xl:px-16 pointer-events-none">
            <div x-ref="bar" class="w-full pointer-events-auto bg-white/95 rounded-xl border border-[#E6DAC0] shadow-[0_18px_44px_rgba(79,47,42,0.22)] p-3 sm:p-4 flex items-center gap-3">
                <div class="flex-1 min-w-0">
                    <template x-if="selectedSlots.length === 0">
                        <div>
                            <div class="text-sm font-bold text-[#4F2F2A]">{{ __('booking.bar_empty_title') }}</div>
                            <div class="text-[11px] text-[#7A5A52] truncate" x-text="dateLabel(activeDate) + ' · ' + T.box_hint"></div>
                        </div>
                    </template>
                    <template x-if="selectedSlots.length > 0">
                        <div class="bk-fade-up min-w-0">
                            <div class="text-[11px] font-bold text-[#4F2F2A] truncate" x-text="selectionHeadline"></div>
                            <div class="text-[11px] font-semibold text-[#662721] truncate" x-text="selectionDetail"></div>
                            <div class="font-black text-lg sm:text-xl leading-tight tabular-nums text-[#4F2F2A]" x-text="'Rp ' + formatNumber(totalPrice)"></div>
                        </div>
                    </template>
                </div>
                <button type="button" x-show="selectedSlots.length > 0" @click="selectedSlots = []" title="{{ __('booking.clear_selection') }}" aria-label="{{ __('booking.clear_selection') }}"
                        class="bk-tap shrink-0 w-10 h-10 rounded-lg border border-[#E6DAC0] text-[#9A3412] hover:bg-rose-50 flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
                <button type="button" :disabled="selectedSlots.length === 0 || isHolding" @click="proceedToHoldAndCart()"
                        :class="selectedSlots.length > 0
                            ? 'bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-[0_8px_20px_rgba(79,47,42,0.18)]  active:scale-95'
                            : 'bg-[#EEE5D3] text-[#A08F86] cursor-not-allowed'"
                        class="bk-tap shrink-0 h-12 px-5 sm:px-7 rounded-lg text-xs sm:text-sm font-black uppercase tracking-wider transition-all flex items-center gap-2">
                    <svg x-show="isHolding" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span x-text="isHolding ? T.reserving : T.continue"></span>
                    <svg x-show="!isHolding" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                </button>
            </div>
        </div>

        {{-- Pemberitahuan: bottom sheet di HP, dialog di layar lebar --}}
        <div x-show="noticeModal.show" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
            <div x-show="noticeModal.show" x-transition.opacity.duration.200ms @click="handleNoticeClose()" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div x-show="noticeModal.show"
                 x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0" x-transition:enter-end="translate-y-0 sm:opacity-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0 sm:opacity-100" x-transition:leave-end="translate-y-full sm:translate-y-4 sm:opacity-0"
                 class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
                <div class="sm:hidden mx-auto w-10 h-1.5 rounded-full bg-[#E6DAC0]"></div>
                <div class="w-14 h-14 rounded-lg flex items-center justify-center mx-auto"
                     :class="{
                         'bg-[#F7F0DB] text-[#662721]': noticeModal.type === 'info' || noticeModal.type === 'gold',
                         'bg-rose-50 text-rose-600': noticeModal.type === 'error' || noticeModal.type === 'danger',
                         'bg-emerald-50 text-emerald-600': noticeModal.type === 'success'
                     }">
                    <svg x-show="noticeModal.type === 'error' || noticeModal.type === 'danger'" class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    <svg x-show="noticeModal.type === 'success'" class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <svg x-show="noticeModal.type === 'info' || noticeModal.type === 'gold'" class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div class="space-y-1.5">
                    <h3 class="font-display font-black text-lg" x-text="noticeModal.title"></h3>
                    <p class="text-sm text-[#7A5A52] leading-relaxed" x-text="noticeModal.message"></p>
                </div>
                <button type="button" @click="handleNoticeClose()" x-text="noticeModal.buttonText"
                        class="bk-tap w-full h-12 rounded-lg text-sm font-black uppercase tracking-wider text-[#F7F0DB] bg-[#662721] hover:bg-[#511D18] active:scale-[0.98] transition-transform"></button>
            </div>
        </div>
    </div>

    {{-- Update realtime (Reverb): jadwal tanggal yang dibuka ikut berubah saat ada yang booking. Tanpa Reverb → tiap 60 dtk saat tab terlihat. --}}
    @php($realtime = \App\Support\Realtime::client())
    @if ($realtime)
        <script src="{{ asset('js/club61-live.js') }}?v={{ filemtime(public_path('js/club61-live.js')) }}"></script>
        <script>window.BK_REALTIME = @json($realtime);</script>
    @endif
    <script>
        function bookingCourtApp() {
            // Teks terjemahan (lang/{id,en}/booking.php kunci "js") + locale tanggal untuk Intl.
            const T = Object.assign(@js(__('booking.js')), { got_it: @js(__('common.got_it')), close: @js(__('common.close')) });
            const LOCALE = @js(app()->getLocale() === 'id' ? 'id-ID' : 'en-US');
            const t = (key, params = {}) => Object.keys(params).reduce((s, k) => s.split(':' + k).join(params[k]), T[key] || key);
            const dayName = (d) => d.toLocaleDateString(LOCALE, { weekday: 'short' });
            const monthName = (d) => d.toLocaleDateString(LOCALE, { month: 'short' });

            // Di luar state Alpine (tidak perlu reaktif).
            let live = null;
            let liveRefreshTimer = null;
            let fetchSeq = 0;

            return {
                T,
                t,
                courts: [],
                dateTabs: [],
                activeDate: '{{ now()->format("Y-m-d") }}',
                minDate: '{{ now()->format("Y-m-d") }}',
                maxDate: '{{ now()->addDays(\App\Services\Padel\PadelBookingService::MAX_DAYS_AHEAD)->format("Y-m-d") }}',
                // Tiap kotak = 1 jam; jam berurutan di lapangan yang sama digabung jadi satu sesi saat lanjut.
                selectedSlots: [],
                matrixRows: [],
                notice: '',
                isLoading: true,
                isHolding: false,
                // HP: kolom lebih sempit supaya 3 lapangan kelihatan sekaligus; ikut berubah saat layar diputar.
                narrow: !window.matchMedia('(min-width: 640px)').matches,

                noticeModal: { show: false, title: '', message: '', type: 'info', buttonText: T.got_it, onClose: null },

                showNotice(title, message, type = 'info', buttonText = T.got_it, onClose = null) {
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

                init() {
                    this.generateDateTabs();
                    this.fetchSchedule();
                    this.startLiveUpdates();
                    window.matchMedia('(min-width: 640px)').addEventListener('change', (e) => { this.narrow = !e.matches; });
                    // Bar ringkasan berubah tinggi (1 → 3 baris saat ada pilihan); ruang bawah halaman ikut tingginya.
                    if (window.ResizeObserver && this.$refs.bar) {
                        new ResizeObserver(() => {
                            this.$root.style.setProperty('--bk-bar-h', this.$refs.bar.offsetHeight + 'px');
                        }).observe(this.$refs.bar);
                    }
                },

                /** Kolom jam + satu kolom per lapangan (semua lapangan aktif, berapa pun jumlahnya). */
                get gridColumns() {
                    const narrow = this.narrow;
                    return `grid-template-columns: ${narrow ? 64 : 80}px repeat(${Math.max(1, this.courts.length)}, minmax(${narrow ? 92 : 112}px, 1fr));`;
                },

                get gridMinWidth() {
                    const narrow = this.narrow;
                    return (narrow ? 64 : 80) + this.courts.length * (narrow ? 92 : 112);
                },

                /** Baris grid yang semua lapangannya sudah lewat jam disembunyikan (diganti satu catatan). */
                get visibleMatrixRows() {
                    return this.matrixRows.filter(row => row.cells.some(cell => cell && cell.status !== 'PAST'));
                },

                /** Jam dikelompokkan 06–17 WIB dan 17–00 WIB. */
                get rowGroups() {
                    const groups = [
                        { key: 'day', label: '06:00 – 17:00 WIB', rows: [] },
                        { key: 'night', label: '17:00 – 00:00 WIB', rows: [] },
                    ];
                    this.visibleMatrixRows.forEach(row => groups[parseInt(row.time, 10) < 17 ? 0 : 1].rows.push(row));
                    return groups.filter(g => g.rows.length > 0);
                },

                get pastNote() {
                    if (!this.matrixRows.some(row => row.cells.some(cell => cell && cell.status === 'PAST'))) return '';
                    const firstOpen = this.visibleMatrixRows[0];
                    return firstOpen ? t('past_before', { time: firstOpen.time }) : T.past_all;
                },

                availableCount(court) {
                    return court && court.slots ? court.slots.filter(s => s.status === 'AVAILABLE').length : 0;
                },

                statusLabel(status) {
                    return { BOOKED: T.status_booked, LOCKED: T.status_locked, CLOSED: T.status_closed, PAST: T.status_past }[status] || status;
                },

                slotButtonClass(courtId, startTime) {
                    if (this.isSlotSelected(courtId, startTime)) {
                        return 'bk-pop bg-[#662721] border-[#662721] text-[#F7F0DB] shadow-[0_6px_16px_rgba(79,47,42,0.32)]';
                    }
                    return 'bg-white border-[#E6DAC0] text-[#4F2F2A] hover:border-[#662721] hover:bg-[#FCF8EE]';
                },

                generateDateTabs() {
                    const today = new Date();
                    this.dateTabs = [];
                    for (let i = 0; i < 14; i++) {
                        const d = new Date();
                        d.setDate(today.getDate() + i);
                        this.dateTabs.push({
                            day: i === 0 ? T.today : dayName(d),
                            dayNum: d.getDate(),
                            month: monthName(d),
                            date: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`,
                        });
                    }
                },

                dateLabel(dateStr) {
                    const tab = this.dateTabs.find(t => t.date === dateStr);
                    return tab ? `${tab.day}, ${tab.dayNum} ${tab.month}` : dateStr;
                },

                // Dipanggil saat input tanggal (transparan di atas ikon) diklik. Browser yang tidak mendukung showPicker()
                // — iOS — sudah membuka pemilih tanggal bawaannya sendiri dari sentuhan itu.
                openCalendarPicker() {
                    const input = this.$refs.calendarInput;
                    if (!input || typeof input.showPicker !== 'function') return;
                    try { input.showPicker(); } catch (e) { /* sudah terbuka / tidak didukung — pemilih bawaan tetap jalan */ }
                },

                onCalendarSelect(val) {
                    if (!val) return;
                    if (!this.dateTabs.some(t => t.date === val)) {
                        const d = new Date(val + 'T00:00:00');
                        this.dateTabs = this.dateTabs.filter(t => !t.isCustom);
                        this.dateTabs.push({ day: dayName(d), dayNum: d.getDate(), month: monthName(d), date: val, isCustom: true });
                    }
                    this.selectDate(val);
                },

                selectDate(dateStr) {
                    this.activeDate = dateStr;
                    this.selectedSlots = [];
                    this.fetchSchedule();
                    this.$nextTick(() => {
                        const el = document.getElementById('date-tab-' + dateStr);
                        if (el) el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                    });
                },

                /** silent = pembaruan otomatis (realtime / polling cadangan): tanpa spinner, pilihan customer dipertahankan. */
                async fetchSchedule(silent = false) {
                    const seq = ++fetchSeq;
                    const date = this.activeDate;
                    if (!silent) this.isLoading = true;
                    try {
                        const res = await fetch(`/api/v1/padel/schedule?date=${date}`, { headers: { 'Accept': 'application/json' } });
                        const json = await res.json();
                        // Jawaban lama (tanggal sudah diganti / ada permintaan yang lebih baru) diabaikan.
                        if (seq !== fetchSeq || date !== this.activeDate) return;
                        if (json.success) {
                            this.notice = json.data.notice || '';
                            this.courts = json.data.courts || [];
                            this.buildMatrixRows(this.courts);
                            if (silent) this.dropTakenSelections();
                        }
                    } catch (e) {
                        console.error('Failed to fetch court schedule:', e);
                    } finally {
                        if (seq === fetchSeq) this.isLoading = false;
                    }
                },

                /** Jam yang dipilih customer tapi baru saja diambil orang lain dilepas dari pilihan, dengan pemberitahuan. */
                dropTakenSelections() {
                    const taken = this.selectedSlots.filter(sel => {
                        const court = this.courts.find(c => c.court_id === sel.court_id);
                        const slot = court ? court.slots.find(s => s.local_start === sel.start_time) : null;
                        return !slot || slot.status !== 'AVAILABLE';
                    });
                    if (taken.length === 0) return;
                    this.selectedSlots = this.selectedSlots.filter(sel => !taken.includes(sel));
                    this.showNotice(T.taken_title, t('taken_message', { slots: taken.map(s => `${s.court} ${s.time}`).join(', ') }), 'info');
                },

                /** Realtime (Reverb) kalau tersedia; kalau tidak / koneksi putus → ambil ulang tiap 60 dtk selama tab terlihat. */
                startLiveUpdates() {
                    const refreshSoon = () => {
                        clearTimeout(liveRefreshTimer);
                        liveRefreshTimer = setTimeout(() => { if (!this.isHolding) this.fetchSchedule(true); }, 250);
                    };
                    const connected = () => !!(live && live.connected());

                    live = window.Club61Live && window.BK_REALTIME ? window.Club61Live.connect(window.BK_REALTIME) : null;
                    if (live) {
                        live.on('booking-board', 'board.changed', (data) => {
                            const dates = data.dates || [];
                            if (dates.includes('*') || dates.includes(this.activeDate)) refreshSoon();
                        });
                        live.onState((isConnected) => { if (isConnected) refreshSoon(); });
                    }

                    setInterval(() => {
                        if (document.visibilityState === 'visible' && !connected()) refreshSoon();
                    }, 60000);
                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'visible' && !connected()) refreshSoon();
                    });
                },

                /** Baris grid = jam; kolom = semua lapangan aktif. */
                buildMatrixRows(courts) {
                    const slotCount = courts.length ? Math.max(...courts.map(c => c.slots.length)) : 0;
                    this.matrixRows = [];
                    for (let i = 0; i < slotCount; i++) {
                        const first = courts.map(c => c.slots[i]).find(Boolean);
                        this.matrixRows.push({
                            index: i,
                            time: first ? first.local_start : '',
                            cells: courts.map(c => c.slots[i] ? { court_id: c.court_id, court_name: c.court_name, ...c.slots[i] } : null),
                        });
                    }
                },

                isSlotSelected(courtId, startTime) {
                    return this.selectedSlots.some(s => s.court_id === courtId && startTime >= s.start_time && startTime < s.end_time);
                },

                handleSlotClick(courtIndex, slotIndex) {
                    const court = this.courts[courtIndex];
                    const slot = court ? court.slots[slotIndex] : null;
                    if (!slot || slot.status !== 'AVAILABLE') return;
                    if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
                    this.toggleSlot(court.court_id, court.court_name, slot.local_start, slot.local_end, slot.price);
                },

                toggleSlot(courtId, courtName, startTime, endTime, price) {
                    const idx = this.selectedSlots.findIndex(s => s.court_id === courtId && s.start_time === startTime && s.end_time === endTime);
                    if (idx >= 0) {
                        this.selectedSlots.splice(idx, 1);
                        return;
                    }
                    this.selectedSlots.push({
                        court_id: courtId,
                        court: courtName,
                        booking_date: this.activeDate,
                        start_time: startTime,
                        end_time: endTime,
                        duration_hours: 1,
                        time: `${startTime} - ${endTime}`,
                        price: price,
                    });
                },

                /** Jam berurutan di lapangan yang sama digabung jadi satu e-tiket; yang terpisah jadi booking sendiri. */
                consolidateContiguousSlots(slots) {
                    if (!slots || slots.length === 0) return [];
                    const courtGroups = {};
                    slots.forEach(s => {
                        if (!courtGroups[s.court_id]) courtGroups[s.court_id] = [];
                        courtGroups[s.court_id].push({ ...s });
                    });

                    const consolidated = [];
                    Object.keys(courtGroups).forEach(courtId => {
                        const list = courtGroups[courtId].sort((a, b) => a.start_time.localeCompare(b.start_time));
                        let current = null;
                        list.forEach(slot => {
                            if (!current) {
                                current = { ...slot, duration_hours: slot.duration_hours || 1 };
                                return;
                            }
                            if (slot.start_time === current.end_time) {
                                current.end_time = slot.end_time;
                                current.price += slot.price;
                                current.duration_hours += (slot.duration_hours || 1);
                                current.time = `${current.start_time} - ${current.end_time} (${current.duration_hours} ${current.duration_hours === 1 ? T.hour : T.hours})`;
                            } else {
                                consolidated.push(current);
                                current = { ...slot, duration_hours: slot.duration_hours || 1 };
                            }
                        });
                        if (current) consolidated.push(current);
                    });
                    return consolidated;
                },

                /** Isi popup cek ulang: per sesi, urut lapangan lalu jam. */
                /** Ringkasan di bar bawah: baris 1 lapangan (atau jumlah sesi), baris 2 tanggal + jam. */
                get selectionHeadline() {
                    const consolidated = this.consolidateContiguousSlots(this.selectedSlots);
                    if (consolidated.length === 1) return consolidated[0].court;
                    const totalHours = consolidated.reduce((sum, s) => sum + s.duration_hours, 0);
                    return t('sessions_hours', { sessions: consolidated.length, hours: totalHours });
                },

                get selectionDetail() {
                    const consolidated = this.consolidateContiguousSlots(this.selectedSlots);
                    if (consolidated.length === 1) {
                        const s = consolidated[0];
                        return `${this.dateLabel(this.activeDate)} · ${s.start_time}–${s.end_time}`;
                    }
                    return this.dateLabel(this.activeDate) + ' · ' + [...new Set(consolidated.map(s => s.court))].join(', ');
                },

                get totalPrice() {
                    return this.selectedSlots.reduce((sum, s) => sum + s.price, 0);
                },

                formatNumber(val) {
                    if (!val) return '0';
                    return val.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                },

                /** Harga ringkas di kotak jam: 380000 → "380k", 382500 → "382.5k". */
                formatK(val) {
                    const k = Math.round((Number(val) || 0) / 100) / 10;
                    return (Number.isInteger(k) ? k : k.toFixed(1)) + 'k';
                },

                // Cek ulang pesanan + ketentuan booking dipindah ke tombol Pay Now di checkout (sebelum ke Midtrans).
                async proceedToHoldAndCart() {
                    if (this.selectedSlots.length === 0 || this.isHolding) return;
                    this.isHolding = true;

                    try {
                        const consolidatedSlots = this.consolidateContiguousSlots(this.selectedSlots);
                        const payload = {
                            booking_date: this.activeDate,
                            slots: consolidatedSlots.map(s => ({ court_id: s.court_id, start_time: s.start_time, end_time: s.end_time })),
                        };

                        const res = await fetch('/api/v1/padel/hold-slot', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            },
                            body: JSON.stringify(payload),
                        });
                        const json = await res.json();

                        if (res.status === 201 && json.success) {
                            localStorage.setItem('club61_cart', JSON.stringify(consolidatedSlots));
                            localStorage.setItem('club61_hold_data', JSON.stringify(json.data));
                            sessionStorage.setItem('club61_cart', JSON.stringify(consolidatedSlots));
                            sessionStorage.setItem('club61_hold_data', JSON.stringify(json.data));
                            window.dispatchEvent(new CustomEvent('cart-updated'));
                            window.location.href = "{{ route('customer.cart') }}";
                        } else {
                            this.showNotice(T.hold_failed_title, json.message || T.hold_failed_message, 'error', T.close);
                            this.fetchSchedule();
                        }
                    } catch (e) {
                        this.showNotice(T.network_title, T.network_message, 'error', T.close);
                    } finally {
                        this.isHolding = false;
                    }
                },
            };
        }
    </script>
</x-app-layout>
