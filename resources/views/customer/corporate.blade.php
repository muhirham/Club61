<x-dynamic-component :component="! empty($previewMode) ? 'embed-layout' : 'app-layout'">
    @php
        // Jam voucher dibagi per jam bulat: tampil "200", bukan "200.0" (data lama berpecahan tetap tampil apa adanya).
        $hrs = fn ($value) => fmod((float) $value, 1.0) == 0.0 ? number_format((float) $value, 0) : number_format((float) $value, 1);
        // Hak Akses PIC (Kelola Sponsor > Edit): tombol yang tidak diizinkan disembunyikan; server tetap menolak.
        $picCan = fn (string $action) => $organization?->picAllows($action) ?? false;
        $canManageMembers = $picCan(\App\Models\Sponsor\SponsorOrganization::PIC_MANAGE_MEMBERS);
        $canReleaseSingle = $picCan(\App\Models\Sponsor\SponsorOrganization::PIC_RELEASE_SINGLE);
        $canReleaseBulk = $picCan(\App\Models\Sponsor\SponsorOrganization::PIC_RELEASE_BULK);
        $canEditVouchers = $picCan(\App\Models\Sponsor\SponsorOrganization::PIC_EDIT_VOUCHERS);
    @endphp
    <div class="py-6 sm:py-8 text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 space-y-6">

            <!-- Top Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white/95 p-5 rounded-xl border border-[#E6DAC0] shadow-sm">
                <div class="flex items-center gap-3.5">
                    @if(empty($previewMode))
                    <a href="{{ route('dashboard') }}" class="p-2.5 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] transition-colors" title="{{ __('corporate.back_home') }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    @endif
                    <div>
                        <h1 class="font-display font-black text-xl sm:text-2xl text-[#4F2F2A]">{{ __('corporate.title') }}</h1>
                        <p class="text-xs text-[#7A5A52] mt-0.5">{{ __('corporate.subtitle') }}</p>
                    </div>
                </div>
            </div>

            @if(! empty($previewMode) && $organization)
                <div class="p-4 rounded-lg border-2 border-amber-300 bg-amber-50 text-amber-900 text-xs font-bold flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <span>{!! __('corporate.preview_banner', ['pic' => '<strong>'.e($organization->sponsorAdmin?->name ?? '-').'</strong>', 'org' => e($organization->name)]) !!}</span>
                </div>
            @endif

            @if(! $organization)
                <div class="p-8 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm text-center">
                    <p class="text-sm font-bold text-[#4F2F2A]">{{ __('corporate.not_pic') }}</p>
                    <p class="text-xs text-[#7A5A52] mt-1.5">{{ __('corporate.not_pic_hint') }}</p>
                </div>
            @else
                <div @if(! empty($previewMode)) inert @endif class="space-y-6">
                <div id="corporate-alert" class="hidden p-4 rounded-lg text-xs font-bold"></div>

                @if($organization->isSuspended())
                    <div data-org-suspended class="p-4 rounded-lg bg-amber-50 border border-amber-300 text-xs text-amber-900 leading-relaxed">
                        <strong>{{ __('corporate.suspended_title') }}</strong> {{ __('corporate.suspended_body') }}
                    </div>
                @endif

                @if($organization->usesMonthlyBilling())
                    <div data-monthly-billing class="p-4 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0] text-xs text-[#7A5A52] leading-relaxed">
                        <strong>{{ __('corporate.monthly_billing_title') }}</strong> {{ __('corporate.monthly_billing_notice') }}
                    </div>
                @endif

                @if(! empty($organization->pic_restricted_actions))
                    <div data-pic-restricted class="p-4 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0] text-xs text-[#7A5A52] leading-relaxed">
                        {{ __('corporate.pic_restricted_notice') }}
                    </div>
                @endif

                <!-- Contract Summary -->
                <div class="relative overflow-hidden rounded-xl p-6 sm:p-8 border-2 border-[#662721] shadow-[0_15px_35px_rgba(102,39,33,0.2)]"
                     style="background: linear-gradient(135deg, #F7F0DB 0%, #FFFFFF 100%);">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                        <div>
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-extrabold uppercase bg-[#F7F0DB] text-[#662721] border border-[#E6DAC0]">
                                <span class="w-2 h-2 rounded-full {{ $organization->isSuspended() ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse' }}"></span>
                                <span>{{ $organization->isSuspended() ? __('corporate.account_suspended') : __('corporate.account_active') }}</span>
                            </div>
                            <div class="font-display font-black text-2xl sm:text-3xl text-[#4F2F2A] mt-2">{{ $organization->name }}</div>
                            <div class="text-xs text-[#7A5A52] mt-1">
                                {{ __('corporate.plan') }} <strong>{{ $organization->userMembership->plan->name ?? '-' }}</strong>
                                @if($organization->userMembership && $organization->userMembership->end_date)
                                    &bull; {{ __('corporate.contract_until') }} <strong>{{ \Carbon\Carbon::parse($organization->userMembership->end_date)->translatedFormat('d M Y') }}</strong>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 min-w-[240px] sm:min-w-[380px]">
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('corporate.active_members') }}</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">{{ $activeMemberCount }}</div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('corporate.contract_quota') }}</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">
                                    {{ $totalQuota !== null ? $hrs($totalQuota) : __('corporate.no_hour_quota') }}
                                </div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('corporate.quota_remaining') }}</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">
                                    {{ $quotaRemaining !== null ? $hrs($quotaRemaining) : __('corporate.no_hour_quota') }}
                                </div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('corporate.hours_released') }}</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">{{ $hrs($totalHoursReleased) }}</div>
                            </div>
                            <div class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('corporate.hours_used') }}</div>
                                <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">{{ $hrs($totalHoursUsed) }}</div>
                            </div>
                            @if($recurringHours > 0)
                                <div data-recurring-hours class="p-4 rounded-lg bg-white border border-[#E6DAC0] shadow-sm text-center">
                                    <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('corporate.hours_recurring') }}</div>
                                    <div class="font-display font-black text-xl text-[#4F2F2A] mt-1">{{ $hrs($recurringHours) }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                    <p class="text-[10px] text-[#7A5A52] mt-4 italic">
                        @if($totalQuota !== null && $recurringHours > 0)
                            {{ __('corporate.quota_summary_recurring', ['quota' => $hrs($totalQuota), 'released' => $hrs($totalHoursReleased), 'members' => $activeMemberCount, 'recurring' => $hrs($recurringHours), 'remaining' => $hrs($quotaRemaining), 'used' => $hrs($totalHoursUsed)]) }}
                        @elseif($totalQuota !== null)
                            {{ __('corporate.quota_summary', ['quota' => $hrs($totalQuota), 'released' => $hrs($totalHoursReleased), 'members' => $activeMemberCount, 'remaining' => $hrs($quotaRemaining), 'used' => $hrs($totalHoursUsed)]) }}
                        @else
                            {{ __('corporate.no_quota_summary', ['released' => $hrs($totalHoursReleased), 'members' => $activeMemberCount, 'used' => $hrs($totalHoursUsed)]) }}
                        @endif
                    </p>
                </div>

                <!-- Access Schedule (Read-Only, set by venue staff) -->
                <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                    <h3 class="font-display font-extrabold text-base text-[#4F2F2A]">{{ __('corporate.schedule_title') }}</h3>
                    <p class="text-xs text-[#7A5A52] mt-0.5 mb-4">{{ __('corporate.schedule_hint') }}</p>
                    @if($organization->accessSchedules->isEmpty() && $recurringSchedules->isEmpty())
                        <p class="text-xs text-[#7A5A52] italic">{{ __('corporate.schedule_empty') }}</p>
                    @endif
                    @if($recurringSchedules->isNotEmpty())
                        {{-- Jadwal Tetap Mingguan (Booking Mingguan di admin): booking otomatis tiap minggu dari kuota jam kontrak. --}}
                        <div class="text-[10px] font-extrabold uppercase tracking-wider text-[#662721] mb-2">{{ __('corporate.recurring_title') }}</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 {{ $organization->accessSchedules->isNotEmpty() ? 'mb-4' : '' }}">
                            @foreach($recurringSchedules as $rs)
                                <div class="p-4 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0]/60 text-xs" data-recurring-schedule>
                                    <div class="font-bold text-[#4F2F2A]">
                                        {{ __('corporate.recurring_every', ['day' => __('corporate.days_long')[$rs->day_of_week] ?? $rs->day_of_week]) }}
                                        &bull; {{ substr((string) $rs->start_time, 0, 5) }} &ndash; {{ substr((string) $rs->end_time, 0, 5) }}
                                    </div>
                                    @if(! empty($rs->court_names))
                                        <div class="text-[#7A5A52] mt-1">{{ $rs->court_names }}</div>
                                    @endif
                                    <div class="text-[#7A5A52] mt-1">
                                        {{ __('corporate.recurring_period', ['from' => $rs->starts_on?->translatedFormat('d M Y') ?? '-']) }}{{ $rs->ends_on ? ' – '.$rs->ends_on->translatedFormat('d M Y') : '' }}
                                        @if($rs->status === \App\Models\Padel\PadelRecurringSchedule::STATUS_PAUSED)
                                            &bull; <strong class="text-amber-700">{{ __('corporate.recurring_paused') }}</strong>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($organization->accessSchedules->isNotEmpty())
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            @foreach($organization->accessSchedules as $sch)
                                <div class="p-4 rounded-lg bg-[#FCF8EE] border border-[#E6DAC0]/60 text-xs">
                                    <div class="font-bold text-[#4F2F2A]">{{ \Carbon\Carbon::parse($sch->valid_from)->translatedFormat('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($sch->valid_until)->translatedFormat('d M Y') }}</div>
                                    <div class="text-[#7A5A52] mt-1">
                                        {{ __('corporate.hours_label') }} <strong>{{ $sch->time_start }} - {{ $sch->time_end }}</strong>
                                        @if($sch->days_of_week)
                                            &bull; {{ __('corporate.days_label') }} {{ collect($sch->days_of_week)->map(fn($d) => __('corporate.days')[$d] ?? $d)->implode(', ') }}
                                        @else
                                            &bull; {{ __('corporate.all_days') }}
                                        @endif
                                    </div>
                                    @if($sch->max_concurrent_courts)
                                        <div class="text-[#7A5A52] mt-1">{{ __('corporate.max_courts', ['count' => $sch->max_concurrent_courts]) }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Add Member & CSV Import -->
                @if($canManageMembers)
                <div data-pic-add-members class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                        <h3 class="font-display font-extrabold text-base text-[#4F2F2A] mb-1">{{ __('corporate.add_one_title') }}</h3>
                        <p class="text-[11px] text-[#7A5A52] mb-4">{{ __('corporate.add_one_hint') }}</p>
                        <form id="add-member-form" class="space-y-3">
                            <input type="text" name="name" placeholder="{{ __('corporate.employee_name') }}" required
                                   class="w-full px-4 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                            <input type="text" name="phone" placeholder="{{ __('corporate.phone_placeholder') }}" required
                                   class="w-full px-4 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                            @if($canReleaseSingle)
                            <div>
                                <label class="text-[11px] font-bold text-[#7A5A52] mb-1 block">{{ __('corporate.starting_hours') }}</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="stepValue('initial_hours_input', -1, 0)"
                                            class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">&minus;</button>
                                    <input type="number" step="1" min="0" name="initial_hours" id="initial_hours_input" placeholder="0"
                                           class="w-full text-center px-2 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                                    <button type="button" onclick="stepValue('initial_hours_input', 1, 0)"
                                            class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">+</button>
                                    <span class="text-xs font-bold text-[#7A5A52] shrink-0 w-8">{{ __('corporate.hrs') }}</span>
                                </div>
                            </div>
                            @endif
                            <button type="submit"
                                    class="w-full py-2.5 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                {{ __('corporate.add_member') }}
                            </button>
                        </form>
                    </div>

                    <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-display font-extrabold text-base text-[#4F2F2A]">{{ __('corporate.import_title') }}</h3>
                            <a href="{{ route('customer.corporate.sample-csv') }}"
                               class="text-[10px] font-bold text-[#662721] hover:text-[#4F2F2A] underline underline-offset-2 shrink-0">
                                {{ __('corporate.download_sample') }}
                            </a>
                        </div>
                        <p class="text-xs text-[#7A5A52] mb-2">{{ __('corporate.column_format') }} <code class="bg-[#F7F0DB] px-1 rounded">name, phone, hours</code>. {{ __('corporate.import_hint') }}</p>
                        <pre class="text-[10px] bg-[#FCF8EE] border border-[#E6DAC0]/60 rounded-xl p-3 mb-3 overflow-x-auto text-[#4F2F2A]">name,phone,hours
Budi Santoso,081234567890,10
Siti Rahma,081399887766,5</pre>
                        <form id="csv-import-form" class="space-y-3">
                            <input type="file" name="file" accept=".csv,.txt" required
                                   class="w-full text-xs text-[#7A5A52] file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-[#F7F0DB] file:text-[#662721] hover:file:bg-[#F7F0DB]">
                            <button type="submit"
                                    class="w-full py-2.5 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                {{ __('corporate.upload_csv') }}
                            </button>
                        </form>
                    </div>
                </div>
                @endif

                <!-- Team Roster -->
                <div class="p-6 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                    <div class="flex items-start justify-between gap-3 flex-wrap mb-1">
                        <h3 class="font-display font-extrabold text-base text-[#4F2F2A]">{{ __('corporate.roster_title') }}</h3>
                        @if($canReleaseBulk)
                            <button type="button" onclick="openBulkModal()" data-pic-bulk
                                    class="px-3.5 py-2 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-[11px] font-bold shrink-0">
                                {{ __('corporate.give_bulk') }}
                            </button>
                        @endif
                    </div>
                    <p class="text-[11px] text-[#7A5A52] mb-4">
                        {!! __('corporate.login_hint', ['link' => '<a href="'.e(route('login')).'" class="underline font-bold">/login</a>']) !!}
                    </p>

                    <!-- Search: filters the roster below by name or phone number -->
                    <form method="GET" action="{{ route('customer.corporate') }}" class="flex items-center gap-2 mb-4">
                        <div class="relative flex-1 max-w-sm">
                            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-[#A08F86]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" />
                            </svg>
                            <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('corporate.search_placeholder') }}"
                                   class="w-full pl-9 pr-3 py-2 rounded-xl border border-[#E6DAC0] text-xs focus:outline-none focus:border-[#662721]">
                        </div>
                        <button type="submit"
                                class="px-3.5 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[11px] font-bold shrink-0">
                            {{ __('corporate.search') }}
                        </button>
                        @if($search !== '')
                            <a href="{{ route('customer.corporate') }}"
                               class="px-3.5 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#7A5A52] text-[11px] font-bold shrink-0">
                                {{ __('corporate.clear') }}
                            </a>
                        @endif
                    </form>
                    @if($search !== '')
                        <p class="text-[11px] text-[#7A5A52] mb-3">{!! __('corporate.search_results', ['query' => '<strong>&ldquo;'.e($search).'&rdquo;</strong>', 'count' => $members->total()]) !!}</p>
                    @endif

                    <!-- Table view: tablet & desktop only (mobile uses the card list below instead of side-scrolling) -->
                    <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-[10px] uppercase font-extrabold text-[#662721] border-b border-[#E6DAC0]">
                                <th class="py-2 pr-3">{{ __('corporate.th_name') }}</th>
                                <th class="py-2 pr-3">{{ __('corporate.th_phone') }}</th>
                                <th class="py-2 pr-3">{{ __('corporate.th_status') }}</th>
                                <th class="py-2 pr-3">{{ __('corporate.th_active_hours') }}</th>
                                <th class="py-2 pr-3">{{ __('corporate.th_action') }}</th>
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
                                    data-recent-release="{{ $recentRelease ? __('corporate.recent_release', ['hours' => $hrs((float) $recentRelease->hours_granted), 'time' => $recentRelease->issued_at->format('H:i')]) : '' }}">
                                    <td class="py-2.5 pr-3 font-bold text-[#4F2F2A]">{{ $m->user->name ?? '-' }}</td>
                                    <td class="py-2.5 pr-3 text-[#7A5A52]">{{ $m->user->phone ?? '-' }}</td>
                                    <td class="py-2.5 pr-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $m->status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' }}">
                                            {{ __('corporate.statuses')[$m->status] ?? $m->status }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-3 font-bold text-[#4F2F2A]">{{ __('corporate.hours_value', ['value' => $hrs($m->totalRemainingHours())]) }}</td>
                                    <td class="py-2.5 pr-3">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <button type="button" onclick="toggleVouchers('{{ $m->id }}')"
                                                    class="px-2.5 py-1 rounded-lg bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                                {{ __('corporate.vouchers_button', ['count' => $m->vouchers->count()]) }}
                                            </button>
                                            @if($canReleaseSingle)
                                                <button type="button" onclick="releaseVoucher('{{ $m->id }}')" title="{{ __('corporate.give_hours_title') }}" data-pic-give-hours
                                                        class="px-2.5 py-1 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                                    {{ __('corporate.give_hours') }}
                                                </button>
                                            @endif
                                            {{-- Tidak ada Revoke (meeting 28 Okt 2026): jam karyawan tidak boleh tersangkut. Anggota lama yang terlanjur dicabut tetap bisa diaktifkan lagi. --}}
                                            @if($m->status !== 'ACTIVE' && $canManageMembers)
                                                <button type="button" onclick="reactivateMember('{{ $m->id }}')"
                                                        class="px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 text-[10px] font-bold">
                                                    {{ __('corporate.activate') }}
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                <tr id="vouchers-row-{{ $m->id }}" class="hidden border-b border-[#F7F0DB] bg-[#FCF8EE]">
                                    <td colspan="5" class="py-3 px-3">
                                        @if($m->vouchers->isEmpty())
                                            <p class="text-[11px] text-[#7A5A52] italic">{{ __('corporate.no_vouchers') }}</p>
                                        @else
                                            <table class="w-full text-[11px]">
                                                <thead>
                                                    <tr class="text-left text-[9px] uppercase font-extrabold text-[#662721]">
                                                        <th class="py-1 pr-3">{{ __('corporate.th_hours_granted') }}</th>
                                                        <th class="py-1 pr-3">{{ __('corporate.th_used') }}</th>
                                                        <th class="py-1 pr-3">{{ __('corporate.th_remaining') }}</th>
                                                        <th class="py-1 pr-3">{{ __('corporate.th_expires') }}</th>
                                                        <th class="py-1 pr-3">{{ __('corporate.th_source') }}</th>
                                                        <th class="py-1 pr-3">{{ __('corporate.th_action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($m->vouchers->sortByDesc('issued_at') as $v)
                                                        <tr class="{{ $v->isExpired() ? 'opacity-50' : '' }}" data-voucher-id="{{ $v->id }}">
                                                            <td class="py-1.5 pr-3">
                                                                @if($canEditVouchers)
                                                                <div class="flex items-center gap-1">
                                                                    <button type="button" onclick="stepValue('voucher-hours-{{ $v->id }}', -1, 1)"
                                                                            class="w-6 h-6 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">&minus;</button>
                                                                    <input type="number" step="1" min="1" value="{{ (float) $v->hours_granted }}"
                                                                           class="w-14 text-center px-1 py-1 rounded-lg border border-[#E6DAC0] text-[11px]" id="voucher-hours-{{ $v->id }}">
                                                                    <button type="button" onclick="stepValue('voucher-hours-{{ $v->id }}', 1, 1)"
                                                                            class="w-6 h-6 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">+</button>
                                                                </div>
                                                                @else
                                                                    <span class="font-bold">{{ $hrs($v->hours_granted) }}</span>
                                                                @endif
                                                            </td>
                                                            <td class="py-1.5 pr-3">{{ $hrs($v->hours_used) }}</td>
                                                            <td class="py-1.5 pr-3 font-bold">{{ $hrs($v->remainingHours()) }}</td>
                                                            <td class="py-1.5 pr-3">{{ \Carbon\Carbon::parse($v->expires_at)->translatedFormat('d M Y') }} {{ $v->isExpired() ? __('corporate.expired_suffix') : '' }}</td>
                                                            <td class="py-1.5 pr-3">{{ __('corporate.sources')[$v->source] ?? $v->source }}</td>
                                                            <td class="py-1.5 pr-3">
                                                                @if($canEditVouchers)
                                                                <div class="flex items-center gap-1" data-pic-edit-voucher>
                                                                    <button type="button" onclick="saveVoucher('{{ $v->id }}')"
                                                                            class="px-2 py-1 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-[10px] font-bold">{{ __('corporate.save') }}</button>
                                                                    @if((float) $v->hours_used == 0)
                                                                        <button type="button" onclick="deleteVoucher('{{ $v->id }}')"
                                                                                class="px-2 py-1 rounded-lg bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 text-[10px] font-bold">{{ __('corporate.delete') }}</button>
                                                                    @endif
                                                                </div>
                                                                @else
                                                                    -
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-center text-[#7A5A52] italic">{{ __('corporate.no_members') }}</td></tr>
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
                                 data-recent-release="{{ $recentReleaseMobile ? __('corporate.recent_release', ['hours' => $hrs((float) $recentReleaseMobile->hours_granted), 'time' => $recentReleaseMobile->issued_at->format('H:i')]) : '' }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="font-bold text-sm text-[#4F2F2A] truncate">{{ $m->user->name ?? '-' }}</div>
                                        <div class="text-[11px] text-[#7A5A52]">{{ $m->user->phone ?? '-' }}</div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold shrink-0 {{ $m->status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' }}">
                                        {{ __('corporate.statuses')[$m->status] ?? $m->status }}
                                    </span>
                                </div>

                                <div class="mt-3 pt-3 border-t border-[#E6DAC0]/50">
                                    <div class="text-[9px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('corporate.th_active_hours') }}</div>
                                    <div class="font-bold text-sm text-[#4F2F2A]">{{ __('corporate.hours_value', ['value' => $hrs($m->totalRemainingHours())]) }}</div>
                                </div>

                                <div class="flex items-center gap-1.5 flex-wrap mt-3">
                                    <button type="button" onclick="toggleVouchers('{{ $m->id }}')"
                                            class="px-2.5 py-1.5 rounded-lg bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                        {{ __('corporate.vouchers_button', ['count' => $m->vouchers->count()]) }}
                                    </button>
                                    @if($canReleaseSingle)
                                        <button type="button" onclick="releaseVoucher('{{ $m->id }}')" title="{{ __('corporate.give_hours_title') }}" data-pic-give-hours
                                                class="px-2.5 py-1.5 rounded-lg bg-[#F7F0DB] hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] text-[10px] font-bold">
                                            {{ __('corporate.give_hours') }}
                                        </button>
                                    @endif
                                    @if($m->status !== 'ACTIVE' && $canManageMembers)
                                        <button type="button" onclick="reactivateMember('{{ $m->id }}')"
                                                class="px-2.5 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 text-[10px] font-bold">
                                            {{ __('corporate.activate') }}
                                        </button>
                                    @endif
                                </div>

                                <div id="vouchers-mobile-{{ $m->id }}" class="hidden mt-3 pt-3 border-t border-[#E6DAC0]/50 space-y-2">
                                    @if($m->vouchers->isEmpty())
                                        <p class="text-[11px] text-[#7A5A52] italic">{{ __('corporate.no_vouchers') }}</p>
                                    @else
                                        @foreach($m->vouchers->sortByDesc('issued_at') as $v)
                                            <div class="rounded-xl bg-white border border-[#E6DAC0]/60 p-3 {{ $v->isExpired() ? 'opacity-50' : '' }}">
                                                <div class="grid grid-cols-2 gap-2 text-[11px] mb-2">
                                                    <div>
                                                        <div class="text-[9px] uppercase font-extrabold text-[#662721]">{{ __('corporate.used_remaining') }}</div>
                                                        <div class="font-bold text-[#4F2F2A]">{{ $hrs($v->hours_used) }} / {{ __('corporate.hours_value', ['value' => $hrs($v->remainingHours())]) }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-[9px] uppercase font-extrabold text-[#662721]">{{ __('corporate.th_expires') }}</div>
                                                        <div class="font-bold text-[#4F2F2A]">{{ \Carbon\Carbon::parse($v->expires_at)->translatedFormat('d M Y') }} {{ $v->isExpired() ? __('corporate.expired_suffix') : '' }}</div>
                                                    </div>
                                                </div>
                                                <div class="text-[10px] text-[#7A5A52] mb-2">{{ __('corporate.source_label', ['source' => __('corporate.sources')[$v->source] ?? $v->source]) }}</div>
                                                @if(! $canEditVouchers)
                                                    <div class="text-[11px] text-[#7A5A52]">{{ __('corporate.th_hours_granted') }}: <strong class="text-[#4F2F2A]">{{ $hrs($v->hours_granted) }}</strong></div>
                                                @else
                                                <div class="flex items-center gap-1.5" data-pic-edit-voucher>
                                                    <button type="button" onclick="stepValue('voucher-hours-m-{{ $v->id }}', -1, 1)"
                                                            class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">&minus;</button>
                                                    <input type="number" step="1" min="1" value="{{ (float) $v->hours_granted }}"
                                                           class="w-16 text-center px-1 py-1.5 rounded-lg border border-[#E6DAC0] text-[11px]" id="voucher-hours-m-{{ $v->id }}">
                                                    <button type="button" onclick="stepValue('voucher-hours-m-{{ $v->id }}', 1, 1)"
                                                            class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">+</button>
                                                    <button type="button" onclick="saveVoucher('{{ $v->id }}', 'voucher-hours-m-{{ $v->id }}')"
                                                            class="px-2.5 py-1.5 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-[10px] font-bold ml-auto">{{ __('corporate.save') }}</button>
                                                    @if((float) $v->hours_used == 0)
                                                        <button type="button" onclick="deleteVoucher('{{ $v->id }}')"
                                                                class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 text-[10px] font-bold">{{ __('corporate.delete') }}</button>
                                                    @endif
                                                </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="py-6 text-center text-[#7A5A52] italic text-xs">{{ __('corporate.no_members') }}</p>
                        @endforelse
                    </div>

                    <!-- Pagination: shared by both the table and card views above -->
                    @if($members->hasPages())
                        <div class="flex items-center justify-between gap-3 flex-wrap mt-5 pt-4 border-t border-[#E6DAC0]/50">
                            <p class="text-[11px] text-[#7A5A52]">
                                {{ __('corporate.showing', ['from' => $members->firstItem(), 'to' => $members->lastItem(), 'total' => $members->total()]) }}
                            </p>
                            <div class="flex items-center gap-1.5">
                                @if($members->onFirstPage())
                                    <span class="px-3 py-1.5 rounded-lg border border-[#E6DAC0]/50 text-[#A08F86] text-[11px] font-bold">{{ __('corporate.prev') }}</span>
                                @else
                                    <a href="{{ $members->previousPageUrl() }}" class="px-3 py-1.5 rounded-lg border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] text-[11px] font-bold">{{ __('corporate.prev') }}</a>
                                @endif
                                <span class="px-3 py-1.5 text-[11px] font-bold text-[#4F2F2A]">{{ __('corporate.page_of', ['page' => $members->currentPage(), 'last' => $members->lastPage()]) }}</span>
                                @if($members->hasMorePages())
                                    <a href="{{ $members->nextPageUrl() }}" class="px-3 py-1.5 rounded-lg border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] text-[11px] font-bold">{{ __('corporate.next') }}</a>
                                @else
                                    <span class="px-3 py-1.5 rounded-lg border border-[#E6DAC0]/50 text-[#A08F86] text-[11px] font-bold">{{ __('corporate.next') }}</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
                <!-- Custom Modal (replaces native browser prompt()/confirm()) -->
                <div id="app-modal-overlay" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(20,15,5,0.55);">
                    <div class="w-full max-w-sm rounded-xl bg-white border-2 border-[#662721] shadow-2xl p-6" style="background: linear-gradient(135deg, #FFFFFF 0%, #F7F0DB 100%);">
                        <h3 id="app-modal-title" class="font-display font-black text-base text-[#4F2F2A] mb-2">{{ __('corporate.confirm') }}</h3>
                        <p id="app-modal-message" class="text-xs text-[#7A5A52] mb-4"></p>
                        <div id="app-modal-input-wrapper" class="hidden flex items-center gap-2 mb-4">
                            <button type="button" onclick="stepValue('app-modal-input', -1, 0)"
                                    class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">&minus;</button>
                            <input type="number" id="app-modal-input" placeholder="0"
                                   class="w-full text-center px-2 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                            <button type="button" onclick="stepValue('app-modal-input', 1, 0)"
                                    class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">+</button>
                            <span class="text-xs font-bold text-[#7A5A52] shrink-0 w-8">{{ __('corporate.hrs') }}</span>
                        </div>
                        <div class="flex items-center gap-2 justify-end">
                            <button type="button" onclick="closeAppModal()"
                                    class="px-4 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#7A5A52] text-xs font-bold">
                                {{ __('corporate.cancel') }}
                            </button>
                            <button type="button" id="app-modal-confirm-btn"
                                    class="px-4 py-2 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                {{ __('corporate.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bulk Release Voucher Modal -->
                <div id="bulk-modal-overlay" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(20,15,5,0.55);">
                    <div class="w-full max-w-lg rounded-xl bg-white border-2 border-[#662721] shadow-2xl p-6" style="background: linear-gradient(135deg, #FFFFFF 0%, #F7F0DB 100%);">
                        <h3 class="font-display font-black text-base text-[#4F2F2A] mb-1">{{ __('corporate.bulk_title') }}</h3>
                        <p class="text-xs text-[#7A5A52] mb-1">{{ __('corporate.bulk_hint') }}</p>
                        @if($quotaRemaining !== null)
                            <p class="text-[11px] font-bold text-[#662721] mb-4">{{ __('corporate.bulk_quota', ['remaining' => $hrs($quotaRemaining), 'total' => $hrs($totalQuota)]) }}</p>
                        @else
                            <p class="text-[11px] text-[#7A5A52] mb-4">&nbsp;</p>
                        @endif

                        <div class="flex items-center gap-2 mb-4">
                            <button type="button" id="bulk-mode-uniform-btn" onclick="setBulkMode('uniform')"
                                    class="flex-1 py-2.5 rounded-xl text-xs font-black uppercase tracking-wide border-2 transition-all">
                                {{ __('corporate.same_for_everyone') }}
                            </button>
                            <button type="button" id="bulk-mode-per-member-btn" onclick="setBulkMode('per_member')"
                                    class="flex-1 py-2.5 rounded-xl text-xs font-black uppercase tracking-wide border-2 transition-all">
                                {{ __('corporate.different_per_person') }}
                            </button>
                        </div>

                        <div id="bulk-uniform-panel">
                            <label class="text-[11px] font-bold text-[#7A5A52] mb-1 block">
                                {{ __('corporate.hours_per_member', ['count' => $activeMembersForBulk->count()]) }}
                            </label>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="stepValue('bulk-uniform-hours', -1, 0)"
                                        class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">&minus;</button>
                                <input type="number" step="1" min="0" id="bulk-uniform-hours" placeholder="0"
                                       class="w-full text-center px-2 py-2.5 rounded-xl border border-[#E6DAC0] text-sm focus:outline-none focus:border-[#662721]">
                                <button type="button" onclick="stepValue('bulk-uniform-hours', 1, 0)"
                                        class="w-10 h-10 rounded-xl border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-base shrink-0">+</button>
                                <span class="text-xs font-bold text-[#7A5A52] shrink-0 w-8">{{ __('corporate.hrs') }}</span>
                            </div>
                        </div>

                        <div id="bulk-per-member-panel" class="hidden max-h-[40vh] overflow-y-auto space-y-2 pr-1">
                            @foreach($activeMembersForBulk as $m)
                                <div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-white/70 border border-[#E6DAC0]/50">
                                    <span class="text-xs font-bold text-[#4F2F2A] truncate">{{ $m->user->name ?? '-' }}</span>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button type="button" onclick="stepValue('bulk-hours-{{ $m->id }}', -1, 0)"
                                                class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">&minus;</button>
                                        <input type="number" step="1" min="0" placeholder="0" data-member-id="{{ $m->id }}" id="bulk-hours-{{ $m->id }}"
                                               class="w-16 text-center px-1 py-1.5 rounded-lg border border-[#E6DAC0] text-xs">
                                        <button type="button" onclick="stepValue('bulk-hours-{{ $m->id }}', 1, 0)"
                                                class="w-7 h-7 rounded-md border border-[#E6DAC0] bg-white hover:bg-[#F7F0DB] text-[#662721] font-black text-xs shrink-0">+</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-2 justify-end mt-5">
                            <button type="button" onclick="closeBulkModal()"
                                    class="px-4 py-2 rounded-xl bg-white hover:bg-[#F7F0DB] border border-[#E6DAC0] text-[#7A5A52] text-xs font-bold">
                                {{ __('corporate.cancel') }}
                            </button>
                            <button type="button" onclick="submitBulkRelease()"
                                    class="px-4 py-2 rounded-xl bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md  active:scale-95 transition-all">
                                {{ __('corporate.release_vouchers') }}
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
            const CORP_T = @js(__('corporate.js'));

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
                input.step = options.step || '1';
                input.min = options.min ?? '1';
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
                    throw new Error(data.message || CORP_T.generic_error);
                }
                return data;
            }

            // Form tambah karyawan / impor CSV / jam awal bisa tidak dirender (Hak Akses PIC).
            document.getElementById('add-member-form')?.addEventListener('submit', async function (e) {
                e.preventDefault();
                const form = e.target;
                const payload = {
                    name: form.name.value,
                    phone: form.phone.value,
                    initial_hours: form.initial_hours?.value || undefined,
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

            document.getElementById('csv-import-form')?.addEventListener('submit', async function (e) {
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
                        CORP_T.recent_title,
                        CORP_T.recent_message.replace(':release', recentRelease),
                        () => openReleaseVoucherPrompt(memberId)
                    );
                } else {
                    openReleaseVoucherPrompt(memberId);
                }
            }

            function openReleaseVoucherPrompt(memberId) {
                openPromptModal(
                    CORP_T.give_title,
                    CORP_T.give_message,
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

            function reactivateMember(memberId) {
                openConfirmModal(
                    CORP_T.reactivate_title,
                    CORP_T.reactivate_message,
                    async () => {
                        try {
                            const data = await apiCall(API_BASE + '/members/' + memberId + '/reactivate', { method: 'POST' });
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
                    CORP_T.delete_title,
                    CORP_T.delete_message,
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
                        showAlert(CORP_T.enter_hours, true);
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
                        showAlert(CORP_T.enter_hours_member, true);
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
