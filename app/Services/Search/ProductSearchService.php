<?php

namespace App\Services\Search;

use App\Models\Address;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Umumiy taom qidiruvi (Claude.md): foydalanuvchi taom nomini yozadi, tizim
 * yetkazish radiusidagi BARCHA restoranlardan o'sha taomni qidiradi.
 *
 * pg_trgm (`%` operatori + f_unaccent GIN indeksi) bilan. Har natijada:
 * taom nomi, narxi, restoran nomi va masofa.
 */
class ProductSearchService
{
    /** @return Collection<int, Product>  har element `distance_km` bilan, `category.restaurant` yuklangan */
    public function search(string $term, Address $address): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        [$lng, $lat] = [$address->lng, $address->lat];

        return Product::query()
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->join('restaurants', 'restaurants.id', '=', 'categories.restaurant_id')
            ->where('products.is_available', true)
            ->where('categories.is_active', true)
            ->where('restaurants.is_open', true)
            ->whereRaw(
                'ST_DWithin(restaurants.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, restaurants.delivery_radius_km * 1000)',
                [$lng, $lat],
            )
            // So'z o'xshashligi: qidiruv so'zi taom nomidagi bironta so'zga o'xshasa
            // yetadi ("lag'mon" -> "Tovuqli lag'mon"). Aniq chegara (0.4) — sessiya
            // GUC'iga bog'lanmaslik uchun. Radius filtri (GIST) mahsulotlar to'plamini
            // allaqachon kichraytirgani sababli bu skaner arzon.
            ->whereRaw(
                '(word_similarity(f_unaccent(?), f_unaccent(products.name)) >= 0.4 OR f_unaccent(products.name) ILIKE ?)',
                [$term, '%'.$term.'%'],
            )
            ->select('products.*')
            ->selectRaw(
                'ST_Distance(restaurants.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000 AS distance_km',
                [$lng, $lat],
            )
            ->selectRaw('word_similarity(f_unaccent(?), f_unaccent(products.name)) AS match_rank', [$term])
            ->orderByDesc('match_rank')
            ->orderBy('distance_km')
            ->limit(50)
            ->with('category.restaurant')
            ->get()
            ->filter(fn (Product $p) => $p->category->restaurant->isOpenNow())
            ->values();
    }
}
