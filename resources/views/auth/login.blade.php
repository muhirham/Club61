<x-guest-layout theme="terracotta">
    {{-- Brand guideline Club 61: Terakota (dominan) #662721, Cokelat #4F2F2A, Cream #F7F0DB — pola sama dengan halaman depan.
         HP: form dulu (tujuan utama halaman ini), panel venue di bawahnya. Layar lebar: panel venue kiri, form kanan. --}}
    <div class="w-full max-w-6xl mx-auto my-auto">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 lg:gap-6 items-stretch">

            {{-- ============ PANEL VENUE ============ --}}
            <div class="order-2 lg:order-1 lg:col-span-7 relative overflow-hidden rounded-xl bg-club-terra-dark text-club-cream min-h-[420px] lg:min-h-[600px] flex flex-col">
                <img src="{{ asset('images/club-hero.jpg') }}" alt="Club 61 Padel Court" class="absolute inset-0 w-full h-full object-cover">
                <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(30,18,15,0.62) 0%, rgba(30,18,15,0.35) 40%, rgba(30,18,15,0.9) 100%);"></div>

                <div class="relative z-10 p-6 sm:p-8 flex items-start justify-between gap-4">
                    <img src="{{ asset('images/identity/logo-cream.png') }}" alt="Club 61 Padel Court" class="h-[90px] sm:h-24 w-auto">
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-club-cream/30 bg-[rgba(30,18,15,0.55)] text-[11px] font-bold uppercase tracking-wider text-club-cream">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 motion-safe:animate-pulse"></span>
                        Venue live &bull; {{ $companyProfile->court_count }} COURTS OPEN
                    </span>
                </div>

                <div class="relative z-10 px-6 sm:px-8 mt-auto pb-6">
                    <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-club-cream/80 mb-3">Exclusive member sanctuary</div>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl lg:text-[2.75rem] leading-[1.1]">
                        Where competition meets <span class="italic text-club-cream/75">refined luxury.</span>
                    </h2>

                    {{-- Kartu fasilitas: sumber datanya sama dengan halaman depan (welcome.blade.php), diedit lewat Filament "Konten Website". --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mt-6">
                        @foreach($companyProfile->localizedFacilityCards() as $card)
                            <div class="flex items-center gap-2.5 p-2.5 rounded-lg bg-[rgba(30,18,15,0.6)] border border-club-cream/15">
                                <span class="w-9 h-9 rounded-md bg-club-cream/10 text-club-cream flex items-center justify-center shrink-0">
                                    <x-company-profile.icon :icon-key="$card['icon_key']" class="w-4 h-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-[13px] font-bold leading-tight">{{ $card['title'] }}</span>
                                    <span class="block text-[11px] text-club-cream/70 mt-0.5 truncate">{{ $card['subtitle'] }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="relative z-10 bg-club-terra px-6 sm:px-8 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1.5 text-xs">
                    <span class="flex items-center gap-2 text-club-cream/90">
                        <span class="w-1.5 h-1.5 rounded-full bg-club-cream/70"></span>
                        Gedung Indosat Medan &bull; {{ $companyProfile->localized('operating_hours_text') }}
                    </span>
                    <span class="font-semibold tracking-wide text-club-cream">{{ $companyProfile->portal_domain_text }}</span>
                </div>
            </div>

            {{-- ============ FORM MASUK ============ --}}
            <div class="order-1 lg:order-2 lg:col-span-5 rounded-xl bg-club-paper border border-club-line shadow-[0_24px_60px_-28px_rgba(79,47,42,0.45)] p-6 sm:p-9 flex flex-col">
                <div class="mb-7">
                    <img src="{{ asset('images/identity/monogram-terracotta.png') }}" alt="" class="h-14 w-auto mb-5">
                    <h1 class="font-display font-semibold text-3xl sm:text-[2.1rem] leading-tight text-club-brown">Masuk ke Club 61</h1>
                    <p class="text-sm text-club-muted mt-1.5 leading-relaxed">Booking lapangan, kelola membership, dan lihat e-ticket Anda.</p>
                </div>

                @if(app()->isLocal())
                <!-- Pilih akun demo (hanya lokal): isi email & sandi otomatis. -->
                <div class="mb-6 p-3 rounded-lg border border-dashed border-club-line bg-club-cream/50">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-club-muted">Cara cepat (pilih akun)</span>
                        <span id="role-destination" class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-club-cream text-club-terra border border-club-line">Siap Masuk</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <button type="button" onclick="selectRole('budi@gmail.com', 'password123', 'Dashboard Member (/dashboard)')"
                                class="role-btn text-left px-2.5 py-2 rounded-md bg-club-paper border border-club-line hover:border-club-terra transition-colors cursor-pointer">
                            <span class="block font-bold text-[11px] text-club-brown">Member</span>
                            <span class="block text-[10px] text-club-muted truncate">Customer VIP</span>
                        </button>
                        <button type="button" onclick="selectRole('cashier@club61.com', 'password123', 'Layar Kasir Frontdesk (/pos)')"
                                class="role-btn text-left px-2.5 py-2 rounded-md bg-club-paper border border-club-line hover:border-club-terra transition-colors cursor-pointer">
                            <span class="block font-bold text-[11px] text-club-brown">Kasir</span>
                            <span class="block text-[10px] text-club-muted truncate">POS Venue</span>
                        </button>
                        <button type="button" onclick="selectRole('barista@club61.com', 'password123', 'Monitor KOT Kitchen (/kitchen)')"
                                class="role-btn text-left px-2.5 py-2 rounded-md bg-club-paper border border-club-line hover:border-club-terra transition-colors cursor-pointer">
                            <span class="block font-bold text-[11px] text-club-brown">Kitchen</span>
                            <span class="block text-[10px] text-club-muted truncate">Display KDS</span>
                        </button>
                        <button type="button" onclick="selectRole('admin@club61.id', 'password123', 'Admin Panel Filament (/admin)')"
                                class="role-btn text-left px-2.5 py-2 rounded-md bg-club-paper border border-club-line hover:border-club-terra transition-colors cursor-pointer">
                            <span class="block font-bold text-[11px] text-club-brown">Admin</span>
                            <span class="block text-[10px] text-club-muted truncate">Super Admin</span>
                        </button>
                    </div>
                </div>
                @endif

                <x-auth-session-status class="mb-4 text-sm text-club-terra bg-club-cream p-3 rounded-md border border-club-line" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="block text-sm font-semibold text-club-brown mb-1.5">
                            Email atau nomor WhatsApp / HP <span class="font-normal text-club-muted">(atau ketik: <span class="font-mono text-club-terra">admin</span>)</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-club-muted">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.206" /></svg>
                            </span>
                            <input id="email" type="text" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                                   placeholder="nama@email.com atau 0812xxxxxxxx"
                                   class="w-full h-12 pl-10 pr-4 rounded-md bg-white border border-club-line text-club-brown placeholder-club-muted/70 text-[15px] focus:border-club-terra focus:ring-2 focus:ring-club-terra/20 outline-none transition">
                        </div>
                        <x-input-error :messages="$errors->get('email')" class="mt-1.5 text-sm text-rose-700" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="block text-sm font-semibold text-club-brown">Kata sandi</label>
                            @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-sm font-semibold text-club-terra hover:text-club-brown underline-offset-4 hover:underline">Lupa kata sandi?</a>
                            @endif
                        </div>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-club-muted">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                            </span>
                            <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••••"
                                   class="w-full h-12 pl-10 pr-12 rounded-md bg-white border border-club-line text-club-brown placeholder-club-muted/70 text-[15px] focus:border-club-terra focus:ring-2 focus:ring-club-terra/20 outline-none transition">
                            <button type="button" onclick="togglePasswordVisibility()" aria-label="Lihat atau sembunyikan kata sandi"
                                    class="absolute inset-y-0 right-0 w-12 flex items-center justify-center text-club-muted hover:text-club-terra transition-colors cursor-pointer">
                                <svg id="eye-icon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </button>
                        </div>
                        <x-input-error :messages="$errors->get('password')" class="mt-1.5 text-sm text-rose-700" />
                    </div>

                    <label for="remember_me" class="inline-flex items-center gap-2.5 cursor-pointer select-none">
                        <input id="remember_me" type="checkbox" name="remember"
                               class="w-[18px] h-[18px] rounded border-club-line text-club-terra focus:ring-club-terra/30">
                        <span class="text-sm text-club-brown">{{ __('Ingat sesi masuk saya') }}</span>
                    </label>

                    <button type="submit"
                            class="w-full h-12 rounded-md bg-club-terra text-club-cream font-bold text-[15px] tracking-wide hover:bg-club-terra-dark active:scale-[0.99] transition flex items-center justify-center gap-2 cursor-pointer">
                        <span>Masuk ke Club</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                    </button>
                </form>

                <div class="mt-auto pt-7">
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-2 py-4 border-t border-club-line text-sm">
                        <span class="text-club-muted">Belum punya akun?</span>
                        <a href="{{ route('register') }}" class="inline-flex items-center gap-1 font-bold text-club-terra hover:text-club-brown underline-offset-4 hover:underline">
                            Daftar membership
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        </a>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-club-line text-xs text-club-muted">
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-club-terra" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                            Club 61 Verified Security
                        </span>
                        <span>&copy; {{ date('Y') }} Club 61</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function selectRole(email, password, destinationLabel) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;

            const destBadge = document.getElementById('role-destination');
            if (destBadge) {
                destBadge.textContent = destinationLabel;
                destBadge.className = 'text-[10px] font-semibold px-2 py-0.5 rounded-full bg-club-terra text-club-cream border border-club-terra transition-colors';
                setTimeout(() => {
                    destBadge.className = 'text-[10px] font-semibold px-2 py-0.5 rounded-full bg-club-cream text-club-terra border border-club-line transition-colors';
                }, 2000);
            }
        }

        function togglePasswordVisibility() {
            const pwdInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />';
            } else {
                pwdInput.type = 'password';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />';
            }
        }
    </script>
</x-guest-layout>
