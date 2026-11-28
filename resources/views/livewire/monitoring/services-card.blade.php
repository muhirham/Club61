@php($fmt = \App\Support\Monitoring\HealthSnapshot::class)
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Layanan Club 61"
        details="{{ $snapshot ? 'diperbarui '.$fmt::ago($snapshot['generated_at'] ?? null) : 'belum ada data' }}">
        <x-slot:icon>
            <x-pulse::icons.server />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="c61m">
            <div class="c61m-row">
                <div class="c61m-stat">
                    <div class="c61m-stat-label">Tugas otomatis</div>
                    <div class="c61m-stat-value">
                        @if ($stale)
                            <span class="c61m-badge c61m-bad">Tidak berjalan</span>
                        @else
                            <span class="c61m-badge c61m-ok">Berjalan</span>
                        @endif
                    </div>
                    <div class="c61m-stat-sub">Terakhir: {{ $fmt::ago($snapshot['generated_at'] ?? null) }}</div>
                </div>

                @if ($services && ! isset($services['error']))
                    @php($queue = $services['queue'])
                    <div class="c61m-stat">
                        <div class="c61m-stat-label">Antrian ({{ $queue['connection'] }})</div>
                        <div class="c61m-stat-value">{{ $queue['pending'] ?? '-' }} <span class="c61m-hint">menunggu</span></div>
                        <div class="c61m-stat-sub">
                            Gagal 24 jam: {{ $queue['failed_24h'] ?? '-' }} · total: {{ $queue['failed_total'] ?? '-' }}
                            @if ($queue['oldest_pending_at'] && \Carbon\Carbon::parse($queue['oldest_pending_at'])->lt(now()->subMinutes(10)))
                                <br><span class="c61m-badge c61m-warn">Ada job menunggu &gt; 10 menit — worker antrian mungkin mati</span>
                            @endif
                        </div>
                    </div>

                    @php($reverb = $services['reverb'])
                    <div class="c61m-stat">
                        <div class="c61m-stat-label">Realtime (Reverb)</div>
                        <div class="c61m-stat-value">
                            @if (! $reverb['used'])
                                <span class="c61m-badge c61m-muted">Tidak dipakai</span>
                            @elseif ($reverb['up'])
                                <span class="c61m-badge c61m-ok">Hidup</span>
                            @else
                                <span class="c61m-badge c61m-bad">Mati</span>
                            @endif
                        </div>
                        <div class="c61m-stat-sub">
                            {{ $reverb['used'] ? 'Layar memakai polling cadangan saat mati.' : 'BROADCAST_CONNECTION bukan reverb.' }}
                        </div>
                    </div>
                @endif
            </div>

            @if (! $services)
                <div class="c61m-alert c61m-warn">
                    Belum ada data. Jalankan <code>php artisan monitoring:snapshot</code> sekali, atau pastikan scheduler berjalan.
                </div>
            @elseif (isset($services['error']))
                <div class="c61m-alert c61m-bad">Gagal membaca layanan: {{ $services['error'] }}</div>
            @else
                @if ($services['production'] && $services['app_debug'])
                    <div class="c61m-alert c61m-bad"><strong>APP_DEBUG menyala di production.</strong> Pesan error lengkap bisa terlihat oleh pengunjung. Ubah ke <code>APP_DEBUG=false</code> lalu <code>php artisan config:cache</code>.</div>
                @endif
                @if ($services['production'] && $services['doku_debug_inbound'])
                    <div class="c61m-alert c61m-warn"><strong>DOKU_SNAP_DEBUG_INBOUND menyala di production.</strong> Matikan bila sudah tidak dibutuhkan untuk penelusuran, lalu <code>php artisan config:cache</code>.</div>
                @endif

                <div>
                    <div class="c61m-section-title">Info server</div>
                    <div class="c61m-list">
                        <div class="c61m-line"><span>Lingkungan</span><span class="c61m-badge {{ $services['production'] ? 'c61m-ok' : 'c61m-muted' }}">{{ $services['environment'] }}</span></div>
                        <div class="c61m-line"><span>PHP · Laravel</span><span>{{ $services['php_version'] }} · {{ $services['laravel_version'] }}</span></div>
                        <div class="c61m-line"><span>Batas memori PHP web</span><span>{{ $webMemoryLimit }}</span></div>
                        <div class="c61m-line"><span>Batas memori PHP tugas otomatis (CLI)</span><span>{{ $services['php_cli_memory_limit'] === '-1' ? 'tanpa batas (-1)' : $services['php_cli_memory_limit'] }}</span></div>
                        @if ($services['uptime_seconds'])
                            <div class="c61m-line"><span>Server menyala sejak</span><span>{{ \Carbon\CarbonInterval::seconds($services['uptime_seconds'])->cascade()->locale('id')->forHumans(['parts' => 2]) }}</span></div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </x-pulse::scroll>
</x-pulse::card>
