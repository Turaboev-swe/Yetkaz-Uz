<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesKitchenOrders;
use App\Models\Category;
use App\Models\Product;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oshxona paneli (/kitchen) "Menyu" bo'limi: taom tugab qolganda bir bosishda «Tugadi» / «Bor».
 *
 * Tugma QOLDIQ bilan ishlaydi (StockService::setSoldOut), `is_available` ga tegmaydi: Mini App 0 qoldiqni allaqachon
 * kulrang «Tugadi» qilib ko'rsatadi. Faqat o'z restoranlari; begona restoran (menyu ham, taom ham) — 404.
 */
class KitchenMenuController extends Controller
{
    use AuthorizesKitchenOrders;

    public function __construct(private readonly StockService $stock) {}

    /** GET /kitchen/menu?restaurant_id= — faol kategoriyalar va taomlar (qoldiq bilan). Berilmasa — asosiy restoran. */
    public function index(Request $request): JsonResponse
    {
        $staff = $this->staff();
        $restaurantId = (int) ($request->query('restaurant_id') ?? $staff->restaurant_id);

        abort_unless($staff->canManageRestaurant($restaurantId), Response::HTTP_NOT_FOUND);

        // Egasining RestaurantScope'i faqat asosiy restoranni ko'radi — biriktirilgan boshqa restoran uchun ham ishlashi
        // kerak, ruxsat yuqorida pivot bo'yicha tekshirilgan. N+1 yo'q: kategoriyalar + taomlar 2 ta so'rov.
        $categories = Category::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->with(['products' => fn ($q) => $q->withoutGlobalScopes()->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $categories->map(fn (Category $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'products' => $c->products->map(fn (Product $p): array => $this->present($p))->all(),
        ])->all()]);
    }

    /** PATCH /kitchen/products/{id}/sold-out — `sold_out` true = «Tugadi», false = «Bor». Holatni aniq aytadi (toggle emas). */
    public function update(Request $request, int $productId): JsonResponse
    {
        $staff = $this->staff();
        $data = $request->validate(['sold_out' => ['required', 'boolean']]);

        $product = Product::query()->withoutGlobalScopes()->find($productId);
        $restaurantId = $product === null
            ? null
            : Category::query()->withoutGlobalScopes()->whereKey($product->category_id)->value('restaurant_id');

        // Yo'q taom ham, begona restoran taomi ham bir xil 404: boshqa restoranda shunday id borligi bilinmasin.
        abort_unless($product !== null && $staff->canManageRestaurant($restaurantId !== null ? (int) $restaurantId : null), Response::HTTP_NOT_FOUND);

        $this->stock->setSoldOut($product, (bool) $data['sold_out']);

        return response()->json(['data' => $this->present($product->refresh())]);
    }

    /** @return array{id: int, name: string, stock: int|null, daily_stock: int|null, sold_out: bool, hidden: bool} */
    private function present(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'stock' => $p->stock_quantity,
            'daily_stock' => $p->daily_stock,
            'sold_out' => $p->isSoldOut(),
            // is_available = false: restoran panelida yashirilgan — Mini App'da umuman ko'rinmaydi (oshxona tugmasi buni o'zgartirmaydi).
            'hidden' => ! $p->is_available,
        ];
    }
}
