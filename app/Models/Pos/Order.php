<?php

namespace App\Models\Pos;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'order_number',
        'user_id',
        'cashier_id',
        'pos_shift_id',
        'order_type',
        'table_number',
        'customer_name',
        'queue_number',
        'delivery_address',
        'delivery_fee',
        'subtotal',
        'discount_amount',
        'voucher_code',
        'tax_amount',
        'service_charge',
        'grand_total',
        'payment_status',
        'is_split_bill',
    ];

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'is_split_bill' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function orderItems()
    {
        return $this->items();
    }

    public function splits()
    {
        return $this->hasMany(BillSplit::class, 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'order_id');
    }

    /** Slip pesanan per stasiun F&B (Kitchen, Bar, ...) — dibuat begitu order F&B lunas. */
    public function kitchenTickets()
    {
        return $this->hasMany(KitchenTicket::class, 'order_id');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class, 'order_id');
    }

    public function padelBookings()
    {
        return $this->hasMany(\App\Models\Padel\PadelBooking::class, 'order_id');
    }

    public function posShift()
    {
        return $this->belongsTo(PosCashierShift::class, 'pos_shift_id');
    }
}
