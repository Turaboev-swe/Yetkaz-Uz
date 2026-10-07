<?php

namespace App\Services\Marketing;

use App\Enums\BannerTarget;
use App\Models\Banner;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class BannerFeed
{
    /**
     * Mini App karuseli uchun hozir ko'rsatiladigan bannerlar, `sort_order`
     * bo'yicha (teng bo'lsa — yaratilish tartibida). Restoran banneri faqat
     * restoran HOZIR ochiq bo'lsa (is_open + work_hours) — ish vaqti jadvali
     * JSON'da, shuning uchun u PHP'da tekshiriladi (bazada faqat is_open).
     * Test restoran (is_test) banneri faqat TEST_TELEGRAM_IDS dagi hisoblarga
     * ($viewer null bo'lsa — hech kimga).
     *
     * @return Collection<int, Banner>
     */
    public function current(?CarbonInterface $now = null, ?User $viewer = null): Collection
    {
        $now ??= now();

        return Banner::query()
            ->visibleAt($now)
            ->with('restaurant:id,is_open,is_test,work_hours')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'image_path', 'target_type', 'restaurant_id'])
            ->filter(fn (Banner $banner) => $banner->target_type !== BannerTarget::Restaurant
                || ($banner->restaurant?->isOpenAt($now) && $banner->restaurant->isVisibleTo($viewer)))
            ->values();
    }
}
