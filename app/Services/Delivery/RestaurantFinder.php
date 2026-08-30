<?php

namespace App\Services\Delivery;

use App\Models\Address;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * Berilgan manzilга yetkazib bera oladigan restoranlar.
 *
 * Filtr (Claude.md):
 * - is_open = true VA joriy vaqt work_hours ichida
 * - masofa delivery_radius_km dan kichik (PostGIS ST_DWithin)
 * Radiusdan yoki ish vaqtidan tashqaridagi restoran umuman qaytmaydi.
 */
class RestaurantFinder
{
    /** @return Collection<int, Restaurant> */
    public function deliveringTo(Address $address): Collection
    {
        return Restaurant::query()
            ->select('restaurants.*')
            ->where('is_open', true)
            ->deliversTo($address->lat, $address->lng)
            ->withDistanceKm($address->lat, $address->lng)
            ->with('city')
            ->orderBy('distance_km')
            ->get()
            ->filter(fn (Restaurant $r) => $r->isOpenNow())
            ->values();
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
