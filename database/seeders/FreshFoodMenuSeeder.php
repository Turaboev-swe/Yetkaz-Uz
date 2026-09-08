<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsRestaurantMenu;
use Illuminate\Database\Seeder;

/**
 * Fresh Food — Qo'rg'ontepa tumani. Salomatlik segmenti: salatlar, smuzi, lavash.
 * Restoran QorgontepaSeeder'da yaratilgan; bu seeder menyusini to'liq qayta quradi.
 *
 * Narxlar 25 000–45 000 so'm oralig'ida taqsimlangan. Ayron va mineral suv —
 * realistik narxda (12 000 / 6 000); ular "taom" emas.
 */
class FreshFoodMenuSeeder extends Seeder
{
    use SeedsRestaurantMenu;

    public function run(): void
    {
        $restaurant = $this->upsertRestaurant('Fresh Food', 72.7629, [
            'min_order_amount' => 2_500_000,
        ]);

        $stats = $this->rebuildMenu($restaurant, [
            'Salatlar' => [
                ['Yunon salati', 32_000, 7, 17, 'Yangi pomidor, bodring, qizil piyoz, feta pishlog\'i va zaytun bilan yunoncha salat.'],
                ['Sezar salat', 35_000, 7, 18, 'Rim salati, non krutonlari, parmezan va sezar sousi bilan.'],
                ['Tovuq-avokado salat', 42_000, 7, 16, 'Grill tovuq filesi, avokado, cherri pomidor va yashil barglar.'],
            ],
            'Smuzi & Ichimlik' => [
                ['Mango smuzi', 28_000, 4, 13, 'Yangi mangodan tayyorlangan quyuq smuzi.'],
                ['Ayron', 12_000, 3, 21, 'Uy uslubidagi sho\'r ayron, yalpiz bilan.'],
                ['Mineral suv', 6_000, 2, 25, '0.5 l gazsiz mineral suv.'],
            ],
            'Lavash' => [
                ['Sabzavotli lavash', 26_000, 9, 14, 'Yangi sabzavotlar, ko\'katlar va sous yupqa lavashda — vegetarian.'],
                ['Tovuqli lavash', 33_000, 9, 15, 'Grill tovuq, sabzavot va sous yupqa lavashda.'],
                ['Mol go\'shtli lavash', 38_000, 9, 36, 'Grill mol go\'shti, sabzavot va sous yupqa lavashda.'],
            ],
        ]);

        $this->ensureStaff($restaurant);

        $this->command->info(sprintf(
            'Fresh Food: %d taom, %d rasm bog\'landi.',
            $stats['products'],
            $stats['photos'],
        ));
    }
}
