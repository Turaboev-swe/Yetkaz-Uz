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
     * GET /api/restaurants?address_id=&district_id=&include_closed= — shu manzilга
     * yetkazadigan restoranlar. `district_id` — ixtiyoriy ko'rsatish filtri.
     * `include_closed=1` — Mini App ro'yxati uchun yopiqlarni ham qaytaradi
     * (`is_open_now` bayrog'i bilan; ochiqlari yuqorida).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'include_closed' => ['nullable', 'boolean'],
        ]);

        return RestaurantResource::collection(
            $this->finder->deliveringTo(
                $this->resolveUserAddress($request),
                $validated['district_id'] ?? null,
                (bool) ($validated['include_closed'] ?? false),
            ),
        );
    }

    /**
     * GET /api/restaurants/{restaurant}?address_id= — bitta restoran.
     * `address_id` berilsa `distance_km` ham qaytadi (rasmiylashtirish ekrani uchun).
     */
    public function show(Request $request, Restaurant $restaurant): RestaurantResource
    {
        $restaurant->load('district.region');

        if ($request->filled('address_id')) {
            $address = $request->user()->addresses()->find($request->integer('address_id'));
            if ($address !== null) {
                $restaurant->distance_km = $this->finder->distanceKm($restaurant, $address);
            }
        }

        return new RestaurantResource($restaurant);
    }

    /** GET /api/restaurants/{restaurant}/menu — faol kategoriyalar + mavjud taomlar. */
    public function menu(Restaurant $restaurant): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            $this->menuService->forRestaurant($restaurant),
        );
    }
}
