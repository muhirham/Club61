<!-- Pilihan sesi kalau satu order berisi beberapa lapangan / jam -->
<template x-if="ticket.order_bookings && ticket.order_bookings.length > 1">
    <div class="bg-white/90 rounded-lg border border-[#E6DAC0] p-2.5">
        <div class="px-1 pb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">Sessions in this order</div>
        <div class="bk-scroll flex items-center gap-2 overflow-x-auto">
            <template x-for="(sBooking, sIdx) in ticket.order_bookings" :key="sBooking.id">
                <button type="button" @click="switchSession(sBooking)"
                        :class="currentTicket.id === sBooking.id ? 'bg-[#662721] text-[#F7F0DB] border-[#662721]' : 'bg-[#F7F0DB] text-[#662721] border-[#E6DAC0] hover:bg-[#F7F0DB]'"
                        class="bk-tap shrink-0 h-9 px-3 rounded-xl border text-xs font-bold whitespace-nowrap flex items-center gap-1.5 transition-colors">
                    <span x-text="'#' + (sIdx + 1)"></span>
                    <span class="tabular-nums" x-text="formatTime(sBooking.start_time) + '–' + formatTime(sBooking.end_time)"></span>
                </button>
            </template>
        </div>
    </div>
</template>

<div class="bg-white/95 rounded-xl border border-[#E6DAC0] shadow-[0_14px_40px_rgba(79,47,42,0.16)] overflow-hidden">

    <!-- Kepala tiket -->
    <div class="p-4 sm:p-6 bg-[#662721] text-white">
        <div class="flex flex-wrap items-center gap-2">
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-[#E6DAC0]/20 text-[#F7F0DB] border border-[#E6DAC0]/40">E-Ticket · Padel</span>
            <span :class="statusTone(currentTicket.status) === 'ok' ? 'bg-emerald-500 text-white' : (statusTone(currentTicket.status) === 'bad' ? 'bg-rose-500 text-white' : 'bg-amber-400 text-[#4F2F2A]')"
                  class="px-2.5 py-0.5 rounded-full text-[11px] font-black whitespace-nowrap shadow-sm"
                  x-text="statusLabel(currentTicket.status)"></span>
        </div>
        <h2 class="mt-2 font-display font-black text-xl sm:text-2xl leading-tight break-words" x-text="currentTicket.court ? currentTicket.court.name : 'Court'"></h2>
        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[#F7F0DB]/90">
            <span class="font-semibold whitespace-nowrap" x-text="formatDate(currentTicket.booking_date)"></span>
            <span class="text-[#F7F0DB]/50">•</span>
            <span class="font-semibold tabular-nums whitespace-nowrap" x-text="formatTime(currentTicket.start_time) + '–' + formatTime(currentTicket.end_time) + ' WIB'"></span>
        </div>
        <div class="mt-1 text-[11px] text-[#F7F0DB]/70 font-mono truncate" x-text="'Code #' + (currentTicket.booking_code || currentTicket.id.substring(0, 10))"></div>
    </div>

    <!-- Strip info -->
    <div class="grid grid-cols-3 divide-x divide-[#E6DAC0] bg-[#F7F0DB] border-y border-dashed border-[#E6DAC0] text-center">
        <div class="py-2.5 px-2 min-w-0">
            <div class="text-[9px] font-extrabold uppercase tracking-wider text-[#7A5A52]">Pass</div>
            <div class="text-xs font-bold text-[#4F2F2A] truncate"
                 x-text="currentTicket.status === 'PAID' || currentTicket.status === 'CHECKED_IN' ? 'Valid' : (statusTone(currentTicket.status) === 'bad' ? 'Inactive' : 'Locked')"></div>
        </div>
        <div class="py-2.5 px-2 min-w-0">
            <div class="text-[9px] font-extrabold uppercase tracking-wider text-[#7A5A52]">Check-in</div>
            <div class="text-xs font-bold text-[#4F2F2A] truncate">Front desk</div>
        </div>
        <div class="py-2.5 px-2 min-w-0">
            <div class="text-[9px] font-extrabold uppercase tracking-wider text-[#7A5A52]">Duration</div>
            <div class="text-xs font-bold text-[#4F2F2A] truncate"
                 x-text="calculateDuration(currentTicket.start_time, currentTicket.end_time) + (calculateDuration(currentTicket.start_time, currentTicket.end_time) > 1 ? ' hrs' : ' hr')"></div>
        </div>
    </div>

    <div class="p-4 sm:p-6 grid grid-cols-1 2xl:grid-cols-2 gap-4 sm:gap-5 items-start">

        <!-- QR check-in: hanya aktif saat PAID / CHECKED_IN -->
        <template x-if="currentTicket.status === 'PAID' || currentTicket.status === 'CHECKED_IN'">
            <div class="w-full text-center">
                <div class="inline-block p-3.5 bg-white rounded-xl border-2 border-dashed border-[#E6DAC0] shadow-inner">
                    {{-- QR dibuat di browser (renderQr) — kode akses gate tidak pernah dikirim ke layanan QR luar. --}}
                    <template x-if="currentTicket.qr_code_hash">
                        <div x-effect="renderQr($el, currentTicket.qr_code_hash)" role="img" aria-label="QR Check-in"
                             class="w-48 h-48 mx-auto rounded-xl overflow-hidden bg-white"></div>
                    </template>
                    <template x-if="!currentTicket.qr_code_hash">
                        <div class="w-48 h-48 mx-auto rounded-xl bg-[#FCF8EE] border border-[#E6DAC0] flex items-center justify-center p-4 text-center text-[11px] font-bold text-[#662721] leading-snug">
                            QR code not available yet. Show your booking code to the front desk.
                        </div>
                    </template>
                    <div class="mt-2.5 max-w-[12rem] mx-auto font-mono font-bold text-[10px] text-[#662721] tracking-wider break-all leading-snug"
                         x-text="currentTicket.qr_code_hash || currentTicket.booking_code"></div>
                </div>
                <div class="max-w-sm mx-auto mt-3">
                    <h4 class="font-bold text-sm">Show this at the front desk</h4>
                    <p class="text-xs text-[#7A5A52] mt-1 leading-relaxed">Staff will scan it for court access and equipment pick-up.</p>
                </div>
            </div>
        </template>

        <!-- Ditutup: kedaluwarsa, batal, refund -->
        <template x-if="['EXPIRED', 'CANCELLED', 'REFUNDED', 'REFUND_PENDING'].includes(currentTicket.status)">
            <div class="w-full max-w-md mx-auto p-5 sm:p-6 rounded-xl border-2 border-dashed border-rose-300 bg-rose-50/70 text-center space-y-3">
                <div class="w-12 h-12 rounded-lg bg-rose-100 border border-rose-300 flex items-center justify-center mx-auto text-rose-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div>
                    <h4 class="font-bold text-sm" x-text="closedTicketTitle(currentTicket)"></h4>
                    <p class="text-xs text-[#7A5A52] mt-1 leading-relaxed" x-text="closedTicketMessage(currentTicket)"></p>
                </div>
                <span class="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border" :class="statusPillClass(currentTicket.status)" x-text="statusLabel(currentTicket.status)"></span>
                <a href="{{ route('customer.booking') }}"
                   class="bk-tap w-full h-12 rounded-lg flex items-center justify-center gap-2 text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform">
                    Book again
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                </a>
            </div>
        </template>

        <!-- Belum lunas (PENDING_PAYMENT / LOCKED) -->
        <template x-if="! ['PAID', 'CHECKED_IN', 'EXPIRED', 'CANCELLED', 'REFUNDED', 'REFUND_PENDING'].includes(currentTicket.status)">
            <div class="w-full max-w-md mx-auto p-4 sm:p-5 rounded-xl border-2 border-dashed border-amber-300 bg-amber-50/60 space-y-3.5">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 shrink-0 rounded-lg bg-amber-100 border border-amber-300 flex items-center justify-center text-amber-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-4a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                    </div>
                    <div class="min-w-0">
                        <h4 class="font-bold text-sm leading-tight">QR ticket locked</h4>
                        <p class="text-[11px] text-[#7A5A52] leading-snug mt-0.5">Finish payment — the QR code unlocks automatically once it's confirmed.</p>
                    </div>
                </div>

                <!-- Metode pembayaran terpilih -->
                <div class="p-3 rounded-lg bg-white border border-[#E6DAC0] flex items-center gap-3">
                    <span class="w-11 h-8 shrink-0 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center font-black text-[10px] text-[#662721]" x-text="selectedMethod.badge"></span>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-xs leading-snug line-clamp-2" x-text="selectedMethod.name"></div>
                        <div class="text-[10px] text-[#7A5A52] leading-snug line-clamp-2" x-text="selectedMethod.note"></div>
                    </div>
                    <button type="button" @click="showPaymentModal = true"
                            class="bk-tap shrink-0 h-8 px-3 rounded-xl bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-xs font-bold whitespace-nowrap transition-colors">
                        Change
                    </button>
                </div>

                <!-- Sisa selisih reschedule -->
                <template x-if="currentTicket.has_pending_delta">
                    <div class="p-3 rounded-lg bg-amber-100/90 border border-amber-300 space-y-1">
                        <div class="flex items-center justify-between gap-3 text-xs font-bold text-amber-900">
                            <span class="min-w-0">Reschedule balance due</span>
                            <span class="shrink-0 tabular-nums font-black text-sm text-red-700 whitespace-nowrap" x-text="'Rp ' + formatNumber(currentTicket.unpaid_delta)"></span>
                        </div>
                        <p class="text-[11px] text-amber-800 leading-snug">Pay the remaining balance (online or at the front desk) to activate your pass.</p>
                    </div>
                </template>

                <!-- Instruksi bayar tunai -->
                <div x-show="selectedMethod.code === 'CASH' || isCashNotice || (currentTicket.order && currentTicket.order.payment_method === 'CASH')"
                     class="p-3 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0] space-y-1">
                    <div class="text-xs font-bold text-[#662721]">Paying in cash</div>
                    <p class="text-[11px] text-[#662721] leading-snug">
                        Show booking code <strong class="font-mono text-[#4F2F2A]" x-text="'#' + (currentTicket.booking_code || currentTicket.id.substring(0, 8))"></strong>
                        at the front desk to pay and activate your e-ticket.
                    </p>
                </div>

                <!-- Tombol bayar -->
                <button type="button" @click="payNow()" :disabled="isSubmittingPayment"
                        class="bk-tap w-full h-12 rounded-lg flex items-center justify-center gap-2 text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform disabled:opacity-60 disabled:cursor-not-allowed">
                    <span x-show="isSubmittingPayment" class="w-4 h-4 border-2 border-[#4F2F2A] border-t-transparent rounded-full animate-spin"></span>
                    <span class="whitespace-nowrap" x-text="isSubmittingPayment ? 'Processing…' : (selectedMethod.code === 'CASH' ? 'Confirm with front desk' : (currentTicket.has_pending_delta ? ('Pay balance Rp ' + formatNumber(currentTicket.unpaid_delta)) : 'Pay Now'))"></span>
                    <svg x-show="!isSubmittingPayment && selectedMethod.code !== 'CASH'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                </button>

                <template x-if="canCancelBooking">
                    <button type="button" @click="openCancelModal()" :disabled="isCancellingBooking"
                            class="bk-tap w-full h-10 rounded-lg text-xs font-bold text-[#9A3412] hover:bg-rose-50 transition-colors flex items-center justify-center gap-2 disabled:opacity-50">
                        <span x-show="isCancellingBooking" class="w-3.5 h-3.5 border-2 border-rose-600 border-t-transparent rounded-full animate-spin"></span>
                        <span x-text="isCancellingBooking ? 'Cancelling…' : 'Cancel this booking'"></span>
                    </button>
                </template>
            </div>
        </template>

        <!-- Rincian -->
        <div class="bg-[#F7F0DB] p-4 rounded-lg border border-[#E6DAC0] text-xs space-y-2.5">
            @php
                $ticketHolderMembership = Auth::check()
                    ? \App\Models\Membership\UserMembership::where('user_id', Auth::id())
                        ->where('status', 'ACTIVE')
                        ->where(function ($q) {
                            $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
                        })
                        ->with('plan')
                        ->orderByRaw('end_date IS NULL, end_date ASC')
                        ->first()
                    : null;
            @endphp
            <div class="flex items-start justify-between gap-3">
                <span class="shrink-0 text-[#7A5A52]">Ticket holder</span>
                <span class="min-w-0 text-right font-bold text-[#4F2F2A] break-words leading-snug">{{ Auth::user()->name }}@if($ticketHolderMembership && $ticketHolderMembership->plan) <span class="font-semibold text-[#662721]">· {{ $ticketHolderMembership->plan->name }}</span>@endif</span>
            </div>
            <div class="flex items-start justify-between gap-3">
                <span class="shrink-0 text-[#7A5A52]">Schedule</span>
                <span class="min-w-0 text-right font-bold text-[#4F2F2A] tabular-nums"
                      x-text="formatTime(currentTicket.start_time) + '–' + formatTime(currentTicket.end_time) + ' WIB'"></span>
            </div>
            <div class="flex items-start justify-between gap-3">
                <span class="shrink-0 text-[#7A5A52]">Court</span>
                <span class="min-w-0 text-right font-bold text-[#4F2F2A] leading-snug" x-text="currentTicket.court ? currentTicket.court.name : 'Court'"></span>
            </div>

            <div class="pt-2.5 border-t border-[#E6DAC0] flex items-start justify-between gap-3">
                <span class="min-w-0 text-[#7A5A52]">Court rental</span>
                <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(currentTicket.court_fee)"></span>
            </div>
            <div class="flex items-start justify-between gap-3" x-show="currentTicket.equipment_fee > 0">
                <span class="min-w-0 text-[#7A5A52]">Equipment add-ons</span>
                <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(currentTicket.equipment_fee)"></span>
            </div>

            {{-- Diskon, pajak & biaya layanan dihitung per ORDER (bukan per lapangan), jadi dibaca dari sumber yang sama
                 dengan ringkasan transaksi — dulu kotak ini hanya menjumlah sewa lapangan + alat sehingga biaya layanan
                 dari Pengaturan Biaya & Pajak tidak pernah muncul dan totalnya lebih kecil dari invoice. --}}
            <template x-if="totalMemberDiscount > 0">
                <div class="flex items-start justify-between gap-3 font-bold text-[#662721]">
                    <span class="min-w-0">Membership discount</span>
                    <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(totalMemberDiscount)"></span>
                </div>
            </template>
            <template x-if="totalSponsorDiscount > 0">
                <div class="flex items-start justify-between gap-3 font-bold text-[#4F2F2A]">
                    <span class="min-w-0">Corporate voucher</span>
                    <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(totalSponsorDiscount)"></span>
                </div>
            </template>
            <template x-if="ticket && ticket.order && ticket.order.tax_amount > 0">
                <div class="flex items-start justify-between gap-3">
                    <span class="min-w-0 text-[#7A5A52]">Tax</span>
                    <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(ticket.order.tax_amount)"></span>
                </div>
            </template>
            <template x-if="ticket && ticket.order && ticket.order.service_charge > 0">
                <div class="flex items-start justify-between gap-3">
                    <span class="min-w-0 text-[#7A5A52]">Service fee</span>
                    <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(ticket.order.service_charge)"></span>
                </div>
            </template>

            {{-- Reschedule: selisih ke jam lebih mahal sudah termasuk di sewa lapangan / pajak / biaya layanan di atas
                 (tarif jadwal baru) — baris ini menjelaskan asal tambahannya & status bayarnya. --}}
            <template x-if="ticket && ticket.reschedule_charges && ticket.reschedule_charges.length > 0">
                <div class="rounded-xl border border-[#E6DAC0] bg-white px-3 py-2 space-y-1">
                    <template x-for="(rc, idx) in ticket.reschedule_charges" :key="idx">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0 text-[#662721] font-semibold">
                                Reschedule difference
                                <span class="font-bold" :class="rc.status === 'SUCCESS' ? 'text-[#662721]' : 'text-red-700'"
                                      x-text="rc.status === 'SUCCESS' ? '(paid · ' + (rc.method_label || '-') + ')' : '(unpaid)'"></span>
                            </span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(rc.amount)"></span>
                        </div>
                    </template>
                    <p class="text-[11px] text-[#7A5A52] leading-snug">
                        <span x-show="ticket.reschedule_charges[0].schedule_before">Moved from <span class="font-semibold" x-text="ticket.reschedule_charges[0].schedule_before"></span>. </span>
                        Already included in the new schedule's rental, tax &amp; service fee above.
                    </p>
                </div>
            </template>
            <template x-if="ticket && ticket.order_reschedule_forfeited > 0">
                <div class="rounded-xl border border-amber-300 bg-amber-50 px-3 py-2">
                    <div class="flex items-start justify-between gap-3 text-amber-900 font-semibold">
                        <span class="min-w-0">Reschedule difference forfeited</span>
                        <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(ticket.order_reschedule_forfeited)"></span>
                    </div>
                    <p class="text-[11px] text-amber-800 leading-snug">Moved to a cheaper time. Per venue policy, the difference isn't refunded.</p>
                </div>
            </template>

            <div class="pt-2.5 border-t border-[#E6DAC0] flex items-center justify-between gap-3">
                <span class="font-bold text-sm text-[#4F2F2A]">Total ticket amount</span>
                <span class="shrink-0 font-black text-lg tabular-nums whitespace-nowrap text-[#4F2F2A]" x-text="'Rp ' + formatNumber(displayGrandTotal)"></span>
            </div>
            <template x-if="ticket && ticket.order_bookings && ticket.order_bookings.length > 1">
                <p class="text-[11px] text-[#7A5A52] leading-snug">
                    Discounts, tax, service fee &amp; total cover all <span x-text="ticket.order_bookings.length"></span> courts in this order.
                </p>
            </template>
        </div>
    </div>
</div>
