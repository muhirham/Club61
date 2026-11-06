<x-guest-layout theme="terracotta">
    <div class="w-full max-w-xl mx-auto my-auto">
        <!-- Luxury Register Card: White Frosted Glass with 3px Polished Gold Bezel -->
        <div class="p-6 sm:p-10 relative overflow-hidden"
             style="background: #FCF8EE; border: 1px solid #E6DAC0; border-radius: 12px; box-shadow: 0 24px 60px -28px rgba(79,47,42,0.45);">

            {{-- Tombol bahasa ID / EN di pojok kanan atas kartu. --}}
            <div style="position: absolute; top: 16px; right: 16px; z-index: 1;">
                <x-lang-switch variant="light" />
            </div>

            <!-- Top Header & Crest -->
            <div class="text-center mb-8">
                <img src="{{ asset('images/identity/monogram-terracotta.png') }}" alt="Club 61 Padel &amp; Wellness" class="h-14 w-auto mx-auto mb-3">
                <div class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider mb-2 shadow-sm"
                     style="background: #F7F0DB; border: 1px solid #E6DAC0; color: #662721;">
                    {{ __('account.register.badge') }}
                </div>
                <h1 class="font-display text-2xl sm:text-3xl font-black text-[#4F2F2A] tracking-wide">
                    {{ __('account.register.title') }}
                </h1>
                <p class="text-xs sm:text-sm text-[#7A5A52] mt-1 font-medium">
                    {{ __('account.register.subtitle') }}
                </p>
            </div>

            <!-- Form -->
            {{-- Tombol Daftar dikunci setelah diklik (server juga menolak dua pendaftaran bersamaan). --}}
            <form method="POST" action="{{ route('register') }}" class="space-y-4"
                  onsubmit="var b = this.querySelector('button[type=submit]'); if (b.disabled) return false; b.disabled = true; b.style.opacity = '0.6';">
                @csrf

                <!-- Name Input -->
                <div>
                    <label for="name" class="block text-xs font-bold uppercase tracking-wider text-[#662721] mb-1.5">
                        {{ __('account.register.name') }}
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus
                               placeholder="{{ __('account.register.name_placeholder') }}"
                               class="w-full pl-10 pr-4 py-3 rounded-xl text-sm font-semibold text-[#4F2F2A] placeholder-[#7A5A52] transition-all duration-200 outline-none"
                               style="background: rgba(255, 255, 255, 0.9); border: 1.5px solid #E6DAC0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);"
                               onfocus="this.style.borderColor='#511D18'; this.style.boxShadow='0 0 0 3px rgba(102,39,33,0.25)';"
                               onblur="this.style.borderColor='#E6DAC0'; this.style.boxShadow='none';" />
                    </div>
                    <x-input-error :messages="$errors->get('name')" class="mt-1 text-xs text-rose-600" />
                </div>

                <!-- Email Input -->
                <div>
                    <label for="email" class="block text-xs font-bold uppercase tracking-wider text-[#662721] mb-1.5">
                        {{ __('account.register.email') }}
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                            </svg>
                        </div>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required
                               placeholder="{{ __('account.register.email_placeholder') }}"
                               class="w-full pl-10 pr-4 py-3 rounded-xl text-sm font-semibold text-[#4F2F2A] placeholder-[#7A5A52] transition-all duration-200 outline-none"
                               style="background: rgba(255, 255, 255, 0.9); border: 1.5px solid #E6DAC0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);"
                               onfocus="this.style.borderColor='#511D18'; this.style.boxShadow='0 0 0 3px rgba(102,39,33,0.25)';"
                               onblur="this.style.borderColor='#E6DAC0'; this.style.boxShadow='none';" />
                    </div>
                    <x-input-error :messages="$errors->get('email')" class="mt-1 text-xs text-rose-600" />
                </div>

                <!-- Phone Input (Modul 33: satu akun satu nomor HP) -->
                <div>
                    <label for="phone" class="block text-xs font-bold uppercase tracking-wider text-[#662721] mb-1.5">
                        {{ __('account.register.phone') }}
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                        <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required inputmode="tel" autocomplete="tel"
                               placeholder="{{ __('account.register.phone_placeholder') }}"
                               class="w-full pl-10 pr-4 py-3 rounded-xl text-sm font-semibold text-[#4F2F2A] placeholder-[#7A5A52] transition-all duration-200 outline-none"
                               style="background: rgba(255, 255, 255, 0.9); border: 1.5px solid #E6DAC0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);"
                               onfocus="this.style.borderColor='#511D18'; this.style.boxShadow='0 0 0 3px rgba(102,39,33,0.25)';"
                               onblur="this.style.borderColor='#E6DAC0'; this.style.boxShadow='none';" />
                    </div>
                    <x-input-error :messages="$errors->get('phone')" class="mt-1 text-xs text-rose-600" />
                    @if ($errors->first('phone') === __('account.register.phone_taken'))
                        @php($registerWa = \App\Models\Setting\CompanyProfileSetting::whatsappUrl(__('site.whatsapp_float_message')))
                        @if ($registerWa)
                            <a href="{{ $registerWa }}" target="_blank" rel="noopener" data-register-wa
                               class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-[#25D366] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#1EBE5A]">
                                {{ __('account.register.phone_taken_help') }}
                            </a>
                        @endif
                        <a href="{{ route('login') }}" class="mt-2 ml-2 inline-block text-xs font-bold text-[#662721] underline">{{ __('account.register.login_here') }}</a>
                    @endif
                </div>

                <!-- Password Input -->
                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#662721] mb-1.5">
                        {{ __('account.register.password') }}
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input id="password" type="password" name="password" required autocomplete="new-password"
                               placeholder="{{ __('account.register.password_placeholder') }}"
                               class="w-full pl-10 pr-4 py-3 rounded-xl text-sm font-semibold text-[#4F2F2A] placeholder-[#7A5A52] transition-all duration-200 outline-none"
                               style="background: rgba(255, 255, 255, 0.9); border: 1.5px solid #E6DAC0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);"
                               onfocus="this.style.borderColor='#511D18'; this.style.boxShadow='0 0 0 3px rgba(102,39,33,0.25)';"
                               onblur="this.style.borderColor='#E6DAC0'; this.style.boxShadow='none';" />
                    </div>
                    <x-input-error :messages="$errors->get('password')" class="mt-1 text-xs text-rose-600" />
                </div>

                <!-- Confirm Password -->
                <div>
                    <label for="password_confirmation" class="block text-xs font-bold uppercase tracking-wider text-[#662721] mb-1.5">
                        {{ __('account.register.password_confirmation') }}
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                               placeholder="{{ __('account.register.password_confirmation_placeholder') }}"
                               class="w-full pl-10 pr-4 py-3 rounded-xl text-sm font-semibold text-[#4F2F2A] placeholder-[#7A5A52] transition-all duration-200 outline-none"
                               style="background: rgba(255, 255, 255, 0.9); border: 1.5px solid #E6DAC0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);"
                               onfocus="this.style.borderColor='#511D18'; this.style.boxShadow='0 0 0 3px rgba(102,39,33,0.25)';"
                               onblur="this.style.borderColor='#E6DAC0'; this.style.boxShadow='none';" />
                    </div>
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1 text-xs text-rose-600" />
                </div>

                <!-- Submit Button -->
                <div class="pt-3">
                    <button type="submit"
                            class="w-full py-3.5 px-6 rounded-xl text-sm font-black uppercase tracking-wider transition-all duration-200 transform active:scale-95 hover:brightness-105 shadow-xl flex items-center justify-center gap-2 cursor-pointer"
                            style="background: #662721; border: 1.5px solid #662721; color: #F7F0DB; box-shadow: 0 10px 24px -12px rgba(79,47,42,0.55);">
                        <span>{{ __('account.register.submit') }}</span>
                        <svg class="w-4 h-4 stroke-[3]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </div>
            </form>

            <!-- Bottom Prompt -->
            <div class="mt-6 pt-6 border-t border-[#E6DAC0]/60 text-center">
                <p class="text-xs text-[#7A5A52]">
                    {{ __('account.register.have_account') }}
                    <a href="{{ route('login') }}" class="font-bold text-[#662721] hover:text-[#662721] underline ml-1">
                        {{ __('account.register.login_here') }}
                    </a>
                </p>
            </div>
        </div>
    </div>
</x-guest-layout>
