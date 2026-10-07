{{-- Sama dengan layouts.app tapi TANPA navigasi portal customer — dipakai saat halaman
     ditampilkan di dalam panel staf (iframe), supaya staf tidak bisa "nyasar" ke portal customer. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Club 61 Padel Court') }} - Play. Compete. Connect.</title>
        <link rel="icon" type="image/png" href="{{ asset('images/identity/monogram-terracotta.png') }}">
        <meta name="theme-color" content="#662721">

        <!-- Google Fonts: Luxury Serif & Athletic Sans -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        {{-- Brand guideline: padanan Cheltenham Classic (judul) & Acumin Variable Concept (teks) — lihat tailwind.config.js. --}}
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@87.5..125,400..800&family=Source+Serif+4:opsz,wght@8..60,400..700&display=swap" rel="stylesheet">

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full font-brand antialiased text-[#4F2F2A] bg-[#F7F0DB] selection:bg-[#662721] selection:text-[#F7F0DB] relative overflow-x-hidden flex flex-col">

        <div class="min-h-screen flex flex-col relative z-10">

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white/80 border-b border-[#662721]/40 shadow-sm">
                    <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 py-4">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="flex-1">
                {{ $slot }}
            </main>

        </div>

        @stack('scripts')
    </body>
</html>