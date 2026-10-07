<x-app-layout>
    @include('customer.partials.bk-style')

    <div x-data="invoiceApp()" x-init="init()" class="text-[#4F2F2A] pb-8">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 pt-4 sm:pt-6 space-y-4 sm:space-y-5">

            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('dashboard') }}" title="Back to Home"
                       class="bk-tap w-10 h-10 shrink-0 flex items-center justify-center rounded-lg bg-white/90 border border-[#E6DAC0] text-[#662721] hover:bg-[#F7F0DB] transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                    </a>
                    <div class="min-w-0">
                        <h1 class="font-display font-black text-xl sm:text-2xl leading-tight">Invoice &amp; E-Ticket</h1>
                        <p class="hidden sm:block text-xs text-[#7A5A52] mt-0.5">Show the QR code at the front desk when you arrive.</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:flex items-center gap-2 shrink-0">
                    <button type="button"
                            x-show="!isMembershipTicket"
                            @click="downloadTicketPng()"
                            :disabled="isDownloadingPng || !currentTicket || currentTicket.status === 'EXPIRED' || currentTicket.status === 'CANCELLED' || currentTicket.status === 'REFUNDED' || currentTicket.status === 'REFUND_PENDING'"
                            class="bk-tap h-10 px-4 rounded-lg bg-white/90 hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-xs font-bold whitespace-nowrap flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                        <span x-show="isDownloadingPng" class="w-3.5 h-3.5 border-2 border-[#662721] border-t-transparent rounded-full animate-spin"></span>
                        <svg x-show="!isDownloadingPng" class="w-4 h-4 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                        <span x-text="isDownloadingPng ? 'Saving…' : 'Save ticket'"></span>
                    </button>
                    <a href="{{ route('customer.booking') }}"
                       :class="isMembershipTicket ? 'col-span-2' : ''"
                       class="bk-tap h-10 px-5 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider whitespace-nowrap flex items-center justify-center gap-1.5 shadow-[0_8px_20px_rgba(79,47,42,0.18)] active:scale-95 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 5v14M5 12h14" /></svg>
                        New booking
                    </a>
                </div>
            </div>

            {{-- Menunggu konfirmasi pembayaran (cek otomatis) --}}
            <div x-show="isPolling" style="display: none;"
                 class="flex items-center gap-3 p-3.5 rounded-lg bg-amber-50 border border-amber-300 text-amber-900">
                <div class="w-5 h-5 border-2 border-amber-600 border-t-transparent rounded-full animate-spin shrink-0"></div>
                <div class="min-w-0">
                    <div class="text-sm font-bold leading-tight">Waiting for payment confirmation</div>
                    <div class="text-[11px] text-amber-800 leading-snug mt-0.5">We check automatically — no need to refresh this page.</div>
                </div>
            </div>

            {{-- Memuat --}}
            <div x-show="isLoading" class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5">
                <div class="lg:col-span-7 bk-skeleton h-80 rounded-xl"></div>
                <div class="lg:col-span-5 space-y-4"><div class="bk-skeleton h-48 rounded-xl"></div><div class="bk-skeleton h-28 rounded-xl"></div></div>
            </div>

            <template x-if="!isLoading && currentTicket">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5 items-start">

                    {{-- Kiri: e-ticket / kartu membership.
                         Pakai x-show (bukan x-if): ticket-card punya lebih dari satu elemen teratas, dan x-if
                         hanya meng-clone anak PERTAMA dari <template>-nya. --}}
                    <div class="lg:col-span-7 flex flex-col gap-4">
                        <div x-show="!isMembershipTicket" class="flex flex-col gap-4">
                            @include('customer.invoice-partials.ticket-card')
                        </div>
                        <div x-show="isMembershipTicket">
                            @include('customer.invoice-partials.membership-invoice-card')
                        </div>
                    </div>

                    {{-- Kanan: rincian invoice & riwayat --}}
                    <div class="lg:col-span-5 flex flex-col gap-4 sm:gap-5">
                        <div x-show="!isMembershipTicket">
                            @include('customer.invoice-partials.invoice-summary')
                        </div>
                        <div x-show="isMembershipTicket">
                            @include('customer.invoice-partials.membership-summary')
                        </div>
                        @include('customer.invoice-partials.booking-history')
                    </div>
                </div>
            </template>

            {{-- Belum ada booking / pembelian membership sama sekali --}}
            <template x-if="!isLoading && !currentTicket">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5 items-start">
                    <div class="lg:col-span-7">
                        <div class="bg-white/90 rounded-xl border border-[#E6DAC0] p-8 sm:p-12 text-center space-y-3">
                            <div class="w-14 h-14 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center mx-auto text-[#662721]">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                            </div>
                            <h3 class="font-display font-black text-lg">No invoices yet</h3>
                            <p class="text-xs text-[#7A5A52] max-w-xs mx-auto">Book a court or buy a membership package — your invoice and e-ticket will show up here.</p>
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-center gap-2 pt-2">
                                <a href="{{ route('customer.booking') }}" class="bk-tap h-11 px-5 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider whitespace-nowrap flex items-center justify-center shadow-md active:scale-95 transition-transform">
                                    Book a Court
                                </a>
                                <a href="{{ route('customer.membership') }}" class="bk-tap h-11 px-5 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-xs font-bold whitespace-nowrap flex items-center justify-center transition-colors">
                                    View Membership Packages
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="lg:col-span-5 flex flex-col gap-4 sm:gap-5">
                        @include('customer.invoice-partials.booking-history')
                    </div>
                </div>
            </template>

            @include('customer.invoice-partials.payment-modal')
            @include('customer.invoice-partials.cancel-modal')
        </div>
    </div>

    <!-- Scripts: Alpine.js Controller & High-Res PNG Ticket Canvas Generator -->
    @include('customer.invoice-partials.invoice-scripts')
</x-app-layout>
