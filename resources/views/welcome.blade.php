<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLUB 61 - Padel Court Medan</title>
    <link rel="icon" type="image/png" href="{{ asset('images/identity/monogram-terracotta.png') }}">
    <meta name="theme-color" content="#662721">

    <!-- Social share preview (WhatsApp/Facebook/Twitter) -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="Club 61 Padel Court - Medan">
    <meta property="og:description" content="{{ $companyProfile->renderedHeroSubtitle() }}">
    <meta property="og:image" content="{{ asset('images/club61-logo-with-text.png') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Club 61 Padel Court - Medan">
    <meta name="twitter:description" content="{{ $companyProfile->renderedHeroSubtitle() }}">
    <meta name="twitter:image" content="{{ asset('images/club61-logo-with-text.png') }}">

    {{-- Brand guideline: Cheltenham Classic (judul) & Acumin Variable Concept (teks) — selama webfont berlisensinya
         belum dipasang, tampil dengan padanan terdekat: Source Serif 4 & Archivo (lihat tailwind.config.js). --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@87.5..125,400..800&family=Source+Serif+4:opsz,wght@8..60,400..700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Satu lebar konten untuk semua section (navbar, hero, isi, footer): melebar sampai 1720px dengan tepi yang
     ikut membesar di layar lebar — tidak lagi terkunci 1280px di tengah. --}}
