<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesUserAddress;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Services\Catalog\MenuService;
use App\Services\Delivery\RestaurantFinder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantController extends Controller
{
    use ResolvesUserAddress;

    public function __construct(
        private readonly RestaurantFinder $finder,
        private readonly MenuService $menuService,
    ) {}

    /**
     * GET /api/restaurants?address_id=&district_id= — shu manzilга yetkazadigan
     * ochiq restoranlar. `district_id` — ixtiyoriy ko'rsatish filtri.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
        ]);

        return RestaurantResource::collection(
            $this->finder->deliveringTo(
                $this->resolveUserAddress($request),
                $validated['district_id'] ?? null,
            ),
        );
    }

    /** GET /api/restaurants/{restaurant}/menu — faol kategoriyalar + mavjud taomlar. */
    public function menu(Restaurant $restaurant): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            $this->menuService->forRestaurant($restaurant),
        );
    }
}
