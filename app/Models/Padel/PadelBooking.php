<?php

namespace App\Models\Padel;

use App\Models\Staff\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PadelBooking extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'booking_code',
        'order_id',
        'user_id',
        'court_id',
        'coach_id',
        'booking_date',
        'start_time',
        'end_time',
        'court_fee',
        'coach_fee',
        'equipment_fee',
        'total_amount',
        'status',
        'expires_at',
        'qr_code_hash',
        'checked_in_at',
        'reschedule_count',
        'reschedule_forfeited_amount',
        'cancel_reason',
        'membership_balance_id',
        'member_discount_court',
        'member_hours_consumed',
        'sponsor_organization_id',
        'sponsor_member_voucher_id',
        'sponsor_discount_court',
        'sponsor_hours_consumed',
        'coaching_booking_id',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date:Y-m-d',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'checked_in_at' => 'datetime',
            'expires_at' => 'datetime',
            'court_fee' => 'decimal:2',
            'coach_fee' => 'decimal:2',
            'equipment_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'reschedule_count' => 'integer',
            'reschedule_forfeited_amount' => 'decimal:2',
            'member_discount_court' => 'decimal:2',
            'member_hours_consumed' => 'decimal:2',
            'sponsor_discount_court' => 'decimal:2',
            'sponsor_hours_consumed' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function court()
    {
        return $this->belongsTo(PadelCourt::class, 'court_id');
    }

    public function coach()
    {
        return $this->belongsTo(StaffProfile::class, 'coach_id');
    }

    /** Booking coaching (Modul 24 Opsi B) — slot lapangan dengan tarif setoran coaching. */
    public function coachingBooking()
    {
        return $this->belongsTo(\App\Models\Coaching\CoachingBooking::class, 'coaching_booking_id');
    }

    /** Booking hasil Jadwal Tetap Mingguan (batas reschedule mengikuti jadwalnya). */
    public function recurringSchedule()
    {
        return $this->belongsTo(PadelRecurringSchedule::class, 'recurring_schedule_id');
    }

    public function equipments()
    {
        return $this->hasMany(PadelBookingEquipment::class, 'booking_id');
    }

    /** Raket / handuk yang diserahkan untuk booking ini (Peminjaman Alat). */
    public function equipmentLoans()
    {
        return $this->hasMany(EquipmentLoan::class, 'booking_id');
    }

    public function order()
    {
        return $this->belongsTo(\App\Models\Pos\Order::class, 'order_id');
    }

    public function membershipBalance()
    {
        return $this->belongsTo(\App\Models\Membership\UserMembershipBalance::class, 'membership_balance_id');
    }

    public function sponsorOrganization()
    {
        return $this->belongsTo(\App\Models\Sponsor\SponsorOrganization::class, 'sponsor_organization_id');
    }

    public function sponsorMemberVoucher()
    {
        return $this->belongsTo(\App\Models\Sponsor\SponsorMemberVoucher::class, 'sponsor_member_voucher_id');
    }
}
