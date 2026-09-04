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
use Illuminate\Validation\Rule;
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

    /** GET /kitchen/couriers — "Yo'lga chiqdi" dropdown'i uchun o'z restorani xodimlari. */
    public function couriers(): JsonResponse
    {
        $list = Staff::query()
            ->where('restaurant_id', $this->staff()->restaurant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        return response()->json(['data' => $list]);
    }

    /** PATCH /kitchen/orders/{order}/advance — statusni keyingi bosqichga. */
    public function advance(Request $request, Order $order): JsonResponse
    {
        $staff = $this->staff();

        abort_unless($order->restaurant_id === $staff->restaurant_id, Response::HTTP_FORBIDDEN);

        // "Yo'lga chiqdi" da kuryer sifatida o'sha restoran xodimi tanlanishi mumkin (ixtiyoriy).
        $data = $request->validate([
            'courier_staff_id' => [
                'nullable', 'integer',
                Rule::exists('staff', 'id')->where(fn ($q) => $q
                    ->where('restaurant_id', $order->restaurant_id)
                    ->where('is_active', true)),
            ],
        ]);

        $fill = [];
        if (! empty($data['courier_staff_id'])) {
            $courier = Staff::findOrFail($data['courier_staff_id']);
            $fill = [
                'courier_staff_id' => $courier->id,
                'courier_name' => $courier->name,
                'courier_phone' => $courier->phone,   // tanlangan paytdagi snapshot
            ];
        }

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
