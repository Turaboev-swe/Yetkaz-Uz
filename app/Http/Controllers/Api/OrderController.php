<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeliveryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Ordering\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** POST /api/orders — savatдан buyurtma yaratadi. */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->place($request->user(), $request->validated());

        return (new OrderResource($order->load('restaurant')))
            ->response()
            ->setStatusCode(201);
    }

    /** POST /api/orders/estimate — rasmiylashtirish ekrani uchun ETA oraliği. */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'delivery_type' => ['required', Rule::enum(DeliveryType::class)],
            'address_id' => ['nullable', 'integer'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
        ]);

        $eta = $this->orders->estimateEta($request->user(), $data);

        return response()->json(['data' => [
            'eta_minutes' => $eta->minutes,
            'eta_low' => $eta->low,
            'eta_high' => $eta->high,
        ]]);
    }

    /** GET /api/orders/{order} — faqat so'rov egasining buyurtmasi. */
    public function show(Request $request, string $order): OrderResource
    {
        $model = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('restaurant')
            ->findOrFail($order);

        return new OrderResource($model);
    }
}
