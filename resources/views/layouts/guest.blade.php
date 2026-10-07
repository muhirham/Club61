<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Club 61 Padel Court') }} - Play. Compete. Connect.</title>
        <link rel="icon" type="image/png" href="{{ asset('images/club61-logo.png') }}">

        <!-- Google Fonts: Luxury Serif, Clean Athletic Sans, Script Accent -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Cinzel:wght@600;700;800;900&family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
        @if($theme === 'terracotta')
            {{-- Padanan Cheltenham Classic & Acumin Variable Concept (brand guideline), lihat tailwind.config.js. --}}
            <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@87.5..125,400..800&family=Source+Serif+4:opsz,wght@8..60,400..700&display=swap" rel="stylesheet">
            <meta name="theme-color" content="#662721">
        @endif

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    {{-- theme="terracotta": palet logo baru (Terakota #662721, Espresso, Cream) — sementara baru halaman login. --}}
    @if($theme === 'terracotta')
    <body class="min-h-full font-brand antialiased text-club-brown bg-club-cream selection:bg-club-terra selection:text-club-cream relative overflow-x-hidden flex flex-col justify-center">
    @else
    <body class="min-h-full font-sans antialiased text-slate-800 bg-[#FBF9F5] selection:bg-[#D4AF37] selection:text-[#1E160A] relative overflow-x-hidden flex flex-col justify-center"
          style="background-image: url('{{ asset('images/white-gold-marble.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;">
        
        <!-- Ambient Warm Gold Luxury Lighting -->
        <div class="fixed inset-0 pointer-events-none z-0 overflow-hidden">
            <div class="absolute -top-32 left-1/4 w-[850px] h-[550px] bg-gradient-to-b from-amber-300/20 via-yellow-500/10 to-transparent blur-3xl rounded-full"></div>
            <div class="absolute -bottom-32 right-1/4 w-[700px] h-[500px] bg-[#D4AF37]/15 blur-3xl rounded-full"></div>
        </div>
    @endif

        <!-- Main Content Area: Responsive container for desktop & mobile -->
        <main class="relative z-10 w-full py-6 sm:py-10 px-4 sm:px-6 lg:px-8 flex items-center justify-center min-h-screen">
            {{ $slot }}
        </main>
    </body>
</html>