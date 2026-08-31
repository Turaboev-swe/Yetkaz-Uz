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

    /** GET /api/restaurants/{restaurant} — bitta restoran (menyu sarlavhasi uchun; masofasiz). */
    public function show(Restaurant $restaurant): RestaurantResource
    {
        return new RestaurantResource($restaurant->load('district.region'));
    }

    /** GET /api/restaurants/{restaurant}/menu — faol kategoriyalar + mavjud taomlar. */
    public function menu(Restaurant $restaurant): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            $this->menuService->forRestaurant($restaurant),
        );
    }
}
