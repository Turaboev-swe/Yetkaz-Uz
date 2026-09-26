<?php

namespace App\Console\Commands;

use App\Jobs\EstimateProductNutrition;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Mavjud taomlar uchun kaloriya taxminini bir martalik navbatga qo'yadi.
 * Faqat hali taxmin qilinmagan (nutrition_status = null) taomlar —
 * tasdiqlangan / yashirilgan / kutayotganlarga tegmaydi.
 *
 * Job'lar --delay soniya oralatib qo'yiladi (API limitini band qilmaslik
 * uchun, sekin). Natijalar pending holatda — egasi panelda tasdiqlaydi.
 *
 *   php artisan products:estimate-nutrition
 *   php artisan products:estimate-nutrition --restaurant=12 --delay=3
 */
class EstimateProductsNutrition extends Command
{
    protected $signature = 'products:estimate-nutrition
        {--restaurant= : Faqat shu restoran taomlari}
        {--delay=2 : Job\'lar orasidagi soniya}';

    protected $description = 'Mavjud taomlar kaloriyasini AI bilan taxminlash (navbat orqali, natija tasdiq kutadi)';

    public function handle(): int
    {
        if (! EstimateProductNutrition::enabled()) {
            $this->error('ANTHROPIC_API_KEY sozlanmagan — funksiya o\'chiq.');

            return self::FAILURE;
        }

        $query = Product::withoutGlobalScopes()
            ->whereNull('nutrition_status')
            ->orderBy('id');

        if ($restaurantId = $this->option('restaurant')) {
            $query->forRestaurant((int) $restaurantId);
        }

        $delay = max(0, (int) $this->option('delay'));
        $count = 0;

        $query->each(function (Product $product) use (&$count, $delay) {
            EstimateProductNutrition::dispatchFor($product, $count * $delay);
            $count++;
        });

        if ($count === 0) {
            $this->info('Taxmin kutayotgan taom yo\'q — ish yo\'q.');

            return self::SUCCESS;
        }

        $this->info("{$count} ta taom navbatga qo'yildi (~".ceil($count * $delay / 60).' daqiqa). Natijalar panelda "Tasdiq kutayotganlar" filtrida chiqadi.');

        return self::SUCCESS;
    }
}
