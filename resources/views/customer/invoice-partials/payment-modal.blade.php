<!-- Pilih metode pembayaran (bottom sheet di HP) -->
<div x-show="showPaymentModal" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
    <div x-show="showPaymentModal" x-transition.opacity.duration.200ms @click="showPaymentModal = false" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div x-show="showPaymentModal" x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         class="relative w-full sm:max-w-md max-h-[85vh] flex flex-col bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl">
        <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3 border-b border-[#E6DAC0]">
            <h3 class="font-bold text-base">Payment method</h3>
            <button type="button" @click="showPaymentModal = false" title="Close"
                    class="bk-tap w-9 h-9 rounded-xl flex items-center justify-center text-[#7A5A52] hover:bg-[#F7F0DB] hover:text-[#4F2F2A]">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
        <div class="overflow-y-auto p-4 space-y-2 pb-[calc(1rem+env(safe-area-inset-bottom,0px))] sm:pb-4" role="radiogroup" aria-label="Payment method">
            <template x-for="m in availableMethods" :key="m.id">
                <button type="button" role="radio" :aria-checked="selectedMethod.id === m.id" @click="selectPaymentMethod(m)"
                        :class="selectedMethod.id === m.id ? 'border-[#662721] bg-[#F7F0DB]' : 'border-[#E6DAC0] bg-white hover:border-[#E6DAC0]'"
                        class="bk-tap w-full p-3 rounded-lg border text-left flex items-center gap-3 transition-colors">
                    <span class="w-12 h-9 shrink-0 rounded-xl bg-white border border-[#E6DAC0] flex items-center justify-center font-black text-[10px] text-[#662721]" x-text="m.badge"></span>
                    <span class="flex-1 min-w-0">
                        <span class="font-bold text-[13px] leading-snug line-clamp-2" x-text="m.name"></span>
                        <span class="text-[11px] text-[#7A5A52] leading-snug line-clamp-2 mt-0.5" x-text="m.note"></span>
                        <span x-show="m.fee > 0" class="text-[11px] font-bold text-[#662721] mt-0.5" x-text="'+ Rp ' + formatNumber(m.fee) + ' fee'"></span>
                    </span>
                    <span class="w-5 h-5 shrink-0 rounded-full border-2 flex items-center justify-center"
                          :class="selectedMethod.id === m.id ? 'border-[#662721] bg-[#662721] hover:bg-[#511D18]' : 'border-[#E6DAC0] bg-white'">
                        <span x-show="selectedMethod.id === m.id" class="w-2 h-2 rounded-full bg-white"></span>
                    </span>
                </button>
            </template>
        </div>
    </div>
</div>
