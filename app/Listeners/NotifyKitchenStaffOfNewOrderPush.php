<?php

namespace App\Listeners;

use App\Enums\StaffRole;
use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\Staff;
use App\Notifications\NewKitchenOrderPushNotification;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Yangi buyurtma — brauzer push bildirishnomasi bilan ham (Web Push).
 * NotifyKitchenStaffOfNewOrder (Telegram) va Reverb ovoz signaliga QO'SHIMCHA
 * kanal — biri ikkinchisini almashtirmaydi, planshet qulflangan/sahifa yopiq
 * bo'lsa ham yetib boradi. Faqat push'ga OBUNA BO'LGAN xodimlarga yuboriladi.
 * Eskirgan obuna (410/404) — paket o'zi avtomatik o'chiradi (ReportHandler).
 */
class NotifyKitchenStaffOfNewOrderPush implements ShouldQueue
{
    public int $tries = 2;

    public int $backoff = 20;

    public function handle(OrderPlaced $event): void
    {
        $order = Order::withoutGlobalScopes()->find($event->orderId);

        if ($order === null) {
            return;
        }

        $recipients = Staff::query()
            ->where('restaurant_id', $order->restaurant_id)
            ->where('is_active', true)
            ->whereIn('role', [StaffRole::KitchenStaff->value, StaffRole::RestaurantOwner->value])
            ->whereHas('pushSubscriptions')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $itemsCount = count($order->items ?? []);
        $summary = "{$itemsCount} ta taom, ".Money::soms($order->total);

        Notification::send($recipients, new NewKitchenOrderPushNotification($order->order_number, $summary));
    }
}
