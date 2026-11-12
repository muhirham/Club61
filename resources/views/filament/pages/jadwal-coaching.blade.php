<x-filament-panels::page>
    <div class="c61" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        @php
            $today = \App\Filament\Pages\JadwalCoaching::filterDate(null);
            $tomorrow = \Illuminate\Support\Carbon::parse($today)->addDay()->toDateString();
            $isDay = $range === 'day';
        @endphp
        <!-- Toolbar tanggal (pengganti panel filter): langsung berubah tanpa tombol terapkan. -->
        <div class="c61-toolbar">
            <div class="c61-row" style="gap: 0.6rem; flex-wrap: wrap;">
                <div class="c61-seg">
                    <button type="button" wire:click="showDay(0)" wire:loading.attr="disabled"
                        class="c61-seg-btn {{ $isDay && $date === $today ? 'is-active' : '' }}">Hari Ini</button>
                    <button type="button" wire:click="showDay(1)" wire:loading.attr="disabled"
                        class="c61-seg-btn {{ $isDay && $date === $tomorrow ? 'is-active' : '' }}">Besok</button>
                    <button type="button" wire:click="showUpcoming" wire:loading.attr="disabled"
                        class="c61-seg-btn {{ $isDay ? '' : 'is-active' }}">Semua Mendatang</button>
                </div>
                <input type="date" wire:model.live="date" class="c61-input" aria-label="Tanggal main"
                    style="width: auto; height: 36px; {{ $isDay && ! in_array($date, [$today, $tomorrow], true) ? 'border-color: var(--c-terra); background: #FFFFFF;' : '' }}">
            </div>
            <div style="font-size: 0.8125rem; font-weight: 700; color: var(--c-muted); white-space: nowrap;">
                {{ $isDay ? \Illuminate\Support\Carbon::parse($date)->locale('id')->translatedFormat('l, d M Y') : 'Hari ini & seterusnya' }}
                &middot; <span style="color: var(--c-brown);">{{ $this->sessionCount() }} sesi</span>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
