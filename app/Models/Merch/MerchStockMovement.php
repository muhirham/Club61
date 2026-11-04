<?php

namespace App\Models\Merch;

use App\Models\Pos\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Kartu stok merchandise — hanya ditulis MerchStockService, tidak pernah diedit / dihapus. */
class MerchStockMovement extends Model
{
    use HasUlids;

    public const SALE = 'SALE';

    public const SALE_CANCEL = 'SALE_CANCEL';

    public const RESTOCK = 'RESTOCK';

    public const ADJUSTMENT = 'ADJUSTMENT';

    public const TYPES = [
        self::SALE => 'Penjualan',
        self::SALE_CANCEL => 'Penjualan Batal',
        self::RESTOCK => 'Barang Masuk',
        self::ADJUSTMENT => 'Penyesuaian',
    ];

    public const REASONS = [
        'DAMAGED' => 'Rusak / cacat',
        'LOST' => 'Hilang',
        'COUNT_DIFF' => 'Selisih hitung fisik',
        'PROMO' => 'Hadiah / promosi',
        'INPUT_ERROR' => 'Salah input',
        'OTHER' => 'Lainnya',
    ];

    /** Penyesuaian yang bukan kerugian barang (koreksi pencatatan). */
    public const NOT_A_LOSS = ['INPUT_ERROR'];

    public $timestamps = false;

    // HPP & harga beli tidak ikut terserialisasi (mis. hasil method komponen Livewire yang terpanggil dari browser) —
    // hanya pengelola produk & Laporan Merchandise yang boleh melihat modal.
    protected $hidden = ['unit_cost', 'purchase_total'];

    protected $fillable = [
        'variant_id',
        'product_name',
        'variant_label',
        'type',
        'qty_change',
        'stock_after',
        'unit_cost',
        'purchase_total',
        'reference_number',
        'supplier',
        'invoice_number',
        'order_id',
        'reason',
        'notes',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_change' => 'integer',
            'stock_after' => 'integer',
            'unit_cost' => 'decimal:2',
            'purchase_total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function variant()
    {
        return $this->belongsTo(MerchVariant::class, 'variant_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
