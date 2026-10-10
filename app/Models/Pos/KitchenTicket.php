<?php

namespace App\Models\Pos;

use App\Models\Fnb\FnbStation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Slip pesanan satu stasiun F&B untuk satu order lunas (App\Services\Fnb\StationTicketService). Isi slip (items)
 * disimpan saat lunas supaya cetak ulang tetap sama walau menu / stasiunnya diubah belakangan.
 */
class KitchenTicket extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'station_id',
        'station_name',
        'items',
        'status',
        'created_at',
        'printed_at',
        'print_attempts',
        'last_print_error',
        'served_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'created_at' => 'datetime',
            'printed_at' => 'datetime',
            'print_attempts' => 'integer',
            'served_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function station()
    {
        return $this->belongsTo(FnbStation::class, 'station_id');
    }
}
