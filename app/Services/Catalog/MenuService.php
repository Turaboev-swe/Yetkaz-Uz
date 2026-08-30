<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

class MenuService
{
    /**
     * Restoran menyusi: faol kategoriyalar + mavjud taomlar, tartiblangan.
     * N+1 dan qochish uchun taomlar `with()` bilan yuklanadi.
     *
     * @return Collection<int, Category>
     */
    public function forRestaurant(Restaurant $restaurant): Collection
    {
        return $restaurant->categories()
            ->where('is_active', true)
            ->with(['products' => function ($query) {
                $query->where('is_available', true)
                    ->orderBy('sort_order')
                    ->orderBy('id');
            }])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
