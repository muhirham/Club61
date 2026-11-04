{{--
    Isi Z-Report yang dicetak (#printable-z-report) — dipakai POS saat tutup shift (shift-report-modal) & cetak ulang admin
    (DetailShiftKasir). $report: data shift; $reprint: null atau ['at' => ..., 'by' => ...] untuk tanda CETAK ULANG.
--}}
@use('App\Models\Pos\PosCashierShift')
@use('App\Services\Pos\ShiftClosingService', 'SCS')
@php
    $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $reprint ??= null;
    $diff = (float) ($report['total_difference'] ?? 0);
    $statusText = match ($report['review_status'] ?? null) {
        PosCashierShift::REVIEW_MATCHED => 'COCOK',
        PosCashierShift::REVIEW_APPROVED => 'DISETUJUI ADMIN',
        PosCashierShift::REVIEW_REJECTED => 'DITOLAK ADMIN',
        default => 'PERLU REVIEW ADMIN',
    };
    $counterLabel = match ($report['counter']) {
        'PADEL_FRONTDESK' => 'Frontdesk Padel',
        'FNB_COUNTER' => 'Kasir F&B',
        default => $report['counter'],
    };
    $line = 'display:flex; justify-content:space-between; gap:0.5rem;';
@endphp
<div id="printable-z-report" style="font-family:monospace; font-size:0.72rem; color:#111827; display:flex; flex-direction:column; gap:0.5rem;">
    <div style="text-align:center;">
        <div style="font-weight:900;">CLUB 61</div>
        <div>{{ \App\Models\Setting\CompanyProfileSetting::receiptAddress() }}</div>
        <div style="font-weight:900; margin-top:0.2rem;">LAPORAN PENUTUPAN KASIR (Z-REPORT)</div>
        <div>Loket: {{ $counterLabel }}</div>
        @if ($reprint)
            <div style="font-weight:900; margin-top:0.2rem;">*** CETAK ULANG ***</div>
            <div>{{ $reprint['at'] }} oleh {{ $reprint['by'] }}</div>
        @endif
    </div>
    <div style="border-top:1px dashed #9CA3AF; padding-top:0.4rem;">
        <div>No. Shift: <strong>{{ $report['shift_number'] }}</strong></div>
        <div>Buka : {{ $report['opened_at'] }} ({{ $report['opened_by'] }})</div>
        <div>Tutup: {{ $report['closed_at'] }} ({{ $report['closed_by'] }})</div>
        <div>Total Transaksi: {{ $report['total_transactions'] }}</div>
        {{-- Rekap harian (X-Report) memperlihatkan angka sistem — admin perlu tahu kalau dibuka sebelum tutup shift. --}}
        @isset($report['sales_recap_views'])
            <div>Rekap harian dilihat: {{ $report['sales_recap_views'] }}x</div>
        @endisset
    </div>

    @if (! empty($report['reconciliation']))
        <div style="border-top:1px dashed #9CA3AF; padding-top:0.4rem; display:flex; flex-direction:column; gap:0.3rem;">
            <div style="font-weight:900;">SISTEM vs STRUK / APLIKASI:</div>
            @foreach ($report['reconciliation'] as $row)
                @php $typed = in_array($row['type'], [SCS::TYPE_EDC, SCS::TYPE_MANUAL], true); @endphp
                <div>
                    <div style="font-weight:700;">{{ $row['label'] }}@if ($row['mode'] ?? null) ({{ $row['mode'] === SCS::MODE_SETTLE ? 'settle' : 'audit report' }})@endif</div>
                    <div style="{{ $line }}"><span>Sistem</span><span>{{ $rupiah($row['system_amount']) }}</span></div>
                    @if ($typed)
                        <div style="{{ $line }}"><span>Struk</span><span>{{ ($row['typed_by_cashier'] ?? true) ? $rupiah($row['settled_amount']) : 'tidak diketik' }}</span></div>
                        <div style="{{ $line }} font-weight:900; color:{{ abs($row['difference']) < 0.005 ? '#15803D' : '#BE123C' }};">
                            <span>Selisih</span><span>{{ abs($row['difference']) < 0.005 ? 'COCOK' : ($row['difference'] > 0 ? '+' : '-').$rupiah(abs($row['difference'])) }}</span>
                        </div>
                    @else
                        <div style="{{ $line }} color:#15803D;"><span>{{ match ($row['type']) { SCS::TYPE_AUTO => 'Terverifikasi sistem', SCS::TYPE_TRANSFER => 'Cek mutasi rekening', default => 'Benefit (bukan uang)' } }}</span><span>&#10003;</span></div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Loket frontdesk (Walk-In, Membership, Pro Shop dalam satu shift): rincian per jenis transaksi. --}}
    @if (! empty($report['transaction_types']))
        <div style="border-top:1px dashed #9CA3AF; padding-top:0.4rem; display:flex; flex-direction:column; gap:0.15rem;">
            <div style="font-weight:900;">PER JENIS TRANSAKSI:</div>
            @foreach ($report['transaction_types'] as $type)
                <div>
                    <div style="font-weight:700;">{{ $type['label'] }}</div>
                    <div style="{{ $line }}"><span>{{ $type['count'] }} transaksi</span><span>{{ $rupiah($type['amount']) }}</span></div>
                </div>
            @endforeach
        </div>
    @endif

    <div style="border-top:1px dashed #9CA3AF; padding-top:0.4rem;">
        <div style="{{ $line }} font-weight:900;"><span>TOTAL OMSET POS</span><span>{{ $rupiah($report['total_sales']) }}</span></div>
        <div style="{{ $line }} font-weight:900; color:{{ abs($diff) < 0.005 ? '#15803D' : '#BE123C' }};">
            <span>TOTAL SELISIH</span><span>{{ abs($diff) < 0.005 ? 'Rp 0 (COCOK)' : ($diff > 0 ? '+' : '-').$rupiah(abs($diff)) }}</span>
        </div>
        <div>Status: {{ $statusText }}</div>
        @if (! empty($report['reviewed_by']))
            <div>Direview: {{ $report['reviewed_by'] }} ({{ $report['reviewed_at'] }})</div>
        @endif
    </div>

    @if (! empty($report['closing_notes']))
        <div style="border-top:1px dashed #9CA3AF; padding-top:0.4rem;"><strong>Catatan:</strong> {{ $report['closing_notes'] }}</div>
    @endif
    @if (! empty($report['review_notes']))
        <div style="border-top:1px dashed #9CA3AF; padding-top:0.4rem;"><strong>Catatan review:</strong> {{ $report['review_notes'] }}</div>
    @endif

    <div style="display:flex; justify-content:space-between; gap:0.75rem; margin-top:0.6rem; text-align:center;">
        <div style="flex:1;">
            <div>Kasir Bertugas,</div>
            <div style="height:2.2rem;"></div>
            <div>( {{ $report['closed_by'] }} )</div>
        </div>
        <div style="flex:1;">
            <div>Supervisor / Manager,</div>
            <div style="height:2.2rem;"></div>
            <div>( {{ $report['reviewed_by'] ?? '....................' }} )</div>
        </div>
    </div>
</div>
