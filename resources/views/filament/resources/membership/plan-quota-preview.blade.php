{{-- Pratinjau "Terapkan Kuota & Masa Aktif" (App\Services\Membership\MembershipPlanQuotaService::preview) — per kartu. --}}
@php
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $date = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('d M Y') : '-';
    $statusLabel = ['ACTIVE' => 'Aktif', 'PENDING_PAYMENT' => 'Menunggu bayar', 'PENDING' => 'Menunggu bayar'];
    $changed = collect($rows)->where('changes', true);
    // Kolom Catatan hanya tampil bila ada kartu yang punya catatan.
    $hasNotes = collect($rows)->contains(fn ($row) => $row['notes'] !== []);
    $th = 'padding:0.4rem; border-bottom:1.5px solid #E6DAC0;';
    $td = 'padding:0.45rem 0.4rem; border-bottom:1px solid #F1E8D3; vertical-align:top;';
@endphp
<div style="font-size:0.8rem; color:#4F2F2A;">
    @if ($rows === [])
        <p style="color:#6B7280;">Belum ada kartu aktif / menunggu pembayaran untuk paket ini.</p>
    @else
        <p style="margin-bottom:0.6rem;">
            <b>{{ $changed->count() }}</b> dari {{ count($rows) }} kartu akan berubah.
            @if ($changed->isEmpty()) Semua kartu sudah sesuai dengan paket. @endif
        </p>
        <div style="overflow-x:auto; max-height:55vh;">
            <table style="width:100%; border-collapse:collapse; min-width:640px;">
                <thead>
                    <tr style="text-align:left; color:#662721; font-size:0.7rem; text-transform:uppercase;">
                        <th style="{{ $th }}">Kartu</th>
                        <th style="{{ $th }}">Kuota (awal &middot; sisa)</th>
                        <th style="{{ $th }}">Berakhir</th>
                        @if ($hasNotes)
                            <th style="{{ $th }}">Catatan</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="{{ $row['changes'] ? '' : 'color:#9CA3AF;' }}">
                            <td style="{{ $td }}">
                                <b>{{ $row['code'] }}</b><br>
                                <span style="font-size:0.72rem;">{{ $row['owner'] }} &middot; {{ $statusLabel[$row['status']] ?? $row['status'] }}</span>
                            </td>
                            <td style="{{ $td }}">
                                @forelse ($row['quotas'] as $q)
                                    <div>
                                        {{ $q['facility'] }}: {{ $num($q['old_initial']) }} &rarr; <b>{{ $num($q['new_initial']) }}</b> {{ $q['unit'] }}
                                        @if ($row['status'] === 'ACTIVE')
                                            <span style="font-size:0.72rem;">(sisa {{ $num($q['old_remaining']) }} &rarr; <b style="color:{{ $q['clamped'] ? '#B91C1C' : '#047857' }};">{{ $num($q['new_remaining']) }}</b>)</span>
                                        @endif
                                    </div>
                                @empty
                                    <span>Tidak berubah</span>
                                @endforelse
                            </td>
                            <td style="{{ $td }}">
                                @if ($row['end_date'])
                                    {{ $date($row['end_date']['old']) }} &rarr;
                                    <b style="{{ $row['end_date']['apply'] ? '' : 'text-decoration:line-through; color:#B91C1C;' }}">{{ $date($row['end_date']['new']) }}</b>
                                @else
                                    {{ $row['status'] === 'ACTIVE' ? 'Tidak berubah' : 'Dihitung saat aktivasi' }}
                                @endif
                            </td>
                            @if ($hasNotes)
                                <td style="{{ $td }} font-size:0.72rem; color:#B45309;">
                                    {{ implode(' ', $row['notes']) }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
