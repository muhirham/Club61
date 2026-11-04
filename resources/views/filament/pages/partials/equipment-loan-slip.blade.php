{{-- Slip peminjaman alat satu transaksi: ditunjukkan untuk peminjam (Peminjaman Alat → Detail). --}}
@php
    $tz = \App\Services\Finance\LedgerReport::TIMEZONE;
    $first = $loans->first();
@endphp
<div style="font-size: 0.8125rem; line-height: 1.6; color: #4F2F2A;" data-loan-slip>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 0.6rem;">
        <tr><td style="color: #7A5A52; width: 35%;">Peminjam</td><td style="font-weight: 800;">{{ $first?->borrower_name }}{{ $first?->borrower_phone ? ' · '.$first->borrower_phone : '' }}</td></tr>
        <tr><td style="color: #7A5A52;">Booking</td><td>{{ $booking->booking_code }} · {{ $booking->court?->name ?? '-' }} · {{ $booking->start_time->format('d M Y, H:i') }}–{{ $booking->end_time->format('H:i') }}</td></tr>
        <tr><td style="color: #7A5A52;">Alat</td><td style="font-weight: 800;">{{ $loans->map(fn ($l) => $l->quantity.'× '.$l->equipment_name)->implode(', ') }}</td></tr>
        <tr><td style="color: #7A5A52;">Batas kembali</td><td style="font-weight: 800;">{{ $loans->max('due_at')?->setTimezone($tz)->format('d M Y, H:i') }} WIB</td></tr>
    </table>
    <div style="font-weight: 900;">RULES PEMINJAMAN RAKET PADEL</div>
    @include('filament.pages.partials.equipment-loan-rules', ['loan' => $first])
    <div style="font-weight: 900; margin-top: 0.4rem;">PLEASE TAKE CARE OF OUR RACKETS — Play hard, respect the equipment.</div>
</div>
