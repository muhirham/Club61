<x-app-layout>
    @include('customer.partials.bk-style')

    <div x-data="checkoutApp()" x-init="init()" class="bk-page bk-bar-compact-only text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 pt-4 sm:pt-6 space-y-4 sm:space-y-5">

            {{-- Header --}}
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('customer.cart') }}" title="Back to Cart"
                       class="bk-tap w-10 h-10 shrink-0 flex items-center justify-center rounded-lg bg-white/90 border border-[#E6DAC0] text-[#662721] hover:bg-[#F7F0DB] transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                    </a>
                    <div class="min-w-0">
                        <h1 class="font-display font-black text-xl sm:text-2xl leading-tight">Checkout</h1>
                        <p class="hidden sm:block text-xs text-[#7A5A52] mt-0.5">Add equipment, apply a voucher and choose how to pay.</p>
                    </div>
                </div>
                <div x-show="bookingItems.length > 0 && !paymentStarted" style="display: none;"
                     :class="remainingMs > 0 && remainingMs < 120000 ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-white/90 border-[#E6DAC0] text-[#662721]'"
                     class="shrink-0 flex items-center gap-1.5 h-9 px-3 rounded-lg border whitespace-nowrap" title="Time left to finish checkout">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="font-black text-sm tabular-nums" x-text="timerDisplay">--:--</span>
                </div>
            </div>

            {{-- Tidak ada slot yang ditahan --}}
            <div x-show="bookingItems.length === 0" style="display: none;" class="bk-fade-up bg-white/90 rounded-xl border border-[#E6DAC0] p-8 sm:p-12 text-center space-y-3">
                <div class="w-16 h-16 mx-auto rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center text-[#662721]">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <h3 class="font-display font-black text-lg">Nothing to check out</h3>
                <p class="text-xs text-[#7A5A52] max-w-xs mx-auto">Your held slots were released or already paid. Pick a new time to continue.</p>
                <a href="{{ route('customer.booking') }}"
                   class="bk-tap inline-flex items-center gap-2 h-11 px-6 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-[0_8px_20px_rgba(79,47,42,0.18)] active:scale-95 transition-transform">
                    Book a Court
                </a>
            </div>

            <div x-show="bookingItems.length > 0" style="display: none;" class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5 items-start">

                <div class="lg:col-span-7 xl:col-span-8 space-y-4 sm:space-y-5">

                    {{-- 1. Jadwal --}}
                    <section class="bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-3 mb-1">
                            <div class="min-w-0">
                                <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Your booking</div>
                                <div class="text-sm font-bold truncate" x-text="bookingDateFormatted"></div>
                            </div>
                            <span class="shrink-0 whitespace-nowrap px-2.5 py-1 rounded-full text-[11px] font-bold bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]"
                                  x-text="bookingItems.length + (bookingItems.length === 1 ? ' session' : ' sessions')"></span>
                        </div>
                        <div class="divide-y divide-[#E6DAC0]">
                            <template x-for="(item, idx) in bookingItems" :key="idx">
                                <div class="flex items-center gap-3 py-3">
                                    <div class="w-10 h-10 shrink-0 rounded-lg bg-[#662721] text-[#F7F0DB] flex items-center justify-center">
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
                                    <div class="shrink-0 font-black text-sm tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(item.price)"></div>
                                </div>
                            </template>
                        </div>
                    </section>

                    {{-- 2. Sewa alat --}}
                    <section class="bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-3 mb-1">
                            <div class="min-w-0">
                                <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Equipment Rental &amp; Add-ons</div>
                                <div class="text-[11px] text-[#7A5A52]">Optional — price is per session.</div>
                            </div>
                            <span x-show="addonsTotal > 0" class="shrink-0 whitespace-nowrap px-2.5 py-1 rounded-full text-[11px] font-bold bg-[#662721] text-[#F7F0DB] tabular-nums"
                                  x-text="'+ Rp ' + formatNumber(addonsTotal)"></span>
                        </div>

                        <template x-if="isLoadingAddOns">
                            <div class="space-y-2 pt-2">
                                <template x-for="i in 3" :key="i"><div class="bk-skeleton h-14 rounded-lg"></div></template>
                            </div>
                        </template>

                        <div x-show="!isLoadingAddOns && availableAddOns.length === 0" class="py-4 text-center text-xs text-[#7A5A52]">
                            No equipment available for rent right now.
                        </div>

                        <div x-show="!isLoadingAddOns" class="divide-y divide-[#E6DAC0]">
                            <template x-for="addon in visibleAddOns" :key="addon.id">
                                <div class="flex items-center gap-3 py-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-bold text-sm leading-snug line-clamp-2" x-text="addon.name"></div>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-[11px] whitespace-nowrap">
                                            <span class="font-bold tabular-nums text-[#662721]" x-text="'Rp ' + formatNumber(addon.price)"></span>
                                            <span class="text-[#A08F86]">•</span>
                                            <span class="text-[#7A5A52]" x-text="addon.stock + ' in stock'"></span>
                                        </div>
                                    </div>

                                    <template x-if="!isAddOnSelected(addon.id)">
                                        <button type="button" @click="toggleAddOn(addon)"
                                                class="bk-tap shrink-0 h-9 px-4 rounded-xl border border-[#E6DAC0] bg-[#F7F0DB] hover:bg-[#F7F0DB] text-[#662721] text-xs font-bold whitespace-nowrap active:scale-95 transition-all">
                                            + Add
                                        </button>
                                    </template>

                                    <template x-if="isAddOnSelected(addon.id)">
                                        <div class="bk-pop shrink-0 flex items-center rounded-xl border border-[#662721] bg-white p-0.5 shadow-sm">
                                            <button type="button" @click="decrementOrRemoveById(addon.id)"
                                                    :title="getAddOnQuantity(addon.id) <= 1 ? 'Remove' : 'Less'"
                                                    class="bk-tap w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[#F7F0DB] transition-colors"
                                                    :class="getAddOnQuantity(addon.id) <= 1 ? 'text-rose-500' : 'text-[#662721]'">
                                                <svg x-show="getAddOnQuantity(addon.id) <= 1" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                <svg x-show="getAddOnQuantity(addon.id) > 1" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2.5" d="M5 12h14" /></svg>
                                            </button>
                                            <span class="w-8 text-center font-black text-sm tabular-nums" x-text="getAddOnQuantity(addon.id)"></span>
                                            <button type="button" @click="incrementAddonById(addon.id)" :disabled="getAddOnQuantity(addon.id) >= addon.stock" title="More"
                                                    class="bk-tap w-8 h-8 rounded-lg flex items-center justify-center text-[#662721] hover:bg-[#F7F0DB] transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2.5" d="M12 5v14M5 12h14" /></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <button type="button" x-show="!isLoadingAddOns && availableAddOns.length > addOnPreviewCount" @click="showAllAddOns = !showAllAddOns"
                                class="bk-tap mt-1 w-full h-10 rounded-lg text-xs font-bold text-[#662721] hover:bg-[#F7F0DB] transition-colors"
                                x-text="showAllAddOns ? 'Show less' : 'Show all equipment (' + availableAddOns.length + ')'"></button>
                    </section>

                    {{-- 3. Metode pembayaran --}}
                    <section class="bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5">
                        <div class="mb-3">
                            <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Payment method</div>
                            <div class="text-[11px] text-[#7A5A52]">Paid online via Midtrans — confirmed automatically.</div>
                        </div>

                        <div x-show="grandTotal <= 0" class="flex items-center gap-3 p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900">
                            <svg class="w-5 h-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                            <div class="text-xs font-semibold">Fully covered — no payment needed.</div>
                        </div>

                        <div x-show="grandTotal > 0 && availableMethods.length === 0" class="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-800">
                            No online payment method is available for this total. Please contact the front desk.
                        </div>

                        <div x-show="grandTotal > 0" class="space-y-2" role="radiogroup" aria-label="Payment method">
                            <template x-for="m in visibleMethods" :key="m.code">
                                <button type="button" role="radio" :aria-checked="selectedMethod.code === m.code" @click="selectPaymentMethod(m)"
                                        :class="selectedMethod.code === m.code ? 'border-[#662721] bg-[#F7F0DB] shadow-[0_6px_18px_rgba(79,47,42,0.14)]' : 'border-[#E6DAC0] bg-white hover:border-[#E6DAC0]'"
                                        class="bk-tap w-full p-3 rounded-lg border text-left flex items-center gap-3 transition-all active:scale-[0.99]">
                                    <span class="w-12 h-9 shrink-0 rounded-xl bg-white border border-[#E6DAC0] flex items-center justify-center text-[10px] font-black tracking-wide text-[#662721]" x-text="m.badge"></span>
                                    <span class="flex-1 min-w-0">
                                        <span class="font-bold text-[13px] leading-snug line-clamp-2" x-text="m.name"></span>
                                        <span class="text-[11px] text-[#7A5A52] leading-snug line-clamp-2 mt-0.5" x-text="m.note"></span>
                                    </span>
                                    <span class="w-5 h-5 shrink-0 rounded-full border-2 flex items-center justify-center transition-colors"
                                          :class="selectedMethod.code === m.code ? 'border-[#662721] bg-[#662721] hover:bg-[#511D18]' : 'border-[#E6DAC0] bg-white'">
                                        <span x-show="selectedMethod.code === m.code" class="w-2 h-2 rounded-full bg-white"></span>
                                    </span>
                                </button>
                            </template>
                            <button type="button" x-show="availableMethods.length > methodPreviewCount" @click="showAllMethods = !showAllMethods"
                                    class="bk-tap w-full h-10 rounded-lg text-xs font-bold text-[#662721] hover:bg-[#F7F0DB] transition-colors"
                                    x-text="showAllMethods ? 'Show fewer methods' : 'Show all payment methods (' + availableMethods.length + ')'"></button>
                        </div>
                    </section>
                </div>

                {{-- Ringkasan pembayaran --}}
                <aside class="lg:col-span-5 xl:col-span-4 lg:sticky lg:top-24 bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.10)] p-4 sm:p-5 space-y-4">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">Payment Summary</div>

                    <template x-if="isLoadingMembershipPreview">
                        <div class="bk-skeleton h-14 rounded-lg"></div>
                    </template>

                    {{-- Benefit membership --}}
                    <template x-if="!isLoadingMembershipPreview && membershipBenefit">
                        <div class="rounded-lg border p-3 space-y-2 transition-colors"
                             :class="useMembershipBenefit ? 'bg-[#F7F0DB] border-[#662721]' : 'bg-[#F7F0DB] border-[#E6DAC0]'">
                            <div class="flex items-center gap-2.5">
                                <span class="shrink-0 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider"
                                      :class="useMembershipBenefit ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-[#E6DAC0] text-[#7A5A52] border border-[#E6DAC0]'">Member</span>
                                <span class="flex-1 min-w-0 text-xs font-bold truncate" x-text="membershipBenefit.plan_name"></span>
                                <button type="button" role="switch" :aria-checked="useMembershipBenefit" @click="toggleMembershipBenefit()"
                                        :title="useMembershipBenefit ? 'Turn off membership benefit' : 'Use membership benefit'"
                                        :class="useMembershipBenefit ? 'bg-[#662721]' : 'bg-[#A08F86]'"
                                        class="bk-tap relative shrink-0 w-11 h-6 rounded-full transition-colors">
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform duration-200" :class="useMembershipBenefit ? 'translate-x-5' : ''"></span>
                                </button>
                            </div>
                            <div x-show="useMembershipBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">
                                <template x-if="membershipBenefit.benefit_type === 'HOURS'">
                                    <span>Uses <b class="text-[#662721]" x-text="membershipBenefit.hours_to_consume + ' hr'"></b> of your quota — <b x-text="membershipBenefit.remaining_quota_after + ' hr'"></b> left after.</span>
                                </template>
                                <template x-if="membershipBenefit.benefit_type === 'DISCOUNT_PERCENT'">
                                    <span>Member discount <b class="text-[#662721]" x-text="membershipBenefit.discount_percent + '%'"></b> on this court rental.</span>
                                </template>
                            </div>
                            <div x-show="!useMembershipBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">Benefit off for this booking — your quota stays untouched.</div>
                        </div>
                    </template>

                    {{-- Voucher jam corporate --}}
                    <template x-if="!isLoadingMembershipPreview && sponsorVoucherBenefit">
                        <div class="rounded-lg border p-3 space-y-2 transition-colors"
                             :class="useSponsorVoucherBenefit ? 'bg-[#F0F7F2] border-[#4F2F2A]' : 'bg-[#F7F0DB] border-[#E6DAC0]'">
                            <div class="flex items-center gap-2.5">
                                <span class="shrink-0 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider"
                                      :class="useSponsorVoucherBenefit ? 'bg-[#4F2F2A] text-[#F7F0DB]' : 'bg-[#E6DAC0] text-[#7A5A52] border border-[#E6DAC0]'">Corporate</span>
                                <span class="flex-1 min-w-0 text-xs font-bold truncate" x-text="sponsorVoucherBenefit.plan_name || sponsorVoucherBenefit.organization_name"></span>
                                <button type="button" role="switch" :aria-checked="useSponsorVoucherBenefit" @click="toggleSponsorVoucherBenefit()"
                                        :title="useSponsorVoucherBenefit ? 'Turn off corporate voucher' : 'Use corporate voucher'"
                                        :class="useSponsorVoucherBenefit ? 'bg-[#4F2F2A]' : 'bg-[#A08F86]'"
                                        class="bk-tap relative shrink-0 w-11 h-6 rounded-full transition-colors">
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform duration-200" :class="useSponsorVoucherBenefit ? 'translate-x-5' : ''"></span>
                                </button>
                            </div>
                            <div x-show="useSponsorVoucherBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">
                                Uses <b class="text-[#4F2F2A]" x-text="sponsorVoucherBenefit.hours_to_consume + ' hr'"></b> of free company hours — <b x-text="sponsorVoucherBenefit.remaining_hours_after + ' hr'"></b> left after.
                            </div>
                            <div x-show="!useSponsorVoucherBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">Corporate voucher off for this booking — your hours stay untouched.</div>
                        </div>
                    </template>

                    {{-- Voucher / promo --}}
                    <div class="space-y-2">
                        <label for="voucher-code" class="block text-[11px] font-bold text-[#662721]">Voucher / promo code</label>
                        <div class="flex gap-2">
                            <input id="voucher-code" type="text" x-model="promoCode" :disabled="promoApplied" autocomplete="off" autocapitalize="characters"
                                   placeholder="Enter code" maxlength="30" @keydown.enter.prevent="applyPromo()"
                                   class="flex-1 min-w-0 h-11 px-3.5 rounded-xl border border-[#E6DAC0] bg-white text-sm font-semibold uppercase tracking-wide placeholder:normal-case placeholder:tracking-normal placeholder:font-normal focus:ring-1 focus:ring-[#662721] focus:border-[#662721] focus:outline-none disabled:bg-[#F7F0DB]">
                            <button type="button" x-show="!promoApplied" @click="applyPromo()" :disabled="isCheckingPromo || !promoCode.trim()"
                                    class="bk-tap shrink-0 h-11 px-4 rounded-xl bg-[#662721] text-[#F7F0DB] text-xs font-bold whitespace-nowrap active:scale-95 transition-all disabled:opacity-40">
                                <span x-text="isCheckingPromo ? '…' : 'Apply'"></span>
                            </button>
                            <button type="button" x-show="promoApplied" @click="removePromo()"
                                    class="bk-tap shrink-0 h-11 px-4 rounded-xl bg-rose-50 text-rose-600 border border-rose-200 text-xs font-bold whitespace-nowrap">
                                Remove
                            </button>
                        </div>
                        <div x-show="promoApplied" class="text-[11px] font-bold leading-snug" :class="promoDiscount > 0 ? 'text-emerald-700' : 'text-rose-600'">
                            <span x-show="promoDiscount > 0" x-text="(promoVoucher ? promoVoucher.code : '') + ' applied — you save Rp ' + formatNumber(promoDiscount)"></span>
                            <span x-show="promoDiscount <= 0">This voucher does not apply to the current total.</span>
                        </div>

                        {{-- Voucher saldo milik customer (refund yang dijadikan voucher) --}}
                        <template x-if="myVouchers.length > 0 && !promoApplied">
                            <div class="space-y-1.5 pt-1">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-[#662721]">Your credit vouchers</div>
                                <template x-for="v in myVouchers" :key="v.code">
                                    <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-emerald-50 border border-emerald-200">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-bold text-emerald-900 truncate" x-text="v.code"></div>
                                            <div class="text-[10px] text-emerald-800 truncate" x-text="'Rp ' + formatNumber(Math.round(v.available)) + (v.valid_until ? ' · until ' + v.valid_until : '')"></div>
                                        </div>
                                        <button type="button" @click="promoCode = v.code; applyPromo()" :disabled="isCheckingPromo || v.available <= 0"
                                                class="bk-tap shrink-0 h-8 px-3 rounded-lg bg-emerald-600 text-white text-[11px] font-bold disabled:opacity-50">Use</button>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Rincian harga --}}
                    <div class="space-y-2 text-xs text-[#662721] border-t border-[#E6DAC0] pt-3">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">Court rental</span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(subtotal)"></span>
                        </div>
                        <div x-show="addonsTotal > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0">Equipment add-ons</span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(addonsTotal)"></span>
                        </div>
                        <div x-show="membershipDiscountAmount > 0" class="flex items-start justify-between gap-3 font-bold text-[#662721]">
                            <span class="min-w-0" x-text="'Membership' + (membershipBenefit ? ' · ' + membershipBenefit.plan_name : '')"></span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(membershipDiscountAmount)"></span>
                        </div>
                        <div x-show="sponsorVoucherDiscountAmount > 0" class="flex items-start justify-between gap-3 font-bold text-[#4F2F2A]">
                            <span class="min-w-0">Corporate voucher</span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(sponsorVoucherDiscountAmount)"></span>
                        </div>
                        <div x-show="promoApplied && promoDiscount > 0" class="flex items-start justify-between gap-3 font-bold text-emerald-700">
                            <span class="min-w-0">Voucher discount</span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(promoDiscount)"></span>
                        </div>
                        {{-- Biaya layanan / admin & pajak dari panel admin --}}
                        <div x-show="isAdminFeeApplicable && calculatedAdminFee > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0" x-text="financeSettings.admin_fee_name || 'Service fee'"></span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(calculatedAdminFee)"></span>
                        </div>
                        <div x-show="isTaxApplicable && calculatedTax > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0" x-text="(financeSettings.tax_name || 'Tax') + (financeSettings.tax_type === 'PERCENTAGE' ? ' (' + financeSettings.tax_rate + '%)' : '')"></span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(calculatedTax)"></span>
                        </div>
                        <div class="pt-3 border-t border-[#E6DAC0] flex items-center justify-between gap-3">
                            <span class="font-bold text-sm text-[#4F2F2A]">Grand total</span>
                            <span class="shrink-0 font-black text-xl tabular-nums whitespace-nowrap text-[#4F2F2A]" x-text="'Rp ' + formatNumber(grandTotal)"></span>
                        </div>
                    </div>

                    <button type="button" :disabled="isSubmitting || isExpired" @click="executePayment()"
                            class="bk-tap hidden lg:flex w-full h-12 rounded-lg items-center justify-center gap-2 text-xs font-black uppercase tracking-wider transition-all bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-[0_8px_20px_rgba(79,47,42,0.18)]  active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed">
                        <span x-show="isSubmitting" class="w-4 h-4 border-2 border-[#4F2F2A] border-t-transparent rounded-full animate-spin"></span>
                        <span x-text="payButtonLabel"></span>
                        <svg x-show="!isSubmitting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                    </button>

                    <template x-if="canCancelBooking">
                        <button type="button" @click="cancelCheckout()"
                                class="bk-tap w-full h-10 rounded-lg text-xs font-bold text-[#7A5A52] hover:text-rose-600 hover:bg-rose-50 transition-colors">
                            Cancel &amp; pick another time
                        </button>
                    </template>

                    <div class="flex items-center justify-center gap-1.5 text-[10px] text-[#7A5A52]">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span>Secure payment by Midtrans</span>
                    </div>
                </aside>
            </div>
        </div>

        {{-- Bar bawah (HP & tablet) --}}
        <div x-show="bookingItems.length > 0" style="display: none;" class="bk-bar bk-bar-compact-only fixed inset-x-0 z-30 px-3 sm:px-8 lg:px-12 2xl:px-16 pointer-events-none">
            <div x-ref="bar" class="w-full pointer-events-auto bg-white/95 rounded-xl border border-[#E6DAC0] shadow-[0_18px_44px_rgba(79,47,42,0.22)] p-3 sm:p-4 flex items-center gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-[11px] font-bold text-[#7A5A52] truncate" x-text="grandTotal > 0 ? (selectedMethod.name || 'Choose a payment method') : 'Fully covered'"></div>
                    <div class="font-black text-lg sm:text-xl leading-tight tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(grandTotal)"></div>
                </div>
                <button type="button" :disabled="isSubmitting || isExpired" @click="executePayment()"
                        class="bk-tap shrink-0 h-12 px-5 sm:px-7 rounded-lg flex items-center gap-2 text-xs sm:text-sm font-black uppercase tracking-wider whitespace-nowrap bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-[0_8px_20px_rgba(79,47,42,0.18)] active:scale-95 transition-transform disabled:opacity-60">
                    <span x-show="isSubmitting" class="w-4 h-4 border-2 border-[#4F2F2A] border-t-transparent rounded-full animate-spin"></span>
                    <span x-text="isSubmitting ? 'Processing…' : (grandTotal > 0 ? 'Pay Now' : 'Confirm')"></span>
                </button>
            </div>
        </div>

        {{-- Pembayaran selesai (sudah LUNAS di server: hanya satu jalan lanjut, ke e-ticket) --}}
        <div x-show="showPaymentSuccessModal" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div x-show="showPaymentSuccessModal" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
                <div class="bk-pop w-16 h-16 mx-auto rounded-full bg-emerald-50 border-2 border-emerald-300 flex items-center justify-center text-emerald-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                </div>
                <div class="space-y-1.5">
                    <h3 class="font-display font-black text-xl">Booking confirmed</h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed">Payment is settled and your court is locked in — no further action needed.</p>
                </div>
                <div class="rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] p-3.5 text-xs space-y-2 text-left">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[#7A5A52] shrink-0">Paid with</span>
                        <span class="font-bold text-right min-w-0 truncate" x-text="grandTotal <= 0 ? 'Fully covered' : (selectedMethod.name || 'Online payment')"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[#7A5A52]">Status</span>
                        <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-200">PAID</span>
                    </div>
                    <div class="pt-2 border-t border-[#E6DAC0] flex items-center justify-between gap-3">
                        <span class="font-bold">Total</span>
                        <span class="font-black text-lg tabular-nums whitespace-nowrap text-[#662721]" x-text="'Rp ' + formatNumber(grandTotal)"></span>
                    </div>
                </div>
                <button type="button" @click="completePaymentAndRedirect()"
                        class="bk-tap w-full h-12 rounded-lg flex items-center justify-center gap-2 text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform">
                    View My E-Ticket
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                </button>
            </div>
        </div>

        {{-- Konfirmasi batal checkout --}}
        <div x-show="showCancelModal" style="display: none; z-index: 99999 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4">
            <div x-show="showCancelModal" x-transition.opacity.duration.200ms @click="if (!isCancellingCheckout) showCancelModal = false" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div x-show="showCancelModal" x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-8 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 class="relative w-full sm:max-w-md bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 text-center space-y-4">
                <div class="w-14 h-14 mx-auto rounded-lg bg-rose-50 border border-rose-200 text-rose-600 flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                </div>
                <div class="space-y-1.5">
                    <h3 class="font-display font-black text-xl">Cancel checkout?</h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed">Your held slots will be released right away so other players can book them.</p>
                </div>
                <template x-if="bookingItems && bookingItems.length > 0">
                    <div class="rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] p-3 text-xs space-y-1.5 text-left">
                        <div class="flex justify-between gap-3"><span class="text-[#7A5A52] shrink-0">Court</span><span class="font-bold text-right min-w-0 truncate" x-text="bookingItems[0].court || 'Court'"></span></div>
                        <div class="flex justify-between gap-3"><span class="text-[#7A5A52] shrink-0">Schedule</span><span class="font-bold text-right min-w-0 truncate" x-text="formatDate(bookingItems[0].booking_date) + ' · ' + timeRange(bookingItems[0])"></span></div>
                        <div x-show="bookingItems.length > 1" class="flex justify-between gap-3"><span class="text-[#7A5A52]">Sessions</span><span class="font-bold" x-text="bookingItems.length"></span></div>
                    </div>
                </template>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="showCancelModal = false" :disabled="isCancellingCheckout"
                            class="bk-tap h-12 rounded-lg border border-[#E6DAC0] bg-[#F7F0DB] text-[#662721] text-xs font-bold disabled:opacity-50">
                        Keep paying
                    </button>
                    <button type="button" @click="confirmCancelCheckout()" :disabled="isCancellingCheckout"
                            class="bk-tap h-12 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-black uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-60 transition-colors">
                        <span x-show="isCancellingCheckout" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                        <span x-text="isCancellingCheckout ? 'Releasing…' : 'Yes, cancel'"></span>
                    </button>
                </div>
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
                <a href="{{ route('customer.booking') }}"
                   class="bk-tap flex items-center justify-center w-full h-12 rounded-lg text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform">
                    Pick a new time
                </a>
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
                    <span x-text="noticeModal.buttonText || 'OK, Understood'"></span>
                </button>
            </div>
        </div>

    </div>

    <script>
        function checkoutApp() {
            return {
                canCancelBooking: @json(Auth::check() && Auth::user()->canCancelBooking()),
                financeSettings: @json($clubFinanceSettings ?? \App\Models\Pos\ClubFinanceSetting::getSettings()),
                noticeModal: {
                    show: false,
                    title: '',
                    message: '',
                    type: 'info',
                    buttonText: 'OK, Understood',
                    onClose: null,
                },

                showNotice(title, message, type = 'info', buttonText = 'OK, Understood', onClose = null) {
                    this.noticeModal = {
                        show: true,
                        title,
                        message,
                        type,
                        buttonText,
                        onClose,
                    };
                },

                handleNoticeClose() {
                    this.noticeModal.show = false;
                    if (typeof this.noticeModal.onClose === 'function') {
                        const cb = this.noticeModal.onClose;
                        this.noticeModal.onClose = null;
                        cb();
                    }
                },

                bookingItems: [],
                bookingDateFormatted: 'Today',
                membershipBenefit: null,
                useMembershipBenefit: true,
                sponsorVoucherBenefit: null,
                useSponsorVoucherBenefit: true,
                isLoadingMembershipPreview: false,
                holdData: null,
                expiresAtTime: null,
                remainingMs: 0,
                timerDisplay: '--:--',
                timerInterval: null,
                isExpired: false,
                paymentStarted: false,
                showExpiredModal: false,
                showCancelModal: false,
                isCancellingCheckout: false,
                isSubmitting: false,
                createdBookingId: null,

                showPaymentSuccessModal: false,
                promoCode: '',
                promoApplied: false,
                // Hasil cek server (/vouchers/check); potongannya dihitung ulang dari total terkini (getter promoDiscount).
                promoVoucher: null,
                isCheckingPromo: false,
                myVouchers: [],
                selectedAddOns: [],
                // Katalog alat hanya dari database (/equipments). Dulu ada daftar contoh bawaan dengan id palsu yang
                // ikut terkirim ke server kalau API gagal dimuat.
                availableAddOns: [],
                isLoadingAddOns: true,
                showAllAddOns: false,
                addOnPreviewCount: 4,
                showAllMethods: false,
                methodPreviewCount: 3,
                // Daftar metode dari menu "Metode Pembayaran Online" (OnlinePaymentMethodService) — sama dengan yang
                // divalidasi server. Metode di luar batas nominal (mis. QRIS maks Rp10 juta) disembunyikan otomatis.
                paymentMethods: @js(app(\App\Services\Payment\OnlinePaymentMethodService::class)->forFrontend()),
                selectedMethod: (@js(app(\App\Services\Payment\OnlinePaymentMethodService::class)->forFrontend())[0]) || { id: '', code: '', name: 'Tidak ada metode tersedia', badge: '-', fee: 0, note: '' },

                get availableMethods() {
                    const total = this.grandTotal;
                    return this.paymentMethods.filter(m => (m.min_amount === null || total >= m.min_amount) && (m.max_amount === null || total <= m.max_amount));
                },

                // Daftar metode diringkas (3 teratas); metode yang sedang dipilih selalu ikut tampil.
                get visibleMethods() {
                    const all = this.availableMethods;
                    if (this.showAllMethods || all.length <= this.methodPreviewCount) return all;
                    const top = all.slice(0, this.methodPreviewCount);
                    const selected = all.find(m => m.code === this.selectedMethod.code);
                    return selected && !top.includes(selected) ? [...top, selected] : top;
                },

                get visibleAddOns() {
                    return this.showAllAddOns ? this.availableAddOns : this.availableAddOns.slice(0, this.addOnPreviewCount);
                },

                get payButtonLabel() {
                    if (this.isSubmitting) return 'Processing…';
                    return this.grandTotal > 0 ? 'Pay Now' : 'Confirm Booking';
                },

                ensureSelectedMethodAvailable() {
                    if (! this.availableMethods.some(m => m.code === this.selectedMethod.code) && this.availableMethods.length) {
                        this.selectedMethod = this.availableMethods[0];
                    }
                },

                init() {
                    const saved = localStorage.getItem('club61_cart') || sessionStorage.getItem('club61_cart') ||
                        sessionStorage.getItem('vantage_cart');
                    const holdSaved = localStorage.getItem('club61_hold_data') || sessionStorage.getItem(
                        'club61_hold_data') || sessionStorage.getItem('vantage_hold_data');

                    if (saved) {
                        try {
                            const parsed = JSON.parse(saved);
                            if (parsed && parsed.length > 0) {
                                this.bookingItems = parsed;
                                if (parsed[0].booking_date) {
                                    this.bookingDateFormatted = this.formatDate(parsed[0].booking_date);
                                }
                            }
                        } catch (e) {}
                    }

                    if (holdSaved) {
                        try {
                            this.holdData = JSON.parse(holdSaved);
                            if (this.holdData && this.holdData.expires_at) {
                                this.expiresAtTime = new Date(this.holdData.expires_at).getTime();
                            }
                        } catch (e) {}
                    }

                    // Fallback if no expires_at
                    if (!this.expiresAtTime && this.bookingItems.length > 0) {
                        this.expiresAtTime = Date.now() + (@js(app(\App\Services\Padel\BookingTimeService::class)->holdMinutes()) * 60 * 1000);
                    }

                    // Countdown Timer & Visibility Listener
                    if (this.bookingItems.length > 0) {
                        this.startCountdown();

                        document.addEventListener('visibilitychange', () => {
                            if (document.visibilityState === 'visible') {
                                this.checkExpiry();
                            }
                        });
                    }

                    this.$nextTick(() => window.bkWatchBar && window.bkWatchBar(this.$root, this.$refs.bar));

                    // Load catalog from database & sync finance settings
                    this.fetchEquipments();
                    this.fetchFinanceSettings();
                    this.fetchMembershipBenefitPreview();
                    this.fetchMyVouchers();
                },

                async fetchMyVouchers() {
                    try {
                        const res = await fetch('/api/v1/padel/vouchers/mine', { headers: { 'Accept': 'application/json' } });
                        const json = await res.json();
                        this.myVouchers = (json.success && Array.isArray(json.data)) ? json.data : [];
                    } catch (e) {
                        this.myVouchers = [];
                    }
                },

                /**
                 * Preview (read-only) benefit membership SEBELUM customer menekan Pay Now — supaya
                 * potongan jam/diskon kelihatan di muka, bukan baru ketahuan setelah bayar.
                 */
                async fetchMembershipBenefitPreview() {
                    let bookingIds = [];
                    if (this.holdData && this.holdData.bookings && this.holdData.bookings.length > 0) {
                        bookingIds = this.holdData.bookings.map(b => b.id);
                    }
                    if (bookingIds.length === 0) return;

                    this.isLoadingMembershipPreview = true;
                    try {
                        const res = await fetch('/api/v1/padel/preview-membership-benefit', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            },
                            body: JSON.stringify({ booking_ids: bookingIds })
                        });
                        const json = await res.json();
                        if (json.success && json.data && json.data.has_benefit) {
                            this.membershipBenefit = json.data;
                        } else {
                            this.membershipBenefit = null;
                        }
                        if (json.success && json.data && json.data.sponsor_voucher_benefit && json.data.sponsor_voucher_benefit.has_benefit) {
                            this.sponsorVoucherBenefit = json.data.sponsor_voucher_benefit;
                        } else {
                            this.sponsorVoucherBenefit = null;
                        }
                    } catch (e) {
                        // Tidak fatal — customer tanpa membership/voucher tetap bisa checkout normal
                        this.membershipBenefit = null;
                        this.sponsorVoucherBenefit = null;
                    } finally {
                        this.isLoadingMembershipPreview = false;
                    }
                },

                toggleMembershipBenefit() {
                    this.useMembershipBenefit = !this.useMembershipBenefit;
                },

                toggleSponsorVoucherBenefit() {
                    this.useSponsorVoucherBenefit = !this.useSponsorVoucherBenefit;
                },

                async fetchFinanceSettings() {
                    try {
                        const res = await fetch('/api/v1/padel/finance-settings');
                        const json = await res.json();
                        if (json.success && json.data) {
                            this.financeSettings = json.data;
                        }
                    } catch (e) {
                        // Fallback to server-rendered initial state
                    }
                },

                async fetchEquipments() {
                    this.isLoadingAddOns = true;
                    try {
                        const res = await fetch('/api/v1/padel/equipments');
                        const json = await res.json();
                        this.availableAddOns = (json.success && Array.isArray(json.data))
                            ? json.data.map(eq => ({
                                id: eq.id,
                                name: eq.name,
                                price: parseFloat(eq.rental_price),
                                stock: parseInt(eq.stock_quantity, 10) || 99,
                                quantity: 1,
                            }))
                            : [];
                    } catch (e) {
                        this.availableAddOns = [];
                    } finally {
                        this.isLoadingAddOns = false;
                    }
                },

                startCountdown() {
                    this.checkExpiry();
                    this.timerInterval = setInterval(() => {
                        this.checkExpiry();
                    }, 1000);
                },

                async checkExpiry() {
                    // Jangan melepas slot saat checkout sedang dikirim (termasuk panggilan Midtrans) atau sudah klik bayar.
                    if (!this.expiresAtTime || this.paymentStarted || this.isSubmitting) return;

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

                    let bookingIds = [];
                    if (this.holdData && this.holdData.bookings && this.holdData.bookings.length > 0) {
                        bookingIds = this.holdData.bookings.map(b => b.id);
                    }

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
                            console.error('Error auto-releasing expired slots:', e);
                        }
                    }

                    this.clearStorage();
                },

                clearStorage() {
                    ['club61_cart', 'club61_hold_data', 'vantage_cart', 'vantage_hold_data'].forEach(k => {
                        try { localStorage.removeItem(k); sessionStorage.removeItem(k); } catch (e) {}
                    });
                    window.dispatchEvent(new CustomEvent('cart-updated'));
                },

                get subtotal() {
                    return this.bookingItems.reduce((sum, item) => sum + (Number(item.price) || 0), 0);
                },

                get addonsTotal() {
                    return this.selectedAddOns.reduce((sum, item) => sum + (item.price * (item.quantity || 1)), 0);
                },

                get membershipDiscountAmount() {
                    if (!this.membershipBenefit || !this.useMembershipBenefit) return 0;
                    return this.membershipBenefit.court_discount_amount || 0;
                },

                get sponsorVoucherDiscountAmount() {
                    if (!this.sponsorVoucherBenefit || !this.useSponsorVoucherBenefit) return 0;
                    return this.sponsorVoucherBenefit.court_discount_amount || 0;
                },

                get taxableAmount() {
                    // Urutan potongan mengikuti persis urutan backend checkout(): benefit membership
                    // individual dipotong dari sewa lapangan dulu, baru voucher jam sponsor corporate
                    // dipotong dari SISA sewa lapangan yang belum ter-cover, baru promo code ke total.
                    const courtAfterMembership = Math.max(0, this.subtotal - this.membershipDiscountAmount);
                    const courtAfterSponsorVoucher = Math.max(0, courtAfterMembership - this.sponsorVoucherDiscountAmount);
                    return Math.max(0, courtAfterSponsorVoucher + this.addonsTotal - this.promoDiscount);
                },

                // Dasar potongan voucher = sama dengan server: sewa lapangan setelah benefit member & sponsor + add-on.
                get voucherBase() {
                    const courtAfterMembership = Math.max(0, this.subtotal - this.membershipDiscountAmount);
                    return Math.max(0, courtAfterMembership - this.sponsorVoucherDiscountAmount) + this.addonsTotal;
                },

                get promoDiscount() {
                    const v = this.promoVoucher;
                    const base = this.voucherBase;
                    if (!this.promoApplied || !v || base <= 0 || base < (v.min_order || 0)) return 0;
                    let discount = 0;
                    if (v.type === 'CREDIT') {
                        discount = v.available_balance || 0;
                    } else if (v.type === 'PERCENT') {
                        discount = base * (v.value || 0) / 100;
                        if (v.max_discount) discount = Math.min(discount, v.max_discount);
                    } else {
                        discount = v.value || 0;
                    }
                    return Math.round(Math.min(discount, base));
                },

                get isTaxApplicable() {
                    if (!this.financeSettings || !this.financeSettings.is_tax_enabled) return false;
                    const ch = (this.financeSettings.tax_channels || 'ALL').toUpperCase();
                    return ch === 'ALL' || ch === 'ONLINE_ONLY';
                },

                get calculatedTax() {
                    if (!this.isTaxApplicable) return 0;
                    const taxable = this.taxableAmount;
                    if (taxable <= 0) return 0;
                    if (this.financeSettings.tax_type === 'FIXED') {
                        return Math.round(parseFloat(this.financeSettings.tax_rate) || 0);
                    }
                    return Math.round((taxable * (parseFloat(this.financeSettings.tax_rate) || 0)) / 100);
                },

                get isAdminFeeApplicable() {
                    if (!this.financeSettings || !this.financeSettings.is_admin_fee_enabled) return false;
                    const ch = (this.financeSettings.admin_fee_channels || 'ONLINE_ONLY').toUpperCase();
                    return ch === 'ALL' || ch === 'ONLINE_ONLY';
                },

                get calculatedAdminFee() {
                    if (!this.isAdminFeeApplicable) return 0;
                    const taxable = this.taxableAmount;
                    if (this.financeSettings.admin_fee_type === 'PERCENTAGE') {
                        return Math.round((taxable * (parseFloat(this.financeSettings.admin_fee_amount) || 0)) / 100);
                    }
                    return Math.round(parseFloat(this.financeSettings.admin_fee_amount) || 0);
                },

                get gatewayFee() {
                    return 0;
                },

                get grandTotal() {
                    const total = this.taxableAmount + this.calculatedTax + this.calculatedAdminFee;
                    return total > 0 ? total : 0;
                },

                selectPaymentMethod(method) {
                    this.selectedMethod = method;
                    if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
                },

                isAddOnSelected(id) {
                    return this.selectedAddOns.some(a => a.id === id);
                },

                getAddOnQuantity(id) {
                    const item = this.selectedAddOns.find(a => a.id === id);
                    return item ? (item.quantity || 1) : 0;
                },

                toggleAddOn(addon) {
                    const idx = this.selectedAddOns.findIndex(a => a.id === addon.id);
                    if (idx >= 0) {
                        this.selectedAddOns.splice(idx, 1);
                    } else {
                        this.selectedAddOns.push({
                            ...addon,
                            quantity: 1,
                            stock: addon.stock || 99
                        });
                    }
                },

                incrementAddon(idx) {
                    if (!this.selectedAddOns[idx]) return;
                    const item = this.selectedAddOns[idx];
                    const maxStock = item.stock || 99;
                    if ((item.quantity || 1) < maxStock) {
                        item.quantity = (item.quantity || 1) + 1;
                    }
                },

                incrementAddonById(id) {
                    const idx = this.selectedAddOns.findIndex(a => a.id === id);
                    if (idx >= 0) {
                        this.incrementAddon(idx);
                    }
                },

                // Tombol kiri stepper: kurangi 1, atau hapus alat kalau jumlahnya tinggal 1.
                decrementOrRemoveById(id) {
                    const idx = this.selectedAddOns.findIndex(a => a.id === id);
                    if (idx < 0) return;
                    const item = this.selectedAddOns[idx];
                    if ((item.quantity || 1) > 1) {
                        item.quantity = (item.quantity || 1) - 1;
                    } else {
                        this.selectedAddOns.splice(idx, 1);
                    }
                },

                async applyPromo() {
                    const code = (this.promoCode || '').trim().toUpperCase();
                    if (!code || this.isCheckingPromo) return;
                    this.isCheckingPromo = true;
                    try {
                        const res = await fetch('/api/v1/padel/vouchers/check', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            },
                            body: JSON.stringify({ code, amount: this.voucherBase }),
                        });
                        const json = await res.json().catch(() => ({}));
                        if (res.ok && json.success) {
                            this.promoCode = json.data.code;
                            this.promoVoucher = json.data;
                            this.promoApplied = true;
                        } else {
                            this.showNotice('Voucher Not Applied', json.message || 'The voucher code is invalid or cannot be used for this booking.', 'error', 'Close');
                        }
                    } catch (e) {
                        this.showNotice('Voucher Check Failed', 'Could not check the voucher right now. Please try again.', 'error', 'Close');
                    } finally {
                        this.isCheckingPromo = false;
                    }
                },

                removePromo() {
                    this.promoApplied = false;
                    this.promoVoucher = null;
                    this.promoCode = '';
                },

                timeRange(item) {
                    if (!item) return '';
                    if (item.start_time && item.end_time) return `${item.start_time}–${item.end_time}`;
                    return String(item.time || '').replace(/\s*\(.*\)\s*$/, '');
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

                /**
                 * Request Snap Token & Execute Payment
                 */
                async executePayment() {
                    if (this.isSubmitting || this.isExpired) return;

                    // Total bisa berubah (voucher, add-on) setelah metode dipilih — pastikan metodenya masih berlaku.
                    if (this.grandTotal > 0) {
                        if (! this.availableMethods.length) {
                            this.showNotice('Pembayaran Online Tidak Tersedia', 'Belum ada metode pembayaran online yang bisa dipakai untuk total tagihan ini. Silakan hubungi frontdesk.', 'error', 'Tutup');
                            return;
                        }
                        // Jangan diam-diam membayar dengan metode lain — beri tahu customer dulu, biar dia yang lanjutkan.
                        if (! this.availableMethods.some(m => m.code === this.selectedMethod.code)) {
                            const previous = this.selectedMethod.name;
                            this.ensureSelectedMethodAvailable();
                            this.showNotice('Metode Pembayaran Diganti', `${previous} tidak bisa dipakai untuk total tagihan ini. Metode diganti ke ${this.selectedMethod.name}. Periksa lagi lalu tekan bayar.`, 'info', 'Oke');
                            return;
                        }
                    }

                    this.isSubmitting = true;

                    try {
                        let bookingIds = [];
                        if (this.holdData && this.holdData.bookings && this.holdData.bookings.length > 0) {
                            bookingIds = this.holdData.bookings.map(b => b.id);
                        }

                        if (bookingIds.length === 0) {
                            this.showNotice(
                                'Slot Reservation Not Found',
                                'Court slot reservation data was not found. Please reselect your match schedule.',
                                'error',
                                'Select Schedule',
                                () => {
                                    window.location.href = "{{ route('customer.booking') }}";
                                }
                            );
                            return;
                        }

                        const payload = {
                            booking_ids: bookingIds,
                            equipments: this.selectedAddOns.map(a => ({
                                equipment_id: a.id,
                                quantity: Math.max(1, parseInt(a.quantity, 10) || 1)
                            })),
                            voucher_code: this.promoApplied ? this.promoCode : null,
                            payment_method: this.selectedMethod.code,
                            // Kirim 'NONE' kalau customer sengaja matiin toggle membership, biar backend
                            // beneran skip benefit-nya (bukan cuma tampilan doang) — konsisten dengan preview.
                            membership_balance_id: (this.membershipBenefit && !this.useMembershipBenefit) ? 'NONE' : null,
                            sponsor_voucher_id: (this.sponsorVoucherBenefit && !this.useSponsorVoucherBenefit) ? 'NONE' : null,
                        };

                        const idempotencyKey = (crypto && crypto.randomUUID) ?
                            crypto.randomUUID() :
                            ('IDEM-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9));

                        const res = await fetch('/api/v1/padel/checkout', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Idempotency-Key': idempotencyKey,
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute(
                                    'content'),
                            },
                            body: JSON.stringify(payload)
                        });

                        const json = await res.json();

                        this.isSubmitting = false;

                        if (res.status === 200 && json.success) {
                            const data = json.data;
                            this.createdBookingId = data.booking_id || (data.bookings && data.bookings[0] ? data
                                .bookings[0].booking_id : '');
                            this.createdOrderId = data.order_id || '';

                            // Sudah klik bayar: waktu tahan slot selesai, sekarang berlaku batas bayar (diatur server & Midtrans).
                            // Dulu countdown tetap jalan & saat habis memanggil "lepas slot" — booking dibatalkan padahal
                            // customer sedang membayar di popup Midtrans.
                            this.paymentStarted = true;
                            if (this.timerInterval) clearInterval(this.timerInterval);
                            // Hapus data hold dari browser SEKARANG (bukan baru saat redirect): kalau halaman ter-reload
                            // (pindah ke aplikasi e-wallet, tab dibuang browser), keranjang/checkout tidak lagi membaca
                            // countdown lama lalu memanggil "lepas slot" untuk booking yang sedang dibayar.
                            this.clearStorage();

                            if (data.driver === 'midtrans' && window.snap && typeof window.snap.pay === 'function' && !
                                data.is_mock && data.snap_token) {
                                // Midtrans Snap Pop-Up
                                window.snap.pay(data.snap_token, {
                                    onSuccess: (result) => {
                                        this.clearSessionAndRedirect(this.createdBookingId, this
                                            .createdOrderId);
                                    },
                                    onPending: (result) => {
                                        this.clearSessionAndRedirect(this.createdBookingId, this
                                            .createdOrderId);
                                    },
                                    onError: (result) => {
                                        this.showNotice(
                                            'Payment Declined',
                                            'Payment was declined or failed to process. Please try again.',
                                            'error',
                                            'Try Again'
                                        );
                                    },
                                    onClose: () => {
                                        this.showNotice(
                                            'Payment Window Closed',
                                            'The payment session window was closed. You can view transaction status or complete payment on the Invoice page.',
                                            'info',
                                            'View Invoice Page',
                                            () => {
                                                this.clearSessionAndRedirect(this.createdBookingId, this
                                                    .createdOrderId);
                                            }
                                        );
                                    }
                                });
                            } else if (data.is_mock || !data.snap_token) {
                                // Simulator lokal / total Rp0 ditanggung kuota-voucher: sudah lunas di server.
                                this.showPaymentSuccessModal = true;
                            } else if (data.redirect_url || data.payment_url) {
                                // Popup Snap tidak termuat (client key kosong / snap.js diblokir) → halaman pembayaran Midtrans.
                                // Dulu jatuh ke cabang simulator di atas: customer melihat "pembayaran berhasil" padahal belum bayar.
                                window.location.href = data.redirect_url || data.payment_url;
                            } else {
                                this.showNotice('Payment Not Started', 'The payment window could not be opened. Continue payment from the Invoice page.', 'error', 'View Invoice Page', () => {
                                    this.clearSessionAndRedirect(this.createdBookingId, this.createdOrderId);
                                });
                            }
                        } else {
                            this.showNotice('Payment Failed', json.message || 'Failed to process payment.',
                                'error', 'Close');
                        }
                    } catch (e) {
                        this.isSubmitting = false;
                        this.showNotice('System Error', 'An unexpected error occurred while processing checkout.', 'error',
                            'Close');
                    }
                },

                clearSessionAndRedirect(bookingId, orderId) {
                    this.clearStorage();
                    let target = "{{ route('customer.invoice') }}";
                    const params = [];
                    if (bookingId) params.push(`booking_id=${bookingId}`);
                    if (orderId) params.push(`order_id=${orderId}`);
                    if (params.length > 0) target += '?' + params.join('&');
                    window.location.href = target;
                },

                completePaymentAndRedirect() {
                    this.clearSessionAndRedirect(this.createdBookingId, this.createdOrderId);
                },

                cancelCheckout() {
                    if (!this.canCancelBooking) {
                        this.showNotice('Access Denied', 'You do not have permission to cancel this booking.', 'error',
                            'Close');
                        return;
                    }
                    this.showCancelModal = true;
                },

                async confirmCancelCheckout() {
                    if (!this.canCancelBooking) {
                        this.showNotice('Access Denied', 'You do not have permission to cancel this booking.',
                            'error', 'Close');
                        return;
                    }
                    this.isCancellingCheckout = true;

                    let bookingIds = [];
                    if (this.holdData && this.holdData.bookings && this.holdData.bookings.length > 0) {
                        bookingIds = this.holdData.bookings.map(b => b.id);
                    }

                    if (bookingIds.length > 0) {
                        try {
                            await fetch('/api/v1/padel/release-slot', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                        .getAttribute('content'),
                                },
                                body: JSON.stringify({
                                    booking_ids: bookingIds,
                                    only_locked: true
                                })
                            });
                        } catch (e) {
                            console.error('Error releasing slots on cancel:', e);
                        }
                    }

                    this.clearStorage();

                    window.location.href = "{{ route('customer.booking') }}";
                }
            }
        }
    </script>

    @push('scripts')
        @if (config('services.payment.driver', 'midtrans') === 'midtrans')
            @include('customer.partials.midtrans-snap')
        @endif
    @endpush
</x-app-layout>
