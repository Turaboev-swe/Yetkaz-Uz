<?php

namespace App\Services\Delivery;

use App\Enums\BeyondFreeZoneDelivery;
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
 *
 * Royal Taxi rejimi (taxi): bepul zonadan tashqarida narx 0, lekin `paidToTaxi`
 * belgisi qo'yiladi — mijoz taksi haqini haydovchiga alohida to'laydi (ikki marta emas).
 * Qarang: quote().
 *
 * NATIJA DOIM BUTUN SO'M (eng yaqiniga yaxlitlanadi, 100 tiyinга karrali).
 * Tiyin aniqligida (3,271 km × 1 500 so'm = 4 906,50 so'm) total kasrli so'm
 * bo'lib, Mini App (Math.round) va PHP (intdiv) bir buyurtmaga 1 so'm farqli
 * summa ko'rsatardi.
 */
class DeliveryFeeCalculator
{
    /** @return int tiyinда, butun so'm */
    public function calculate(Restaurant $restaurant, DeliveryType $type, ?float $distanceKm): int
    {
        return $this->quote($restaurant, $type, $distanceKm)->fee;
    }

    /**
     * Yagona hisoblash nuqtasi: narx + "yetkazish haqi taksiga alohida" belgisi.
     *
     * Royal Taxi rejimi (`beyond_free_zone_delivery = taxi`) — faqat BEPUL RADIUS
     * belgilangan bo'lsa ma'noli:
     *   masofa <= bepul radius -> 0, belgisiz (oddiy bepul yetkazish);
     *   masofa >  bepul radius -> 0 va paidToTaxi = true (mijoz taksi haqini alohida to'laydi).
     * Bepul radius yo'q bo'lsa "bepul zona" tushunchasi yo'q — taksi rejimi e'tiborga
     * olinmaydi va oddiy (km/qat'iy) narx ishlaydi (/admin formasi ham shuni talab qiladi).
     * Maksimal yetkazish radiusi (delivery_radius_km) bu yerda emas — RestaurantFinder'da.
     */
    public function quote(Restaurant $restaurant, DeliveryType $type, ?float $distanceKm): DeliveryQuote
    {
        if ($type->isPickup()) {
            return new DeliveryQuote(0);
        }

        $km = (float) ($distanceKm ?? 0);

        if ($restaurant->free_delivery_radius_km !== null && $km <= (float) $restaurant->free_delivery_radius_km) {
            return new DeliveryQuote(0);
        }

        if ($restaurant->free_delivery_radius_km !== null
            && $restaurant->beyond_free_zone_delivery === BeyondFreeZoneDelivery::Taxi) {
            return new DeliveryQuote(0, paidToTaxi: true);
        }

        if ($restaurant->price_per_km !== null) {
            return new DeliveryQuote(self::wholeSom($km * $restaurant->price_per_km));
        }

        return new DeliveryQuote(self::wholeSom((int) $restaurant->delivery_fee));
    }

    /** Tiyin -> eng yaqin butun so'm (tiyinда). 4 906,50 so'm -> 4 907 so'm. */
    private static function wholeSom(int|float $tiyin): int
    {
        return (int) round($tiyin / 100) * 100;
    }
}
