<?php

namespace App\Models\Coaching;

use App\Models\Pos\PosCashierShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modul 31 — satu pelunasan Tagihan Coaching di kasir POS Walk-In: banyak sesi academy dibayar sekaligus (QRIS / EDC /
 * Transfer Bank + bukti). Uangnya tercatat per order sesi lewat PaymentOrchestratorService (Buku Transaksi & Tutup Shift).
 */
class CoachingSettlement extends Model
{
    use HasUlids;

    protected $fillable = [
        'settlement_number', 'academy_id', 'academy_name', 'payment_method', 'total_amount', 'session_count',
        'reference', 'sender_name', 'proof_path', 'cashier_shift_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'session_count' => 'integer'];
    }

    public function academy(): BelongsTo
    {
        return $this->belongsTo(CoachingAcademy::class, 'academy_id');
    }

    public function coachingBookings(): HasMany
    {
        return $this->hasMany(CoachingBooking::class, 'settlement_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(PosCashierShift::class, 'cashier_shift_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
