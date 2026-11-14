@php
    use App\Filament\Pages\LogAktivitas;
    use App\Services\Audit\AuditRegistry;

    // Semua nilai ditampilkan lewat {{ }} (di-escape) — isi log bisa berasal dari input user.
    $format = function ($value) {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            default => (string) $value,
        };
    };
    $label = fn (string $key) => AuditRegistry::columnLabel($key);
    $box = 'border:1px solid rgba(128,128,128,0.25); border-radius:12px; padding:0.9rem 1rem; margin-bottom:1rem;';
    $heading = 'font-size:0.7rem; font-weight:800; letter-spacing:0.06em; text-transform:uppercase; opacity:0.65; margin-bottom:0.6rem;';
    $cell = 'padding:0.45rem 0.6rem; border-top:1px solid rgba(128,128,128,0.18); vertical-align:top; font-size:0.8rem; word-break:break-word;';
    $pre = 'margin:0; white-space:pre-wrap; font-family:inherit; font-size:0.78rem;';
    $severityColor = match ($log->severity) {
        'CRITICAL' => '#DC2626',
        'WARNING' => '#D97706',
        default => '#6B7280',
    };
@endphp

<div style="font-size:0.85rem;">
    <div style="{{ $box }}">
        <div style="{{ $heading }}">Ringkasan</div>
        <table style="width:100%; border-collapse:collapse;">
            <tr><td style="{{ $cell }} width:34%; opacity:0.7;">Pelaku</td>
                <td style="{{ $cell }} font-weight:700;">
                    {{ LogAktivitas::actorName($log) }}
                    @if($log->causer_role) <span style="font-weight:400; opacity:0.7;">({{ $log->causer_role }})</span> @endif
                </td></tr>
            <tr><td style="{{ $cell }} opacity:0.7;">Jenis pelaku</td><td style="{{ $cell }}">{{ LogAktivitas::ACTOR_TYPES[$log->actor_type] ?? $log->actor_type }}</td></tr>
            <tr><td style="{{ $cell }} opacity:0.7;">Modul</td><td style="{{ $cell }}">{{ LogAktivitas::MODULES[$log->module] ?? $log->module }}</td></tr>
            <tr><td style="{{ $cell }} opacity:0.7;">Aksi</td><td style="{{ $cell }}"><code>{{ $log->event }}</code></td></tr>
            <tr><td style="{{ $cell }} opacity:0.7;">Tingkat</td>
                <td style="{{ $cell }} font-weight:800; color:{{ $severityColor }};">{{ LogAktivitas::SEVERITIES[$log->severity] ?? $log->severity }}</td></tr>
            @if($log->subject_label)
                <tr><td style="{{ $cell }} opacity:0.7;">Data</td><td style="{{ $cell }}">{{ $log->subject_label }} <span style="opacity:0.6;">({{ $log->subject_type }})</span></td></tr>
            @endif
            <tr><td style="{{ $cell }} opacity:0.7;">Dari</td><td style="{{ $cell }}">{{ LogAktivitas::CHANNELS[$log->channel] ?? $log->channel }}</td></tr>
            @if($log->url)
                <tr><td style="{{ $cell }} opacity:0.7;">Halaman</td><td style="{{ $cell }}"><code>{{ $log->http_method }} {{ $log->url }}</code></td></tr>
            @endif
            @if($log->ip_address)
                <tr><td style="{{ $cell }} opacity:0.7;">IP &amp; perangkat</td><td style="{{ $cell }}">{{ $log->ip_address }}<br><span style="opacity:0.6; font-size:0.72rem;">{{ $log->user_agent }}</span></td></tr>
            @endif
        </table>
    </div>

    @if(! empty($log->changes))
        <div style="{{ $box }}">
            <div style="{{ $heading }}">Perubahan data</div>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left; font-size:0.72rem; opacity:0.7;">
                        <th style="padding:0.3rem 0.6rem; width:28%;">Kolom</th>
                        <th style="padding:0.3rem 0.6rem;">Sebelum</th>
                        <th style="padding:0.3rem 0.6rem;">Sesudah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($log->changes as $column => $diff)
                        @php $diff = is_array($diff) ? $diff : ['new' => $diff]; @endphp
                        <tr>
                            <td style="{{ $cell }} font-weight:700;">{{ $label((string) $column) }}</td>
                            <td style="{{ $cell }} color:#B91C1C;"><pre style="{{ $pre }}">{{ array_key_exists('old', $diff) ? $format($diff['old']) : '—' }}</pre></td>
                            <td style="{{ $cell }} color:#15803D;"><pre style="{{ $pre }}">{{ array_key_exists('new', $diff) ? $format($diff['new']) : '—' }}</pre></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(! empty($log->meta))
        <div style="{{ $box }}">
            <div style="{{ $heading }}">Detail</div>
            <table style="width:100%; border-collapse:collapse;">
                @foreach($log->meta as $key => $value)
                    <tr>
                        <td style="{{ $cell }} width:34%; opacity:0.7;">{{ str_replace('_', ' ', (string) $key) }}</td>
                        <td style="{{ $cell }}"><pre style="{{ $pre }}">{{ $format($value) }}</pre></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if($related->isNotEmpty())
        <div style="{{ $box }}">
            <div style="{{ $heading }}">Aktivitas lain dalam request / proses yang sama</div>
            @foreach($related as $item)
                <div style="{{ $cell }} display:flex; gap:0.75rem;">
                    <span style="opacity:0.6; white-space:nowrap;">{{ $item->created_at?->timezone('Asia/Jakarta')->format('H:i:s') }}</span>
                    <span>{{ $item->description }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
