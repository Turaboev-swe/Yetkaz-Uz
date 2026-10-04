<?php

namespace App\Services\Marketing;

use App\Models\Banner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class BannerFeed
{
    /**
     * Mini App karuseli uchun hozir ko'rsatiladigan bannerlar, `sort_order`
     * bo'yicha (teng bo'lsa — yaratilish tartibida).
     *
     * @return Collection<int, Banner>
     */
    public function current(?CarbonInterface $now = null): Collection
    {
        return Banner::query()
            ->visibleAt($now ?? now())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'image_path', 'target_type', 'restaurant_id']);
    }
}
