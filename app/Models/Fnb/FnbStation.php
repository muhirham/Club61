<?php

namespace App\Models\Fnb;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stasiun produksi F&B (Kitchen, Bar, ...) dengan printer LAN-nya. Menu tanpa stasiun dibuat langsung di kasir dan
 * tidak mendapat slip.
 */
class FnbStation extends Model
{
    use HasUlids;

    /** Label menu tanpa stasiun. */
    public const CASHIER_LABEL = 'Kasir (tanpa slip)';

    protected $fillable = [
        'name',
        'printer_host',
        'printer_port',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'printer_port' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function menus()
    {
        return $this->hasMany(FnbMenu::class, 'station_id');
    }

    public function hasPrinter(): bool
    {
        return trim((string) $this->printer_host) !== '';
    }
}
