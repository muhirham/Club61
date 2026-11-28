@php($fmt = \App\Support\Monitoring\HealthSnapshot::class)
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Pembayaran Club 61"
        details="{{ $snapshot ? 'ringkasan diperbarui '.$fmt::ago($snapshot['generated_at'] ?? null).' · [ALERT] '.$this->periodLabel().' terakhir' : 'belum ada data' }}">
        <x-slot:icon>
            <x-pulse::icons.scale />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="c61m">
            @if ($stale && $snapshot)
                <div class="c61m-alert c61m-warn">Ringkasan terakhir sudah lebih dari 15 menit — tugas otomatis (scheduler) kemungkinan berhenti.</div>
            @endif

            {{-- [ALERT] dari log: selama ini hanya tertulis di laravel.log / doku.log. --}}
            <div>
                <div class="c61m-section-title">Peringatan sistem [ALERT] · {{ $this->periodLabel() }} terakhir</div>
                @if ($alerts->isEmpty())
                    <div class="c61m-hint"><span class="c61m-badge c61m-ok">Tidak ada</span></div>
                @else
                    <table class="c61m-table">
                        <thead><tr><th>Pesan</th><th class="c61m-num">Jumlah</th></tr></thead>
                        <tbody>
                            @foreach ($alerts as $alert)
                                <tr>
                                    <td class="c61m-mono" style="color: #b91c1c;">{{ $alert->message }}</td>
                                    <td class="c61m-num"><strong>{{ number_format($alert->count, 0, ',', '.') }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="c61m-hint" style="margin-top: 0.25rem;">Rincian lengkap ada di storage/logs (laravel.log / doku.log) di server.</div>
                @endif
            </div>

            @if (! $payments)
                <div class="c61m-alert c61m-warn">
                    Ringkasan pembayaran belum ada. Jalankan <code>php artisan monitoring:snapshot</code> sekali, atau pastikan scheduler berjalan.
                </div>
            @elseif (isset($payments['error']))
                <div class="c61m-alert c61m-bad">Gagal membaca data pembayaran: {{ $payments['error'] }}</div>
            @else
                @php($settled = $payments['settled'])
                @php($late = $payments['late_settlements'])
                @php($hanging = $payments['hanging'])
                @php($doku = $payments['doku'])
                @php($refunds = $payments['refunds'])

                <div class="c61m-row">
                    <div class="c61m-stat">
                        <div class="c61m-stat-label">Lunas via webhook</div>
                        <div class="c61m-stat-value">{{ $settled['webhook_24h'] }}</div>
                        <div class="c61m-stat-sub">24 jam · 7 hari: {{ $settled['webhook_7d'] }}</div>
                    </div>
                    <div class="c61m-stat">
                        <div class="c61m-stat-label">Lunas via polling</div>
                        <div class="c61m-stat-value">{{ $settled['polling_24h'] }}</div>
                        <div class="c61m-stat-sub">24 jam · 7 hari: {{ $settled['polling_7d'] }}</div>
                    </div>
                    <div class="c61m-stat">
                        <div class="c61m-stat-label">Uang masuk setelah batal</div>
                        <div class="c61m-stat-value">
                            <span class="c61m-badge {{ $late['count_24h'] > 0 ? 'c61m-bad' : ($late['count_7d'] > 0 ? 'c61m-warn' : 'c61m-ok') }}">{{ $late['count_24h'] }}</span>
                        </div>
                        <div class="c61m-stat-sub">24 jam · 7 hari: {{ $late['count_7d'] }}</div>
                    </div>
                    <div class="c61m-stat">
                        <div class="c61m-stat-label">Tagihan menggantung</div>
                        <div class="c61m-stat-value">
                            <span class="c61m-badge {{ $hanging['count'] > 0 ? 'c61m-warn' : 'c61m-ok' }}">{{ $hanging['count'] }}</span>
                        </div>
                        <div class="c61m-stat-sub">Masih menunggu &gt; {{ $hanging['after_minutes'] }} menit</div>
                    </div>
                </div>

                @php($totalSettled24 = $settled['webhook_24h'] + $settled['polling_24h'])
                @if ($totalSettled24 >= 3 && $settled['polling_24h'] > $settled['webhook_24h'])
                    <div class="c61m-alert c61m-warn">
                        Lebih banyak pembayaran lunas lewat polling daripada webhook dalam 24 jam — notifikasi gateway kemungkinan telat / tidak sampai.
                        Pembayaran tetap tercatat, tapi periksa URL notifikasi di dashboard Midtrans / DOKU.
                    </div>
                @endif

                <div>
                    <div class="c61m-section-title">Notifikasi terakhir dari gateway</div>
                    <div class="c61m-list">
                        <div class="c61m-line"><span>Midtrans (lunas via webhook)</span><span>{{ $fmt::ago($settled['last_webhook']['MIDTRANS'] ?? null) }}</span></div>
                        <div class="c61m-line"><span>DOKU (lunas via webhook)</span><span>{{ $fmt::ago($settled['last_webhook']['DOKU'] ?? null) }}</span></div>
                        @if ($doku)
                            <div class="c61m-line"><span>DOKU (notifikasi apa pun diterima)</span><span>{{ $fmt::ago($doku['last_received_at']) }}</span></div>
                        @endif
                    </div>
                    <div class="c61m-hint" style="margin-top: 0.2rem;">"-" = tidak ada dalam 7 hari terakhir.</div>
                </div>

                @if ($doku)
                    @php($byResult = $doku['by_result_24h'])
                    <div>
                        <div class="c61m-section-title">Notifikasi DOKU bermasalah</div>
                        <div class="c61m-row">
                            <span class="c61m-badge {{ ($byResult['retry'] ?? 0) > 0 ? 'c61m-warn' : 'c61m-muted' }}">Dicoba ulang (24 jam): {{ $byResult['retry'] ?? 0 }}</span>
                            <span class="c61m-badge {{ ($byResult['unmatched'] ?? 0) > 0 ? 'c61m-warn' : 'c61m-muted' }}">Tagihan tidak ditemukan (24 jam): {{ $byResult['unmatched'] ?? 0 }}</span>
                            <span class="c61m-badge {{ ($byResult['rejected'] ?? 0) > 0 ? 'c61m-bad' : 'c61m-muted' }}">Ditolak (24 jam): {{ $byResult['rejected'] ?? 0 }}</span>
                            <span class="c61m-badge {{ $doku['exhausted_7d'] > 0 ? 'c61m-bad' : 'c61m-muted' }}">Menyerah (7 hari): {{ $doku['exhausted_7d'] }}</span>
                            <span class="c61m-badge {{ $doku['contradicted_7d'] > 0 ? 'c61m-bad' : 'c61m-muted' }}">SUCCESS dibantah API (7 hari): {{ $doku['contradicted_7d'] }}</span>
                        </div>
                        @if (! empty($doku['contradicted_latest']))
                            <div class="c61m-alert c61m-bad" style="margin-top: 0.5rem;">
                                DOKU mengabarkan <strong>sudah bayar</strong>, tetapi cek status DOKU menyatakan lain, sehingga notifikasi diabaikan.
                                Uang customer bisa sudah masuk tanpa tercatat — cek invoice ini di dashboard DOKU.
                            </div>
                            <table class="c61m-table" style="margin-top: 0.35rem;">
                                <thead><tr><th>Waktu</th><th>Invoice</th><th class="c61m-num">Nominal</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    @foreach ($doku['contradicted_latest'] as $row)
                                        <tr>
                                            <td>{{ $fmt::time($row['at']) }}</td>
                                            <td class="c61m-mono">{{ $row['invoice'] }}</td>
                                            <td class="c61m-num">{{ $fmt::rupiah($row['amount']) }}</td>
                                            <td>{{ $row['note'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                @endif

                @if (! empty($late['latest']))
                    <div>
                        <div class="c61m-section-title">Uang masuk setelah booking batal (7 hari)</div>
                        <table class="c61m-table">
                            <thead><tr><th>Waktu</th><th>Order</th><th>Customer</th><th class="c61m-num">Nominal</th></tr></thead>
                            <tbody>
                                @foreach ($late['latest'] as $row)
                                    <tr>
                                        <td>{{ $fmt::time($row['at']) }}</td>
                                        <td class="c61m-mono">{{ $row['order'] ?? '-' }}</td>
                                        <td>{{ $row['customer'] ?? '-' }}</td>
                                        <td class="c61m-num">{{ $fmt::rupiah($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (! empty($hanging['latest']))
                    <div>
                        <div class="c61m-section-title">Tagihan menggantung (24 jam)</div>
                        <table class="c61m-table">
                            <thead><tr><th>Dibuat</th><th>Order</th><th>Gateway</th><th class="c61m-num">Nominal</th></tr></thead>
                            <tbody>
                                @foreach ($hanging['latest'] as $row)
                                    <tr>
                                        <td>{{ $fmt::time($row['at']) }}</td>
                                        <td class="c61m-mono">{{ $row['order'] ?? '-' }}</td>
                                        <td>{{ $row['gateway'] }}</td>
                                        <td class="c61m-num">{{ $fmt::rupiah($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="c61m-hint" style="margin-top: 0.2rem;">Biasanya selesai sendiri lewat pengecekan otomatis tiap 5 menit. Kalau tetap ada setelah 30 menit, cek di dashboard gateway.</div>
                    </div>
                @endif

                <div class="c61m-line">
                    <span>Refund menunggu diproses: <strong>{{ $refunds['pending'] }}</strong> (dari gateway: {{ $refunds['pending_gateway'] }})</span>
                    <span>
                        @if ($refundUrl)<a href="{{ $refundUrl }}" target="_blank" rel="noopener">Antrian Refund</a>@endif
                        @if ($logUrl) · <a href="{{ $logUrl }}" target="_blank" rel="noopener">Log Aktivitas</a>@endif
                    </span>
                </div>
            @endif
        </div>
    </x-pulse::scroll>
</x-pulse::card>
