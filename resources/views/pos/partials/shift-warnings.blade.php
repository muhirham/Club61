{{-- Pengingat di layar POS: shift lupa ditutup, EDC belum di-settle menjelang 22.00 (ClosesPosShift::getShiftWarningsProperty). --}}
@foreach ($this->shiftWarnings as $warning)
    <div role="status" class="pos-shift-warning" style="background:#FFFBEB; border:1px solid #FCD34D; color:#78350F; border-radius:10px; padding:0.35rem 0.75rem; font-size:0.75rem; font-weight:700; line-height:1.4; margin-bottom:0.5rem;">
        &#9888; {{ $warning }}
    </div>
@endforeach
