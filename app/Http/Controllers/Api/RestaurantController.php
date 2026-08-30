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

    /** GET /api/restaurants?address_id= — shu manzilга yetkazadigan ochiq restoranlar. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return RestaurantResource::collection(
            $this->finder->deliveringTo($this->resolveUserAddress($request)),
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
