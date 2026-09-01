<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Ordering\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