@php $wrap = 'mx-auto w-full max-w-[1720px] px-5 sm:px-8 lg:px-12 2xl:px-20'; @endphp
<body class="min-h-full font-brand antialiased text-club-brown bg-club-cream selection:bg-club-terra selection:text-club-cream overflow-x-hidden">

    {{-- ============ NAVIGASI ============ --}}
    <header class="sticky top-0 z-50 bg-club-terra border-b border-club-terra-dark">
        <div class="{{ $wrap }} h-16 lg:h-[76px] flex items-center justify-between gap-4">
            <a href="#top" class="flex items-center gap-3 shrink-0" aria-label="Club 61 Padel Court">
                <img src="{{ asset('images/identity/monogram-cream.png') }}" alt="" class="h-10 lg:h-12 w-auto">
                <span class="hidden min-[440px]:block leading-none whitespace-nowrap">
                    <span class="block font-bold text-[17px] lg:text-xl tracking-[0.18em] text-club-cream" style="font-stretch: 125%;">CLUB 61</span>
                    <span class="block text-[9px] lg:text-[10px] font-semibold tracking-[0.34em] text-club-cream/70 mt-1">PADEL COURT</span>
                </span>
            </a>

            <nav class="hidden lg:flex items-center gap-8 xl:gap-10 text-[13px] font-semibold uppercase tracking-[0.12em] text-club-cream/80">
                <a href="#how-to-book" class="py-2 hover:text-white transition-colors">{{ __('site.nav_how_to_book') }}</a>
                <a href="#facilities" class="py-2 hover:text-white transition-colors">{{ __('site.nav_facilities') }}</a>
                <a href="#membership" class="py-2 hover:text-white transition-colors">{{ __('site.nav_membership') }}</a>
                <a href="#location" class="py-2 hover:text-white transition-colors">{{ __('site.nav_location') }}</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Toggle Bahasa ID/EN — disimpan di session (SetLocale middleware & route "lang.switch"),
                     redirect balik ke halaman yang sama. -->
                <div class="flex items-center rounded-full border border-club-cream/20 bg-club-terra-dark p-0.5 text-[11px] font-bold">
                    <a href="{{ route('lang.switch', 'id') }}" class="px-2.5 py-1.5 rounded-full transition-colors {{ app()->getLocale() === 'id' ? 'bg-club-cream text-club-terra' : 'text-club-cream/70 hover:text-club-cream' }}">ID</a>
                    <a href="{{ route('lang.switch', 'en') }}" class="px-2.5 py-1.5 rounded-full transition-colors {{ app()->getLocale() === 'en' ? 'bg-club-cream text-club-terra' : 'text-club-cream/70 hover:text-club-cream' }}">EN</a>
                </div>

                @auth
                    @php
                        $loggedUser = auth()->user();
                        $homeRoute = \App\Services\Permission\HomeRouteResolver::resolve($loggedUser);
                        $buttonLabel = match (true) {
                            str_starts_with($homeRoute, '/admin') => __('site.btn_admin_panel'),
                            $homeRoute === '/pos' => __('site.btn_pos_screen'),
                            $homeRoute === '/kitchen' => __('site.btn_kitchen_screen'),
                            default => __('site.btn_open_dashboard'),
                        };
                    @endphp
                    <a href="{{ url($homeRoute) }}"
                       class="inline-flex items-center gap-2 h-11 px-4 sm:px-5 rounded-md bg-club-cream text-club-terra text-[13px] font-bold whitespace-nowrap hover:bg-white transition-colors">
                        <span>{{ $buttonLabel }}</span>
                        <svg class="hidden sm:block w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                    </a>
                @else
                    <a href="{{ route('register') }}"
                       class="hidden md:inline-flex items-center h-11 px-5 rounded-md border border-club-cream/40 text-club-cream text-[13px] font-bold whitespace-nowrap hover:border-club-cream hover:bg-club-cream/10 transition-colors">
                        {{ __('site.btn_register') }}
                    </a>
                    <a href="{{ route('login') }}"
                       class="inline-flex items-center h-11 px-4 sm:px-5 rounded-md bg-club-cream text-club-terra text-[13px] font-bold whitespace-nowrap hover:bg-white transition-colors">
                        {{ __('site.btn_login') }}
                    </a>
                @endauth
            </div>
        </div>
    </header>

    {{-- ============ HERO ============ --}}
    @php
        // Foto kartu diambil dari Facilities Showcase dengan judul (ID) yang sama — cukup upload sekali di panel admin.
        $cardPhotos = $companyFacilities->mapWithKeys(fn ($f) => [mb_strtolower($f->title) => $f->photoUrl()]);
    @endphp
    <section id="top" class="relative text-club-cream lg:min-h-[calc(100svh-76px)] flex flex-col"
             style="background-image: linear-gradient(100deg, rgba(30,18,15,0.9) 0%, rgba(30,18,15,0.72) 40%, rgba(30,18,15,0.3) 75%, rgba(30,18,15,0.15) 100%), url('{{ asset('images/club-hero.jpg') }}'); background-size: cover; background-position: center;">
        <div class="{{ $wrap }} flex-1 grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-12 items-end pt-14 pb-12 sm:pt-20 lg:pt-24 lg:pb-16">
            <div class="lg:col-span-7 xl:col-span-7 space-y-7">
                <div class="inline-flex items-center gap-3 text-[11px] sm:text-xs font-bold uppercase tracking-[0.2em] text-club-cream/80">
                    <span class="w-8 h-px bg-club-cream/60 shrink-0"></span>
                    <span>{{ $companyProfile->localized('hero_badge_text') }}</span>
                </div>

                <h1 class="font-display font-semibold text-[2.6rem] leading-[1.04] sm:text-6xl xl:text-7xl 2xl:text-[5.25rem] tracking-tight max-w-[15ch]">
                    {{ $companyProfile->localized('hero_headline_line1') }}
                    <span class="italic text-club-cream/75">{{ $companyProfile->localized('hero_headline_highlight') }}</span>
                    {{ $companyProfile->localized('hero_headline_line2') }}
                </h1>

                <p class="text-base sm:text-lg leading-relaxed text-club-cream/85 max-w-[46ch]">
                    {{ $companyProfile->renderedHeroSubtitle() }}
                </p>

                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    @auth
                        <a href="{{ route('customer.booking') }}"
                           class="inline-flex items-center justify-center gap-2 h-14 px-8 rounded-md bg-club-terra text-club-cream font-bold text-[15px] hover:bg-club-terra-dark transition-colors">
                            {{ __('site.btn_book_court') }}
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                        </a>
                        <a href="{{ route('dashboard') }}"
                           class="inline-flex items-center justify-center h-14 px-8 rounded-md border border-club-cream/40 text-club-cream font-semibold text-[15px] hover:bg-club-cream/10 transition-colors">
                            {{ __('site.btn_open_member_dashboard') }}
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full sm:w-auto h-14 px-4 text-club-cream/70 font-semibold text-[15px] underline-offset-4 hover:underline hover:text-club-cream">
                                {{ __('site.btn_logout') }}
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center justify-center gap-2 h-14 px-8 rounded-md bg-club-terra text-club-cream font-bold text-[15px] hover:bg-club-terra-dark transition-colors">
                            {{ __('site.btn_book_court') }}
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                        </a>
                        <a href="{{ route('register') }}"
                           class="inline-flex items-center justify-center h-14 px-8 rounded-md border border-club-cream/40 text-club-cream font-semibold text-[15px] hover:bg-club-cream/10 transition-colors">
                            {{ __('site.btn_join_vip') }}
                        </a>
                    @endauth
                </div>
            </div>

            <!-- Kartu fasilitas — sumber data sama dengan panel login, diedit lewat Filament "Konten Website"
                 (app/Filament/Pages/KelolaKontenWebsite.php). Layar lebar: tumpukan di kanan bawah, menyeimbangkan teks. -->
            <ul class="lg:col-span-5 xl:col-span-4 xl:col-start-9 grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 gap-2.5">
                @foreach($companyProfile->localizedFacilityCards() as $rawIndex => $card)
                    @php $cardPhoto = $cardPhotos[mb_strtolower($companyProfile->facility_cards[$rawIndex]['title'] ?? '')] ?? null; @endphp
                    <li class="flex items-center gap-4 p-3 pr-5 rounded-lg bg-[rgba(30,18,15,0.6)] border border-club-cream/15">
                        @if($cardPhoto)
                            <img src="{{ $cardPhoto }}" alt="{{ $card['title'] }}" class="w-14 h-14 rounded-md object-cover shrink-0">
                        @else
                            <span class="w-14 h-14 rounded-md bg-club-cream/10 border border-club-cream/20 text-club-cream flex items-center justify-center shrink-0">
                                <x-company-profile.icon :icon-key="$card['icon_key']" class="w-5 h-5" />
                            </span>
                        @endif
                        <span class="min-w-0">
                            <span class="block text-[15px] font-bold text-club-cream leading-tight">{{ $card['title'] }}</span>
                            @if(! empty($card['subtitle']))
                                <span class="block text-[13px] text-club-cream/65 mt-1">{{ $card['subtitle'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>

        <!-- Angka nyata dari database. -->
        <div class="bg-club-terra">
            <dl class="{{ $wrap }} grid grid-cols-2 lg:grid-cols-4">
                @foreach([
                    [__('site.stats_courts'), $companyProfile->court_count],
                    [__('site.stats_active_members'), $venueStats['active_members'].'+'],
                    [__('site.stats_corporate_partners'), $venueStats['sponsor_partners']],
                    [__('site.stats_open_daily'), $companyProfile->localized('operating_hours_text')],
                ] as $i => [$label, $value])
                    <div class="py-5 lg:py-6 {{ $i % 2 ? 'pl-5 border-l' : '' }} {{ $i >= 2 ? 'border-t lg:border-t-0' : '' }} {{ $i === 2 ? 'lg:pl-5 lg:border-l' : '' }} border-club-cream/10">
                        <dt class="text-[11px] font-bold uppercase tracking-[0.16em] text-club-cream/65">{{ $label }}</dt>
                        <dd class="font-display font-semibold text-2xl lg:text-3xl mt-1.5">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- ============ CARA BOOKING ============ --}}
    <section id="how-to-book" class="bg-club-paper border-b border-club-line scroll-mt-20">
        <div class="{{ $wrap }} py-16 sm:py-24">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6 mb-10 sm:mb-14">
                <div>
                    <div class="text-xs font-bold uppercase tracking-[0.2em] text-club-terra mb-3">{{ __('site.howto_eyebrow') }}</div>
                    <h2 class="font-display font-semibold text-[2rem] sm:text-5xl leading-tight">{{ __('site.howto_headline') }}</h2>
                </div>
                <a href="{{ auth()->check() ? route('customer.booking') : route('login') }}"
                   class="self-start md:self-auto inline-flex items-center gap-2 h-12 px-6 rounded-md bg-club-terra text-club-cream font-bold text-[15px] hover:bg-club-terra-dark transition-colors">
                    {{ __('site.btn_book_court') }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                </a>
            </div>
            <ol class="grid grid-cols-1 md:grid-cols-3 gap-4 lg:gap-6">
                @foreach([1, 2, 3] as $step)
                    <li class="relative rounded-lg bg-club-terra text-club-cream p-6 lg:p-8">
                        <div class="flex items-baseline justify-between">
                            <span class="font-display font-semibold text-5xl lg:text-6xl text-club-cream/50 leading-none">0{{ $step }}</span>
                            @if($step < 3)
                                <svg class="hidden md:block w-6 h-6 text-club-cream/40" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                            @endif
                        </div>
                        <h3 class="text-xl font-bold mt-6">{{ __('site.howto_'.$step.'_title') }}</h3>
                        <p class="text-[15px] leading-relaxed text-club-cream/75 mt-2">{{ __('site.howto_'.$step.'_text') }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    @if($companyFacilities->isNotEmpty())
    {{-- ============ FASILITAS ============ --}}
    <section id="facilities" class="scroll-mt-20">
        <div class="{{ $wrap }} py-16 sm:py-24">
            <div class="mb-10 sm:mb-14 max-w-3xl">
                <div class="text-xs font-bold uppercase tracking-[0.2em] text-club-terra mb-3">{{ __('site.facilities_eyebrow') }}</div>
                <h2 class="font-display font-semibold text-[2rem] sm:text-5xl leading-tight">{{ __('site.facilities_headline') }}</h2>
            </div>

            <!-- Bento: fasilitas pertama kartu besar (2 baris), sisanya bertumpuk di kanan. -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-5 auto-rows-[20rem] lg:auto-rows-[22rem] 2xl:auto-rows-[25rem]">
                @foreach($companyFacilities as $index => $facility)
                    <article class="group relative rounded-lg overflow-hidden bg-club-brown {{ $index === 0 ? 'lg:row-span-2' : '' }}">
                        @if($facility->photoUrl())
                            <img src="{{ $facility->photoUrl() }}" alt="{{ $facility->localizedTitle() }}"
                                 class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.03]">
                        @else
                            <img src="{{ asset('images/identity/monogram-cream.png') }}" alt="" class="absolute right-8 top-8 h-40 lg:h-56 w-auto opacity-[0.08]">
                        @endif
                        <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(79,47,42,0) 0%, rgba(79,47,42,0.35) 45%, rgba(79,47,42,0.94) 100%);"></div>

                        <div class="absolute top-6 left-7 text-xs font-bold tracking-[0.2em] text-club-cream/80">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</div>

                        <div class="absolute inset-x-0 bottom-0 p-7 lg:p-9 text-club-cream">
                            <h3 class="font-display font-semibold {{ $index === 0 ? 'text-3xl lg:text-[2.6rem]' : 'text-2xl lg:text-3xl' }} mb-2.5">{{ $facility->localizedTitle() }}</h3>
                            @if($facility->description)
                                <p class="text-[15px] leading-relaxed text-club-cream/85 mb-4 max-w-lg {{ $index === 0 ? '' : 'line-clamp-2' }}">{{ $facility->localizedDescription() }}</p>
                            @endif
                            @if(!empty($facility->amenities))
                                <div class="flex flex-wrap gap-2">
                                    @foreach($facility->localizedAmenities() as $amenity)
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-club-cream/10 border border-club-cream/20 text-club-cream">{{ $amenity }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    @if($companyValueProps->isNotEmpty())
    {{-- ============ KENAPA CLUB 61 ============ --}}
    <section id="why-us" class="bg-club-terra text-club-cream scroll-mt-20">
        <div class="{{ $wrap }} py-16 sm:py-24 grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12">
            <div class="lg:col-span-5 xl:col-span-4 flex flex-col gap-8">
                <div>
                    <div class="text-xs font-bold uppercase tracking-[0.2em] text-club-cream/65 mb-3">{{ __('site.why_us_eyebrow') }}</div>
                    <h2 class="font-display font-semibold text-[2rem] sm:text-5xl leading-tight">{{ __('site.why_us_headline') }}</h2>
                </div>
                <div class="relative rounded-lg overflow-hidden min-h-[18rem] flex-1">
                    <img src="{{ asset('images/club61-reception.jpg') }}" alt="Resepsionis Club61 Padel Court" class="absolute inset-0 w-full h-full object-cover">
                    <div class="absolute inset-0" style="background: linear-gradient(0deg, rgba(79,47,42,0.9) 0%, rgba(79,47,42,0.08) 60%, transparent 100%);"></div>
                    <div class="absolute left-6 right-6 bottom-6 text-club-cream">
                        <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-club-cream/80 mb-1.5">{{ __('site.why_us_photo_eyebrow') }}</div>
                        <div class="font-display font-semibold text-xl leading-snug">{{ __('site.why_us_photo_caption') }}</div>
                    </div>
                </div>
            </div>

            {{-- Daftar bergaris (bukan grid kartu): jumlah poin berapa pun tidak menyisakan slot kosong. --}}
            <ul class="lg:col-span-7 xl:col-span-7 xl:col-start-6 self-center border-t border-club-cream/20">
                @foreach($companyValueProps as $prop)
                    <li class="flex gap-5 py-6 border-b border-club-cream/20">
                        <span class="inline-flex items-center justify-center w-12 h-12 rounded-md bg-club-cream text-club-terra shrink-0">
                            <x-company-profile.icon :icon-key="$prop->icon_key" class="w-5 h-5" />
                        </span>
                        <div>
                            <div class="font-display font-semibold text-xl text-club-cream">{{ $prop->localizedTitle() }}</div>
                            @if($prop->description)
                                <div class="text-[15px] text-club-cream/75 leading-relaxed mt-1">{{ $prop->localizedDescription() }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
    @endif

    @if($membershipPlans->isNotEmpty())
    {{-- ============ MEMBERSHIP ============ --}}
    <section id="membership" class="scroll-mt-20">
        <div class="{{ $wrap }} py-16 sm:py-24">
            <div class="mb-10 sm:mb-14 max-w-3xl">
                <div class="text-xs font-bold uppercase tracking-[0.2em] text-club-terra mb-3">{{ __('site.membership_eyebrow') }}</div>
                <h2 class="font-display font-semibold text-[2rem] sm:text-5xl leading-tight">{{ __('site.membership_headline') }}</h2>
            </div>

            {{-- Kartu seragam: judul selalu 2 baris, harga & tombol sejajar. Baris terakhir yang tidak penuh ditaruh
                 di tengah, jadi jumlah paket berapa pun tetap rapi. HP: digeser ke samping, bukan tumpukan panjang. --}}
            <div class="flex sm:flex-wrap sm:justify-center gap-4 sm:gap-5 overflow-x-auto sm:overflow-visible snap-x snap-mandatory scroll-pl-5 sm:scroll-pl-0 -mx-5 px-5 sm:mx-0 sm:px-0 pb-3 sm:pb-0 [scrollbar-width:none]">
                {{-- Urutan & nama fasilitas dari Master Fasilitas; fasilitas nonaktif tidak ditampilkan. --}}
                @php $facilityCatalog = app(\App\Services\Membership\MembershipFacilityService::class)->all(); @endphp
                @foreach($membershipPlans as $plan)
                    @php
                        $orderedBenefits = $plan->benefits
                            ->filter(fn ($b) => $facilityCatalog[$b->facility]['is_active'] ?? true)
                            ->sortBy(fn ($b) => $facilityCatalog[$b->facility]['sort_order'] ?? 999)->values();
                    @endphp
                    <article class="group shrink-0 w-[84%] snap-start sm:shrink sm:w-[calc(50%-10px)] lg:w-[calc(33.333%-14px)] flex flex-col rounded-lg bg-club-paper border border-club-line p-6 lg:p-7 transition-colors hover:border-club-terra/50">
                        <div class="text-[11px] font-bold uppercase tracking-[0.16em] text-club-muted">{{ __('site.membership_valid_days', ['days' => $plan->duration_days]) }}</div>
                        <h3 class="font-display font-semibold text-2xl leading-tight mt-2 line-clamp-2 min-h-[2.5em]">{{ $plan->name }}</h3>
                        <div class="mt-4 font-bold text-[1.7rem] leading-none text-club-terra tabular-nums">
                            Rp{{ number_format((float) $plan->price, 0, ',', '.') }}
                        </div>

                        <ul class="flex-1 space-y-2.5 mt-6 pt-6 border-t border-club-line">
                            @foreach($orderedBenefits as $benefit)
                                <li class="text-[15px] flex items-start gap-2.5 text-club-brown">
                                    <svg class="w-4 h-4 mt-1 shrink-0 text-club-terra" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                                    <span>{{ $benefit->describe() }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <a href="{{ route('register') }}"
                           class="mt-7 inline-flex items-center justify-center h-12 rounded-md bg-club-terra text-club-cream font-bold text-sm transition-colors hover:bg-club-terra-dark">
                            {{ __('site.membership_cta') }}
                        </a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    @if($companyProfile->whatsapp_number)
    {{-- ============ SPONSOR KORPORAT ============ --}}
    <section class="bg-club-brown text-club-cream relative overflow-hidden">
        <img src="{{ asset('images/identity/monogram-cream.png') }}" alt="" class="absolute right-[6%] -bottom-16 h-72 w-auto opacity-[0.07] pointer-events-none">
        <div class="{{ $wrap }} relative py-14 sm:py-20 flex flex-col md:flex-row md:items-center md:justify-between gap-8">
            <div class="max-w-2xl">
                <div class="text-xs font-bold uppercase tracking-[0.2em] text-club-cream/65 mb-3">{{ __('site.sponsor_eyebrow') }}</div>
                <h3 class="font-display font-semibold text-3xl sm:text-4xl mb-3">{{ __('site.sponsor_headline') }}</h3>
                <p class="text-base leading-relaxed text-club-cream/80">{{ __('site.sponsor_description') }}</p>
            </div>
            <a href="https://wa.me/{{ preg_replace('/\D/', '', $companyProfile->whatsapp_number) }}?text={{ urlencode(__('site.sponsor_whatsapp_message')) }}"
               target="_blank" rel="noopener"
               class="shrink-0 self-start md:self-auto inline-flex items-center justify-center gap-2 h-14 px-8 rounded-md bg-club-terra text-club-cream font-bold text-[15px] hover:bg-club-terra-dark transition-colors">
                {{ __('site.sponsor_cta') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
            </a>
        </div>
    </section>
    @endif

    {{-- ============ LOKASI ============ --}}
    @php
        // Kalau maps_embed_url bukan URL valid (pernah terisi teks alamat biasa → iframe gagal dimuat),
        // abaikan dan pakai peta hasil generate dari alamat.
        $mapsUrl = $companyProfile->maps_embed_url && filter_var($companyProfile->maps_embed_url, FILTER_VALIDATE_URL)
            ? $companyProfile->maps_embed_url
            : 'https://www.google.com/maps?q='.urlencode($companyProfile->address_line).'&output=embed';
    @endphp
    <section id="location" class="scroll-mt-20">
        <div class="{{ $wrap }} py-16 sm:py-24 grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center">
            <div class="lg:col-span-5">
                <div class="text-xs font-bold uppercase tracking-[0.2em] text-club-terra mb-3">{{ __('site.location_eyebrow') }}</div>
                <h2 class="font-display font-semibold text-[2rem] sm:text-5xl leading-tight mb-8">{{ __('site.location_headline') }}</h2>
                <dl class="border-t border-club-line">
                    <div class="flex flex-col sm:flex-row sm:justify-between gap-1 sm:gap-8 py-4 border-b border-club-line">
                        <dt class="text-sm text-club-muted shrink-0">{{ __('site.location_address') }}</dt>
                        <dd class="text-[15px] font-semibold sm:text-right">{{ $companyProfile->address_line }}</dd>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:justify-between gap-1 sm:gap-8 py-4 border-b border-club-line">
                        <dt class="text-sm text-club-muted shrink-0">{{ __('site.location_hours') }}</dt>
                        <dd class="text-[15px] font-semibold sm:text-right">{{ $companyProfile->localized('operating_hours_text') }}</dd>
                    </div>
                    @if($companyProfile->whatsapp_number)
                        <div class="flex flex-col sm:flex-row sm:justify-between gap-1 sm:gap-8 py-4 border-b border-club-line">
                            <dt class="text-sm text-club-muted shrink-0">{{ __('site.location_whatsapp') }}</dt>
                            <dd class="text-[15px] font-semibold sm:text-right">{{ $companyProfile->whatsapp_number }}</dd>
                        </div>
                    @endif
                </dl>
                @if($companyProfile->whatsapp_number)
                    <a href="https://wa.me/{{ preg_replace('/\D/', '', $companyProfile->whatsapp_number) }}" target="_blank" rel="noopener"
                       class="mt-8 inline-flex items-center justify-center h-12 px-6 rounded-md bg-club-terra text-club-cream font-bold text-[15px] hover:bg-club-terra-dark transition-colors">
                        {{ __('site.location_whatsapp_cta') }}
                    </a>
                @endif
            </div>
            <div class="lg:col-span-7 rounded-lg overflow-hidden border border-club-line bg-club-paper">
                <iframe src="{{ $mapsUrl }}" title="Google Maps Club 61"
                        width="100%" height="460" style="border:0; display:block;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
        </div>
    </section>

    {{-- ============ FOOTER ============ --}}
    <footer class="bg-club-terra-dark text-club-cream/75">
        <div class="{{ $wrap }} py-12 lg:py-14 flex flex-col md:flex-row md:items-center md:justify-between gap-8">
            <div class="flex items-center gap-6">
                <img src="{{ asset('images/identity/logo-cream.png') }}" alt="Club 61 Padel Court" class="h-24 w-auto shrink-0">
                @if($companyProfile->footer_tagline)
                    <p class="text-sm leading-relaxed max-w-sm">{{ $companyProfile->localized('footer_tagline') }}</p>
                @endif
            </div>
            @php $social = $companyProfile->footer_social_links ?: []; @endphp
            @if(!empty($social))
                <div class="flex flex-wrap items-center gap-6">
                    @foreach($social as $platform => $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="text-sm font-semibold uppercase tracking-wider text-club-cream/80 hover:text-white transition-colors">{{ $platform }}</a>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="border-t border-club-cream/10">
            <p class="{{ $wrap }} py-5 text-xs">&copy; {{ date('Y') }} Club 61 Padel Court. {{ $companyProfile->address_line }}</p>
        </div>
    </footer>

</body>
</html>
