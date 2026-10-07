<!-- Ringkasan transaksi pembelian membership -->
<div class="bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5 space-y-4">
    <div class="pb-3 border-b border-[#E6DAC0]">
        <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Payment receipt</div>
        <h3 class="font-bold text-base leading-tight mt-0.5">Membership purchase</h3>
        <div class="mt-1 text-[11px] font-mono font-bold text-[#662721] truncate" x-text="'#' + (ticket.order_number || ticket.membership_code)"></div>
    </div>

    <div class="space-y-2.5 text-xs text-[#662721]">
        <div class="flex items-start justify-between gap-3">
            <span class="shrink-0">Package</span>
            <span class="min-w-0 text-right font-bold text-[#4F2F2A] leading-snug" x-text="ticket.plan_name"></span>
        </div>
        <div class="flex items-start justify-between gap-3">
            <span class="min-w-0">Package price</span>
            <span class="shrink-0 font-bold text-[#4F2F2A] tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(ticket.subtotal)"></span>
        </div>
        <template x-if="ticket.discount_amount > 0">
            <div class="flex items-start justify-between gap-3 text-emerald-700 font-bold">
                <span class="min-w-0">Discount</span>
                <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(ticket.discount_amount)"></span>
            </div>
        </template>
        <template x-if="ticket.tax_amount > 0">
            <div class="flex items-start justify-between gap-3">
                <span class="min-w-0">Tax</span>
                <span class="shrink-0 font-bold text-[#4F2F2A] tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(ticket.tax_amount)"></span>
            </div>
        </template>
        <template x-if="ticket.service_charge > 0">
            <div class="flex items-start justify-between gap-3">
                <span class="min-w-0">Service fee</span>
                <span class="shrink-0 font-bold text-[#4F2F2A] tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(ticket.service_charge)"></span>
            </div>
        </template>
        <div class="flex items-center justify-between gap-3">
            <span class="min-w-0">Payment</span>
            <span class="shrink-0 px-2 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap" :class="statusPillClass(ticket.payment_status)" x-text="statusLabel(ticket.payment_status)"></span>
        </div>
        <div class="pt-3 border-t border-[#E6DAC0] flex items-center justify-between gap-3">
            <span class="font-bold text-sm text-[#4F2F2A]">Total</span>
            <span class="shrink-0 font-black text-lg tabular-nums whitespace-nowrap text-[#4F2F2A]" x-text="'Rp ' + formatNumber(ticket.grand_total)"></span>
        </div>
    </div>

    <div class="p-3 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center gap-2.5">
        <span class="w-10 h-7 shrink-0 rounded-md bg-white border border-[#E6DAC0] flex items-center justify-center font-black text-[9px] text-[#662721]"
              x-text="getPaymentMethodObject(ticket.payment_method) ? getPaymentMethodObject(ticket.payment_method).badge : 'PAY'"></span>
        <span class="flex-1 min-w-0 text-xs font-bold text-[#4F2F2A] truncate"
              x-text="getPaymentMethodObject(ticket.payment_method) ? getPaymentMethodObject(ticket.payment_method).name : (ticket.payment_method || '-')"></span>
        <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold border whitespace-nowrap" :class="statusPillClass(ticket.payment_status)" x-text="statusLabel(ticket.payment_status)"></span>
    </div>
</div>
