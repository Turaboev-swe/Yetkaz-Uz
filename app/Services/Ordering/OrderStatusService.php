<?php

namespace App\Services\Ordering;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyurtma statusini bir qadam oldinga suradi (oshxona paneli).
 *
 *   yetkazish:  new -> accepted -> preparing -> on_the_way -> delivered
 *   olib ketish: new -> accepted -> preparing -> delivered ("Mijoz oldi")
 *
 * Har o'zgarishda: tarix yozuvi, timestamp, Reverb hodisasi, mijozga bot xabari.
 */
class OrderStatusService
{
    public function advance(Order $order, string $changedBy): Order
    {
        $next = $order->delivery_type->isPickup() && $order->status === OrderStatus::Preparing
            ? OrderStatus::Delivered
            : $order->status->next();

        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Bu buyurtma allaqachon yakunlangan.']);
        }

        return $this->transition($order, $next, $changedBy);
    }

    public function transition(Order $order, OrderStatus $to, string $changedBy): Order
    {
        DB::transaction(function () use ($order, $to, $changedBy) {
            $order->status = $to;

            match ($to) {
                OrderStatus::OnTheWay => $order->dispatched_at = now(),
                OrderStatus::Delivered => $order->delivered_at = now(),
                OrderStatus::Cancelled => $order->cancelled_at = now(),
                default => null,
            };

            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $to->value,
                'changed_by' => $changedBy,
                'changed_at' => now(),
            ]);
        });

        OrderStatusChanged::dispatch($order->id, $order->restaurant_id, $to);
        NotifyCustomerOfStatusChange::dispatch($order->id);

        return $order;
    }
}
