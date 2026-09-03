<?php

namespace App\Services\Eta;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Yetkazib berish vaqti (Claude.md formulasi):
 *
 *   ETA = pishirish + navbat_jarimasi + kuryer_kutish + yol_vaqti + bufer
 *
 *   pishirish       = max(savatdagi taomlarning prep_time_min)   (parallel)
 *   navbat_jarimasi = min(restorandagi faol buyurtmalar * 2, 20)
 *   kuryer_kutish   = 5   (pickup: 0 — kuryer yo'q)
 *   yol_vaqti       = (yo'l_masofasi_km / tezlik) * 60
 *                     tezlik = 22 (07:30–10:00, 17:00–20:00), aks holda 28
 *                     yo'l_masofasi ≈ to'g'ri chiziq * 1.35 (OSRM keyin qo'shiladi)
 *   bufer           = 5
 *
 * Mijozga aniq raqam emas, oraliq ko'rsatiladi: minutes-5 … minutes+10 (5 ga yaxlit).
 */
class EtaEstimator
{
    private const COURIER_WAIT = 5;

    private const BUFFER = 5;

    private const ROAD_FACTOR = 1.35;

    public function estimate(
        Restaurant $restaurant,
        DeliveryType $type,
        ?float $straightLineKm,
        int $maxPrepMin,
        ?CarbonInterface $at = null,
    ): EtaEstimate {
        $at = $at
            ? CarbonImmutable::instance($at)
            : CarbonImmutable::now(config('app.display_timezone'));

        $prep = max(1, $maxPrepMin);
        $queue = min($this->activeOrders($restaurant) * 2, 20);

        if ($type->isPickup()) {
            $courier = 0;
            $travel = 0;
        } else {
            $courier = self::COURIER_WAIT;
            $roadKm = (float) ($straightLineKm ?? 0) * self::ROAD_FACTOR;
            $speed = $this->isPeak($at) ? 22 : 28;
            $travel = (int) round($roadKm / $speed * 60);
        }

        $minutes = max(10, $prep + $queue + $courier + $travel + self::BUFFER);

        return EtaEstimate::fromMinutes($minutes);
    }

    private function activeOrders(Restaurant $restaurant): int
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', OrderStatus::activeValues())
            ->count();
    }

    /** Band vaqt: 07:30–10:00 va 17:00–20:00 (Asia/Tashkent). */
    private function isPeak(CarbonInterface $at): bool
    {
        $minuteOfDay = $at->hour * 60 + $at->minute;

        return ($minuteOfDay >= 450 && $minuteOfDay < 600)
            || ($minuteOfDay >= 1020 && $minuteOfDay < 1200);
    }
}
