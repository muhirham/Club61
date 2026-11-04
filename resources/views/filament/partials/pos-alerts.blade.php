{{--
    Baris peringatan halaman POS frontdesk (Walk-In / Membership / Pro Shop), berdampingan di layar lebar:
    shift lupa ditutup / EDC belum di-settle (halaman yang memakai ClosesPosShift) dan alat sewa lewat batas kembali.
    Param: $shiftWarnings (bool) — true kalau halaman punya $this->shiftWarnings.
--}}
@php
    $alertShiftCount = ($shiftWarnings ?? false) ? count($this->shiftWarnings) : 0;
    $alertOverdue = \App\Filament\Pages\PeminjamanAlat::canAccess() ? \App\Filament\Pages\PeminjamanAlat::overdueTransactions() : 0;
@endphp
@if ($alertShiftCount > 0 || $alertOverdue > 0)
    <div class="pos-alerts">
        @if ($alertShiftCount > 0)
            @include('pos.partials.shift-warnings')
        @endif
        {{-- Peminjaman Alat: raket / handuk yang lewat batas kembali — kasir / resepsionis lupa menerima kembali. --}}
        @if ($alertOverdue > 0)
            <a href="{{ \App\Filament\Pages\PeminjamanAlat::getUrl(['tableFilters' => ['status' => ['value' => 'overdue']]]) }}" role="status" class="pos-shift-warning" data-loan-overdue
                style="background:#FEF2F2; border:1px solid #FCA5A5; color:#991B1B; border-radius:10px; padding:0.35rem 0.75rem; font-size:0.75rem; font-weight:700; line-height:1.4; text-decoration:none;">
                &#9888; {{ $alertOverdue }} transaksi dengan alat sewa lewat batas kembali &mdash; cek Peminjaman Alat.
            </a>
        @endif
    </div>
@endif
