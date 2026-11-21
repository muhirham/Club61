@use('App\Filament\Pages\ReviewShiftKasir')
@use('App\Services\Pos\ShiftClosingService', 'SCS')
<x-filament-panels::page>
    @php
        $shift = $this->record;
        $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $tz = \App\Services\Finance\LedgerReport::TIMEZONE;
        $first = $shift->closing_inputs['first_attempt'] ?? null;
        $diff = (float) $shift->settlement_difference;
        // Shift masih buka: total belum disimpan — hitung langsung dari pembayarannya.
        $sales = $shift->status === 'OPEN' ? $shift->calculateSummary() : ['total_sales' => $shift->total_sales, 'total_transactions' => $shift->total_transactions];
        $canCorrect = $shift->status === 'CLOSED' && $shift->review_status === \App\Models\Pos\PosCashierShift::REVIEW_NEEDS_REVIEW
            && auth()->user()?->can('approve_shift_closing');
    @endphp
    <div class="c61" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        <div class="c61-card">
            <div class="c61-card-body" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.75rem; font-size: 0.8125rem; padding: 1rem 1.25rem;">
                <div><div style="color: var(--c-muted);">Loket</div><b>{{ ReviewShiftKasir::counterLabel($shift->counter) }}</b></div>
                <div><div style="color: var(--c-muted);">Dibuka</div><b>{{ $shift->opened_at?->setTimezone($tz)->format('d M Y H:i') }}</b> · {{ $shift->openedBy?->name }}</div>
                <div><div style="color: var(--c-muted);">Ditutup</div><b>{{ $shift->closed_at?->setTimezone($tz)->format('d M Y H:i') ?? 'Masih buka' }}</b> · {{ $shift->closedBy?->name }}</div>
                <div><div style="color: var(--c-muted);">Penjualan</div><b>{{ $rupiah($sales['total_sales']) }}</b> · {{ (int) $sales['total_transactions'] }} transaksi</div>
                <div><div style="color: var(--c-muted);">Status</div><b>{{ $shift->status === 'OPEN' ? 'Sedang buka — koreksi salah klik bisa langsung' : (ReviewShiftKasir::STATUS_LABELS[$shift->review_status] ?? 'Sebelum rekonsiliasi blind') }}</b>
                    @if ($shift->reviewedBy) · {{ $shift->reviewedBy->name }} {{ $shift->reviewed_at?->setTimezone($tz)->format('d M H:i') }} @endif</div>
                <div><div style="color: var(--c-muted);">Total selisih</div>@if ($shift->status === 'OPEN')<b>-</b> <span style="color: var(--c-muted);">(dicek saat tutup shift)</span>@else<b style="color: {{ abs($diff) < 0.005 ? '#15803D' : '#BE123C' }};">{{ abs($diff) < 0.005 ? 'Rp 0' : ($diff > 0 ? '+' : '-').$rupiah(abs($diff)) }}</b>@endif</div>
            </div>
        </div>

        @if (! empty($shift->settlement_reconciliation))
            <div class="c61-card">
                <div class="c61-card-body" style="padding: 1rem 1.25rem;">
                    <div class="c61-card-title" style="margin-bottom: 0.5rem;">Sistem vs yang diketik kasir</div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; font-size: 0.8125rem; border-collapse: collapse;">
                            <thead>
                                <tr style="text-align: left; color: var(--c-muted); font-size: 0.7rem; text-transform: uppercase;">
                                    <th style="padding: 0.4rem;">Kategori</th>
                                    <th style="padding: 0.4rem; text-align: right;">Trx</th>
                                    <th style="padding: 0.4rem; text-align: right;">Sistem</th>
                                    <th style="padding: 0.4rem; text-align: right;">Diketik</th>
                                    <th style="padding: 0.4rem; text-align: right;">Selisih</th>
                                    @if ($canCorrect)<th style="padding: 0.4rem;"></th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shift->settlement_reconciliation as $row)
                                    @php $typed = in_array($row['type'] ?? SCS::TYPE_MANUAL, [SCS::TYPE_EDC, SCS::TYPE_MANUAL], true); @endphp
                                    <tr style="border-top: 1px solid #EFE6D2;">
                                        <td style="padding: 0.4rem;">{{ $row['label'] ?? $row['key'] }}
                                            @if ($row['mode'] ?? null)<span style="color: var(--c-muted);"> · {{ $row['mode'] === SCS::MODE_SETTLE ? 'settle' : 'audit report (ganti shift)' }}</span>@endif
                                        </td>
                                        <td style="padding: 0.4rem; text-align: right;">{{ $row['count'] ?? '-' }}</td>
                                        <td style="padding: 0.4rem; text-align: right; font-family: monospace;">{{ $rupiah($row['system_amount'] ?? 0) }}</td>
                                        <td style="padding: 0.4rem; text-align: right; font-family: monospace;">
                                            @if (! $typed) ✔ sistem
                                            @elseif ($row['corrected'] ?? null)
                                                {{ $rupiah($row['settled_amount'] ?? 0) }}
                                                <div data-typed-correction style="font-family: inherit; font-size: 0.7rem; color: #B45309; line-height: 1.4;">
                                                    dikoreksi {{ $row['corrected']['by_name'] ?? '-' }} {{ \Illuminate\Support\Carbon::parse($row['corrected']['at'])->setTimezone($tz)->format('d M H:i') }}<br>
                                                    diketik kasir: {{ ($row['typed_by_cashier'] ?? false) ? $rupiah($row['cashier_amount'] ?? 0) : 'tidak diketik' }}<br>
                                                    <span style="color: var(--c-muted);">{{ $row['corrected']['reason'] ?? '' }}</span>
                                                </div>
                                            @elseif (! ($row['typed_by_cashier'] ?? true))<span style="font-family: inherit; color: var(--c-muted);" title="Baris ini muncul setelah koreksi metode — kasir tidak pernah mengetiknya. Cek struk / mutasi.">— tidak diketik</span>
                                            @else {{ $rupiah($row['settled_amount'] ?? 0) }} @endif
                                        </td>
                                        <td style="padding: 0.4rem; text-align: right; font-family: monospace; font-weight: 800; color: {{ abs((float) ($row['difference'] ?? 0)) < 0.005 ? '#15803D' : '#BE123C' }};">
                                            {{ abs((float) ($row['difference'] ?? 0)) < 0.005 ? 'COCOK' : (($row['difference'] > 0 ? '+' : '-').$rupiah(abs($row['difference']))) }}
                                        </td>
                                        @if ($canCorrect)
                                            <td style="padding: 0.4rem; text-align: right; white-space: nowrap;">
                                                @if ($typed){{ ($this->correctTypedAction)(['row' => $row['key']]) }}@endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--c-muted); margin-top: 0.5rem; line-height: 1.5;">
                        EDC = semua transaksi mesin itu di loket ini sejak settlement terakhir (bisa lintas shift kalau ganti shift tanpa settle).
                        Angka sistem dihitung ulang setelah koreksi metode bayar. Kasir salah ketik angka struk → <b>Koreksi</b> di baris itu
                        sesuai struk / aplikasi (ketikan asli kasir tetap tampil). "Tidak diketik" = baris yang baru muncul setelah koreksi metode.
                        <b>Setujui</b> baru bisa setelah semua baris COCOK; selisih nyata (uang kurang / transaksi tidak tercatat) → <b>Tolak</b>.
                        @if ($first) Cek pertama {{ \Illuminate\Support\Carbon::parse($first['at'])->setTimezone($tz)->format('d M H:i') }} oleh {{ $first['by_name'] ?? '-' }}: selisih {{ $rupiah($first['difference'] ?? 0) }}. @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($shift->closing_notes || $shift->review_notes)
            <div class="c61-card">
                <div class="c61-card-body" style="font-size: 0.8125rem; line-height: 1.6; padding: 1rem 1.25rem;">
                    @if ($shift->closing_notes)<div><b>Penjelasan kasir:</b> {{ $shift->closing_notes }}</div>@endif
                    @if ($shift->review_notes)<div><b>Catatan review:</b> {{ $shift->review_notes }}</div>@endif
                </div>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
