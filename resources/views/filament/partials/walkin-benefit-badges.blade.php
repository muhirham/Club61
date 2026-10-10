{{-- Benefit customer di layar kasir walk-in: kuota membership & voucher jam corporate + toggle pakai/tidak.
     Param: $benefitQuote (BookOfflineCourt::getBenefitQuoteProperty), sisanya properti publik komponen. --}}
@php
    $fmtJam = fn ($h) => rtrim(rtrim(number_format((float) $h, 1, ',', '.'), '0'), ',');
    $badge = 'display:inline-flex; align-items:center; gap:0.25rem; border-radius:4px; padding:0.15rem 0.35rem; font-size:0.65rem; font-weight:700;';
    $toggle = fn (bool $on) => 'display:inline-flex; align-items:center; gap:0.3rem; background:'.($on ? '#ECFDF5' : '#F3F4F6').'; border:1px solid '.($on ? '#6EE7B7' : '#D1D5DB').'; border-radius:999px; padding:0.15rem 0.5rem 0.15rem 0.3rem; font-size:0.6rem; font-weight:800; color:'.($on ? '#047857' : '#6B7280').'; cursor:pointer;';
    $dot = fn (bool $on) => 'width:0.55rem; height:0.55rem; border-radius:999px; background:'.($on ? '#10B981' : '#9CA3AF').';';
@endphp

@if ($activeMembershipInfo)
    <div style="margin-top:0.25rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:wrap;">
        <div style="{{ $badge }} background:#FEF3C7; border:1px solid #F59E0B; color:#92400E;">
            <span>{{ $activeMembershipInfo['plan_name'] }}</span>
            <span>•</span>
            <span>
                @if ($activeMembershipInfo['quota_type'] === 'HOURS')
                    Sisa: {{ $fmtJam($activeMembershipInfo['remaining_quota']) }} Jam
                @elseif ($activeMembershipInfo['discount_percent'] > 0)
                    Diskon {{ $activeMembershipInfo['discount_percent'] }}%
                @else
                    Member
                @endif
            </span>
        </div>
        @if ($posStep === 'selection')
            <button type="button" wire:click="toggleMembershipBenefit" style="{{ $toggle($useMembershipBenefit) }}"
                title="{{ $useMembershipBenefit ? 'Klik untuk tidak memakai benefit membership' : 'Klik untuk memakai benefit membership' }}">
                <span style="{{ $dot($useMembershipBenefit) }}"></span>
                {{ $useMembershipBenefit ? 'Benefit Dipakai' : 'Benefit Dimatikan' }}
            </button>
        @endif
    </div>
    @if ($useMembershipBenefit && ($benefitQuote['membership']['rejected_reason'] ?? null) === 'OUTSIDE_TIME_WINDOW')
        <div style="font-size:0.6rem; color:#92400E; margin-top:0.2rem;">Jadwal di luar jam akses paket — kuota member tidak dipakai.</div>
    @endif
@endif

@if ($sponsorVoucherInfo)
    <div style="margin-top:0.25rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:wrap;">
        <div style="{{ $badge }} background:#F6EAE7; border:1px solid #E8CFC9; color:#662721;">
            <span>Corporate {{ $sponsorVoucherInfo['organization_name'] }}</span>
            <span>•</span>
            <span>Sisa: {{ $fmtJam($sponsorVoucherInfo['remaining_hours']) }} Jam</span>
        </div>
        @if ($posStep === 'selection')
            <button type="button" wire:click="toggleSponsorVoucherBenefit" style="{{ $toggle($useSponsorVoucherBenefit) }}"
                title="{{ $useSponsorVoucherBenefit ? 'Klik untuk tidak memakai voucher jam corporate' : 'Klik untuk memakai voucher jam corporate' }}">
                <span style="{{ $dot($useSponsorVoucherBenefit) }}"></span>
                {{ $useSponsorVoucherBenefit ? 'Jam Corporate Dipakai' : 'Jam Corporate Dimatikan' }}
            </button>
        @endif
    </div>
@endif
