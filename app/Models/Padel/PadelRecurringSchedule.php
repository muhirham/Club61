<?php

namespace App\Models\Padel;

use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jadwal Tetap Mingguan: tiap {day_of_week} jam {start_time}–{end_time} di lapangan {court_ids}, dibuatkan booking
 * otomatis atas nama pemilik membership dan dipotong dari kuota jam PADEL membership itu (corporate / individu).
 * Booking dibuat paling jauh {weeks_ahead} minggu ke depan (RecurringScheduleService). Batas reschedule mengikuti
 * pengaturan global (BookingTimeService::rescheduleCutoffHours), sama dengan booking lain.
 */
class PadelRecurringSchedule extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_PAUSED = 'PAUSED';

    /** Dihapus admin setelah ada sesi yang dimainkan: tidak membuat booking lagi, disimpan untuk riwayat. */
    public const STATUS_STOPPED = 'STOPPED';

    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public const MAX_WEEKS_AHEAD = 12;


    protected $fillable = [
        'name',
        'user_membership_id',
        'day_of_week',
        'start_time',
        'end_time',
        'court_ids',
        'starts_on',
        'ends_on',
        'weeks_ahead',
        'status',
        'notes',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'court_ids' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weeks_ahead' => 'integer',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'user_membership_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PadelRecurringOccurrence::class, 'recurring_schedule_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(PadelBooking::class, 'recurring_schedule_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    /** Kuota jam PADEL yang dipotong jadwal ini (null = membership tidak punya kuota jam PADEL). */
    public function padelBalance(): ?UserMembershipBalance
    {
        $balance = $this->membership?->balanceFor('PADEL');

        return $balance && $balance->quota_type === 'HOURS' ? $balance : null;
    }

    /** Kartu Padel Tagihan Bulanan jadwal ini (Modul 34): booking dicatat sebagai tagihan, tidak memotong kuota jam. */
    public function monthlyBillingBalance(): ?UserMembershipBalance
    {
        return \App\Services\Membership\MonthlyBilling::balanceOf($this->membership);
    }

    /** Nama pemilik kuota: nama perusahaan untuk corporate, nama member untuk individu. */
    public function ownerLabel(): string
    {
        $membership = $this->membership;
        if (! $membership) {
            return '-';
        }

        return $membership->sponsorOrganization?->name ?? ($membership->user?->name ?? '-');
    }

    public function durationHours(): int
    {
        return self::hourOf($this->end_time) - self::hourOf($this->start_time);
    }

    public function hoursPerWeek(): int
    {
        return $this->durationHours() * count((array) $this->court_ids);
    }

    public function scheduleLabel(): string
    {
        return (self::DAYS[$this->day_of_week] ?? '-').', '.$this->start_time.'–'.$this->end_time;
    }

    /** "19:00" → 19, "24:00" → 24. */
    public static function hourOf(string $time): int
    {
        return (int) substr($time, 0, 2);
    }

    /** Tanggal main pertama (hari yang cocok) mulai dari $from. */
    public function firstDateOnOrAfter(Carbon $from): Carbon
    {
        $date = $from->copy()->startOfDay();
        while ($date->dayOfWeekIso !== $this->day_of_week) {
            $date->addDay();
        }

        return $date;
    }
}
