<?php

namespace App\Models\Merch;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MerchVariant extends Model
{
    use HasUlids;

    /** Produk tanpa pilihan ukuran / warna punya satu varian ini (kolom color & size wajib terisi di tabel lama). */
    public const STANDARD_COLOR = 'Standar';

    public const STANDARD_SIZE = 'All';

    protected $fillable = [
        'product_id',
        'sku',
        'color',
        'size',
        'additional_price',
        'stock_quantity',
        'min_stock',
        'is_active',
    ];

    protected $hidden = ['min_stock'];

    protected function casts(): array
    {
        return [
            'additional_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'min_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(MerchProduct::class, 'product_id');
    }

    public function isStandard(): bool
    {
        return $this->color === self::STANDARD_COLOR && $this->size === self::STANDARD_SIZE;
    }

    /** "Hitam / L"; varian standar = "Standar". */
    public function label(): string
    {
        return $this->isStandard() ? self::STANDARD_COLOR : trim($this->color.' / '.$this->size, ' /');
    }

    /** Harga jual varian = harga produk + tambahan harga varian. */
    public function price(): float
    {
        return round((float) ($this->product?->base_price ?? 0) + (float) $this->additional_price, 2);
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= 0 || ($this->min_stock !== null && $this->stock_quantity <= $this->min_stock);
    }
}
