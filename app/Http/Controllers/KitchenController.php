<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Resources\KitchenOrderResource;
use App\Models\Order;
use App\Models\Staff;
use App\Services\Ordering\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oshxona paneli (/kitchen) — session (staff guard) bilan himoyalangan.
 * Faqat o'z restorani buyurtmalari.
 */
class KitchenController extends Controller
{
    public function __construct(private readonly OrderStatusService $status) {}

    /** Sahifa (React ilova). */
    public function page()
    {
        return view('kitchen', ['staff' => $this->staff()]);
    }

    /** GET /kitchen/orders — faol buyurtmalar (eng eskisi birinchi). */
    public function orders(): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $this->staff()->restaurant_id)
            ->whereIn('status', OrderStatus::activeValues())
            ->with('user')
            ->orderBy('created_at')
            ->get();

        return KitchenOrderResource::collection($orders);
    }

    /** PATCH /kitchen/orders/{order}/advance — statusni keyingi bosqichga. */
    public function advance(Request $request, Order $order): JsonResponse
    {
        $staff = $this->staff();

        abort_unless($order->restaurant_id === $staff->restaurant_id, Response::HTTP_FORBIDDEN);

        // "Yo'lga chiqdi" bosilganда oshxona kuryer ismi/telefonini kiritishi mumkin (ixtiyoriy).
        $data = $request->validate([
            'courier_name' => ['nullable', 'string', 'max:100'],
            'courier_phone' => ['nullable', 'string', 'max:32'],
        ]);
        $fill = array_filter([
            'courier_name' => filled($data['courier_name'] ?? null) ? trim($data['courier_name']) : null,
            'courier_phone' => filled($data['courier_phone'] ?? null) ? trim($data['courier_phone']) : null,
        ], fn ($v) => $v !== null);

        $this->status->advance($order, "kitchen:{$staff->id}", $fill);

        return (new KitchenOrderResource($order->fresh('user')))->response();
    }

    private function staff(): Staff
    {
        $staff = auth('staff')->user();

        abort_unless($staff instanceof Staff && $staff->canManageKitchen(), Response::HTTP_FORBIDDEN);

        return $staff;
    }
}
