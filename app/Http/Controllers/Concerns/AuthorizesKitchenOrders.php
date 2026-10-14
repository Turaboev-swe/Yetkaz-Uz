<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use App\Models\Staff;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oshxona paneli kontrollerlari uchun umumiy: joriy xodim va uning restoranidagi buyurtma.
 */
trait AuthorizesKitchenOrders
{
    /**
     * Buyurtma RestaurantScope'siz topiladi (egasining scope'i faqat asosiy
     * restoranni ko'radi), ruxsat esa xodimning biriktirilgan restoranlari
     * bo'yicha tekshiriladi. Biriktirilmagan restoran buyurtmasi — egasiga 404
     * (scope uni "ko'rmas" edi), oshxona xodimiga 403.
     */
    private function manageableOrder(Staff $staff, int $orderId): Order
    {
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);

        if (! $staff->canManageRestaurant($order->restaurant_id)) {
            abort($staff->isRestaurantOwner() ? Response::HTTP_NOT_FOUND : Response::HTTP_FORBIDDEN);
        }

        return $order;
    }

    private function staff(): Staff
    {
        $staff = auth('staff')->user();

        abort_unless($staff instanceof Staff && $staff->canManageKitchen(), Response::HTTP_FORBIDDEN);

        return $staff;
    }
}
