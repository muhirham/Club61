<x-guest-layout theme="terracotta">
    <div class="w-full max-w-xl mx-auto my-auto">
        <!-- Luxury Register Card: White Frosted Glass with 3px Polished Gold Bezel -->
        <div class="p-6 sm:p-10 relative overflow-hidden"
             style="background: #FCF8EE; border: 1px solid #E6DAC0; border-radius: 12px; box-shadow: 0 24px 60px -28px rgba(79,47,42,0.45);">

            <!-- Top Header & Crest -->
            <div class="text-center mb-8">
                <img src="{{ asset('images/identity/monogram-terracotta.png') }}" alt="Club 61 Padel Court" class="h-14 w-auto mx-auto mb-3">
                <div class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider mb-2 shadow-sm"
                     style="background: #F7F0DB; border: 1px solid #E6DAC0; color: #662721;">
                    Club 61 Registration
                </div>
                <h1 class="font-display text-2xl sm:text-3xl font-black text-[#4F2F2A] tracking-wide">
                    Bergabung ke Club 61 Padel Court
                </h1>
                <p class="text-xs sm:text-sm text-[#7A5A52] mt-1 font-medium">
                    Daftarkan akun member eksklusif Anda untuk menikmati seluruh fasilitas venue.
                </p>
            </div>

            <!-- Form -->
            <form method="POST" action="{{ route('register') }}" class="space-y-4">
                @csrf

                <!-- Name Input -->
                <div>
                    <label for="name" class="block text-xs font-bold uppercase tracking-wider text-[#662721] mb-1.5">
                        Nama Lengkap
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus
                               placeholder="Nama lengkap member..."
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
                        Email Member
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                            </svg>
                        </div>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required
                               placeholder="nama@email.com"
                               class="w-full pl-10 pr-4 py-3 rounded-xl text-sm font-semibold text-[#4F2F2A] placeholder-[#7A5A52] transition-all duration-200 outline-none"
                               style="background: rgba(255, 255, 255, 0.9); border: 1.5px solid #E6DAC0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);"
                               onfocus="this.style.borderColor='#511D18'; this.style.boxShadow='0 0 0 3px rgba(102,39,33,0.25)';"
                               onblur="this.style.borderColor='#E6DAC0'; this.style.boxShadow='none';" />
                    </div>
                    <x-input-error :messages="$errors->get('email')" class="mt-1 text-xs text-rose-600" />
                </div>

                <!-- Password Input -->
                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#662721] mb-1.5">
                        Password
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input id="password" type="password" name="password" required autocomplete="new-password"
                               placeholder="Minimal 8 karakter..."
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
                        Konfirmasi Password
                    </label>
                    <div class="relative rounded-xl shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#662721]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                               placeholder="Ketik ulang password..."
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
                        <span>Daftar Membership Sekarang</span>
                        <svg class="w-4 h-4 stroke-[3]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </div>
            </form>

            <!-- Bottom Prompt -->
            <div class="mt-6 pt-6 border-t border-[#E6DAC0]/60 text-center">
                <p class="text-xs text-[#7A5A52]">
                    Sudah memiliki akun member?
                    <a href="{{ route('login') }}" class="font-bold text-[#662721] hover:text-[#662721] underline ml-1">
                        Masuk di sini
                    </a>
                </p>
            </div>
        </div>
    </div>
</x-guest-layout>
