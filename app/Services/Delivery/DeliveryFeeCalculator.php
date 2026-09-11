<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryType;
use App\Models\Restaurant;

/**
 * Yetkazish narxi — masofaga qarab (ixtiyoriy). Yagona manba: OrderService::place()
 * (haqiqiy buyurtma) va /api/orders/estimate (rasmiylashtirish ekrani) ikkalasi
 * ham shu klassni chaqiradi — narx hech qachon ikki xil hisoblanmaydi.
 *
 * Mantiq (Claude.md / topshiriq spetsifikatsiyasi):
 *
 *   pickup                                                      -> 0
 *   free_delivery_radius_km TO'LDIRILGAN va masofa <= shu radius -> 0
 *   price_per_km TO'LDIRILGAN                                    -> round(masofa_km * price_per_km)
 *   aks holda                                                    -> restaurant.delivery_fee (ZAXIRA)
 *
 * `free_delivery_radius_km` bor-u `price_per_km` yo'q bo'lsa: radius ichida
 * bepul, undan tashqarida qat'iy `delivery_fee` (zaxira qatoriga tushadi —
 * bu ataylab shunday, spetsifikatsiya pseudokodidan to'g'ridan-to'g'ri kelib
 * chiqadi). `price_per_km` bor-u `free_delivery_radius_km` yo'q bo'lsa: har
 * qanday masofada (hatto 0 km da ham) km narxi ishlaydi.
 *
 * Restoran yangi maydonlarni umuman to'ldirmagan bo'lsa (ikkalasi ham null) —
 * eski qat'iy narx o'zgarishsiz ishlaydi (regressiya yo'q).
 */
class DeliveryFeeCalculator
{
    /** @return int tiyinda */
    public function calculate(Restaurant $restaurant, DeliveryType $type, ?float $distanceKm): int
    {
        if ($type->isPickup()) {
            return 0;
        }

        $km = (float) ($distanceKm ?? 0);

        if ($restaurant->free_delivery_radius_km !== null && $km <= (float) $restaurant->free_delivery_radius_km) {
            return 0;
        }

        if ($restaurant->price_per_km !== null) {
            return (int) round($km * $restaurant->price_per_km);
        }

        return (int) $restaurant->delivery_fee;
    }
}
