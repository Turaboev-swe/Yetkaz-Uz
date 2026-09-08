<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Restoranning keshlangan reytingini qayta hisoblaydi — `orders.rating`
 * to'ldirilgan barcha buyurtmalari bo'yicha o'rtacha va son.
 *
 * Yangi baho saqlangач ishga tushiriladi (RateOrderHandler). Bir restoran uchun
 * bir vaqtда bitta (ShouldBeUnique) — ketma-ket bosishлар navbatni to'ldirmaydi.
 */
class RecalculateRestaurantRating implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $restaurantId) {}

    public function uniqueId(): string
    {
        return (string) $this->restaurantId;
    }

    public function handle(): void
    {
        $restaurant = Restaurant::find($this->restaurantId);
        if ($restaurant === null) {
            return;
        }

        $stats = Order::withoutGlobalScopes()
            ->where('restaurant_id', $this->restaurantId)
            ->whereNotNull('rating')
            ->selectRaw('COUNT(*) AS c, AVG(rating) AS a')
            ->first();

        $count = (int) ($stats->c ?? 0);

        $restaurant->forceFill([
            'cached_ratings_count' => $count,
            'cached_average_rating' => $count > 0 ? round((float) $stats->a, 1) : null,
        ])->save();
    }
}
