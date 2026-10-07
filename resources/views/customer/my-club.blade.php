<x-app-layout>
    <div class="py-6 sm:py-8 text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 space-y-6">

            <!-- Top Header & Navigation -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white/95 p-5 rounded-xl border border-[#E6DAC0] shadow-sm">
                <div class="flex items-center gap-3.5">
                    <a href="{{ route('dashboard') }}" class="p-2.5 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] transition-colors" title="Back to Home">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="font-display font-black text-xl sm:text-2xl text-[#4F2F2A]">My Club &bull; CLUB 61 Padel Court</h1>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]">Medan Venue</span>
                        </div>
                        <p class="text-xs text-[#7A5A52] mt-0.5">Exclusive club facilities, operational hours, and membership privileges</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <span class="px-4 py-2 rounded-lg text-xs font-black bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0] shadow-sm">
                        Club 61 Member
                    </span>
                    <a href="{{ route('customer.booking') }}" 
                       class="px-5 py-2.5 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                        + Book Court
                    </a>
                </div>
            </div>

            <!-- Club Hero Visual Banner -->
            <div class="relative overflow-hidden rounded-xl p-6 sm:p-10 text-white shadow-[0_15px_40px_rgba(20,40,30,0.25)] border border-[#E6DAC0]/70"
                 style="background: #662721;">
                <!-- Glowing Orb Accents -->
                <div class="absolute -right-10 -bottom-10 w-96 h-96 rounded-full bg-[#662721]/20 blur-3xl pointer-events-none"></div>

                <div class="relative z-10 max-w-2xl">
                    <div class="inline-flex items-center gap-1.5 px-3.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-widest bg-[#E6DAC0]/20 text-[#F7F0DB] border border-[#E6DAC0]/40 mb-3 shadow-sm">
                        <span>Club Profile &bull; Indosat Building, Medan, North Sumatra</span>
                    </div>
                    <h2 class="font-display text-2xl sm:text-4xl font-extrabold tracking-tight text-[#F7F0DB] leading-tight">
                        Club 61 Padel Court Medan
                    </h2>
                    <p class="text-xs sm:text-sm text-emerald-100/80 mt-3 font-medium leading-relaxed">
                        Medan's premier padel sporting venue featuring 3 tournament-standard panoramic courts (2 indoor AC, 1 outdoor), cedarwood Finnish sauna, and specialty cafe lounge.
                    </p>

                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/10 border border-white/20 text-xs font-bold text-white">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            Venue Open Daily: 06:00 - 23:00 WIB
                        </div>
                        <a href="https://wa.me/6281261617233" target="_blank" 
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-[#E6DAC0]/20 hover:bg-[#E6DAC0]/30 border border-[#E6DAC0]/50 text-xs font-bold text-[#F7F0DB] transition-colors">
                            <span>Contact Concierge via WhatsApp</span>
                        </a>
                    </div>
                </div>
            </div>

            @php
                $activeMbr = \App\Models\Membership\UserMembership::with(['plan', 'balances'])
                    ->where('user_id', Auth::id())
                    ->where('status', 'ACTIVE')
                    ->where(function ($q) {
                        $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
                    })
                    ->latest('start_date')
                    ->first();
                $allPlans = \App\Models\Membership\MembershipPlan::with('benefits')->where('is_active', true)->get();

                $facilityService = app(\App\Services\Membership\MembershipFacilityService::class);
                $creditVouchers = app(\App\Services\Finance\VoucherService::class)->walletFor(Auth::user());
            @endphp

            @if($activeMbr)
                <!-- Active Membership Digital Pass -->
                <div class="relative overflow-hidden rounded-xl p-6 sm:p-8 border-2 border-[#662721] shadow-[0_15px_35px_rgba(102,39,33,0.2)]"
                     style="background: linear-gradient(135deg, #F7F0DB 0%, #FFFFFF 100%);">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                        <div>
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-extrabold uppercase bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>Digital Member Pass</span>
                            </div>
                            <div class="font-display font-black text-2xl sm:text-3xl text-[#4F2F2A] mt-2">{{ $activeMbr->plan->name }}</div>
                            <div class="text-xs font-mono font-bold text-[#662721] mt-0.5">KODE: {{ $activeMbr->membership_code }}</div>
                            <div class="text-xs text-[#7A5A52] mt-1">
                                Masa Aktif: <strong>{{ \Carbon\Carbon::parse($activeMbr->start_date)->format('d M Y') }}</strong> s/d <strong>{{ \Carbon\Carbon::parse($activeMbr->end_date)->format('d M Y') }}</strong>
                                @if($activeMbr->end_date)
                                    ({{ now()->diffInDays(\Carbon\Carbon::parse($activeMbr->end_date), false) }} hari tersisa)
                                @endif
                            </div>
                        </div>

                        <!-- Balances Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 min-w-[320px] lg:min-w-[480px]">
                            @foreach($activeMbr->balances as $bal)
                                <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                    <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">{{ $facilityService->name($bal->facility) }}</div>
                                    <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">
                                        @if($bal->quota_type === 'HOURS')
                                            {{ (float)$bal->remaining_quota }} Jam
                                        @elseif($bal->quota_type === 'VISITS')
                                            {{ $bal->initial_quota ? ((float)$bal->remaining_quota . ' Sesi') : 'Unlimited' }}
                                        @elseif((float) $bal->discount_percent > 0)
                                            Diskon {{ (float) $bal->discount_percent }}%
                                        @else
                                            Termasuk
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-[#7A5A52] mt-0.5">
                                        @if($bal->discount_percent > 0 && $bal->quota_type !== 'NONE')
                                            Diskon {{ $bal->discount_percent }}%
                                        @elseif($bal->booking_priority_days > 0)
                                            Prioritas H-{{ $bal->booking_priority_days }}
                                        @else
                                            Entitlement Aktif
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @php
                $myCorporateMember = \App\Models\Sponsor\SponsorOrganizationMember::where('user_id', Auth::id())
                    ->where('status', 'ACTIVE')
                    ->with(['organization', 'vouchers' => fn ($q) => $q->orderByDesc('issued_at')])
                    ->first();
            @endphp

            @if($myCorporateMember)
                <!-- Corporate Team Voucher -->
                <div id="corporate-vouchers" class="relative overflow-hidden rounded-xl p-6 sm:p-8 border-2 border-[#662721] shadow-[0_15px_35px_rgba(102,39,33,0.2)] scroll-mt-24"
                     style="background: linear-gradient(135deg, #F7F0DB 0%, #FFFFFF 100%);">
                    <div class="flex items-center justify-between gap-4 flex-wrap mb-4">
                        <div>
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-extrabold uppercase bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>Corporate Team Benefit</span>
                            </div>
                            <div class="font-display font-black text-xl sm:text-2xl text-[#4F2F2A] mt-2">{{ $myCorporateMember->organization->name ?? 'Corporate Team' }}</div>
                            <p class="text-xs text-[#7A5A52] mt-0.5">Free play-hour vouchers granted by your company. Hours are already active as soon as they're released — no need to activate anything before booking.</p>
                        </div>
                        <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center shrink-0">
                            <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">Total Active Hours</div>
                            <div class="font-display font-black text-2xl text-[#4F2F2A] mt-1">{{ number_format($myCorporateMember->totalRemainingHours(), 1) }}</div>
                        </div>
                    </div>

                    @if($myCorporateMember->vouchers->isEmpty())
                        <p class="text-xs text-[#7A5A52] italic">No vouchers issued yet.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="text-left text-[10px] uppercase font-extrabold text-[#662721] border-b border-[#E6DAC0]">
                                        <th class="py-2 pr-3">Hours Granted</th>
                                        <th class="py-2 pr-3">Used</th>
                                        <th class="py-2 pr-3">Remaining</th>
                                        <th class="py-2 pr-3">Issued</th>
                                        <th class="py-2 pr-3">Expires</th>
                                        <th class="py-2 pr-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($myCorporateMember->vouchers as $v)
                                        <tr class="border-b border-[#F7F0DB] {{ $v->isExpired() ? 'opacity-50' : '' }}">
                                            <td class="py-2 pr-3 font-bold text-[#4F2F2A]">{{ number_format((float) $v->hours_granted, 1) }}</td>
                                            <td class="py-2 pr-3">{{ number_format((float) $v->hours_used, 1) }}</td>
                                            <td class="py-2 pr-3 font-bold">{{ number_format($v->remainingHours(), 1) }}</td>
                                            <td class="py-2 pr-3">{{ $v->issued_at->format('d M Y') }}</td>
                                            <td class="py-2 pr-3">{{ $v->expires_at->format('d M Y') }}</td>
                                            <td class="py-2 pr-3">
                                                @if($v->isExpired())
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-600 border border-gray-300">Expired</span>
                                                @elseif(! $v->isAcknowledged())
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">New</span>
                                                @else
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Claimed</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            <!-- Voucher saldo (refund yang dijadikan voucher) -->
            @if ($creditVouchers->isNotEmpty())
                <div class="p-5 rounded-xl bg-emerald-50/80 border border-emerald-200 shadow-sm space-y-3">
                    <div>
                        <h3 class="font-display font-extrabold text-lg text-[#4F2F2A]">My Credit Vouchers</h3>
                        <p class="text-xs text-[#7A5A52]">Saldo dari refund yang dialihkan ke voucher. Bisa dipakai sebagian untuk booking padel berikutnya &mdash; muncul otomatis di halaman checkout, atau sebutkan kodenya di kasir.</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($creditVouchers as $v)
                            <div class="p-4 rounded-lg bg-white border border-emerald-200">
                                <div class="text-sm font-mono font-black text-emerald-900">{{ $v['code'] }}</div>
                                <div class="text-lg font-black text-[#4F2F2A]">Rp {{ number_format($v['balance'], 0, ',', '.') }}</div>
                                <div class="text-[11px] text-[#7A5A52]">
                                    dari Rp {{ number_format($v['initial'], 0, ',', '.') }}
                                    @if ($v['valid_until']) &middot; berlaku s/d {{ $v['valid_until'] }} @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Membership Plans Catalog -->            <div class="space-y-4">
                <div class="flex items-center justify-between px-1">
                    <div>
                        <h3 class="font-display font-extrabold text-lg text-[#4F2F2A]">Paket Keanggotaan Club 61</h3>
                        <p class="text-xs text-[#7A5A52]">Satu keanggotaan terintegrasi untuk seluruh fasilitas: Padel Court, Gym Fitness, dan Finnish Sauna</p>
                    </div>
                    <span class="text-xs font-bold text-[#662721]">{{ $allPlans->count() }} Pilihan Paket</span>
                </div>

                <div class="flex flex-wrap justify-center gap-4 [&>*]:w-full md:[&>*]:w-[calc(50%-8px)] lg:[&>*]:w-[calc(25%-12px)]">
                    @foreach($allPlans as $p)
                        @php
                            $cards = $facilityService->presentPlan($p);
                            $perks = array_values(array_filter((array) ($p->perks ?? []), fn ($perk) => is_string($perk) && trim($perk) !== ''));
                        @endphp
                        <div onclick="window.location.href='{{ route('customer.membership', ['plan' => $p->id]) }}'"
                             class="p-5 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm hover:border-[#662721] hover:shadow-xl hover:-translate-y-1 transition-all flex flex-col justify-between group cursor-pointer">
                            <div>
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-[10px] font-extrabold uppercase text-[#662721]">{{ $p->ownership_type }}</span>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#F7F0DB] text-[#662721]">{{ $p->duration_days }} Hari</span>
                                </div>
                                <div class="font-display font-black text-lg text-[#4F2F2A] group-hover:text-[#662721] transition-colors">{{ $p->name }}</div>
                                <div class="font-black text-base text-[#662721] mt-1">Rp {{ number_format($p->price, 0, ',', '.') }}</div>

                                <div class="mt-4 border-t border-[#F7F0DB] pt-3 space-y-2 text-xs text-[#7A5A52]">
                                    @foreach ($cards as $card)
                                        <div class="flex items-start gap-1.5">
                                            <span class="w-1.5 h-1.5 rounded-full bg-[#662721] hover:bg-[#511D18] mt-1 shrink-0"></span>
                                            <span>{{ $card['title'] }}@if ($card['details']) <span class="text-[#7A5A52]">({{ implode(', ', $card['details']) }})</span>@endif</span>
                                        </div>
                                    @endforeach
                                    @foreach ($perks as $perk)
                                        <div class="flex items-start gap-1.5">
                                            <span class="w-1.5 h-1.5 rounded-full bg-[#662721] hover:bg-[#511D18] mt-1 shrink-0"></span>
                                            <span>{{ $perk }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-5 pt-3 border-t border-[#F7F0DB] space-y-2">
                                <a href="{{ route('customer.membership', ['plan' => $p->id]) }}" 
                                   class="block w-full text-center py-2.5 px-3 rounded-xl bg-[#662721] hover:bg-[#511D18]  text-[#F7F0DB] font-extrabold text-xs shadow-md transition-all active:scale-95 cursor-pointer">
                                    Lihat Detail &amp; Beli Online &rarr;
                                </a>
                                <a href="https://wa.me/6281261617233?text=Halo%20Club%2061%2C%20saya%20tertarik%20mendaftar%20paket%20{{ urlencode($p->name) }}"
                                   target="_blank"
                                   onclick="event.stopPropagation()"
                                   class="block w-full text-center py-1.5 px-3 rounded-xl bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] font-bold text-[11px] transition-colors">
                                    Tanya Concierge WA
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Key Info Bar (3 Cards) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-5 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex items-start gap-4">
                    <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 text-[#662721]">
                        <svg class="w-6 h-6 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase font-extrabold tracking-wider text-[#662721]">Operating Hours</div>
                        <div class="font-display font-black text-base text-[#4F2F2A] mt-0.5">Monday &ndash; Sunday (Daily)</div>
                        <div class="text-xs text-[#7A5A52] mt-1 font-mono font-bold">06:00 &ndash; 23:00 WIB</div>
                    </div>
                </div>

                <div class="p-5 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex items-start gap-4">
                    <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 text-[#662721]">
                        <svg class="w-6 h-6 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase font-extrabold tracking-wider text-[#662721]">Prime Location</div>
                        <div class="font-display font-black text-base text-[#4F2F2A] mt-0.5">Club 61 Padel Court Medan</div>
                        <div class="text-xs text-[#7A5A52] mt-1">Indosat Building, Jl. Perintis Kemerdekaan No. 39, Medan, North Sumatra</div>
                    </div>
                </div>

                <div class="p-5 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex items-start gap-4">
                    <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 text-[#662721]">
                        <svg class="w-6 h-6 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase font-extrabold tracking-wider text-[#662721]">Concierge &amp; Valet</div>
                        <div class="font-display font-black text-base text-[#4F2F2A] mt-0.5">Dedicated Member Services</div>
                        <div class="text-xs text-[#7A5A52] mt-1 font-mono">0812-6161-PADEL &bull; Free VIP Valet</div>
                    </div>
                </div>
            </div>

            <!-- Facilities Showcase: 6 Premium Cards -->
            <div class="space-y-4">
                <div class="flex items-center justify-between px-1">
                    <div>
                        <h3 class="font-display font-extrabold text-lg text-[#4F2F2A]">Exclusive Member Facilities</h3>
                        <p class="text-xs text-[#7A5A52]">International championship standards tailored for peak athletic performance and recovery</p>
                    </div>
                    <span class="text-xs font-bold text-[#662721]">6 Integrated Facilities</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    
                    <!-- Facility 1: Courts -->
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex flex-col justify-between hover:border-[#662721] hover:shadow-[0_10px_25px_rgba(102,39,33,0.2)] transition-all group">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                <span class="text-[10px] font-black tracking-widest text-[#662721] uppercase">COURT</span>
                            </div>
                            <div>
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">3 Panoramic Padel Courts</h4>
                                <p class="text-xs text-[#7A5A52] mt-1.5 leading-relaxed">
                                    12mm tempered glass without center pillars, Mondo Supercourt XN turf, anti-glare 1000 lux LED illumination, and climate-controlled central AC.
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-[#E6DAC0]/40 flex items-center justify-between text-[11px] font-bold text-[#662721]">
                            <span>FIP / WPT Standard</span>
                            <span class="text-emerald-700">Available Daily</span>
                        </div>
                    </div>

                    <!-- Facility 2: Sauna -->
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex flex-col justify-between hover:border-[#662721] hover:shadow-[0_10px_25px_rgba(102,39,33,0.2)] transition-all group">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                <span class="text-[10px] font-black tracking-widest text-[#662721] uppercase">SAUNA</span>
                            </div>
                            <div>
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">Finnish Cedarwood Sauna</h4>
                                <p class="text-xs text-[#7A5A52] mt-1.5 leading-relaxed">
                                    Finnish red cedarwood sauna for optimal post-match muscle recovery.
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-[#E6DAC0]/40 flex items-center justify-between text-[11px] font-bold text-[#662721]">
                            <span>Muscle Recovery</span>
                            <span class="text-emerald-700">Akses Kuota Member</span>
                        </div>
                    </div>

                    <!-- Facility 3: Cafe & Lounge -->
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex flex-col justify-between hover:border-[#662721] hover:shadow-[0_10px_25px_rgba(102,39,33,0.2)] transition-all group">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                <span class="text-[10px] font-black tracking-widest text-[#662721] uppercase">CAFE</span>
                            </div>
                            <div>
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">Club 61 Cafe &amp; Lounge</h4>
                                <p class="text-xs text-[#7A5A52] mt-1.5 leading-relaxed">
                                    Artisan protein smoothie bar, specialty single-origin espresso, wholesome brunch menu, and panoramic court-viewing deck.
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-[#E6DAC0]/40 flex items-center justify-between text-[11px] font-bold text-[#662721]">
                            <span>F&amp;B &bull; Social Lounge</span>
                            <span class="text-[#662721] font-mono">Open 07:00 - 22:30</span>
                        </div>
                    </div>

                    <!-- Facility 4: Smart Locker -->
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex flex-col justify-between hover:border-[#662721] hover:shadow-[0_10px_25px_rgba(102,39,33,0.2)] transition-all group">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                <span class="text-[10px] font-black tracking-widest text-[#662721] uppercase">LOCKER</span>
                            </div>
                            <div>
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">Smart Locker &amp; Rain Shower</h4>
                                <p class="text-xs text-[#7A5A52] mt-1.5 leading-relaxed">
                                    RFID digital lockers with device charging ports, high-pressure rain showers, Dyson hair dryers, and Le Labo bath amenities.
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-[#E6DAC0]/40 flex items-center justify-between text-[11px] font-bold text-[#662721]">
                            <span>Private Locker Room</span>
                            <span class="text-emerald-700">RFID Security</span>
                        </div>
                    </div>

                    <!-- Facility 5: Pro Shop -->
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex flex-col justify-between hover:border-[#662721] hover:shadow-[0_10px_25px_rgba(102,39,33,0.2)] transition-all group">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                <span class="text-[10px] font-black tracking-widest text-[#662721] uppercase">SHOP</span>
                            </div>
                            <div>
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">Pro Shop &amp; Custom Gear</h4>
                                <p class="text-xs text-[#7A5A52] mt-1.5 leading-relaxed">
                                    Curated selection of limited-edition padel rackets (Babolat, Bullpadel, Nox), complimentary racket demo testing, and official pro tour apparel.
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-[#E6DAC0]/40 flex items-center justify-between text-[11px] font-bold text-[#662721]">
                            <span>Padel Gear &amp; Rental</span>
                            <span class="text-emerald-700">Demo Rackets</span>
                        </div>
                    </div>

                    <!-- Facility 6: Valet & EV -->
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm flex flex-col justify-between hover:border-[#662721] hover:shadow-[0_10px_25px_rgba(102,39,33,0.2)] transition-all group">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                <span class="text-[10px] font-black tracking-widest text-[#662721] uppercase">VALET</span>
                            </div>
                            <div>
                                <h4 class="font-display font-black text-sm text-[#4F2F2A]">Dedicated Valet &amp; EV Charger</h4>
                                <p class="text-xs text-[#7A5A52] mt-1.5 leading-relaxed">
                                    Private member parking area with complimentary zero-wait valet service and ultra-fast charging stations for electric vehicles.
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-[#E6DAC0]/40 flex items-center justify-between text-[11px] font-bold text-[#662721]">
                            <span>Valet Service</span>
                            <span class="text-emerald-700">Free for Members</span>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Club Etiquette & Rules (Left) + Member Status Benefits (Right) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                
                <!-- Left: Etiquette Guidelines (Col 7) -->
                <div class="lg:col-span-7 bg-white/95 rounded-xl p-6 sm:p-8 border border-[#E6DAC0] shadow-sm space-y-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] flex items-center justify-center shrink-0 text-[#662721]">
                            <svg class="w-5 h-5 text-[#662721]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-display font-black text-base sm:text-lg text-[#4F2F2A]">Club Etiquette &amp; Rules</h3>
                            <p class="text-xs text-[#7A5A52]">Maintaining an exceptional standard of comfort for all members</p>
                        </div>
                    </div>

                    <div class="space-y-3 text-xs">
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0]/60">
                            <span class="font-black text-[#662721] shrink-0">01.</span>
                            <div>
                                <strong class="text-[#4F2F2A]">Dedicated Padel Footwear:</strong>
                                <span class="text-[#7A5A52] block mt-0.5">Players are required to wear dedicated court/padel footwear with non-marking outsoles to protect turf integrity.</span>
                            </div>
                        </div>

                        <div class="flex items-start gap-3 p-3 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0]/60">
                            <span class="font-black text-[#662721] shrink-0">02.</span>
                            <div>
                                <strong class="text-[#4F2F2A]">Punctual Turnstile Check-In:</strong>
                                <span class="text-[#7A5A52] block mt-0.5">Present your E-Ticket QR Code at the turnstile gate at least 10 minutes prior to session start for automatic access.</span>
                            </div>
                        </div>

                        <div class="flex items-start gap-3 p-3 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0]/60">
                            <span class="font-black text-[#662721] shrink-0">03.</span>
                            <div>
                                <strong class="text-[#4F2F2A]">Sauna Protocol:</strong>
                                <span class="text-[#7A5A52] block mt-0.5">Showering is mandatory prior to entering the sauna to preserve hygiene and community wellness.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Official Member Privileges (Col 5) -->
                <div class="lg:col-span-5 space-y-6">
                    <div class="bg-[#511D18] text-white rounded-xl p-6 border border-transparent shadow-lg space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-widest bg-[#E6DAC0]/20 text-[#F7F0DB] border border-[#E6DAC0]/40">
                                Club Privileges
                            </span>
                            <span class="text-xs text-[#E6DAC0] font-bold">Club 61 Medan</span>
                        </div>

                        <div>
                            <h4 class="font-display font-black text-lg text-white">Standar Hak Istimewa Member</h4>
                            <p class="text-xs text-emerald-100/70 mt-1">Hak akses resmi terintegrasi bagi seluruh pemegang keanggotaan aktif Club 61</p>
                        </div>
                        
                        <ul class="space-y-3 text-xs text-emerald-100/85 font-medium">
                            <li class="flex items-start gap-2.5">
                                <span class="text-[#E6DAC0] font-bold mt-0.5">&bull;</span>
                                <div>
                                    <strong class="text-white block font-bold">Prioritas Reservasi Lapangan (H-7 s/d H-14)</strong>
                                    <span class="text-[11px] text-emerald-100/70">Akses booking 3 panoramic courts lebih awal sebelum dibuka untuk umum.</span>
                                </div>
                            </li>
                            <li class="flex items-start gap-2.5">
                                <span class="text-[#E6DAC0] font-bold mt-0.5">&bull;</span>
                                <div>
                                    <strong class="text-white block font-bold">Turnstile Smart Pass Smartphone</strong>
                                    <span class="text-[11px] text-emerald-100/70">Check-in mandiri dengan QR Digital Pass tanpa antre di pintu masuk venue.</span>
                                </div>
                            </li>
                            <li class="flex items-start gap-2.5">
                                <span class="text-[#E6DAC0] font-bold mt-0.5">&bull;</span>
                                <div>
                                    <strong class="text-white block font-bold">Integrated Multi-Facility Access</strong>
                                    <span class="text-[11px] text-emerald-100/70">Akses terpadu fasilitas Technogym Fitness Center &amp; Finnish Sauna.</span>
                                </div>
                            </li>
                            <li class="flex items-start gap-2.5">
                                <span class="text-[#E6DAC0] font-bold mt-0.5">&bull;</span>
                                <div>
                                    <strong class="text-white block font-bold">Free VIP Valet &amp; Member Rate Lounge</strong>
                                    <span class="text-[11px] text-emerald-100/70">Layanan parkir valet gratis di Indosat Building serta potongan harga di Cafe.</span>
                                </div>
                            </li>
                        </ul>

                        <div class="pt-3 border-t border-white/15 space-y-2">
                            <a href="{{ route('customer.booking') }}" 
                               class="w-full py-3.5 px-4 rounded-lg bg-[#F7F0DB] hover:bg-white text-[#4F2F2A] text-xs font-black uppercase tracking-wider flex items-center justify-center gap-2 shadow-md  active:scale-95 transition-all">
                                <span>Reservasi Lapangan Sekarang &rarr;</span>
                            </a>
                            <a href="{{ route('customer.membership') }}"
                               class="block w-full text-center py-2 text-[11px] font-bold text-[#E6DAC0] hover:text-[#F7F0DB] transition-colors">
                                Lihat &amp; Beli Paket Keanggotaan &rarr;
                            </a>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</x-app-layout>


