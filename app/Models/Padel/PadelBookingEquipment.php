<?php

namespace App\Models\Padel;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PadelBookingEquipment extends Model
{
    use HasUlids;

    protected $table = 'padel_booking_equipments';

    protected $fillable = [
        'order_id',
        'booking_id',
        'equipment_id',
        'quantity',
        'unit_price',
        'subtotal',
        'stock_deducted_at',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'stock_deducted_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(PadelBooking::class, 'booking_id');
    }

    public function equipment()
    {
        return $this->belongsTo(CourtEquipment::class, 'equipment_id');
    }

    public function order()
    {
        return $this->belongsTo(\App\Models\Pos\Order::class, 'order_id');
    }
}
