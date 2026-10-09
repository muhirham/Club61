@php $rp = fn ($v) => \App\Filament\Pages\BukuTransaksi::rupiah($v); @endphp
<div style="font-size:0.8rem; color:#4F2F2A;">
    <div style="display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:0.75rem; color:#4F2F2A;">
        <span>Pemilik: <b>{{ $voucher->user?->name ?? 'Semua customer' }}</b></span>
        @if ($voucher->isCredit())
            <span>Nilai awal: <b>{{ $rp($voucher->discount_value) }}</b></span>
            <span>Sisa: <b>{{ $rp($voucher->balance) }}</b></span>
            @if ($available < (float) $voucher->balance)
                <span style="color:#B45309;">Sedang dipesan order belum dibayar: <b>{{ $rp((float) $voucher->balance - $available) }}</b></span>
            @endif
        @endif
    </div>

    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="text-align:left; color:#662721; font-size:0.7rem; text-transform:uppercase;">
                <th style="padding:0.4rem; border-bottom:1.5px solid #E6DAC0;">Waktu</th>
                <th style="padding:0.4rem; border-bottom:1.5px solid #E6DAC0;">No. Order</th>
                <th style="padding:0.4rem; border-bottom:1.5px solid #E6DAC0;">Customer</th>
                <th style="padding:0.4rem; border-bottom:1.5px solid #E6DAC0;">Status</th>
                <th style="padding:0.4rem; border-bottom:1.5px solid #E6DAC0; text-align:right;">Potongan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td style="padding:0.4rem; border-bottom:1px solid #EFE6D2; white-space:nowrap; color:#6B7280;">{{ $order->created_at?->timezone(\App\Services\Finance\LedgerReport::TIMEZONE)->format('d M Y H:i') }}</td>
                    <td style="padding:0.4rem; border-bottom:1px solid #EFE6D2; font-family:monospace; font-weight:700;">{{ $order->order_number }}</td>
                    <td style="padding:0.4rem; border-bottom:1px solid #EFE6D2;">{{ $order->user?->name ?? '-' }}</td>
                    <td style="padding:0.4rem; border-bottom:1px solid #EFE6D2;">{{ $order->payment_status }}</td>
                    <td style="padding:0.4rem; border-bottom:1px solid #EFE6D2; text-align:right; font-weight:800;">{{ $rp($order->discount_amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="padding:1rem; text-align:center; color:#7A5A52;">Voucher ini belum pernah dipakai.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>