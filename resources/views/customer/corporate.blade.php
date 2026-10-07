<x-dynamic-component :component="! empty($previewMode) ? 'embed-layout' : 'app-layout'">
    <div class="py-6 sm:py-8 text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 space-y-6">

            <!-- Top Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white/95 p-5 rounded-xl border border-[#E6DAC0] shadow-sm">
                <div class="flex items-center gap-3.5">
                    @if(empty($previewMode))
                    <a href="{{ route('dashboard') }}" class="p-2.5 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] transition-colors" title="Back to Home">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    @endif
                    <div>
                        <h1 class="font-display font-black text-xl sm:text-2xl text-[#4F2F2A]">Manage Corporate Sponsor Team</h1>
                        <p class="text-xs text-[#7A5A52] mt-0.5">Manage your team's employee roster &amp; play-hour vouchers at Club 61</p>
                    </div>
                </div>
            </div>

            @if(! empty($previewMode) && $organization)
                <div class="p-4 rounded-lg border-2 border-amber-300 bg-amber-50 text-amber-900 text-xs font-bold flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <span>MODE PRATINJAU STAF — Anda melihat dashboard PIC <strong>{{ $organization->sponsorAdmin?->name ?? '-' }}</strong> ({{ $organization->name }}). Semua aksi dinonaktifkan.</span>
                </div>
            @endif

            @if(! $organization)
                <div class="p-8 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm text-center">
                    <p class="text-sm font-bold text-[#4F2F2A]">You are not registered as the PIC of any corporate account.</p>
                    <p class="text-xs text-[#7A5A52] mt-1.5">Contact Club 61 Concierge if your company already subscribes to a corporate package but this menu hasn't appeared yet.</p>
                </div>
            @else
                <div @if(! empty($previewMode)) inert @endif class="space-y-6">
                <div id="corporate-alert" class="hidden p-4 rounded-lg text-xs font-bold"></div>

                <!-- Contract Summary -->
                <div class="relative overflow-hidden rounded-xl p-6 sm:p-8 border-2 border-[#662721] shadow-[0_15px_35px_rgba(102,39,33,0.2)]"
                     style="background: linear-gradient(135deg, #F7F0DB 0%, #FFFFFF 100%);">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                        <div>
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-extrabold uppercase bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>Active Corporate Account</span>
                            </div>
                            <div class="font-display font-black text-2xl sm:text-3xl text-[#4F2F2A] mt-2">{{ $organization->name }}</div>
                            <div class="text-xs text-[#7A5A52] mt-1">
                                Plan: <strong>{{ $organization->userMembership->plan->name ?? '-' }}</strong>
                                @if($organization->userMembership && $organization->userMembership->end_date)
                                    &bull; Contract until <strong>{{ \Carbon\Carbon::parse($organization->userMembership->end_date)->format('d M Y') }}</strong>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 min-w-[240px] sm:min-w-[380px]">
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">Active Members</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">{{ $activeMemberCount }}</div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">Contract Quota</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">
                                    {{ $totalQuota !== null ? number_format($totalQuota, 1) : 'Unlimited' }}
                                </div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">Quota Remaining</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">
                                    {{ $quotaRemaining !== null ? number_format($quotaRemaining, 1) : 'Unlimited' }}
                                </div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">Hours Released</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">{{ number_format($totalHoursReleased, 1) }}</div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">Hours Used</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">{{ number_format($totalHoursUsed, 1) }}</div>
                            </div>
                        </div>
                    </div>
                    <p class="text-[10px] text-[#7A5A52] mt-4 italic">
                        @if($totalQuota !== null)
                            Contract quota of {{ number_format($totalQuota, 1) }} hrs &mdash; {{ number_format($totalHoursReleased, 1) }} hrs already released to {{ $activeMemberCount }} active member(s), {{ number_format($quotaRemaining, 1) }} hrs still available to release. {{ number_format($totalHoursUsed, 1) }} hrs actually used so far.
                        @else
                            No contract quota cap set (unlimited) &mdash; {{ number_format($totalHoursReleased, 1) }} hrs released so far to {{ $activeMemberCount }} active member(s), {{ number_format($totalHoursUsed, 1) }} hrs actually used.
                        @endif
                    </p>
                </div>

                <!-- Access Schedule (Read-Only, set by venue staff) -->
                <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                    <h3 class="font-display font-extrabold text-base text-[#4F2F2A]">Team Play Access Schedule</h3>
                    <p class="text-xs text-[#7A5A52] mt-0.5 mb-4">Allowed hours &amp; days are set by Club 61 (to balance court availability with other sponsors). Contact Concierge for adjustments.</p>
                    @if($organization->accessSchedules->isEmpty())
                        <p class="text-xs text-[#7A5A52] italic">No access schedule has been set for your team yet.</p>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            @foreach($organization->accessSchedules as $sch)
                                <div class="p-4 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0]/60 text-xs">
                                    <div class="font-bold text-[#4F2F2A]">{{ \Carbon\Carbon::parse($sch->valid_from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($sch->valid_until)->format('d M Y') }}</div>
                                    <div class="text-[#7A5A52] mt-1">
                                        Hours: <strong>{{ $sch->time_start }} - {{ $sch->time_end }}</strong>
                                        @if($sch->days_of_week)
                                            &bull; Days: {{ collect($sch->days_of_week)->map(fn($d) => [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'][$d] ?? $d)->implode(', ') }}
                                        @else
                                            &bull; All Days
                                        @endif
                                    </div>
                                    @if($sch->max_concurrent_courts)
                                        <div class="text-[#7A5A52] mt-1">Max {{ $sch->max_concurrent_courts }} court(s) at once</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Add Member & CSV Import -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                        <h3 class="font-display font-extrabold text-base text-[#4F2F2A] mb-1">Add One Member</h3>
                        <p class="text-[11px] text-[#7A5A52] mb-4">Adding just one employee? Fill this in. Adding many at once? Use CSV import on the right instead.</p>
                        <form id="add-member-form" class="space-y-3">
                            <input type="text" name="name" placeholder="Employee Name" required
                                   class="w-full px-4 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                            <input type="text" name="phone" placeholder="Phone Number (e.g. 081234567890)" required
                                   class="w-full px-4 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                            <div>
                                <label class="text-[11px] font-bold text-[#7A5A52] mb-1 block">Starting play hours (optional)</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="stepValue('initial_hours_input', -0.5, 0)"
                                            class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">&minus;</button>
                                    <input type="number" step="0.5" min="0" name="initial_hours" id="initial_hours_input" placeholder="0"
                                           class="w-full text-center px-2 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                                    <button type="button" onclick="stepValue('initial_hours_input', 0.5, 0)"
                                            class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">+</button>
                                    <span class="text-xs font-bold text-[#7A5A52] shrink-0 w-8">hrs</span>
                                </div>
                            </div>
                            <button type="submit"
                                    class="w-full py-2.5 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                Add Member
                            </button>
                        </form>
                    </div>

                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-display font-extrabold text-base text-[#4F2F2A]">Import Roster + Voucher (CSV)</h3>
                            <a href="{{ route('customer.corporate.sample-csv') }}"
                               class="text-[10px] font-bold text-[#662721] hover:text-[#4F2F2A] underline underline-offset-2 shrink-0">
                                Download Sample CSV
                            </a>
                        </div>
                        <p class="text-xs text-[#7A5A52] mb-2">Column format: <code class="bg-[#F7F0DB] px-1 rounded">name, phone, hours</code>. Re-upload every month to release new voucher hours to your team.</p>
                        <pre class="text-[10px] bg-[#FCF8EE] border border-[#E6DAC0]/60 rounded-xl p-3 mb-3 overflow-x-auto text-[#4F2F2A]">name,phone,hours
Budi Santoso,081234567890,10
Siti Rahma,081399887766,5</pre>
                        <form id="csv-import-form" class="space-y-3">
                            <input type="file" name="file" accept=".csv,.txt" required
                                   class="w-full text-xs text-[#7A5A52] file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-[#F7F0DB] file:text-[#662721] hover:file:bg-[#F7F0DB]">
                            <button type="submit"
                                    class="w-full py-2.5 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                Upload &amp; Process CSV
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Team Roster -->
                <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                    <div class="flex items-start justify-between gap-3 flex-wrap mb-1">
                        <h3 class="font-display font-extrabold text-base text-[#4F2F2A]">Team Member Roster</h3>
                        <button type="button" onclick="openBulkModal()"
                                class="px-3.5 py-2 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-[11px] font-bold shrink-0">
                            🎟️ Give Hours to Multiple Members
                        </button>
                    </div>
                    <p class="text-[11px] text-[#7A5A52] mb-4">
                        Members log in at <a href="{{ route('login') }}" class="underline font-bold">/login</a> using their <strong>Phone Number</strong> (not their name) &bull; default password is the <strong>last 6 digits of their phone number</strong>.
                    </p>

                    <!-- Search: filters the roster below by name or phone number -->
                    <form method="GET" action="{{ route('customer.corporate') }}" class="flex items-center gap-2 mb-4">
                        <div class="relative flex-1 max-w-sm">
                            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-[#A08F86]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" />
                            </svg>
                            <input type="text" name="search" value="{{ $search }}" placeholder="Search by name or phone number..."
                                   class="w-full pl-9 pr-3 py-2 rounded-xl border border-[#E6DAC0] text-xs focus:outline-none focus:border-[#662721]">
                        </div>
                        <button type="submit"
                                class="px-3.5 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[11px] font-bold shrink-0">
                            Search
                        </button>
                        @if($search !== '')
                            <a href="{{ route('customer.corporate') }}"
                               class="px-3.5 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#7A5A52] text-[11px] font-bold shrink-0">
                                Clear
                            </a>
                        @endif
                    </form>
                    @if($search !== '')
                        <p class="text-[11px] text-[#7A5A52] mb-3">Showing results for <strong>&ldquo;{{ $search }}&rdquo;</strong> &bull; {{ $members->total() }} member(s) found.</p>
                    @endif

                    <!-- Table view: tablet & desktop only (mobile uses the card list below instead of side-scrolling) -->
                    <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-[10px] uppercase font-extrabold text-[#662721] border-b border-[#E6DAC0]">
                                <th class="py-2 pr-3">Name</th>
                                <th class="py-2 pr-3">Phone</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3">Active Hours</th>
                                <th class="py-2 pr-3">Action</th>
                            </tr>
                        </thead>
                        <tbody id="roster-tbody">
                            @forelse($members as $m)
                                @php
                                    $recentRelease = $m->vouchers
                                        ->where('source', 'MANUAL_RELEASE')
                                        ->where('issued_at', '>=', now()->subHours(2))
                                        ->sortByDesc('issued_at')
                                        ->first();
                                @endphp
                                <tr class="border-b border-[#F7F0DB]" data-member-id="{{ $m->id }}"
                                    data-recent-release="{{ $recentRelease ? number_format((float) $recentRelease->hours_granted, 1).' hrs at '.$recentRelease->issued_at->format('H:i') : '' }}">
                                    <td class="py-2.5 pr-3 font-bold text-[#4F2F2A]">{{ $m->user->name ?? '-' }}</td>
                                    <td class="py-2.5 pr-3 text-[#7A5A52]">{{ $m->user->phone ?? '-' }}</td>
                                    <td class="py-2.5 pr-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $m->status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' }}">
                                            {{ $m->status }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-3 font-bold text-[#4F2F2A]">{{ number_format($m->totalRemainingHours(), 1) }} hrs</td>
                                    <td class="py-2.5 pr-3">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <button type="button" onclick="toggleVouchers('{{ $m->id }}')"
                                                    class="px-2.5 py-1 rounded-lg bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                                Vouchers ({{ $m->vouchers->count() }})
                                            </button>
                                            <button type="button" onclick="releaseVoucher('{{ $m->id }}')" title="Give this member extra play hours right now"
                                                    class="px-2.5 py-1 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                                🎟️ Give Hours
                                            </button>
                                            @if($m->status === 'ACTIVE')
                                                <button type="button" onclick="setMemberStatus('{{ $m->id }}', 'revoke')"
                                                        class="px-2.5 py-1 rounded-lg bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 text-[10px] font-bold">
                                                    Revoke
                                                </button>
                                            @else
                                                <button type="button" onclick="setMemberStatus('{{ $m->id }}', 'reactivate')"
                                                        class="px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 text-[10px] font-bold">
                                                    Activate
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                <tr id="vouchers-row-{{ $m->id }}" class="hidden border-b border-[#F7F0DB] bg-[#FCF8EE]">
                                    <td colspan="5" class="py-3 px-3">
                                        @if($m->vouchers->isEmpty())
                                            <p class="text-[11px] text-[#7A5A52] italic">No vouchers issued for this member yet.</p>
                                        @else
                                            <table class="w-full text-[11px]">
                                                <thead>
                                                    <tr class="text-left text-[9px] uppercase font-extrabold text-[#662721]">
                                                        <th class="py-1 pr-3">Hours Granted</th>
                                                        <th class="py-1 pr-3">Used</th>
                                                        <th class="py-1 pr-3">Remaining</th>
                                                        <th class="py-1 pr-3">Expires</th>
                                                        <th class="py-1 pr-3">Source</th>
                                                        <th class="py-1 pr-3">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($m->vouchers->sortByDesc('issued_at') as $v)
                                                        <tr class="{{ $v->isExpired() ? 'opacity-50' : '' }}" data-voucher-id="{{ $v->id }}">
                                                            <td class="py-1.5 pr-3">
                                                                <div class="flex items-center gap-1">
                                                                    <button type="button" onclick="stepValue('voucher-hours-{{ $v->id }}', -0.5, 0.5)"
                                                                            class="w-6 h-6 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">&minus;</button>
                                                                    <input type="number" step="0.5" min="0.01" value="{{ (float) $v->hours_granted }}"
                                                                           class="w-14 text-center px-1 py-1 rounded-lg border border-[#E6DAC0] text-[11px]" id="voucher-hours-{{ $v->id }}">
                                                                    <button type="button" onclick="stepValue('voucher-hours-{{ $v->id }}', 0.5, 0.5)"
                                                                            class="w-6 h-6 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">+</button>
                                                                </div>
                                                            </td>
                                                            <td class="py-1.5 pr-3">{{ number_format($v->hours_used, 1) }}</td>
                                                            <td class="py-1.5 pr-3 font-bold">{{ number_format($v->remainingHours(), 1) }}</td>
                                                            <td class="py-1.5 pr-3">{{ \Carbon\Carbon::parse($v->expires_at)->format('d M Y') }} {{ $v->isExpired() ? '(expired)' : '' }}</td>
                                                            <td class="py-1.5 pr-3">{{ $v->source }}</td>
                                                            <td class="py-1.5 pr-3">
                                                                <div class="flex items-center gap-1">
                                                                    <button type="button" onclick="saveVoucher('{{ $v->id }}')"
                                                                            class="px-2 py-1 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-[10px] font-bold">Save</button>
                                                                    @if((float) $v->hours_used == 0)
                                                                        <button type="button" onclick="deleteVoucher('{{ $v->id }}')"
                                                                                class="px-2 py-1 rounded-lg bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 text-[10px] font-bold">Delete</button>
                                                                    @endif
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-center text-[#7A5A52] italic">No members registered yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>

                    <!-- Card view: mobile only, no side-scrolling needed -->
                    <div class="md:hidden space-y-3">
                        @forelse($members as $m)
                            @php
                                $recentReleaseMobile = $m->vouchers
                                    ->where('source', 'MANUAL_RELEASE')
                                    ->where('issued_at', '>=', now()->subHours(2))
                                    ->sortByDesc('issued_at')
                                    ->first();
                            @endphp
                            <div class="rounded-lg border border-[#E6DAC0] bg-[#FCF8EE] p-4" data-member-id-mobile="{{ $m->id }}"
                                 data-recent-release="{{ $recentReleaseMobile ? number_format((float) $recentReleaseMobile->hours_granted, 1).' hrs at '.$recentReleaseMobile->issued_at->format('H:i') : '' }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="font-bold text-sm text-[#4F2F2A] truncate">{{ $m->user->name ?? '-' }}</div>
                                        <div class="text-[11px] text-[#7A5A52]">{{ $m->user->phone ?? '-' }}</div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold shrink-0 {{ $m->status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' }}">
                                        {{ $m->status }}
                                    </span>
                                </div>

                                <div class="mt-3 pt-3 border-t border-[#E6DAC0]/50">
                                    <div class="text-[9px] font-extrabold uppercase tracking-wider text-[#662721]">Active Hours</div>
                                    <div class="font-bold text-sm text-[#4F2F2A]">{{ number_format($m->totalRemainingHours(), 1) }} hrs</div>
                                </div>

                                <div class="flex items-center gap-1.5 flex-wrap mt-3">
                                    <button type="button" onclick="toggleVouchers('{{ $m->id }}')"
                                            class="px-2.5 py-1.5 rounded-lg bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                        Vouchers ({{ $m->vouchers->count() }})
                                    </button>
                                    <button type="button" onclick="releaseVoucher('{{ $m->id }}')" title="Give this member extra play hours right now"
                                            class="px-2.5 py-1.5 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                        🎟️ Give Hours
                                    </button>
                                    @if($m->status === 'ACTIVE')
                                        <button type="button" onclick="setMemberStatus('{{ $m->id }}', 'revoke')"
                                                class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 text-[10px] font-bold">
                                            Revoke
                                        </button>
                                    @else
                                        <button type="button" onclick="setMemberStatus('{{ $m->id }}', 'reactivate')"
                                                class="px-2.5 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 text-[10px] font-bold">
                                            Activate
                                        </button>
                                    @endif
                                </div>

                                <div id="vouchers-mobile-{{ $m->id }}" class="hidden mt-3 pt-3 border-t border-[#E6DAC0]/50 space-y-2">
                                    @if($m->vouchers->isEmpty())
                                        <p class="text-[11px] text-[#7A5A52] italic">No vouchers issued for this member yet.</p>
                                    @else
                                        @foreach($m->vouchers->sortByDesc('issued_at') as $v)
                                            <div class="rounded-xl bg-white border border-[#E6DAC0]/60 p-3 {{ $v->isExpired() ? 'opacity-50' : '' }}">
                                                <div class="grid grid-cols-2 gap-2 text-[11px] mb-2">
                                                    <div>
                                                        <div class="text-[9px] uppercase font-extrabold text-[#662721]">Used / Remaining</div>
                                                        <div class="font-bold text-[#4F2F2A]">{{ number_format($v->hours_used, 1) }} / {{ number_format($v->remainingHours(), 1) }} hrs</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-[9px] uppercase font-extrabold text-[#662721]">Expires</div>
                                                        <div class="font-bold text-[#4F2F2A]">{{ \Carbon\Carbon::parse($v->expires_at)->format('d M Y') }} {{ $v->isExpired() ? '(expired)' : '' }}</div>
                                                    </div>
                                                </div>
                                                <div class="text-[10px] text-[#7A5A52] mb-2">Source: {{ $v->source }}</div>
                                                <div class="flex items-center gap-1.5">
                                                    <button type="button" onclick="stepValue('voucher-hours-m-{{ $v->id }}', -0.5, 0.5)"
                                                            class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">&minus;</button>
                                                    <input type="number" step="0.5" min="0.01" value="{{ (float) $v->hours_granted }}"
                                                           class="w-16 text-center px-1 py-1.5 rounded-lg border border-[#E6DAC0] text-[11px]" id="voucher-hours-m-{{ $v->id }}">
                                                    <button type="button" onclick="stepValue('voucher-hours-m-{{ $v->id }}', 0.5, 0.5)"
                                                            class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">+</button>
                                                    <button type="button" onclick="saveVoucher('{{ $v->id }}', 'voucher-hours-m-{{ $v->id }}')"
                                                            class="px-2.5 py-1.5 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-[10px] font-bold ml-auto">Save</button>
                                                    @if((float) $v->hours_used == 0)
                                                        <button type="button" onclick="deleteVoucher('{{ $v->id }}')"
                                                                class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 text-[10px] font-bold">Delete</button>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="py-6 text-center text-[#7A5A52] italic text-xs">No members registered yet.</p>
                        @endforelse
                    </div>

                    <!-- Pagination: shared by both the table and card views above -->
                    @if($members->hasPages())
                        <div class="flex items-center justify-between gap-3 flex-wrap mt-5 pt-4 border-t border-[#E6DAC0]/50">
                            <p class="text-[11px] text-[#7A5A52]">
                                Showing {{ $members->firstItem() }}&ndash;{{ $members->lastItem() }} of {{ $members->total() }} member(s)
                            </p>
                            <div class="flex items-center gap-1.5">
                                @if($members->onFirstPage())
                                    <span class="px-3 py-1.5 rounded-lg border border-[#E6DAC0]/50 text-[#A08F86] text-[11px] font-bold">&laquo; Prev</span>
                                @else
                                    <a href="{{ $members->previousPageUrl() }}" class="px-3 py-1.5 rounded-lg border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] text-[11px] font-bold">&laquo; Prev</a>
                                @endif
                                <span class="px-3 py-1.5 text-[11px] font-bold text-[#4F2F2A]">Page {{ $members->currentPage() }} of {{ $members->lastPage() }}</span>
                                @if($members->hasMorePages())
                                    <a href="{{ $members->nextPageUrl() }}" class="px-3 py-1.5 rounded-lg border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] text-[11px] font-bold">Next &raquo;</a>
                                @else
                                    <span class="px-3 py-1.5 rounded-lg border border-[#E6DAC0]/50 text-[#A08F86] text-[11px] font-bold">Next &raquo;</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
                <!-- Custom Modal (replaces native browser prompt()/confirm()) -->
                <div id="app-modal-overlay" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(20,15,5,0.55);">
                    <div class="w-full max-w-sm rounded-xl bg-white border-2 border-[#662721] shadow-2xl p-6" style="background: linear-gradient(135deg, #FFFFFF 0%, #F7F0DB 100%);">
                        <h3 id="app-modal-title" class="font-display font-black text-base text-[#4F2F2A] mb-2">Confirm</h3>
                        <p id="app-modal-message" class="text-xs text-[#7A5A52] mb-4"></p>
                        <div id="app-modal-input-wrapper" class="hidden flex items-center gap-2 mb-4">
                            <button type="button" onclick="stepValue('app-modal-input', -0.5, 0)"
                                    class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">&minus;</button>
                            <input type="number" id="app-modal-input" placeholder="0"
                                   class="w-full text-center px-2 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                            <button type="button" onclick="stepValue('app-modal-input', 0.5, 0)"
                                    class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">+</button>
                            <span class="text-xs font-bold text-[#7A5A52] shrink-0 w-8">hrs</span>
                        </div>
                        <div class="flex items-center gap-2 justify-end">
                            <button type="button" onclick="closeAppModal()"
                                    class="px-4 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#7A5A52] text-xs font-bold">
                                Cancel
                            </button>
                            <button type="button" id="app-modal-confirm-btn"
                                    class="px-4 py-2 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                Confirm
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bulk Release Voucher Modal -->
                <div id="bulk-modal-overlay" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(20,15,5,0.55);">
                    <div class="w-full max-w-lg rounded-xl bg-white border-2 border-[#662721] shadow-2xl p-6" style="background: linear-gradient(135deg, #FFFFFF 0%, #F7F0DB 100%);">
                        <h3 class="font-display font-black text-base text-[#4F2F2A] mb-1">🎟️ Give Hours to Multiple Members</h3>
                        <p class="text-xs text-[#7A5A52] mb-1">Release play-hour vouchers to your active team members in one go &mdash; give everyone the same amount, or set a different amount for each person.</p>
                        @if($quotaRemaining !== null)
                            <p class="text-[11px] font-bold text-[#662721] mb-4">Quota remaining: {{ number_format($quotaRemaining, 1) }} hrs out of your {{ number_format($totalQuota, 1) }} hrs contract.</p>
                        @else
                            <p class="text-[11px] text-[#7A5A52] mb-4">&nbsp;</p>
                        @endif

                        <div class="flex items-center gap-2 mb-4">
                            <button type="button" id="bulk-mode-uniform-btn" onclick="setBulkMode('uniform')"
                                    class="flex-1 py-2.5 rounded-xl text-xs font-black uppercase tracking-wide border-2 transition-all">
                                Same for Everyone
                            </button>
                            <button type="button" id="bulk-mode-per-member-btn" onclick="setBulkMode('per_member')"
                                    class="flex-1 py-2.5 rounded-xl text-xs font-black uppercase tracking-wide border-2 transition-all">
                                Different per Person
                            </button>
                        </div>

                        <div id="bulk-uniform-panel">
                            <label class="text-[11px] font-bold text-[#7A5A52] mb-1 block">
                                Hours per member &bull; applies to all {{ $activeMembersForBulk->count() }} active member(s)
                            </label>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="stepValue('bulk-uniform-hours', -0.5, 0)"
                                        class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">&minus;</button>
                                <input type="number" step="0.5" min="0" id="bulk-uniform-hours" placeholder="0"
                                       class="w-full text-center px-2 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                                <button type="button" onclick="stepValue('bulk-uniform-hours', 0.5, 0)"
                                        class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">+</button>
                                <span class="text-xs font-bold text-[#7A5A52] shrink-0 w-8">hrs</span>
                            </div>
                        </div>

                        <div id="bulk-per-member-panel" class="hidden max-h-[40vh] overflow-y-auto space-y-2 pr-1">
                            @foreach($activeMembersForBulk as $m)
                                <div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-white/70 border border-[#E6DAC0]/50">
                                    <span class="text-xs font-bold text-[#4F2F2A] truncate">{{ $m->user->name ?? '-' }}</span>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button type="button" onclick="stepValue('bulk-hours-{{ $m->id }}', -0.5, 0)"
                                                class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">&minus;</button>
                                        <input type="number" step="0.5" min="0" placeholder="0" data-member-id="{{ $m->id }}" id="bulk-hours-{{ $m->id }}"
                                               class="w-16 text-center px-1 py-1.5 rounded-lg border border-[#E6DAC0] text-xs">
                                        <button type="button" onclick="stepValue('bulk-hours-{{ $m->id }}', 0.5, 0)"
                                                class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">+</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-2 justify-end mt-5">
                            <button type="button" onclick="closeBulkModal()"
                                    class="px-4 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#7A5A52] text-xs font-bold">
                                Cancel
                            </button>
                            <button type="button" onclick="submitBulkRelease()"
                                    class="px-4 py-2 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                Release Vouchers
                            </button>
                        </div>
                    </div>
                </div>
                </div>
            @endif
        </div>
    </div>

    @if($organization && empty($previewMode))
        <script>
            const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            const API_BASE = '/api/v1/sponsor/organization';

            function showAlert(message, isError) {
                const el = document.getElementById('corporate-alert');
                el.textContent = message;
                el.className = 'p-4 rounded-lg text-xs font-bold ' + (isError
                    ? 'bg-rose-50 text-rose-700 border border-rose-200'
                    : 'bg-emerald-50 text-emerald-700 border border-emerald-200');
                el.classList.remove('hidden');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            // --- Reusable +/- hour stepper (replaces bare native number inputs so it's obvious
            // at a glance this is a quantity, not a date/time or anything else) ---
            function stepValue(inputId, delta, min = 0) {
                const input = document.getElementById(inputId);
                if (!input) return;
                let val = parseFloat(input.value) || 0;
                val = Math.max(min, Math.round((val + delta) * 10) / 10);
                input.value = Number.isInteger(val) ? val.toFixed(0) : val.toFixed(1);
            }

            // --- Custom modal (replaces native prompt()/confirm() with a themed dialog) ---
            let _appModalOnConfirm = null;

            function openConfirmModal(title, message, onConfirm) {
                document.getElementById('app-modal-title').textContent = title;
                document.getElementById('app-modal-message').textContent = message;
                document.getElementById('app-modal-input-wrapper').classList.add('hidden');
                _appModalOnConfirm = () => onConfirm();
                document.getElementById('app-modal-overlay').classList.remove('hidden');
            }

            function openPromptModal(title, message, onConfirm, options = {}) {
                document.getElementById('app-modal-title').textContent = title;
                document.getElementById('app-modal-message').textContent = message;
                document.getElementById('app-modal-input-wrapper').classList.remove('hidden');
                const input = document.getElementById('app-modal-input');
                input.value = '';
                input.step = options.step || '0.5';
                input.min = options.min ?? '0.01';
                _appModalOnConfirm = () => {
                    if (!input.value) return;
                    onConfirm(input.value);
                };
                document.getElementById('app-modal-overlay').classList.remove('hidden');
                setTimeout(() => input.focus(), 50);
            }

            function closeAppModal() {
                document.getElementById('app-modal-overlay').classList.add('hidden');
                _appModalOnConfirm = null;
            }

            document.getElementById('app-modal-confirm-btn').addEventListener('click', function () {
                const action = _appModalOnConfirm;
                closeAppModal();
                if (action) action();
            });

            async function apiCall(url, options = {}) {
                const response = await fetch(url, {
                    ...options,
                    headers: {
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'Accept': 'application/json',
                        ...(options.headers || {}),
                    },
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Something went wrong.');
                }
                return data;
            }

            document.getElementById('add-member-form').addEventListener('submit', async function (e) {
                e.preventDefault();
                const form = e.target;
                const payload = {
                    name: form.name.value,
                    phone: form.phone.value,
                    initial_hours: form.initial_hours.value || undefined,
                };
                try {
                    const data = await apiCall(API_BASE + '/members', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    showAlert(data.message, false);
                    setTimeout(() => location.reload(), 900);
                } catch (err) {
                    showAlert(err.message, true);
                }
            });

            document.getElementById('csv-import-form').addEventListener('submit', async function (e) {
                e.preventDefault();
                const form = e.target;
                const formData = new FormData(form);
                try {
                    const data = await apiCall(API_BASE + '/members/import-csv', {
                        method: 'POST',
                        body: formData,
                    });
                    showAlert(data.message, false);
                    setTimeout(() => location.reload(), 1200);
                } catch (err) {
                    showAlert(err.message, true);
                }
            });

            function releaseVoucher(memberId) {
                // Anti-double-klik: kalau anggota ini baru aja dikasih voucher manual dalam 2 jam
                // terakhir, kasih 1 lapis peringatan tambahan dulu sebelum lanjut ke form jumlah
                // jam — supaya klik ganda yang gak sengaja gak langsung nerbitin voucher kedua.
                const row = document.querySelector('tr[data-member-id="' + memberId + '"]');
                const recentRelease = row ? row.dataset.recentRelease : '';

                if (recentRelease) {
                    openConfirmModal(
                        'Already Given Hours Recently',
                        'This member was already given ' + recentRelease + ' just now. Are you sure you want to give them more hours again?',
                        () => openReleaseVoucherPrompt(memberId)
                    );
                } else {
                    openReleaseVoucherPrompt(memberId);
                }
            }

            function openReleaseVoucherPrompt(memberId) {
                openPromptModal(
                    'Give Play Hours',
                    'How many voucher hours would you like to give this member?',
                    async (hours) => {
                        try {
                            const data = await apiCall(API_BASE + '/members/' + memberId + '/release-voucher', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ hours: hours }),
                            });
                            showAlert(data.message, false);
                            setTimeout(() => location.reload(), 900);
                        } catch (err) {
                            showAlert(err.message, true);
                        }
                    }
                );
            }

            function setMemberStatus(memberId, action) {
                openConfirmModal(
                    action === 'revoke' ? 'Revoke Access' : 'Reactivate Member',
                    action === 'revoke' ? "Revoke this member's access?" : 'Reactivate this member?',
                    async () => {
                        try {
                            const data = await apiCall(API_BASE + '/members/' + memberId + '/' + action, { method: 'POST' });
                            showAlert(data.message, false);
                            setTimeout(() => location.reload(), 900);
                        } catch (err) {
                            showAlert(err.message, true);
                        }
                    }
                );
            }

            function toggleVouchers(memberId) {
                // Desktop table row and mobile card panel both exist in the DOM at once
                // (only CSS decides which is visible at the current breakpoint), so toggle both.
                const row = document.getElementById('vouchers-row-' + memberId);
                if (row) row.classList.toggle('hidden');
                const card = document.getElementById('vouchers-mobile-' + memberId);
                if (card) card.classList.toggle('hidden');
            }

            async function saveVoucher(voucherId, inputId) {
                const input = document.getElementById(inputId || ('voucher-hours-' + voucherId));
                const hoursGranted = input.value;
                try {
                    const data = await apiCall(API_BASE + '/vouchers/' + voucherId, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ hours_granted: hoursGranted }),
                    });
                    showAlert(data.message, false);
                    setTimeout(() => location.reload(), 900);
                } catch (err) {
                    showAlert(err.message, true);
                }
            }

            function deleteVoucher(voucherId) {
                openConfirmModal(
                    'Delete Voucher',
                    'Delete this voucher? This cannot be undone.',
                    async () => {
                        try {
                            const data = await apiCall(API_BASE + '/vouchers/' + voucherId, { method: 'DELETE' });
                            showAlert(data.message, false);
                            setTimeout(() => location.reload(), 900);
                        } catch (err) {
                            showAlert(err.message, true);
                        }
                    }
                );
            }

            // --- Bulk Release Voucher modal ---
            let bulkMode = 'uniform';

            function openBulkModal() {
                setBulkMode('uniform');
                document.getElementById('bulk-modal-overlay').classList.remove('hidden');
            }

            function closeBulkModal() {
                document.getElementById('bulk-modal-overlay').classList.add('hidden');
            }

            function setBulkMode(mode) {
                bulkMode = mode;
                const uniformBtn = document.getElementById('bulk-mode-uniform-btn');
                const perMemberBtn = document.getElementById('bulk-mode-per-member-btn');
                const activeClasses = ['bg-[#4F2F2A]', 'text-[#F7F0DB]', 'border-[#4F2F2A]'];
                const inactiveClasses = ['bg-white', 'text-[#7A5A52]', 'border-[#E6DAC0]'];

                uniformBtn.classList.remove(...activeClasses, ...inactiveClasses);
                perMemberBtn.classList.remove(...activeClasses, ...inactiveClasses);
                uniformBtn.classList.add(...(mode === 'uniform' ? activeClasses : inactiveClasses));
                perMemberBtn.classList.add(...(mode === 'per_member' ? activeClasses : inactiveClasses));

                document.getElementById('bulk-uniform-panel').classList.toggle('hidden', mode !== 'uniform');
                document.getElementById('bulk-per-member-panel').classList.toggle('hidden', mode !== 'per_member');
            }

            async function submitBulkRelease() {
                let payload;

                if (bulkMode === 'uniform') {
                    const hours = document.getElementById('bulk-uniform-hours').value;
                    if (!hours || parseFloat(hours) <= 0) {
                        showAlert('Please enter how many hours to give.', true);
                        return;
                    }
                    payload = { mode: 'uniform', hours };
                } else {
                    const allocations = [];
                    document.querySelectorAll('#bulk-per-member-panel [data-member-id]').forEach(function (el) {
                        const hours = parseFloat(el.value);
                        if (hours > 0) {
                            allocations.push({ member_id: el.dataset.memberId, hours: hours });
                        }
                    });
                    if (!allocations.length) {
                        showAlert('Please enter hours for at least one member.', true);
                        return;
                    }
                    payload = { mode: 'per_member', allocations };
                }

                try {
                    const data = await apiCall(API_BASE + '/members/bulk-release-voucher', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    closeBulkModal();
                    showAlert(data.message, false);
                    setTimeout(() => location.reload(), 1000);
                } catch (err) {
                    showAlert(err.message, true);
                }
            }
        </script>
    @endif
</x-dynamic-component>
