<?php

namespace App\Models\Merch;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MerchProduct extends Model
{
    use HasUlids;

    /** Daftar kategori disimpan di kode (kolom string) — menambah kategori tidak butuh migrasi. */
    public const CATEGORIES = [
        'APPAREL' => 'Apparel',
        'HEADWEAR' => 'Topi',
        'ACCESSORY' => 'Aksesoris',
        'BOTTLE' => 'Botol',
        'OTHER' => 'Lainnya',
    ];

    protected $fillable = [
        'name',
        'brand',
        'category',
        'description',
        'image',
        'base_price',
        'hpp',
        'is_active',
    ];

    // HPP (modal) tidak pernah ikut terkirim ke API publik katalog merchandise.
    protected $hidden = ['hpp'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'hpp' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Foto lama dibuang SETELAH perubahan tersimpan (pola sama dengan FnbMenu): simpan gagal → foto tetap ada.
        static::updated(function (self $product) {
            $oldPath = $product->getOriginal('image');
            if ($product->wasChanged('image') && $oldPath) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($oldPath));
            }
        });

        static::deleted(function (self $product) {
            if ($product->image) {
                $path = $product->image;
                DB::afterCommit(fn () => Storage::disk('public')->delete($path));
            }
        });
    }

    public function variants()
    {
        return $this->hasMany(MerchVariant::class, 'product_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Lainnya';
    }

    /** URL dari host yang sedang dibuka (bukan APP_URL) — sama dengan foto fasilitas, lihat PublicStorageUrl. */
    public function imageUrl(): ?string
    {
        return \App\Support\PublicStorageUrl::for($this->image);
    }
}
