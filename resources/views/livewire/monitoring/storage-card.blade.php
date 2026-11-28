@php($fmt = \App\Support\Monitoring\HealthSnapshot::class)
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Storage Club 61"
        details="{{ $snapshot ? 'diperbarui '.$fmt::ago($snapshot['generated_at'] ?? null) : 'belum ada data' }}">
        <x-slot:icon>
            <x-pulse::icons.circle-stack />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="c61m">
            @if (! $storage)
                <div class="c61m-alert c61m-warn">
                    Belum ada data. Data dihitung tugas otomatis <code>monitoring:snapshot</code> tiap 5 menit — pastikan
                    scheduler berjalan, atau jalankan perintah itu sekali di server.
                </div>
            @elseif (isset($storage['error']))
                <div class="c61m-alert c61m-bad">Gagal menghitung storage: {{ $storage['error'] }}</div>
            @else
                @if ($stale)
                    <div class="c61m-alert c61m-warn">Data terakhir sudah lebih dari 15 menit — tugas otomatis (scheduler) kemungkinan berhenti.</div>
                @endif

                @php($disk = $storage['disk'])
                @php($pct = $disk['percent'])
                @php($level = $pct === null ? '' : ($pct >= 90 ? 'is-bad' : ($pct >= 80 ? 'is-warn' : '')))
                <div>
                    <div class="c61m-section-title">Disk server</div>
                    <div class="c61m-bar {{ $level }}"><span style="width: {{ min(100, (float) $pct) }}%"></span></div>
                    <div class="c61m-line" style="margin-top: 0.35rem;">
                        <span><strong>{{ $pct ?? '-' }}%</strong> terpakai · {{ $fmt::bytes($disk['used']) }} dari {{ $fmt::bytes($disk['total']) }}</span>
                        <span class="c61m-hint">sisa {{ $fmt::bytes($disk['free']) }}</span>
                    </div>
                    <div class="c61m-hint" style="margin-top: 0.2rem;">
                        @if ($disk['days_until_full'] !== null)
                            Perkiraan penuh dalam ±{{ number_format($disk['days_until_full'], 0, ',', '.') }} hari (dari kenaikan rata-rata harian).
                        @elseif (count($storage['history'] ?? []) < 3)
                            Perkiraan penuh muncul setelah ada data minimal 3 hari.
                        @else
                            Pemakaian disk tidak bertambah — belum ada perkiraan penuh.
                        @endif
                        Production & sandbox memakai disk yang sama.
                    </div>
                </div>

                @php($history = collect($storage['history'] ?? [])->take(-30))
                @if ($history->count() >= 2)
                    @php($max = max(1, $history->max('total')))
                    <div>
                        <div class="c61m-section-title">Pemakaian disk {{ $history->count() }} hari terakhir</div>
                        <div style="display: flex; align-items: flex-end; gap: 2px; height: 48px;">
                            @foreach ($history as $point)
                                <div title="{{ $point['date'] }}: {{ $fmt::bytes($point['used']) }}"
                                     style="flex: 1; min-width: 3px; height: {{ max(2, round($point['used'] / $max * 48)) }}px; background: #9ca3af; border-radius: 2px 2px 0 0;"></div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @php($db = $storage['database'] ?? [])
                <div>
                    <div class="c61m-section-title">Database · {{ $fmt::bytes($db['total'] ?? null) }}</div>
                    @if (! empty($db['tables']))
                        <table class="c61m-table">
                            <thead><tr><th>Tabel terbesar</th><th class="c61m-num">Baris (perkiraan)</th><th class="c61m-num">Ukuran</th></tr></thead>
                            <tbody>
                                @foreach ($db['tables'] as $table)
                                    <tr>
                                        <td class="c61m-mono">{{ $table['name'] }}</td>
                                        <td class="c61m-num">{{ number_format($table['rows'], 0, ',', '.') }}</td>
                                        <td class="c61m-num">{{ $fmt::bytes($table['size']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="c61m-hint">Rincian tabel hanya tersedia untuk MySQL / MariaDB.</div>
                    @endif
                </div>

                <div>
                    <div class="c61m-section-title">Folder file</div>
                    <table class="c61m-table">
                        <thead><tr><th>Folder</th><th class="c61m-num">File</th><th class="c61m-num">Ukuran</th></tr></thead>
                        <tbody>
                            @foreach ($storage['folders'] ?? [] as $folder)
                                <tr>
                                    <td>{{ $folder['label'] }} @if ($folder['label'] !== $folder['key'])<span class="c61m-hint">({{ $folder['key'] }})</span>@endif</td>
                                    <td class="c61m-num">{{ number_format($folder['files'], 0, ',', '.') }}</td>
                                    <td class="c61m-num">{{ $fmt::bytes($folder['size']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-pulse::scroll>
</x-pulse::card>
