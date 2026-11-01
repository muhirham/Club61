<?php

namespace App\Models\Coaching;

use App\Models\Padel\PadelBooking;
use App\Models\Pos\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu booking coaching (1 per order kasir): coach utama + tambahan, murid, catatan, dan snapshot harga saat dibuat.
 * Slot lapangannya = padel_bookings.coaching_booking_id.
 */
class CoachingBooking extends Model
{
    use HasUlids;

    public const MAX_STUDENTS = 2;

    protected $fillable = [
        'order_id', 'head_coach_id', 'academy_name', 'academy_id', 'billing', 'billing_cycle', 'cashier_shift_id', 'due_at',
        'settlement_id', 'students', 'notes', 'extra_coach_fee_per_hour', 'pricing', 'created_by_id',
    ];

    protected function casts(): array
    {
        return ['students' => 'array', 'pricing' => 'array', 'extra_coach_fee_per_hour' => 'decimal:2', 'due_at' => 'datetime'];
    }

    /** Modul 31: sesi tercatat pada tagihan berkala academy (dilunasi di kasir sesuai periode), bukan dibayar saat booking. */
    public function isTab(): bool
    {
        return $this->billing === CoachingAcademy::BILLING_TAB;
    }

    public function academy(): BelongsTo
    {
        return $this->belongsTo(CoachingAcademy::class, 'academy_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CoachingSettlement::class, 'settlement_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Pos\PosCashierShift::class, 'cashier_shift_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function headCoach(): BelongsTo
    {
        return $this->belongsTo(Coach::class, 'head_coach_id');
    }

    /** Semua coach sesi ini (pivot role HEAD / EXTRA). */
    public function coaches(): BelongsToMany
    {
        return $this->belongsToMany(Coach::class, 'coaching_booking_coaches', 'coaching_booking_id', 'coach_id')->withPivot('role');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(PadelBooking::class, 'coaching_booking_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function extraCoachCount(): int
    {
        return $this->coaches->where('pivot.role', 'EXTRA')->count();
    }

    /** Coach utama dulu, lalu coach tambahan. */
    public function sortedCoaches()
    {
        return $this->coaches->sortBy(fn (Coach $c) => $c->pivot->role === 'HEAD' ? 0 : 1)->values();
    }

    /** Ringkas untuk sel jadwal: "Nalda +1" (nama depan coach utama + jumlah coach tambahan; sel timetable sempit). */
    public function coachSummary(): string
    {
        $head = $this->sortedCoaches()->first();
        $extra = $this->extraCoachCount();
        // Nama yang diawali "Coach " (mis. "Coach Nalda") → "Nalda", supaya sel tidak terbaca "Coach Coach".
        $name = $head ? (preg_replace('/^coach\s+/i', '', trim($head->name)) ?: trim($head->name)) : '-';
        $firstName = preg_split('/\s+/', $name)[0] ?: $name;

        return $firstName.($extra > 0 ? ' +'.$extra : '');
    }
}
