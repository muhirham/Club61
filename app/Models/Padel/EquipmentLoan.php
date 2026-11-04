<?php

namespace App\Models\Padel;

use App\Models\Pos\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu peminjaman alat Sewa (raket / handuk): siapa meminjam, siapa menyerahkan & menerima, kondisi, dan dendanya. */
class EquipmentLoan extends Model
{
    use HasUlids;

    public const FINE_NONE = 'NONE';

    /** Ada kerusakan / kehilangan — menunggu keputusan management. */
    public const FINE_PENDING = 'PENDING';

    /** Denda disetujui — ditagih di kasir. */
    public const FINE_APPROVED = 'APPROVED';

    /** Denda dihapus management. */
    public const FINE_WAIVED = 'WAIVED';

    /** Dianggap terlambat setelah lewat batas kembali selama ini (menit). */
    public const OVERDUE_GRACE_MINUTES = 30;

    /** Kondisi saat diterima kembali → kolom jumlah & kolom nominal denda di court_equipments. */
    public const CONDITIONS = [
        'good' => ['short' => 'Baik', 'label' => 'Baik / lecet pemakaian wajar', 'fine' => null, 'restock' => true],
        'minor' => ['short' => 'Lecet disengaja', 'label' => 'Lecet / rusak ringan karena disengaja', 'fine' => 'fine_minor', 'restock' => true],
        'major' => ['short' => 'Patah disengaja', 'label' => 'Patah / pecah / tidak bisa dipakai karena disengaja', 'fine' => 'fine_major', 'restock' => false],
        'lost' => ['short' => 'Hilang', 'label' => 'Hilang / tidak dikembalikan', 'fine' => 'fine_lost', 'restock' => false],
    ];

    protected $fillable = [
        'equipment_id', 'equipment_name', 'booking_equipment_id', 'booking_id', 'borrower_user_id', 'borrower_name', 'borrower_phone',
        'quantity', 'handed_out_by_id', 'handed_out_at', 'due_at', 'returned_at', 'returned_by_id',
        'qty_good', 'qty_minor', 'qty_major', 'qty_lost', 'return_notes',
        'fine_status', 'fine_proposed', 'fine_amount', 'fine_decided_by_id', 'fine_decided_at', 'fine_decision_note', 'fine_order_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'handed_out_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
            'fine_decided_at' => 'datetime',
            'qty_good' => 'integer',
            'qty_minor' => 'integer',
            'qty_major' => 'integer',
            'qty_lost' => 'integer',
            'fine_proposed' => 'decimal:2',
            'fine_amount' => 'decimal:2',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(CourtEquipment::class, 'equipment_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(PadelBooking::class, 'booking_id');
    }

    public function bookingEquipment(): BelongsTo
    {
        return $this->belongsTo(PadelBookingEquipment::class, 'booking_equipment_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function handedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_out_by_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_id');
    }

    public function fineDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fine_decided_by_id');
    }

    public function fineOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'fine_order_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('returned_at');
    }

    /** Belum kembali dan sudah lewat batas kembali + toleransi. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('returned_at')->where('due_at', '<', now()->subMinutes(self::OVERDUE_GRACE_MINUTES));
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at->lt(now()->subMinutes(self::OVERDUE_GRACE_MINUTES));
    }

    /** Denda sudah disetujui dan tagihannya lunas di kasir. */
    public function isFinePaid(): bool
    {
        if ($this->fine_order_id === null) {
            return false;
        }
        $status = $this->relationLoaded('fineOrder') ? $this->fineOrder?->payment_status : Order::whereKey($this->fine_order_id)->value('payment_status');

        return $status === 'PAID';
    }

    /** Denda disetujui dan masih harus dibayar (belum ada tagihan lunas). */
    public function fineDue(): float
    {
        return $this->fine_status === self::FINE_APPROVED && ! $this->isFinePaid() ? (float) $this->fine_amount : 0.0;
    }
}
