<?php

namespace App\Models\Padel;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Harga Khusus per Periode (Modul 32): harga per jam lapangan untuk rentang tanggal main + hari + jam tertentu,
 * di atas Harga Dasar Master Data. Hari 0 = Minggu ... 6 = Sabtu; end_hour eksklusif (24 = tengah malam).
 * Dibaca lewat PeakHourService::rateFor() — satu-satunya penentu harga lapangan.
 */
class PadelPriceRule extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'court_ids',
        'valid_from',
        'valid_until',
        'days',
        'include_holidays',
        'start_hour',
        'end_hour',
        'price_per_hour',
        'is_prime',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'court_ids' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'days' => 'array',
            'include_holidays' => 'boolean',
            'start_hour' => 'integer',
            'end_hour' => 'integer',
            'price_per_hour' => 'decimal:2',
            'is_prime' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
