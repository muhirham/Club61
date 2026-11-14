<x-app-layout>
    @include('customer.partials.bk-style')

    <div x-data="checkoutApp()" x-init="init()" class="bk-page bk-bar-compact-only text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 pt-4 sm:pt-6 space-y-4 sm:space-y-5">

            {{-- Header --}}
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('customer.cart') }}" title="{{ __('checkout.back_to_cart') }}"
                       class="bk-tap w-10 h-10 shrink-0 flex items-center justify-center rounded-lg bg-white/90 border border-[#E6DAC0] text-[#662721] hover:bg-[#F7F0DB] transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                    </a>
                    <div class="min-w-0">
                        <h1 class="font-display font-black text-xl sm:text-2xl leading-tight">{{ __('checkout.title') }}</h1>
                        <p class="hidden sm:block text-xs text-[#7A5A52] mt-0.5">{{ __('checkout.subtitle') }}</p>
                    </div>
                </div>
                <div x-show="bookingItems.length > 0 && !paymentStarted" style="display: none;"
                     :class="remainingMs > 0 && remainingMs < 120000 ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-white/90 border-[#E6DAC0] text-[#662721]'"
                     class="shrink-0 flex items-center gap-1.5 h-9 px-3 rounded-lg border whitespace-nowrap" title="{{ __('checkout.time_left') }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="font-black text-sm tabular-nums" x-text="timerDisplay">--:--</span>
                </div>
            </div>

            {{-- Tidak ada slot yang ditahan --}}
            <div x-show="bookingItems.length === 0" style="display: none;" class="bk-fade-up bg-white/90 rounded-xl border border-[#E6DAC0] p-8 sm:p-12 text-center space-y-3">
                <div class="w-16 h-16 mx-auto rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center text-[#662721]">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <h3 class="font-display font-black text-lg">{{ __('checkout.empty_title') }}</h3>
                <p class="text-xs text-[#7A5A52] max-w-xs mx-auto">{{ __('checkout.empty_text') }}</p>
                <a href="{{ route('customer.booking') }}"
                   class="bk-tap inline-flex items-center gap-2 h-11 px-6 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-[0_8px_20px_rgba(79,47,42,0.18)] active:scale-95 transition-transform">
                    {{ __('checkout.book_court') }}
                </a>
            </div>

            <div x-show="bookingItems.length > 0" style="display: none;" class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5 items-start">

                <div class="lg:col-span-7 xl:col-span-8 space-y-4 sm:space-y-5">

                    {{-- 1. Jadwal --}}
                    <section class="bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-3 mb-1">
                            <div class="min-w-0">
                                <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('checkout.your_booking') }}</div>
                                <div class="text-sm font-bold truncate" x-text="bookingDateFormatted"></div>
                            </div>
                            <span class="shrink-0 whitespace-nowrap px-2.5 py-1 rounded-full text-[11px] font-bold bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]"
                                  x-text="plural('sessions', bookingItems.length)"></span>
                        </div>
                        <div class="divide-y divide-[#E6DAC0]">
                            <template x-for="(item, idx) in bookingItems" :key="idx">
                                <div class="flex items-center gap-3 py-3">
                                    <div class="w-10 h-10 shrink-0 rounded-lg bg-[#662721] text-[#F7F0DB] flex items-center justify-center">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2" stroke-width="1.8" /><path stroke-width="1.8" stroke-linecap="round" d="M4 12h16M12 3v18" /></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-extrabold text-sm leading-snug line-clamp-2" x-text="item.court || i18n.court"></div>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-[#7A5A52] whitespace-nowrap">
                                            <span class="font-semibold tabular-nums" x-text="timeRange(item)"></span>
                                            <span x-show="item.duration_hours" class="px-1.5 py-px rounded-md bg-[#F7F0DB] border border-[#E6DAC0] font-bold text-[10px] text-[#662721]"
                                                  x-text="plural('hrs', item.duration_hours)"></span>
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
                                <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('checkout.addons_title') }}</div>
                                <div class="text-[11px] text-[#7A5A52]">{{ __('checkout.addons_hint') }}</div>
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
                            {{ __('checkout.addons_empty') }}
                        </div>

                        <div x-show="!isLoadingAddOns" class="divide-y divide-[#E6DAC0]">
                            <template x-for="addon in visibleAddOns" :key="addon.id">
                                <div class="flex items-center gap-3 py-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-bold text-sm leading-snug line-clamp-2" x-text="addon.name"></div>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-[11px] whitespace-nowrap">
                                            <span class="font-bold tabular-nums text-[#662721]" x-text="'Rp ' + formatNumber(addon.price)"></span>
                                            <span class="text-[#A08F86]">•</span>
                                            <span class="text-[#7A5A52]" x-text="t('in_stock', { count: addon.stock })"></span>
                                        </div>
                                    </div>

                                    <template x-if="!isAddOnSelected(addon.id)">
                                        <button type="button" @click="toggleAddOn(addon)"
                                                class="bk-tap shrink-0 h-9 px-4 rounded-xl border border-[#E6DAC0] bg-[#F7F0DB] hover:bg-[#F7F0DB] text-[#662721] text-xs font-bold whitespace-nowrap active:scale-95 transition-all">
                                            {{ __('checkout.add') }}
                                        </button>
                                    </template>

                                    <template x-if="isAddOnSelected(addon.id)">
                                        <div class="bk-pop shrink-0 flex items-center rounded-xl border border-[#662721] bg-white p-0.5 shadow-sm">
                                            <button type="button" @click="decrementOrRemoveById(addon.id)"
                                                    :title="getAddOnQuantity(addon.id) <= 1 ? i18n.remove : i18n.less"
                                                    class="bk-tap w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[#F7F0DB] transition-colors"
                                                    :class="getAddOnQuantity(addon.id) <= 1 ? 'text-rose-500' : 'text-[#662721]'">
                                                <svg x-show="getAddOnQuantity(addon.id) <= 1" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                <svg x-show="getAddOnQuantity(addon.id) > 1" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2.5" d="M5 12h14" /></svg>
                                            </button>
                                            <span class="w-8 text-center font-black text-sm tabular-nums" x-text="getAddOnQuantity(addon.id)"></span>
                                            <button type="button" @click="incrementAddonById(addon.id)" :disabled="getAddOnQuantity(addon.id) >= addon.stock" title="{{ __('checkout.more') }}"
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
                                x-text="showAllAddOns ? i18n.show_less : t('show_all_equipment', { count: availableAddOns.length })"></button>
                    </section>

                    {{-- 3. Metode pembayaran --}}
                    <section class="bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.08)] p-4 sm:p-5">
                        <div class="mb-3">
                            <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('checkout.payment_method') }}</div>
                            <div class="text-[11px] text-[#7A5A52]">{{ __('checkout.payment_method_hint') }}</div>
                        </div>

                        <div x-show="grandTotal <= 0" class="flex items-center gap-3 p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900">
                            <svg class="w-5 h-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                            <div class="text-xs font-semibold">{{ __('checkout.fully_covered_no_payment') }}</div>
                        </div>

                        <div x-show="grandTotal > 0 && availableMethods.length === 0" class="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-800">
                            {{ __('checkout.no_method') }}
                        </div>

                        <div x-show="grandTotal > 0" class="space-y-2" role="radiogroup" aria-label="{{ __('checkout.payment_method') }}">
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
                                    x-text="showAllMethods ? i18n.show_fewer_methods : t('show_all_methods', { count: availableMethods.length })"></button>
                        </div>
                    </section>
                </div>

                {{-- Ringkasan pembayaran --}}
                <aside class="lg:col-span-5 xl:col-span-4 lg:sticky lg:top-24 bg-white/90 rounded-xl border border-[#E6DAC0] shadow-[0_8px_30px_rgba(79,47,42,0.10)] p-4 sm:p-5 space-y-4">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('checkout.payment_summary') }}</div>

                    <template x-if="isLoadingMembershipPreview">
                        <div class="bk-skeleton h-14 rounded-lg"></div>
                    </template>

                    {{-- Benefit membership --}}
                    <template x-if="!isLoadingMembershipPreview && membershipBenefit">
                        <div class="rounded-lg border p-3 space-y-2 transition-colors"
                             :class="useMembershipBenefit ? 'bg-[#F7F0DB] border-[#662721]' : 'bg-[#F7F0DB] border-[#E6DAC0]'">
                            <div class="flex items-center gap-2.5">
                                <span class="shrink-0 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider"
                                      :class="useMembershipBenefit ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-[#E6DAC0] text-[#7A5A52] border border-[#E6DAC0]'">{{ __('checkout.member_badge') }}</span>
                                <span class="flex-1 min-w-0 text-xs font-bold truncate" x-text="membershipBenefit.plan_name"></span>
                                <button type="button" role="switch" :aria-checked="useMembershipBenefit" @click="toggleMembershipBenefit()"
                                        :title="useMembershipBenefit ? i18n.membership_turn_off : i18n.membership_turn_on"
                                        :class="useMembershipBenefit ? 'bg-[#662721]' : 'bg-[#A08F86]'"
                                        class="bk-tap relative shrink-0 w-11 h-6 rounded-full transition-colors">
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform duration-200" :class="useMembershipBenefit ? 'translate-x-5' : ''"></span>
                                </button>
                            </div>
                            <div x-show="useMembershipBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">
                                <template x-if="membershipBenefit.benefit_type === 'HOURS'">
                                    <span>{!! __('checkout.member_hours_html', ['hours' => '<b class="text-[#662721]" x-text="t(\'hr\', { count: membershipBenefit.hours_to_consume })"></b>', 'left' => '<b x-text="t(\'hr\', { count: membershipBenefit.remaining_quota_after })"></b>']) !!}</span>
                                </template>
                                <template x-if="membershipBenefit.benefit_type === 'MONTHLY_BILLING'">
                                    <span x-text="t('member_monthly', { company: membershipBenefit.plan_name })"></span>
                                </template>
                                <template x-if="membershipBenefit.benefit_type === 'DISCOUNT_PERCENT'">
                                    <span>{!! __('checkout.member_discount_html', ['percent' => '<b class="text-[#662721]" x-text="membershipBenefit.discount_percent + \'%\'"></b>']) !!}</span>
                                </template>
                            </div>
                            <div x-show="!useMembershipBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">{{ __('checkout.member_off') }}</div>
                        </div>
                    </template>
                    <template x-if="!isLoadingMembershipPreview && !membershipBenefit && membershipNotice">
                        <div data-membership-notice class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[11px] leading-relaxed text-amber-900" x-text="membershipNotice"></div>
                    </template>

                    {{-- Voucher jam corporate --}}
                    <template x-if="!isLoadingMembershipPreview && sponsorVoucherBenefit">
                        <div class="rounded-lg border p-3 space-y-2 transition-colors"
                             :class="useSponsorVoucherBenefit ? 'bg-[#F0F7F2] border-[#4F2F2A]' : 'bg-[#F7F0DB] border-[#E6DAC0]'">
                            <div class="flex items-center gap-2.5">
                                <span class="shrink-0 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider"
                                      :class="useSponsorVoucherBenefit ? 'bg-[#4F2F2A] text-[#F7F0DB]' : 'bg-[#E6DAC0] text-[#7A5A52] border border-[#E6DAC0]'">{{ __('checkout.corporate_badge') }}</span>
                                <span class="flex-1 min-w-0 text-xs font-bold truncate" x-text="sponsorVoucherBenefit.plan_name || sponsorVoucherBenefit.organization_name"></span>
                                <button type="button" role="switch" :aria-checked="useSponsorVoucherBenefit" @click="toggleSponsorVoucherBenefit()"
                                        :title="useSponsorVoucherBenefit ? i18n.corporate_turn_off : i18n.corporate_turn_on"
                                        :class="useSponsorVoucherBenefit ? 'bg-[#4F2F2A]' : 'bg-[#A08F86]'"
                                        class="bk-tap relative shrink-0 w-11 h-6 rounded-full transition-colors">
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform duration-200" :class="useSponsorVoucherBenefit ? 'translate-x-5' : ''"></span>
                                </button>
                            </div>
                            <div x-show="useSponsorVoucherBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">
                                {!! __('checkout.corporate_hours_html', ['hours' => '<b class="text-[#4F2F2A]" x-text="t(\'hr\', { count: sponsorVoucherBenefit.hours_to_consume })"></b>', 'left' => '<b x-text="t(\'hr\', { count: sponsorVoucherBenefit.remaining_hours_after })"></b>']) !!}
                            </div>
                            <div x-show="!useSponsorVoucherBenefit" class="text-[11px] leading-relaxed text-[#7A5A52]">{{ __('checkout.corporate_off') }}</div>
                        </div>
                    </template>

                    {{-- Voucher / promo --}}
                    <div class="space-y-2">
                        <label for="voucher-code" class="block text-[11px] font-bold text-[#662721]">{{ __('checkout.voucher_label') }}</label>
                        <div class="flex gap-2">
                            <input id="voucher-code" type="text" x-model="promoCode" :disabled="promoApplied" autocomplete="off" autocapitalize="characters"
                                   placeholder="{{ __('checkout.voucher_placeholder') }}" maxlength="30" @keydown.enter.prevent="applyPromo()"
                                   class="flex-1 min-w-0 h-11 px-3.5 rounded-xl border border-[#E6DAC0] bg-white text-sm font-semibold uppercase tracking-wide placeholder:normal-case placeholder:tracking-normal placeholder:font-normal focus:ring-1 focus:ring-[#662721] focus:border-[#662721] focus:outline-none disabled:bg-[#F7F0DB]">
                            <button type="button" x-show="!promoApplied" @click="applyPromo()" :disabled="isCheckingPromo || !promoCode.trim()"
                                    class="bk-tap shrink-0 h-11 px-4 rounded-xl bg-[#662721] text-[#F7F0DB] text-xs font-bold whitespace-nowrap active:scale-95 transition-all disabled:opacity-40">
                                <span x-text="isCheckingPromo ? '…' : i18n.apply"></span>
                            </button>
                            <button type="button" x-show="promoApplied" @click="removePromo()"
                                    class="bk-tap shrink-0 h-11 px-4 rounded-xl bg-rose-50 text-rose-600 border border-rose-200 text-xs font-bold whitespace-nowrap">
                                {{ __('checkout.remove') }}
                            </button>
                        </div>
                        <div x-show="corporateVoucherOn && !promoApplied" class="text-[11px] leading-snug text-[#7A5A52]">
                            {{ __('checkout.corporate_on_hint') }}
                        </div>
                        <div x-show="promoApplied" class="text-[11px] font-bold leading-snug" :class="promoDiscount > 0 ? 'text-emerald-700' : 'text-rose-600'">
                            <span x-show="promoDiscount > 0" x-text="t('promo_applied', { code: promoVoucher ? promoVoucher.code : '', amount: formatNumber(promoDiscount) })"></span>
                            <span x-show="promoDiscount <= 0">{{ __('checkout.voucher_not_apply') }}</span>
                        </div>
                        <template x-if="promoApplied && promoTerms">
                            <div data-voucher-terms class="px-3 py-2 rounded-xl bg-emerald-50 border border-emerald-200 text-[11px] leading-relaxed text-emerald-900">
                                <div x-show="promoTerms.name" class="font-bold" x-text="promoTerms.name"></div>
                                <div x-text="promoTerms.headline"></div>
                                <div x-show="promoTerms.rules.length" class="text-emerald-800/80" x-text="promoTerms.rules.join(' · ')"></div>
                            </div>
                        </template>

                        {{-- Promo yang sudah diklaim customer (cara sebar C) — aturan tetap dicek server saat "Use" --}}
                        <template x-if="myPromos.length > 0 && !promoApplied">
                            <div class="space-y-1.5 pt-1" data-claimed-promos>
                                <div class="text-[10px] font-bold uppercase tracking-wider text-[#662721]">{{ __('checkout.claimed_promos') }}</div>
                                <template x-for="p in myPromos" :key="p.code">
                                    <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-rose-50 border border-rose-200">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-bold text-rose-900 truncate" x-text="p.name || p.code"></div>
                                            <div class="text-[10px] text-rose-800 truncate" x-text="p.headline + (p.valid_until ? ' · ' + t('until', { date: p.valid_until }) : '')"></div>
                                        </div>
                                        <button type="button" @click="promoCode = p.code; applyPromo()" :disabled="isCheckingPromo"
                                                class="bk-tap shrink-0 h-8 px-3 rounded-lg bg-[#B91C1C] text-white text-[11px] font-bold disabled:opacity-50">{{ __('checkout.use') }}</button>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Voucher saldo milik customer (refund yang dijadikan voucher) --}}
                        <template x-if="myVouchers.length > 0 && !promoApplied">
                            <div class="space-y-1.5 pt-1">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-[#662721]">{{ __('checkout.credit_vouchers') }}</div>
                                <template x-for="v in myVouchers" :key="v.code">
                                    <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-emerald-50 border border-emerald-200">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-bold text-emerald-900 truncate" x-text="v.code"></div>
                                            <div class="text-[10px] text-emerald-800 truncate" x-text="'Rp ' + formatNumber(Math.round(v.available)) + (v.valid_until ? ' · ' + t('until', { date: v.valid_until }) : '')"></div>
                                        </div>
                                        <button type="button" @click="promoCode = v.code; applyPromo()" :disabled="isCheckingPromo || v.available <= 0"
                                                class="bk-tap shrink-0 h-8 px-3 rounded-lg bg-emerald-600 text-white text-[11px] font-bold disabled:opacity-50">{{ __('checkout.use') }}</button>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Rincian harga --}}
                    <div class="space-y-2 text-xs text-[#662721] border-t border-[#E6DAC0] pt-3">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">{{ __('checkout.court_rental') }}</span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(subtotal)"></span>
                        </div>
                        {{-- Paket / voucher ada tapi jam ini di luar ketentuannya (penjelasan lengkap di pop-up sekali). --}}
                        <div x-show="accessNotice" data-access-notice class="flex items-start gap-1.5 text-[11px] text-amber-800" style="display: none;">
                            <svg class="w-3.5 h-3.5 mt-px shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span x-text="accessNotice ? accessNotice.short : ''"></span>
                        </div>
                        <div x-show="addonsTotal > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0">{{ __('checkout.equipment_addons') }}</span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(addonsTotal)"></span>
                        </div>
                        <div x-show="membershipDiscountAmount > 0" class="flex items-start justify-between gap-3 font-bold text-[#662721]">
                            <span class="min-w-0" x-text="membershipBenefit && membershipBenefit.benefit_type === 'MONTHLY_BILLING' ? t('member_monthly_row', { company: membershipBenefit.plan_name }) : 'Membership' + (membershipBenefit ? ' · ' + membershipBenefit.plan_name : '')"></span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(membershipDiscountAmount)"></span>
                        </div>
                        <div x-show="sponsorVoucherDiscountAmount > 0" class="flex items-start justify-between gap-3 font-bold text-[#4F2F2A]">
                            <span class="min-w-0">{{ __('checkout.corporate_voucher') }}</span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(sponsorVoucherDiscountAmount)"></span>
                        </div>
                        <div x-show="promoApplied && promoDiscount > 0" class="flex items-start justify-between gap-3 font-bold text-emerald-700">
                            <span class="min-w-0" x-text="i18n.voucher_discount + (promoVoucher && promoVoucher.type === 'PERCENT' ? ' (' + formatPercent(promoVoucher.value) + ')' : '')"></span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(promoDiscount)"></span>
                        </div>
                        {{-- Biaya layanan / admin & pajak dari panel admin --}}
                        <div x-show="isAdminFeeApplicable && calculatedAdminFee > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0" x-text="financeSettings.admin_fee_name || i18n.service_fee"></span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(calculatedAdminFee)"></span>
                        </div>
                        <div x-show="isTaxApplicable && calculatedTax > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0" x-text="(financeSettings.tax_name || i18n.tax) + (financeSettings.tax_type === 'PERCENTAGE' ? ' (' + financeSettings.tax_rate + '%)' : '')"></span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(calculatedTax)"></span>
                        </div>
                        <div class="pt-3 border-t border-[#E6DAC0] flex items-center justify-between gap-3">
                            <span class="font-bold text-sm text-[#4F2F2A]">{{ __('checkout.grand_total') }}</span>
                            <span class="shrink-0 font-black text-xl tabular-nums whitespace-nowrap text-[#4F2F2A]" x-text="'Rp ' + formatNumber(grandTotal)"></span>
                        </div>
                    </div>

                    <button type="button" :disabled="isSubmitting || isExpired" @click="openPayConfirm()"
                            class="bk-tap hidden lg:flex w-full h-12 rounded-lg items-center justify-center gap-2 text-xs font-black uppercase tracking-wider transition-all bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-[0_8px_20px_rgba(79,47,42,0.18)]  active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed">
                        <span x-show="isSubmitting" class="w-4 h-4 border-2 border-[#4F2F2A] border-t-transparent rounded-full animate-spin"></span>
                        <span x-text="payButtonLabel"></span>
                        <svg x-show="!isSubmitting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                    </button>

                    <template x-if="canCancelBooking">
                        <button type="button" @click="cancelCheckout()"
                                class="bk-tap w-full h-10 rounded-lg text-xs font-bold text-[#7A5A52] hover:text-rose-600 hover:bg-rose-50 transition-colors">
                            {{ __('checkout.cancel_pick_another') }}
                        </button>
                    </template>

                    <div class="flex items-center justify-center gap-1.5 text-[10px] text-[#7A5A52]">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span>{{ __('checkout.secure_payment') }}</span>
                    </div>
                </aside>
            </div>
        </div>

        {{-- Bar bawah (HP & tablet) --}}
        <div x-show="bookingItems.length > 0" style="display: none;" class="bk-bar bk-bar-compact-only fixed inset-x-0 z-30 px-3 sm:px-8 lg:px-12 2xl:px-16 pointer-events-none">
            <div x-ref="bar" class="w-full pointer-events-auto bg-white/95 rounded-xl border border-[#E6DAC0] shadow-[0_18px_44px_rgba(79,47,42,0.22)] p-3 sm:p-4 flex items-center gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-[11px] font-bold text-[#7A5A52] truncate" x-text="grandTotal > 0 ? (selectedMethod.name || i18n.choose_method) : i18n.fully_covered"></div>
                    <div class="font-black text-lg sm:text-xl leading-tight tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(grandTotal)"></div>
                </div>
                <button type="button" :disabled="isSubmitting || isExpired" @click="openPayConfirm()"
                        class="bk-tap shrink-0 h-12 px-5 sm:px-7 rounded-lg flex items-center gap-2 text-xs sm:text-sm font-black uppercase tracking-wider whitespace-nowrap bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-[0_8px_20px_rgba(79,47,42,0.18)] active:scale-95 transition-transform disabled:opacity-60">
                    <span x-show="isSubmitting" class="w-4 h-4 border-2 border-[#4F2F2A] border-t-transparent rounded-full animate-spin"></span>
                    <span x-text="isSubmitting ? i18n.processing : (grandTotal > 0 ? i18n.pay_now : i18n.confirm)"></span>
                </button>
            </div>
        </div>

        {{-- Cek kembali pesanan + ketentuan sebelum ke Midtrans (bottom sheet di HP, dialog di layar lebar).
             Di bawah modal lain (99999): waktu tahan habis / pemberitahuan tetap tampil di atasnya. --}}
        <div x-show="payConfirm.show" style="display: none; z-index: 99998 !important;" class="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4"
             @keydown.escape.window="payConfirm.show = false">
            <div x-show="payConfirm.show" x-transition.opacity.duration.200ms @click="payConfirm.show = false" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div x-show="payConfirm.show" role="dialog" aria-modal="true" aria-labelledby="co-confirm-title"
                 x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0" x-transition:enter-end="translate-y-0 sm:opacity-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0 sm:opacity-100" x-transition:leave-end="translate-y-full sm:translate-y-4 sm:opacity-0"
                 class="relative w-full sm:max-w-lg max-h-[92vh] overflow-y-auto bg-white rounded-t-xl sm:rounded-xl border-t-2 sm:border-2 border-[#662721] shadow-2xl p-5 sm:p-6 pb-[calc(1.25rem+env(safe-area-inset-bottom,0px))] sm:pb-6 space-y-4">
                <div class="sm:hidden mx-auto w-10 h-1.5 rounded-full bg-[#E6DAC0]"></div>
                <div>
                    <h3 id="co-confirm-title" class="font-display font-black text-lg">{{ __('checkout.confirm_title') }}</h3>
                    <p class="text-xs text-[#7A5A52] mt-0.5">{{ __('checkout.confirm_text') }}</p>
                </div>

                <div class="rounded-lg border border-[#E6DAC0] divide-y divide-[#E6DAC0]">
                    <template x-for="(item, idx) in bookingItems" :key="idx">
                        <div class="flex items-start justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <div class="text-sm font-extrabold truncate" x-text="item.court || i18n.court"></div>
                                <div class="text-xs text-[#7A5A52]" x-text="formatDate(item.booking_date) + ' · ' + timeRange(item) + ' WIB'"></div>
                                <div x-show="item.duration_hours" class="text-[11px] text-[#662721] font-semibold" x-text="plural('hours', item.duration_hours)"></div>
                            </div>
                            <div class="text-sm font-black tabular-nums shrink-0" x-text="'Rp ' + formatNumber(item.price)"></div>
                        </div>
                    </template>
                    {{-- Rincian dari harga sewa ke total bayar — sama dengan ringkasan di samping (potongan, alat, biaya, pajak). --}}
                    <div x-show="addonsTotal > 0 || membershipDiscountAmount > 0 || sponsorVoucherDiscountAmount > 0 || promoDiscount > 0 || (isAdminFeeApplicable && calculatedAdminFee > 0) || (isTaxApplicable && calculatedTax > 0)"
                         data-confirm-breakdown class="px-4 py-3 space-y-1.5 text-xs text-[#662721]">
                        <div x-show="addonsTotal > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0">{{ __('checkout.equipment_addons') }}</span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(addonsTotal)"></span>
                        </div>
                        <div x-show="membershipDiscountAmount > 0" class="flex items-start justify-between gap-3 font-bold">
                            <span class="min-w-0" x-text="membershipBenefit && membershipBenefit.benefit_type === 'MONTHLY_BILLING' ? t('member_monthly_row', { company: membershipBenefit.plan_name }) : 'Membership' + (membershipBenefit ? ' · ' + membershipBenefit.plan_name : '')"></span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(membershipDiscountAmount)"></span>
                        </div>
                        <div x-show="sponsorVoucherDiscountAmount > 0" class="flex items-start justify-between gap-3 font-bold">
                            <span class="min-w-0">{{ __('checkout.corporate_voucher') }}</span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(sponsorVoucherDiscountAmount)"></span>
                        </div>
                        <div x-show="promoApplied && promoDiscount > 0" class="flex items-start justify-between gap-3 font-bold text-emerald-700">
                            <span class="min-w-0">
                                <span x-text="'Voucher ' + (promoVoucher ? promoVoucher.code : '')"></span>
                                <span x-show="promoTerms" class="block font-medium text-[11px] text-emerald-800/80" x-text="promoTerms ? ((promoTerms.name ? promoTerms.name + ' · ' : '') + promoTerms.headline) : ''"></span>
                            </span>
                            <span class="shrink-0 tabular-nums whitespace-nowrap" x-text="'- Rp ' + formatNumber(promoDiscount)"></span>
                        </div>
                        <div x-show="isAdminFeeApplicable && calculatedAdminFee > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0" x-text="financeSettings.admin_fee_name || i18n.service_fee"></span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(calculatedAdminFee)"></span>
                        </div>
                        <div x-show="isTaxApplicable && calculatedTax > 0" class="flex items-start justify-between gap-3">
                            <span class="min-w-0" x-text="financeSettings.tax_name || i18n.tax"></span>
                            <span class="shrink-0 font-bold tabular-nums whitespace-nowrap" x-text="'Rp ' + formatNumber(calculatedTax)"></span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-3 bg-[#F7F0DB] rounded-b-lg">
                        <div class="min-w-0">
                            <div class="text-xs font-bold text-[#7A5A52]">{{ __('checkout.total_to_pay') }}</div>
                            <div class="text-[11px] text-[#7A5A52] truncate" x-text="grandTotal > 0 ? t('via', { method: selectedMethod.name }) : i18n.fully_covered_no_payment"></div>
                        </div>
                        <span class="text-base font-black tabular-nums shrink-0" x-text="'Rp ' + formatNumber(grandTotal)"></span>
                    </div>
                </div>

                <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 space-y-2 text-xs text-amber-900">
                    <div class="font-extrabold uppercase tracking-wider text-[11px]">{{ __('checkout.policy_title') }}</div>
                    <ul class="space-y-1.5 list-disc pl-4 leading-relaxed">
                        <li>{!! __('checkout.policy_reschedule_html') !!}</li>
                        <li>{!! __('checkout.policy_no_refund_html') !!}</li>
                        <li>{!! __('checkout.policy_responsibility_html') !!}</li>
                    </ul>
                </div>

                <label class="flex items-start gap-3 cursor-pointer select-none">
                    <input type="checkbox" x-model="payConfirm.agreed" class="mt-0.5 w-5 h-5 rounded border-[#CDBB9C] text-[#662721] focus:ring-[#662721]">
                    <span class="text-sm text-[#4F2F2A]">{{ __('checkout.agree') }}</span>
                </label>

                <div class="flex gap-2.5">
                    <button type="button" @click="payConfirm.show = false"
                            class="bk-tap flex-1 h-12 rounded-lg border border-[#E6DAC0] text-sm font-bold text-[#7A5A52] hover:bg-[#F7F0DB] transition-colors">{{ __('checkout.check_again') }}</button>
                    <button type="button" :disabled="!payConfirm.agreed || isSubmitting || isExpired" @click="confirmAndPay()"
                            :class="payConfirm.agreed ? 'bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] active:scale-[0.98]' : 'bg-[#EEE5D3] text-[#A08F86] cursor-not-allowed'"
                            class="bk-tap flex-[1.4] h-12 rounded-lg text-sm font-black uppercase tracking-wider transition-all"
                            x-text="grandTotal > 0 ? i18n.yes_pay_now : i18n.yes_confirm"></button>
                </div>
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
                    <h3 class="font-display font-black text-xl">{{ __('checkout.success_title') }}</h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed">{{ __('checkout.success_text') }}</p>
                </div>
                <div class="rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] p-3.5 text-xs space-y-2 text-left">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[#7A5A52] shrink-0">{{ __('checkout.paid_with') }}</span>
                        <span class="font-bold text-right min-w-0 truncate" x-text="grandTotal <= 0 ? i18n.fully_covered : (selectedMethod.name || i18n.online_payment)"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[#7A5A52]">{{ __('checkout.status') }}</span>
                        <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-200">{{ __('checkout.paid_badge') }}</span>
                    </div>
                    <div class="pt-2 border-t border-[#E6DAC0] flex items-center justify-between gap-3">
                        <span class="font-bold">{{ __('checkout.total') }}</span>
                        <span class="font-black text-lg tabular-nums whitespace-nowrap text-[#662721]" x-text="'Rp ' + formatNumber(grandTotal)"></span>
                    </div>
                </div>
                <button type="button" @click="completePaymentAndRedirect()"
                        class="bk-tap w-full h-12 rounded-lg flex items-center justify-center gap-2 text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform">
                    {{ __('checkout.view_eticket') }}
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
                    <h3 class="font-display font-black text-xl">{{ __('checkout.cancel_title') }}</h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed">{{ __('checkout.cancel_text') }}</p>
                </div>
                <template x-if="bookingItems && bookingItems.length > 0">
                    <div class="rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] p-3 text-xs space-y-1.5 text-left">
                        <div class="flex justify-between gap-3"><span class="text-[#7A5A52] shrink-0">{{ __('checkout.court') }}</span><span class="font-bold text-right min-w-0 truncate" x-text="bookingItems[0].court || i18n.court"></span></div>
                        <div class="flex justify-between gap-3"><span class="text-[#7A5A52] shrink-0">{{ __('checkout.schedule') }}</span><span class="font-bold text-right min-w-0 truncate" x-text="formatDate(bookingItems[0].booking_date) + ' · ' + timeRange(bookingItems[0])"></span></div>
                        <div x-show="bookingItems.length > 1" class="flex justify-between gap-3"><span class="text-[#7A5A52]">{{ __('checkout.sessions') }}</span><span class="font-bold" x-text="bookingItems.length"></span></div>
                    </div>
                </template>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="showCancelModal = false" :disabled="isCancellingCheckout"
                            class="bk-tap h-12 rounded-lg border border-[#E6DAC0] bg-[#F7F0DB] text-[#662721] text-xs font-bold disabled:opacity-50">
                        {{ __('checkout.keep_paying') }}
                    </button>
                    <button type="button" @click="confirmCancelCheckout()" :disabled="isCancellingCheckout"
                            class="bk-tap h-12 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-black uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-60 transition-colors">
                        <span x-show="isCancellingCheckout" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                        <span x-text="isCancellingCheckout ? i18n.releasing : i18n.yes_cancel"></span>
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
                    <h3 class="font-display font-black text-xl">{{ __('checkout.expired_title') }}</h3>
                    <p class="text-xs text-[#7A5A52] leading-relaxed">{{ __('checkout.expired_text', ['minutes' => app(\App\Services\Padel\BookingTimeService::class)->holdMinutes()]) }}</p>
                </div>
                <a href="{{ route('customer.booking') }}"
                   class="bk-tap flex items-center justify-center w-full h-12 rounded-lg text-xs font-black uppercase tracking-wider bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] shadow-md active:scale-[0.98] transition-transform">
                    {{ __('checkout.pick_new_time') }}
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
                    <span x-text="noticeModal.buttonText || i18n.ok_understood"></span>
                </button>
            </div>
        </div>

    </div>

    <script>
        function checkoutApp() {
            return {
                // Teks yang disusun di JS (lang/{id,en}/checkout.php → "js"); :x diganti lewat t().
                i18n: @js(__('checkout.js')),
                locale: @js(app()->getLocale()),
                dateLocale: @js(app()->getLocale() === 'id' ? 'id-ID' : 'en-US'),
                canCancelBooking: @json(Auth::check() && Auth::user()->canCancelBooking()),
                financeSettings: @json($clubFinanceSettings ?? \App\Models\Pos\ClubFinanceSetting::getSettings()),
                noticeModal: {
                    show: false,
                    title: '',
                    message: '',
                    type: 'info',
                    buttonText: @js(__('checkout.js.ok_understood')),
                    onClose: null,
                },

                t(key, params = {}) {
                    let s = (this.i18n && this.i18n[key]) || key;
                    Object.keys(params).forEach(k => { s = s.split(':' + k).join(params[k]); });
                    return s;
                },

                plural(key, count) {
                    return this.t(key + (Number(count) === 1 ? '_one' : '_other'), { count });
                },

                showNotice(title, message, type = 'info', buttonText = this.i18n.ok_understood, onClose = null) {
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
                bookingDateFormatted: @js(__('checkout.js.today')),
                membershipBenefit: null,
                membershipNotice: null,
                useMembershipBenefit: true,
                sponsorVoucherBenefit: null,
                accessNotice: null,
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
                payConfirm: { show: false, agreed: false },

                showPaymentSuccessModal: false,
                promoCode: '',
                promoApplied: false,
                // Hasil cek server (/vouchers/check); potongannya dihitung ulang dari total terkini (getter promoDiscount).
                promoVoucher: null,
                isCheckingPromo: false,
                myVouchers: [],
                // Promo hasil klaim (Modul 22 tahap 3) — dipakai tanpa mengetik kode.
                myPromos: [],
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
                selectedMethod: (@js(app(\App\Services\Payment\OnlinePaymentMethodService::class)->forFrontend())[0]) || { id: '', code: '', name: @js(__('checkout.js.no_method_available')), badge: '-', fee: 0, note: '' },

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
                    if (this.isSubmitting) return this.i18n.processing;
                    return this.grandTotal > 0 ? this.i18n.pay_now : this.i18n.confirm_booking;
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
                        this.myPromos = (json.success && Array.isArray(json.claimed)) ? json.claimed : [];
                    } catch (e) {
                        this.myVouchers = [];
                        this.myPromos = [];
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
                        // Kartu member ada tapi tidak berlaku untuk jadwal ini → jelaskan kenapa harga normal.
                        this.membershipNotice = (json.success && json.data && !json.data.has_benefit) ? ({
                            MEMBERSHIP_ENDS_BEFORE_PLAY_DATE: this.t('membership_ends', {
                                plan: json.data.plan_name ? this.t('membership_ends_plan', { name: json.data.plan_name }) : '',
                                date: json.data.membership_end_date ? this.t('membership_ends_date', { date: this.formatDate(json.data.membership_end_date) }) : '',
                            }),
                        }[json.data.rejected_reason] || null) : null;
                        if (json.success && json.data && json.data.sponsor_voucher_benefit && json.data.sponsor_voucher_benefit.has_benefit) {
                            this.sponsorVoucherBenefit = json.data.sponsor_voucher_benefit;
                        } else {
                            this.sponsorVoucherBenefit = null;
                        }
                        this.accessNotice = json.success && json.data ? this.buildAccessNotice(json.data) : null;
                        this.showAccessNoticeOnce(bookingIds);
                    } catch (e) {
                        // Tidak fatal — customer tanpa membership/voucher tetap bisa checkout normal
                        this.membershipBenefit = null;
                        this.membershipNotice = null;
                        this.sponsorVoucherBenefit = null;
                        this.accessNotice = null;
                    } finally {
                        this.isLoadingMembershipPreview = false;
                    }
                },

                /**
                 * Membership / voucher ada tapi jam main ini di luar ketentuannya (hari / jam / jam peak) → satu pesan
                 * gabungan: baris kecil di rincian harga + pop-up sekali per checkout. Harga normal (server yang menentukan).
                 */
                buildAccessNotice(data) {
                    const outside = (b) => b && !b.has_benefit && b.rejected_reason === 'OUTSIDE_TIME_WINDOW';
                    const member = outside(data);
                    const voucher = outside(data.sponsor_voucher_benefit);
                    if (!member && !voucher) return null;

                    const which = member && voucher ? 'both' : (member ? 'member' : 'voucher');
                    // Label aturan dari server: access_rule (Indonesia) / access_rule_en (Inggris).
                    const ruleKey = this.locale === 'id' ? 'access_rule' : 'access_rule_en';
                    const rules = [...new Set([member ? data[ruleKey] : null, voucher ? data.sponsor_voucher_benefit[ruleKey] : null].filter(Boolean))];

                    return {
                        title: this.t('access_title_' + which),
                        message: rules.length ? this.t('access_message_rules', { rules: rules.join(' / ') }) : this.i18n.access_message,
                        short: this.t('access_short_' + which),
                    };
                },

                showAccessNoticeOnce(bookingIds) {
                    if (!this.accessNotice) return;
                    const key = 'c61-access-notice:' + [...bookingIds].sort().join(',');
                    try {
                        if (sessionStorage.getItem(key)) return;
                        sessionStorage.setItem(key, '1');
                    } catch (e) { /* storage diblokir → tetap tampilkan */ }
                    this.showNotice(this.accessNotice.title, this.accessNotice.message, 'info', this.i18n.ok);
                },

                toggleMembershipBenefit() {
                    this.useMembershipBenefit = !this.useMembershipBenefit;
                    // Promo yang tidak boleh digabung benefit member dilepas saat benefit dinyalakan lagi (server juga menolak).
                    if (this.useMembershipBenefit && this.membershipDiscountAmount > 0 && this.promoIsDiscount && !this.promoVoucher.stack_with_membership) {
                        const code = this.promoVoucher.code;
                        this.removePromo();
                        this.showNotice(this.i18n.promo_removed_title, this.t('promo_removed_membership', { code }), 'info', this.i18n.got_it);
                    }
                },

                toggleSponsorVoucherBenefit() {
                    this.useSponsorVoucherBenefit = !this.useSponsorVoucherBenefit;
                    // 1 transaksi 1 voucher: menyalakan voucher corporate melepas kode promo (voucher saldo tetap).
                    if (this.useSponsorVoucherBenefit && this.promoIsDiscount) {
                        const code = this.promoVoucher.code;
                        this.removePromo();
                        this.showNotice(this.i18n.promo_removed_title, this.t('promo_removed_corporate', { code }), 'info', this.i18n.got_it);
                    }
                },

                /** Voucher jam corporate sedang dipakai di checkout ini. */
                get corporateVoucherOn() {
                    return !!(this.sponsorVoucherBenefit && this.useSponsorVoucherBenefit);
                },

                /** Kode promo diskon (bukan voucher saldo hasil refund) sedang dipasang. */
                get promoIsDiscount() {
                    return !!(this.promoApplied && this.promoVoucher && this.promoVoucher.type !== 'CREDIT');
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
                    this.payConfirm.show = false;
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

                // Promo hanya memotong sewa lapangan (PRD Modul 22 §5) — add-on raket/bola tidak ikut. Voucher saldo tetap semua.
                get promoCourtBase() {
                    return Math.max(0, Math.max(0, this.subtotal - this.membershipDiscountAmount) - this.sponsorVoucherDiscountAmount);
                },

                get promoDiscount() {
                    const v = this.promoVoucher;
                    const base = v && v.type !== 'CREDIT' ? this.promoCourtBase : this.voucherBase;
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

                formatPercent(value) {
                    return Number(value || 0).toLocaleString(this.dateLocale, { maximumFractionDigits: 2 }) + '%';
                },

                /** Rincian aturan voucher yang terpasang — sama dengan yang dicek server saat cek kode & checkout. */
                get promoTerms() {
                    const v = this.promoVoucher;
                    if (!v) return null;
                    const until = v.valid_until ? this.t('valid_until', { date: v.valid_until }) : null;
                    if (v.type === 'CREDIT') {
                        return { name: null, headline: this.t('credit_headline', { amount: this.formatNumber(Math.round(v.available_balance || 0)) }), rules: [until].filter(Boolean) };
                    }
                    const headline = v.type === 'PERCENT'
                        ? (v.max_discount
                            ? this.t('percent_off_max', { percent: this.formatPercent(v.value), max: this.formatNumber(v.max_discount) })
                            : this.t('percent_off', { percent: this.formatPercent(v.value) }))
                        : this.t('amount_off', { amount: this.formatNumber(v.value) });
                    return {
                        name: v.name || null,
                        headline,
                        rules: [
                            v.min_order > 0 ? this.t('min_court_rental', { amount: this.formatNumber(v.min_order) }) : null,
                            until,
                            v.channel === 'ONLINE' ? this.i18n.online_only : null,
                            v.stack_with_membership ? this.i18n.combinable : this.i18n.not_combinable,
                            this.i18n.once_per_account,
                        ].filter(Boolean),
                    };
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
                    // Maksimal 10 per alat per checkout (sama dengan validasi server), dan tidak melebihi stok.
                    const maxStock = Math.min(10, item.stock || 10);
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
                            body: JSON.stringify({
                                code, amount: this.voucherBase, court_amount: this.promoCourtBase, addon_amount: this.addonsTotal,
                                membership_applied: this.membershipDiscountAmount > 0,
                            }),
                        });
                        const json = await res.json().catch(() => ({}));
                        if (res.ok && json.success && this.corporateVoucherOn && json.data.type !== 'CREDIT') {
                            this.showNotice(this.i18n.one_voucher_title, this.i18n.one_voucher_apply, 'info', this.i18n.got_it);
                        } else if (res.ok && json.success) {
                            this.promoCode = json.data.code;
                            this.promoVoucher = json.data;
                            this.promoApplied = true;
                        } else {
                            this.showNotice(this.i18n.voucher_not_applied_title, json.message || this.i18n.voucher_invalid, 'error', this.i18n.close);
                        }
                    } catch (e) {
                        this.showNotice(this.i18n.voucher_check_failed_title, this.i18n.voucher_check_failed, 'error', this.i18n.close);
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

                // "2026-10-05" → "Sen, 5 Okt 2026" / "Mon, Oct 5, 2026" (diurai manual supaya tidak bergeser zona waktu).
                formatDate(val) {
                    if (!val) return '-';
                    const m = String(val).match(/^(\d{4})-(\d{2})-(\d{2})/);
                    if (!m) return String(val).substring(0, 10);
                    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
                    return d.toLocaleDateString(this.dateLocale, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
                },

                get heldBookingIds() {
                    return (this.holdData && Array.isArray(this.holdData.bookings)) ? this.holdData.bookings.map(b => b.id) : [];
                },

                /**
                 * Cek yang bisa gagal SEBELUM customer menyetujui ketentuan — jangan sampai sudah centang "setuju"
                 * lalu ternyata metodenya diganti / slotnya tidak ada. Dipanggil lagi di executePayment().
                 */
                paymentPrecheck() {
                    if (this.isSubmitting || this.isExpired) return false;

                    // Server juga menolaknya; dicegah di sini supaya customer tidak sampai menyetujui ketentuan dulu.
                    if (this.corporateVoucherOn && this.promoIsDiscount) {
                        this.showNotice(this.i18n.one_voucher_title, this.i18n.one_voucher_precheck, 'info', this.i18n.got_it);
                        return false;
                    }

                    if (this.heldBookingIds.length === 0) {
                        this.showNotice(
                            this.i18n.slot_not_found_title,
                            this.i18n.slot_not_found,
                            'error',
                            this.i18n.select_schedule,
                            () => {
                                window.location.href = "{{ route('customer.booking') }}";
                            }
                        );
                        return false;
                    }

                    // Total bisa berubah (voucher, add-on) setelah metode dipilih — pastikan metodenya masih berlaku.
                    if (this.grandTotal > 0) {
                        if (! this.availableMethods.length) {
                            this.showNotice(this.i18n.online_unavailable_title, this.i18n.online_unavailable, 'error', this.i18n.close);
                            return false;
                        }
                        // Jangan diam-diam membayar dengan metode lain — beri tahu customer dulu, biar dia yang lanjutkan.
                        if (! this.availableMethods.some(m => m.code === this.selectedMethod.code)) {
                            const previous = this.selectedMethod.name;
                            this.ensureSelectedMethodAvailable();
                            this.showNotice(this.i18n.method_changed_title, this.t('method_changed', { previous, current: this.selectedMethod.name }), 'info', this.i18n.ok);
                            return false;
                        }
                    }

                    return true;
                },

                /** Pay Now: cek ulang pesanan + ketentuan booking dulu, baru ke Midtrans. Centang selalu mulai kosong. */
                openPayConfirm() {
                    if (!this.paymentPrecheck()) return;
                    this.payConfirm = { show: true, agreed: false };
                },

                confirmAndPay() {
                    if (!this.payConfirm.agreed || this.isSubmitting || this.isExpired) return;
                    this.payConfirm.show = false;
                    this.executePayment();
                },

                /**
                 * Request Snap Token & Execute Payment
                 */
                async executePayment() {
                    if (!this.payConfirm.agreed || !this.paymentPrecheck()) return;

                    this.isSubmitting = true;

                    try {
                        const bookingIds = this.heldBookingIds;

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
                                            this.i18n.payment_declined_title,
                                            this.i18n.payment_declined,
                                            'error',
                                            this.i18n.try_again
                                        );
                                    },
                                    onClose: () => {
                                        this.showNotice(
                                            this.i18n.payment_closed_title,
                                            this.i18n.payment_closed,
                                            'info',
                                            this.i18n.view_invoice,
                                            () => {
                                                this.clearSessionAndRedirect(this.createdBookingId, this
                                                    .createdOrderId);
                                            }
                                        );
                                    }
                                });
                            } else if (data.driver === 'doku' && data.checkout_mode === 'SNAP') {
                                // DOKU SNAP: QR / nomor VA tampil di halaman invoice kita sendiri (status dicek otomatis).
                                this.clearSessionAndRedirect(this.createdBookingId, this.createdOrderId);
                            } else if (data.driver === 'doku') {
                                // DOKU Checkout: halaman bayar DOKU (server memastikan URL https *.doku.com). Selesai / batal
                                // bayar → DOKU mengembalikan customer ke halaman invoice. WAJIB sebelum cabang di bawah: sesi
                                // DOKU tidak punya snap_token, jangan sampai dianggap "sudah lunas".
                                if (data.payment_url) {
                                    window.location.href = data.payment_url;
                                } else {
                                    this.showNotice(this.i18n.payment_not_started_title, this.i18n.payment_page_failed, 'error', this.i18n.view_invoice, () => {
                                        this.clearSessionAndRedirect(this.createdBookingId, this.createdOrderId);
                                    });
                                }
                            } else if (data.is_mock || !data.snap_token) {
                                // Simulator lokal / total Rp0 ditanggung kuota-voucher: sudah lunas di server.
                                this.showPaymentSuccessModal = true;
                            } else if (data.redirect_url || data.payment_url) {
                                // Popup Snap tidak termuat (client key kosong / snap.js diblokir) → halaman pembayaran Midtrans.
                                // Dulu jatuh ke cabang simulator di atas: customer melihat "pembayaran berhasil" padahal belum bayar.
                                window.location.href = data.redirect_url || data.payment_url;
                            } else {
                                this.showNotice(this.i18n.payment_not_started_title, this.i18n.payment_window_failed, 'error', this.i18n.view_invoice, () => {
                                    this.clearSessionAndRedirect(this.createdBookingId, this.createdOrderId);
                                });
                            }
                        } else {
                            this.showNotice(this.i18n.payment_failed_title, json.message || this.i18n.payment_failed,
                                'error', this.i18n.close);
                        }
                    } catch (e) {
                        this.isSubmitting = false;
                        this.showNotice(this.i18n.system_error_title, this.i18n.system_error, 'error',
                            this.i18n.close);
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
                        this.showNotice(this.i18n.access_denied_title, this.i18n.access_denied, 'error',
                            this.i18n.close);
                        return;
                    }
                    this.showCancelModal = true;
                },

                async confirmCancelCheckout() {
                    if (!this.canCancelBooking) {
                        this.showNotice(this.i18n.access_denied_title, this.i18n.access_denied,
                            'error', this.i18n.close);
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
