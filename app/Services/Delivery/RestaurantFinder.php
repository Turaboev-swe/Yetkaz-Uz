<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryType;
use App\Models\Address;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * Berilgan manzilга yetkazib bera oladigan (yoki olib ketish uchun yaqin)
 * restoranlar.
 *
 * Filtr (Claude.md):
 * - is_open = true VA joriy vaqt work_hours ichida
 * - masofa: yetkazishда har restoranning o'z delivery_radius_km'idan kichik
 *   (PostGIS ST_DWithin); olib ketishда esa restoran radiusi cheklov EMAS —
 *   platforma darajasidagi kengroq FIKS radius ishlatiladi
 *   (config('geo.pickup_radius_km')), chunki mijoz o'zi boradi.
 * Radiusdan yoki ish vaqtidan tashqaridagi restoran umuman qaytmaydi.
 *
 * Saralash (masofa filtridan keyin, ikkala rejimda ham):
 *   1. ochiq restoranlar oldinda (includeClosed rejimida)
 *   2. ommaviy reytingli ("Yangi" emas) restoranlar tepada — reyting bo'yicha kamayish
 *   3. qolganlari — masofa bo'yicha o'sish
 */
class RestaurantFinder
{
    /**
     * @param  int|null  $districtId  faqat shu tuman restoranlari (ko'rsatish filtri;
     *                                masofa/radius baribir lat/lng dan)
     * @param  bool  $includeClosed  Mini App ro'yxati uchun — yopiq restoranlarni ham
     *                               qaytaradi (ochiqlari yuqorida, keyin masofa bo'yicha).
     *                               Yopiqlar `is_open_now = false` bilan belgilanadi.
     * @param  DeliveryType  $type  delivery — restoranning o'z radiusi; pickup —
     *                              kengroq FIKS radius, restoran radiusi qo'llanmaydi.
     * @return Collection<int, Restaurant>
     */
    public function deliveringTo(
        Address $address,
        ?int $districtId = null,
        bool $includeClosed = false,
        DeliveryType $type = DeliveryType::Delivery,
    ): Collection {
        $restaurants = Restaurant::query()
            ->select('restaurants.*')
            ->when(! $includeClosed, fn ($q) => $q->where('is_open', true))
            ->when($districtId, fn ($q) => $q->where('district_id', $districtId))
            ->when(
                $type->isPickup(),
                fn ($q) => $q->withinKm($address->lat, $address->lng, (float) config('geo.pickup_radius_km')),
                fn ($q) => $q->deliversTo($address->lat, $address->lng),
            )
            ->withDistanceKm($address->lat, $address->lng)
            ->with('district.region')
            ->orderBy('distance_km')
            ->get();

        if (! $includeClosed) {
            $restaurants = $restaurants->filter(fn (Restaurant $r) => $r->isOpenNow())->values();
        }

        return $restaurants->sort($this->comparator(...))->values();
    }

    /** Ochiq oldinda -> reytingli oldinda -> reyting DESC -> masofa ASC. */
    private function comparator(Restaurant $a, Restaurant $b): int
    {
        return ($b->isOpenNow() <=> $a->isOpenNow())
            ?: ($b->hasPublicRating() <=> $a->hasPublicRating())
            ?: ($a->hasPublicRating() && $b->hasPublicRating()
                ? (float) $b->cached_average_rating <=> (float) $a->cached_average_rating
                : 0)
            ?: (float) $a->distance_km <=> (float) $b->distance_km;
    }

    /** Bitta restoran shu manzilга yetkazadimi (menyu endpointi uchun). */
    public function canDeliver(Restaurant $restaurant, Address $address): bool
    {
        if (! $restaurant->isOpenNow()) {
            return false;
        }

        return Restaurant::query()
            ->whereKey($restaurant->getKey())
            ->deliversTo($address->lat, $address->lng)
            ->exists();
    }

    public function distanceKm(Restaurant $restaurant, Address $address): ?float
    {
        $row = Restaurant::query()
            ->whereKey($restaurant->getKey())
            ->withDistanceKm($address->lat, $address->lng)
            ->first();

        return $row?->distance_km !== null ? round((float) $row->distance_km, 2) : null;
    }
}
