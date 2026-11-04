<?php

namespace App\Http\Controllers\Api\V1\Merch;

use App\Http\Controllers\Controller;
use App\Models\Merch\MerchProduct;
use App\Models\Merch\MerchVariant;
use Illuminate\Http\JsonResponse;

class MerchController extends Controller
{
    /** Katalog publik: hanya produk & varian aktif. HPP dan stok minimum tidak pernah dikirim. */
    public function index(): JsonResponse
    {
        $products = MerchProduct::with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('color')->orderBy('size')])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (MerchProduct $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'brand' => $p->brand,
                'category' => $p->category,
                'category_label' => $p->categoryLabel(),
                'description' => $p->description,
                'image_url' => $p->imageUrl(),
                'base_price' => $p->base_price,
                'variants' => $p->variants->map(fn (MerchVariant $v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'color' => $v->color,
                    'size' => $v->size,
                    'additional_price' => $v->additional_price,
                    'stock_quantity' => $v->stock_quantity,
                ])->values(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Daftar merchandise resmi Club 61.',
            'data' => $products,
        ]);
    }
}
