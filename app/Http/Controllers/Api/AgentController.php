<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Dispatch\ReceiptFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oshxona kompyuteridagi print agent uchun (browser emas, sessiyasiz).
 * Autentifikatsiya: `Authorization: Bearer <restaurants.print_agent_token>`.
 */
class AgentController extends Controller
{
    /**
     * Pusher/Reverb private-kanal imzosi — agent `restaurant.{id}.print` ga
     * obuna bo'lishi uchun.
     */
    public function broadcastAuth(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant($request);

        $channel = (string) $request->input('channel_name');
        $socketId = (string) $request->input('socket_id');

        if ($channel !== "private-restaurant.{$restaurant->id}.print") {
            abort(Response::HTTP_FORBIDDEN);
        }

        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        $signature = hash_hmac('sha256', "{$socketId}:{$channel}", $secret);

        return response()->json(['auth' => "{$key}:{$signature}"]);
    }

    /**
     * Chop etilmagan buyurtmalar — agent ulanганда (yoki qayta ulanганда) so'raydi.
     * Agent offline paytida broadcast yo'qolган bo'lsa ham buyurtma tiklanadi.
     */
    public function pending(Request $request, ReceiptFormatter $formatter): JsonResponse
    {
        $restaurant = $this->restaurant($request);

        $orders = Order::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('printed_at')
            ->where('created_at', '>=', now()->subHours(3))
            ->with('user')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(function (Order $order) use ($formatter) {
                $receipt = $formatter->format($order);

                return [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'escpos' => base64_encode($receipt['escpos']),
                    'text' => $receipt['text'],
                ];
            }),
        ]);
    }

    /** Chek chiqarilгач agent chaqiradi — `orders.printed_at` to'ldiriladi. */
    public function confirmPrinted(Request $request, string $order): JsonResponse
    {
        $restaurant = $this->restaurant($request);

        $model = Order::withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->findOrFail($order);

        if ($model->printed_at === null) {
            $model->forceFill(['printed_at' => now(), 'dispatch_failed_at' => null])->save();
        }

        return response()->json(['ok' => true, 'printed_at' => $model->printed_at?->toIso8601String()]);
    }

    private function restaurant(Request $request): Restaurant
    {
        $token = $request->bearerToken();

        abort_if(blank($token), Response::HTTP_UNAUTHORIZED);

        return Restaurant::query()
            ->where('print_agent_token', $token)
            ->firstOr(fn () => abort(Response::HTTP_FORBIDDEN));
    }
}
