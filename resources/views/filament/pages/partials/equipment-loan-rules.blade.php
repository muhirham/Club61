{{-- Ketentuan peminjaman & daftar denda (EquipmentLoanService) — halaman Peminjaman Alat & slip peminjam. --}}
<ol style="margin: 0.4rem 0 0.5rem 1.1rem; padding: 0; list-style: decimal;">
    @foreach (\App\Services\Padel\EquipmentLoanService::RULES as $rule)
        <li>{{ $rule }}</li>
    @endforeach
</ol>
<div style="font-weight: 800; margin-top: 0.25rem;">Denda kerusakan</div>
<ul style="margin: 0.2rem 0 0.4rem 1.1rem; padding: 0; list-style: disc;">
    @foreach (\App\Models\Padel\EquipmentLoan::CONDITIONS as $condition)
        @if ($condition['fine'])
            <li>{{ $condition['label'] }}: sesuai daftar denda alat{{ isset($loan) && $loan->equipment && (float) $loan->equipment->{$condition['fine']} > 0 ? ' — Rp '.number_format((float) $loan->equipment->{$condition['fine']}, 0, ',', '.').' per unit' : '' }}</li>
        @endif
    @endforeach
</ul>
<div style="font-size: 0.75rem;">{{ \App\Services\Padel\EquipmentLoanService::FINE_NOTE }}</div>
