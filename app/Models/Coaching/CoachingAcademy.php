<?php

namespace App\Models\Coaching;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Academy partner coaching (mis. SR Academy) — Modul 24 Opsi B. Modul 31: metode pembayaran sewa lapangan per academy —
 * Bayar Langsung (setiap booking) atau Tagihan Berkala (per shift / harian / mingguan / dua mingguan, dilunasi di kasir).
 */
class CoachingAcademy extends Model
{
    use HasUlids;

    public const BILLING_PER_BOOKING = 'PER_BOOKING';

    public const BILLING_TAB = 'TAB';

    public const BILLING_MODES = [
        self::BILLING_TAB => 'Tagihan Berkala (dibayar per periode)',
        self::BILLING_PER_BOOKING => 'Bayar Langsung (setiap booking)',
    ];

    public const CYCLE_SHIFT = 'SHIFT';

    public const CYCLE_DAILY = 'DAILY';

    public const CYCLE_WEEKLY = 'WEEKLY';

    public const CYCLE_BIWEEKLY = 'BIWEEKLY';

    public const CYCLES = [
        self::CYCLE_SHIFT => 'Per Shift',
        self::CYCLE_DAILY => 'Harian',
        self::CYCLE_WEEKLY => 'Mingguan',
        self::CYCLE_BIWEEKLY => 'Dua Mingguan',
    ];

    protected $fillable = ['name', 'pic_name', 'phone', 'billing_mode', 'billing_cycle', 'billing_anchor_date', 'block_when_overdue', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'block_when_overdue' => 'boolean', 'billing_anchor_date' => 'date'];
    }

    public function coaches(): HasMany
    {
        return $this->hasMany(Coach::class, 'academy_id');
    }

    public function usesTab(): bool
    {
        return $this->billing_mode === self::BILLING_TAB;
    }

    /** "Tagihan Berkala · Mingguan" / "Bayar Langsung". */
    public function billingLabel(): string
    {
        return $this->usesTab()
            ? 'Tagihan Berkala · '.(self::CYCLES[$this->billing_cycle] ?? '-')
            : 'Bayar Langsung';
    }
}
