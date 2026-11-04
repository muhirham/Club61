<?php

namespace App\Models\Padel;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CourtEquipment extends Model
{
    use HasUlids;

    /** Jual: habis pakai — stok dipotong saat bayar, boleh dijual tanpa booking. */
    public const USAGE_SALE = 'SALE';

    /** Sewa: harus kembali — stok dipotong saat check-in & kembali saat alat dikembalikan, wajib lewat booking. */
    public const USAGE_RENTAL = 'RENTAL';

    public const USAGES = [self::USAGE_SALE => 'Jual (habis pakai)', self::USAGE_RENTAL => 'Sewa (harus kembali)'];

    protected $table = 'court_equipments';

    protected $fillable = [
        'name',
        'type',
        'usage',
        'rental_price',
        'stock_quantity',
        'fine_minor',
        'fine_major',
        'fine_lost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rental_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'fine_minor' => 'decimal:2',
            'fine_major' => 'decimal:2',
            'fine_lost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Sifat alat: diatur di Master Data (kolom usage). Kosong = aturan lama dari jenisnya: BALL = Jual, RACKET / TOWEL =
     * Sewa, jenis lain (COACH / OTHER) = null — stoknya tidak bergerak & tidak bisa dijual tanpa booking.
     */
    public static function usageOf(?string $usage, ?string $type): ?string
    {
        if (in_array($usage, [self::USAGE_SALE, self::USAGE_RENTAL], true)) {
            return $usage;
        }

        return match (strtoupper((string) $type)) {
            'BALL' => self::USAGE_SALE,
            'RACKET', 'TOWEL' => self::USAGE_RENTAL,
            default => null,
        };
    }

    // Bukan usage(): nama method yang sama dengan kolom dibaca Eloquent sebagai relasi kalau kolomnya tidak di-select.
    public function resolvedUsage(): ?string
    {
        return self::usageOf($this->getAttributes()['usage'] ?? null, $this->type);
    }

    public function isSale(): bool
    {
        return $this->resolvedUsage() === self::USAGE_SALE;
    }

    public function isRental(): bool
    {
        return $this->resolvedUsage() === self::USAGE_RENTAL;
    }
}
