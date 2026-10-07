<!-- Riwayat invoice lain (tidak termasuk order yang sedang dibuka) -->
<div class="bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5 space-y-3">
    <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Other invoices</div>
            <div class="text-[11px] text-[#7A5A52] truncate">Past bookings &amp; membership purchases</div>
        </div>
        <span class="shrink-0 whitespace-nowrap px-2.5 py-1 rounded-full text-[11px] font-bold bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]"
              x-text="(searchQuery || searchDate) ? (filteredPastBookings.length + ' of ' + pastBookings.length) : (pastBookings.length + (pastBookings.length === 1 ? ' item' : ' items'))"></span>
    </div>

    <!-- Cari (nama lapangan / kode) & filter tanggal -->
    <div class="space-y-2" x-show="pastBookings.length > 0">
        <div class="relative">
            <svg class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-[#7A5A52] pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input type="text" x-model="searchQuery" @input="currentPage = 1" placeholder="Search court or code"
                   class="w-full h-10 pl-10 pr-9 rounded-xl border border-[#E6DAC0] bg-[#F7F0DB] text-xs text-[#4F2F2A] placeholder-[#7A5A52]/80 focus:ring-1 focus:ring-[#662721] focus:border-[#662721] focus:outline-none">
            <button type="button" x-show="searchQuery" @click="searchQuery = ''; currentPage = 1" title="Clear"
                    class="absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 rounded-lg flex items-center justify-center text-[#7A5A52] hover:text-rose-600">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="flex items-center gap-2">
            <input type="date" x-model="searchDate" @click="$el.showPicker ? $el.showPicker() : null" @change="currentPage = 1" title="Filter by booking date"
                   class="flex-1 min-w-0 h-10 px-3 rounded-xl border border-[#E6DAC0] bg-[#F7F0DB] text-xs text-[#4F2F2A] focus:ring-1 focus:ring-[#662721] focus:border-[#662721] focus:outline-none cursor-pointer">
            <button type="button" x-show="searchQuery || searchDate" @click="resetFilters()"
                    class="bk-tap shrink-0 h-10 px-3 rounded-xl border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 text-xs font-bold whitespace-nowrap transition-colors">
                Reset
            </button>
        </div>
    </div>

    <div x-show="pastBookings.length === 0" class="text-xs text-[#7A5A52] py-3 text-center">No other invoices yet.</div>

    <div x-show="pastBookings.length > 0 && filteredPastBookings.length === 0" class="text-center py-5 space-y-2 bg-[#F7F0DB] rounded-lg border border-dashed border-[#E6DAC0]">
        <div class="text-xs text-[#7A5A52]">Nothing matches this filter.</div>
        <button type="button" @click="resetFilters()" class="bk-tap h-8 px-3 text-xs font-bold bg-white border border-[#E6DAC0] rounded-xl text-[#662721] hover:bg-[#F7F0DB] transition-colors">Reset search</button>
    </div>

    <div x-show="paginatedBookings.length > 0" class="space-y-2">
        <template x-for="item in paginatedBookings" :key="item.id">
            <a :href="'{{ route('customer.invoice') }}?' + (item.type === 'MEMBERSHIP' ? 'membership_id=' : 'booking_id=') + item.id"
               @click.prevent="switchToEntry(item)"
               class="bk-tap group p-3 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] flex items-center gap-3 transition-colors">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-1.5 min-w-0">
                        <span x-show="item.type === 'MEMBERSHIP'" class="shrink-0 px-1.5 py-0.5 rounded text-[8px] font-extrabold uppercase bg-[#4F2F2A] text-[#F7F0DB]">Membership</span>
                        <span class="text-xs font-bold text-[#4F2F2A] group-hover:text-[#662721] truncate" x-text="item.type === 'MEMBERSHIP' ? item.plan_name : (item.court ? item.court.name : 'Court')"></span>
                    </div>
                    <div class="text-[10px] text-[#7A5A52] truncate mt-0.5"
                         x-text="item.type === 'MEMBERSHIP' ? ('#' + item.membership_code) : (formatDate(item.booking_date) + ' · #' + (item.booking_code || item.id.substring(0, 8)))"></div>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-xs font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(item.type === 'MEMBERSHIP' ? item.grand_total : item.total_amount)"></div>
                    <div class="text-[10px] font-bold whitespace-nowrap"
                         :class="statusTone(item.status) === 'ok' ? 'text-emerald-700' : (statusTone(item.status) === 'bad' ? 'text-rose-600' : 'text-amber-700')"
                         x-text="statusLabel(item.status)"></div>
                </div>
            </a>
        </template>
    </div>

    <div x-show="totalPages > 1 && paginatedBookings.length > 0" class="flex items-center justify-between gap-2 pt-2 border-t border-[#E6DAC0] text-xs">
        <button type="button" @click="prevPage()" :disabled="currentPage === 1"
                class="bk-tap h-9 px-3 rounded-xl border border-[#E6DAC0] bg-white text-[#662721] font-bold hover:bg-[#F7F0DB] disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
            Prev
        </button>
        <span class="text-[11px] text-[#7A5A52] tabular-nums whitespace-nowrap">Page <b class="text-[#4F2F2A]" x-text="currentPage"></b> of <b class="text-[#4F2F2A]" x-text="totalPages"></b></span>
        <button type="button" @click="nextPage()" :disabled="currentPage === totalPages"
                class="bk-tap h-9 px-3 rounded-xl border border-[#E6DAC0] bg-white text-[#662721] font-bold hover:bg-[#F7F0DB] disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1 transition-colors">
            Next
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>
</div>

<!-- Bantuan -->
<div class="p-4 rounded-xl bg-gradient-to-r from-[#F7F0DB] via-[#F7F0DB] to-[#F7F0DB] border border-[#E6DAC0] flex items-center gap-3">
    <div class="flex-1 min-w-0">
        <h4 class="font-bold text-sm leading-tight">Need help with check-in?</h4>
        <p class="text-[11px] text-[#7A5A52] leading-snug mt-0.5">Our front desk team is happy to help.</p>
    </div>
    <a href="https://wa.me/6281261617233" target="_blank" rel="noopener"
       class="bk-tap shrink-0 h-10 px-4 rounded-xl bg-[#662721] text-[#F7F0DB] text-xs font-bold whitespace-nowrap flex items-center hover:bg-[#511D18] transition-colors">
        WhatsApp
    </a>
</div>
