<?php

namespace App\Filament\Pages;

use App\Models\Membership\FacilityCheckin;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipUsageLog;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

class Kustomer extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Data Pelanggan';

    protected static string|UnitEnum|null $navigationGroup = 'Customer & Membership';

    protected static ?string $title = 'Data Kustomer & Live Monitoring Membership';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.kustomer';

    public string $activeTab = 'members'; // 'members' | 'live_monitoring'

    public string $search = '';

    public string $statusFilter = 'ALL'; // 'ALL' | 'ACTIVE' | 'PENDING_PAYMENT' | 'EXPIRED'

    #[Url(as: 'member')]
    public ?string $selectedMembershipId = null;

    public string $detailTab = 'habit_schedule'; // 'habit_schedule' | 'corporate_roster' | 'card_info' | 'audit_logs'

    public function selectTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['members', 'live_monitoring']) ? $tab : 'members';
    }

    public function selectDetailTab(string $tab): void
    {
        $this->detailTab = $tab;
    }

    public function openDetail(string $id): void
    {
        $this->selectedMembershipId = $id;
        $m = $this->selectedMembership;
        if ($m && $m->owner_type === 'ORGANIZATIONAL') {
            $this->detailTab = 'corporate_roster';
        } else {
            $this->detailTab = 'habit_schedule';
        }
    }

    public function closeDetail(): void
    {
        $this->selectedMembershipId = null;
    }

    public function getMetricsProperty(): array
    {
        $totalActive = UserMembership::where('status', 'ACTIVE')->count();
        $newThisMonth = UserMembership::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $totalPadelHours = UserMembershipBalance::where('facility', 'PADEL')
            ->whereHas('membership', fn ($q) => $q->where('status', 'ACTIVE'))
            ->sum('remaining_quota');
        $todayUsageCount = MembershipUsageLog::whereDate('created_at', now()->toDateString())->count();

        return [
            'total_active' => $totalActive,
            'new_this_month' => $newThisMonth,
            'total_padel_hours' => (float) $totalPadelHours,
            'today_usage_count' => $todayUsageCount,
        ];
    }

    public function getMembersProperty()
    {
        return UserMembership::with(['user', 'plan', 'balances', 'soldByAdmin', 'order'])
            ->when($this->statusFilter !== 'ALL', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->when(filled($this->search), function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('membership_code', 'like', $term)
                        ->orWhereHas('user', function ($u) use ($term) {
                            $u->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        })
                        ->orWhereHas('plan', function ($p) use ($term) {
                            $p->where('name', 'like', $term)
                                ->orWhere('code', 'like', $term);
                        });
                });
            })
            ->latest('created_at')
            ->get();
    }

    public function getLiveLogsProperty()
    {
        return MembershipUsageLog::with(['balance.membership.user', 'balance.membership.plan', 'performer'])
            ->latest('created_at')
            ->limit(50)
            ->get();
    }

    public function getSelectedMembershipProperty()
    {
        if (! $this->selectedMembershipId) {
            return null;
        }

        return UserMembership::with([
            'user',
            'plan.benefits',
            'balances.usageLogs' => fn ($q) => $q->latest('created_at')->limit(20),
            'order.payments',
            'soldByAdmin',
        ])->find($this->selectedMembershipId);
    }

    public function getMemberBookingsProperty()
    {
        $m = $this->selectedMembership;
        if (! $m || ! $m->user_id) {
            return collect();
        }

        return PadelBooking::with('court')
            ->where('user_id', $m->user_id)
            ->latest('booking_date')
            ->latest('start_time')
            ->get();
    }

    public function getMemberCheckinsProperty()
    {
        $m = $this->selectedMembership;
        if (! $m || ! $m->user_id) {
            return collect();
        }

        return FacilityCheckin::where('user_id', $m->user_id)
            ->latest('checkin_at')
            ->get();
    }

    /**
     * Tab "Anggota Tim Sponsor & Jadwal" — semua dari data asli:
     *  - Kuota kartu (jam PADEL): kontrak, dipakai langsung oleh kartu (Jadwal Tetap Mingguan / booking atas nama kartu),
     *    diberikan ke karyawan (voucher, menu Kelola Sponsor), dan sisa. Sponsor seperti IM3 bisa memakai kuota hanya
     *    lewat Jadwal Tetap tanpa anggota karyawan.
     *  - Jadwal Tetap Mingguan kartu ini + jadwal terakhir main & berikutnya.
     *  - Anggota karyawan: jatah voucher yang masih berlaku, jam terpakai, jadwal dari booking yang memakai jatah
     *    perusahaan (angka mengikuti dashboard PIC).
     *
     * @return array{organization: ?SponsorOrganization, total_quota: ?float, direct_used: float, released: float, used: float, remaining: ?float, schedules: list<array>, members: list<array>, active_count: int, idle_count: int}
     */
    public function getCorporateTeamProperty(): array
    {
        $team = ['organization' => null, 'total_quota' => null, 'direct_used' => 0.0, 'released' => 0.0, 'used' => 0.0, 'remaining' => null,
            'schedules' => [], 'members' => [], 'active_count' => 0, 'idle_count' => 0];
        $m = $this->selectedMembership;
        if (! $m || $m->owner_type !== 'ORGANIZATIONAL') {
            return $team;
        }

        $now = now();
        $schedule = fn (?PadelBooking $b) => $b
            ? $b->start_time->format('d M Y, H:i').' - '.$b->end_time->format('H:i').' WIB ('.($b->court?->name ?? '-').')'
            : null;

        // Kuota jam PADEL kartu. Jam yang dipakai langsung = booking atas nama kartu (pembatalan mengembalikan & menolkan jamnya).
        $padel = $m->balances->first(fn ($b) => $b->facility === 'PADEL' && $b->quota_type === 'HOURS');
        if ($padel) {
            $team['total_quota'] = $padel->initial_quota !== null ? (float) $padel->initial_quota : null;
            $team['remaining'] = (float) $padel->remaining_quota;
            $team['direct_used'] = (float) PadelBooking::where('membership_balance_id', $padel->id)->sum('member_hours_consumed');
        }

        $courtNames = \App\Models\Padel\PadelCourt::pluck('name', 'id');
        $team['schedules'] = \App\Models\Padel\PadelRecurringSchedule::where('user_membership_id', $m->id)
            ->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 WHEN 'PAUSED' THEN 1 ELSE 2 END")->orderBy('day_of_week')->orderBy('start_time')
            ->get()
            ->map(function (\App\Models\Padel\PadelRecurringSchedule $s) use ($courtNames, $now, $schedule) {
                $bookings = PadelBooking::with('court:id,name')->where('recurring_schedule_id', $s->id)
                    ->whereIn('status', ['PAID', 'CHECKED_IN', 'COMPLETED'])->get(['id', 'court_id', 'start_time', 'end_time', 'status']);

                return [
                    'name' => $s->name,
                    'schedule' => $s->scheduleLabel().' WIB',
                    'courts' => collect((array) $s->court_ids)->map(fn ($id) => $courtNames[$id] ?? '-')->implode(', '),
                    'hours_per_week' => $s->hoursPerWeek(),
                    'status' => $s->status,
                    'last_played' => $schedule($bookings->filter(fn ($b) => in_array($b->status, ['CHECKED_IN', 'COMPLETED'], true))->sortByDesc('start_time')->first()),
                    'next_schedule' => $schedule($bookings->filter(fn ($b) => $b->start_time->gte($now) && in_array($b->status, ['PAID', 'CHECKED_IN'], true))->sortBy('start_time')->first()),
                ];
            })->all();

        $organization = SponsorOrganization::with('sponsorAdmin:id,name,phone')->where('user_membership_id', $m->id)->first();
        $team['organization'] = $organization;
        if (! $organization) {
            return $team;
        }

        $members = SponsorOrganizationMember::where('sponsor_organization_id', $organization->id)
            ->with(['user:id,name,email,phone', 'vouchers'])
            ->get()
            ->sortBy(fn (SponsorOrganizationMember $member) => ($member->isActive() ? '0' : '1').mb_strtolower((string) $member->user?->name))
            ->values();

        // Booking yang memakai jatah perusahaan ini (ditandai sponsor_organization_id saat checkout).
        $bookings = PadelBooking::with('court:id,name')
            ->where('sponsor_organization_id', $organization->id)
            ->whereIn('user_id', $members->pluck('user_id')->filter()->all())
            ->whereIn('status', ['PAID', 'CHECKED_IN', 'COMPLETED'])
            ->get(['id', 'user_id', 'court_id', 'start_time', 'end_time', 'status'])
            ->groupBy('user_id');

        $rows = $members->map(function (SponsorOrganizationMember $member) use ($bookings, $now, $schedule) {
            // Sama dengan dashboard PIC: jatah & terpakai dari voucher yang masih berlaku.
            $valid = $member->vouchers->filter(fn ($v) => ! $v->isExpired());
            $own = $bookings->get($member->user_id, collect());
            $last = $own->filter(fn (PadelBooking $b) => in_array($b->status, ['CHECKED_IN', 'COMPLETED'], true))->sortByDesc('start_time')->first();
            $next = $own->filter(fn (PadelBooking $b) => $b->start_time->gte($now) && in_array($b->status, ['PAID', 'CHECKED_IN'], true))->sortBy('start_time')->first();
            $used = (float) $valid->sum(fn ($v) => (float) $v->hours_used);

            return [
                'name' => $member->user?->name ?? '-',
                'email' => $member->user?->email,
                'phone' => $member->user?->phone,
                'allocated_hours' => (float) $valid->sum(fn ($v) => (float) $v->hours_granted),
                'used_hours' => $used,
                'remaining_hours' => $member->totalRemainingHours(),
                'last_played' => $schedule($last),
                'next_schedule' => $schedule($next),
                'status' => ! $member->isActive() ? 'INACTIVE' : (($own->isNotEmpty() || $used > 0) ? 'ACTIVE' : 'IDLE'),
            ];
        })->all();

        $statuses = array_count_values(array_column($rows, 'status'));

        return array_merge($team, [
            'released' => $organization->totalHoursReleased(),
            'used' => (float) array_sum(array_map(fn ($r) => $r['status'] === 'INACTIVE' ? 0 : $r['used_hours'], $rows)),
            'members' => $rows,
            'active_count' => $statuses['ACTIVE'] ?? 0,
            'idle_count' => $statuses['IDLE'] ?? 0,
        ]);
    }

    /** Jam mulai yang paling sering dipesan (booking lunas / check-in / selesai); "-" bila belum ada. */
    private function favoriteTime(\Illuminate\Support\Collection $bookings): string
    {
        $hours = $bookings->whereIn('status', ['PAID', 'CHECKED_IN', 'COMPLETED'])
            ->countBy(fn (PadelBooking $b) => (int) $b->start_time->format('H'))
            ->sortByDesc(fn ($count) => $count);
        if ($hours->isEmpty()) {
            return '-';
        }

        $hour = (int) $hours->keys()->first();

        return sprintf('%02d:00 - %02d:00 WIB (%dx booking)', $hour, ($hour + 1) % 24, $hours->first());
    }

    public function getMemberHabitAnalysisProperty(): array
    {
        $m = $this->selectedMembership;
        if (! $m) {
            return [];
        }

        $bookings = $this->memberBookings;
        $checkins = $this->memberCheckins;

        $totalMatches = $bookings->whereIn('status', ['CONFIRMED', 'CHECKED_IN', 'COMPLETED'])->count();
        $lastBooking = $bookings->first();
        $lastPlayedDate = $lastBooking ? $lastBooking->booking_date->format('d M Y') : null;
        $daysSinceLastPlayed = $lastBooking ? now()->diffInDays($lastBooking->booking_date, false) : 999;

        if ($m->owner_type === 'ORGANIZATIONAL') {
            $habitStatus = 'CORPORATE_TEAM';
            $habitLabel = 'Tim Korporat (Multi-Member)';
            $habitColor = '#6C3483';
            $recommendation = 'Akun sponsor korporat dengan kuota bersama untuk seluruh personil karyawan terdaftar.';
        } elseif ($totalMatches >= 4 || ($daysSinceLastPlayed !== 999 && $daysSinceLastPlayed >= -7 && $daysSinceLastPlayed <= 3)) {
            $habitStatus = 'VERY_ACTIVE';
            $habitLabel = 'Padel Addict (Sangat Aktif)';
            $habitColor = '#1E7E34';
            $recommendation = 'Member sangat aktif dengan frekuensi tanding rutin dan konsisten di Club 61.';
        } elseif ($totalMatches >= 1) {
            $habitStatus = 'REGULAR';
            $habitLabel = 'Regular Player (Cukup Aktif)';
            $habitColor = '#8C6418';
            $recommendation = 'Member aktif bermain secara berkala pada jadwal favorit.';
        } else {
            $habitStatus = 'DORMANT';
            $habitLabel = 'Pasif / Kosong Tanpa Kabar';
            $habitColor = '#C0392B';
            $recommendation = 'Member belum aktif menggunakan kuota bermain sejak kartu keanggotaan diterbitkan.';
        }

        return [
            'total_matches' => $totalMatches,
            'total_checkins' => $checkins->count(),
            'last_played' => $lastPlayedDate ?? 'Belum ada riwayat tanding',
            'days_since_last' => $daysSinceLastPlayed,
            'habit_status' => $habitStatus,
            'habit_label' => $habitLabel,
            'habit_color' => $habitColor,
            'favorite_court' => $bookings->groupBy('court.name')->sortByDesc->count()->keys()->first() ?? '-',
            'favorite_time' => $this->favoriteTime($bookings),
            'recommendation' => $recommendation,
        ];
    }
}
