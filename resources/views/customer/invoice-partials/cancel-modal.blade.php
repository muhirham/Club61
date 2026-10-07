<!-- Konfirmasi batal booking -->
<div x-show="showCancelModal" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
    <div x-show="showCancelModal" x-transition.opacity.duration.200ms @click="if (!isCancellingBooking) showCancelModal = false" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div x-show="showCancelModal" x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
        <div class="w-14 h-14 mx-auto rounded-lg bg-rose-50 border border-rose-200 text-rose-600 flex items-center justify-center">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
        </div>
        <div class="space-y-1.5">
            <h3 class="font-display font-black text-xl">Cancel this booking?</h3>
            <p class="text-xs text-[#7A5A52] leading-relaxed">The court slot goes back to the public schedule right away. You can pick a new time afterwards.</p>
        </div>

        <template x-if="currentTicket">
            <div class="rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] p-3 text-xs space-y-1.5 text-left">
                <div class="flex justify-between gap-3"><span class="shrink-0 text-[#7A5A52]">Court</span><span class="min-w-0 text-right font-bold truncate" x-text="currentTicket.court ? currentTicket.court.name : (currentTicket.court_name || 'Court')"></span></div>
                <div class="flex justify-between gap-3"><span class="shrink-0 text-[#7A5A52]">Schedule</span><span class="min-w-0 text-right font-bold truncate" x-text="formatDate(currentTicket.booking_date) + ' · ' + formatTime(currentTicket.start_time) + '–' + formatTime(currentTicket.end_time)"></span></div>
                <template x-if="ticket && ticket.order_bookings && ticket.order_bookings.length > 1">
                    <div class="flex justify-between gap-3"><span class="shrink-0 text-[#7A5A52]">Sessions</span><span class="font-bold text-[#662721]" x-text="ticket.order_bookings.length + ' linked sessions'"></span></div>
                </template>
                <div class="flex justify-between gap-3"><span class="shrink-0 text-[#7A5A52]">Code</span><span class="min-w-0 text-right font-mono font-bold text-[#662721] truncate" x-text="currentTicket.booking_code || currentTicket.order_number || currentTicket.id"></span></div>
            </div>
        </template>

        <div class="grid grid-cols-2 gap-2">
            <button type="button" @click="showCancelModal = false" :disabled="isCancellingBooking"
                    class="bk-tap h-12 rounded-lg border border-[#E6DAC0] bg-[#F7F0DB] text-[#662721] text-xs font-bold disabled:opacity-50">
                Keep booking
            </button>
            <button type="button" @click="confirmCancelBooking()" :disabled="isCancellingBooking"
                    class="bk-tap h-12 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-black uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-60 transition-colors">
                <span x-show="isCancellingBooking" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                <span x-text="isCancellingBooking ? 'Releasing…' : 'Yes, cancel'"></span>
            </button>
        </div>
    </div>
</div>

<!-- Konfirmasi batal order membership yang belum dibayar -->
<div x-show="showCancelMembershipModal" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
    <div x-show="showCancelMembershipModal" x-transition.opacity.duration.200ms @click="if (!isCancellingMembership) showCancelMembershipModal = false" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div x-show="showCancelMembershipModal" x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
        <div class="w-14 h-14 mx-auto rounded-lg bg-rose-50 border border-rose-200 text-rose-600 flex items-center justify-center">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
        </div>
        <div class="space-y-1.5">
            <h3 class="font-display font-black text-xl">Cancel membership order?</h3>
            <p class="text-xs text-[#7A5A52] leading-relaxed">This unpaid order and its payment link will be closed. Don't pay any virtual account number from this order after cancelling.</p>
        </div>
        <template x-if="currentTicket">
            <div class="rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] p-3 text-xs space-y-1.5 text-left">
                <div class="flex justify-between gap-3"><span class="shrink-0 text-[#7A5A52]">Package</span><span class="min-w-0 text-right font-bold truncate" x-text="currentTicket.plan_name"></span></div>
                <div class="flex justify-between gap-3"><span class="shrink-0 text-[#7A5A52]">Order</span><span class="min-w-0 text-right font-mono font-bold text-[#662721] truncate" x-text="currentTicket.order_number"></span></div>
            </div>
        </template>
        <div class="grid grid-cols-2 gap-2">
            <button type="button" @click="showCancelMembershipModal = false" :disabled="isCancellingMembership"
                    class="bk-tap h-12 rounded-lg border border-[#E6DAC0] bg-[#F7F0DB] text-[#662721] text-xs font-bold disabled:opacity-50">
                Keep order
            </button>
            <button type="button" @click="confirmCancelMembership()" :disabled="isCancellingMembership"
                    class="bk-tap h-12 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-black uppercase tracking-wider disabled:opacity-60 transition-colors">
                <span x-text="isCancellingMembership ? 'Cancelling…' : 'Yes, cancel'"></span>
            </button>
        </div>
    </div>
</div>

<!-- Pemberitahuan -->
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
            <span x-text="noticeModal.buttonText || 'OK, Understood'"></span>
        </button>
    </div>
</div>
