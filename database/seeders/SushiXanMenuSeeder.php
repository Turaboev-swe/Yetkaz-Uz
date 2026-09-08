<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsRestaurantMenu;
use Illuminate\Database\Seeder;

/**
 * Sushi Xan — Qo'rg'ontepa tumani. Yapon oshxonasi: rolllar, nigiri, setlar.
 * Rasmsiz (photo_url = null). Nigiri narxi 1 dona uchun.
 */
class SushiXanMenuSeeder extends Seeder
{
    use SeedsRestaurantMenu;

    public function run(): void
    {
        $restaurant = $this->upsertRestaurant('Sushi Xan', 72.8097, [
            'min_order_amount' => 5_000_000,
        ]);

        $stats = $this->rebuildMenu($restaurant, [
            'Rolllar' => [
                ['Filadelfiya roll', 48_000, 15, null, 'Losos, krem-pishloq va bodring bilan mashhur roll.'],
                ['Kaliforniya roll', 42_000, 15, null, 'Krab go\'shti, avokado, bodring va tobiko ikrasi.'],
                ['Spaysi tuna roll', 52_000, 15, null, 'Achchiq sousli tunes va bodring.'],
                ['Yasay roll (vegetarian)', 35_000, 13, null, 'Avokado, bodring va bolgar qalampiri — vegetarian roll.'],
                ['Unagi roll', 55_000, 15, null, 'Marinadlangan ilonbaliq (unagi) va unagi sousi.'],
            ],
            'Nigiri' => [
                ['Nigiri Losos', 10_000, 10, null, 'Yangi losos bo\'lagi guruch ustida (1 dona).'],
                ['Nigiri Tunets', 12_000, 10, null, 'Tunes bo\'lagi guruch ustida (1 dona).'],
                ['Nigiri Krevetka', 8_000, 10, null, 'Qaynatilgan qisqichbaqa guruch ustida (1 dona).'],
            ],
            'Setlar' => [
                ['Set №1 (20 dona)', 120_000, 15, null, '20 bo\'lakli aralash set: filadelfiya, kaliforniya va boshqalar.'],
                ['Set №2 (32 dona)', 180_000, 15, null, '32 bo\'lakli katta set — kompaniya uchun.'],
            ],
            'Ichimlik' => [
                ['Yashil choy', 8_000, 4, null, 'Issiq yapon yashil choyi.'],
                ['Gazli ichimlik', 8_000, 3, null, '0.5 l gazli ichimlik.'],
            ],
        ]);

        $this->ensureStaff($restaurant);

        $this->command->info(sprintf(
            'Sushi Xan: %d taom, %d rasm bog\'landi.',
            $stats['products'],
            $stats['photos'],
        ));
    }
}
