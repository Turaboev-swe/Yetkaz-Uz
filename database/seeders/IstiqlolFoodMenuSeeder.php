<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsRestaurantMenu;
use Illuminate\Database\Seeder;

/**
 * Istiqlol Food — Qo'rg'ontepa tumani. Fast food: burger, sendvich, hot dog,
 * tovuq, snek, ichimlik. Narxlar 15 000–40 000 so'm oralig'ida taqsimlangan
 * (souslar va ba'zi ichimliklar tabiiy ravishda pastroq).
 */
class IstiqlolFoodMenuSeeder extends Seeder
{
    use SeedsRestaurantMenu;

    public function run(): void
    {
        $restaurant = $this->upsertRestaurant('Istiqlol Food', 72.8214, [
            'min_order_amount' => 2_000_000,
        ]);

        $stats = $this->rebuildMenu($restaurant, [
            'Burger' => [
                ['Klassik cheeseburger', 28_000, 10, 69, 'Mol go\'shti kotleti, cheddar, pomidor va salat, kunjutli bulochkada.'],
                ['Achchiq cheeseburger', 30_000, 10, 4, 'Jalapeño, achchiq sous va cheddar bilan.'],
                ['Katta cheeseburger', 38_000, 11, 5, 'Qo\'sh kotlet, ikki bo\'lak pishloq, pomidor va salat.'],
            ],
            'Sendvich' => [
                ['Mol go\'shtli sendvich', 33_000, 10, 30, 'Tolali qovurilgan mol go\'shti, cheddar va BBQ sous uzun bulochkada.'],
                ['Tovuqli sendvich', 28_000, 10, 31, 'Grill tovuq, ko\'kat va sous uzun bulochkada.'],
                ['Kolbasali sendvich', 30_000, 10, 58, 'Bir nechta grill sosiska, bekon, pishloq sousi va tuzlangan bodring.'],
            ],
            'Hot Dog' => [
                ['Hot Dog Oddiy', 15_000, 8, 62, 'Klassik hot dog: sosiska, gorchitsa va ketchup.'],
                ['Hot Dog Achchiq', 22_000, 8, 60, 'Achchiq chili sous, cheddar va bekon bo\'lakchalari bilan.'],
            ],
            'Tovuq' => [
                ['Grill tovuq (butun)', 40_000, 15, 26, 'Butun tovuq, ziravorlar va limon bilan pishirilgan.'],
                ['Tovuq bo\'lakchalari', 25_000, 13, 7, 'Qovurilgan tovuq bo\'lakchalari, sous bilan.'],
            ],
            'Snek' => [
                ['Fri kartoshka', 15_000, 8, 8, 'To\'g\'ri kesilgan qarsildoq fri, dengiz tuzi bilan.'],
                ['Piyoz halqalari', 18_000, 8, 2, 'Panirlangan piyoz halqalari, oq sous bilan.'],
                ['Kartoshka wedges', 18_000, 9, 51, 'Po\'sti bilan ziravorlangan kartoshka bo\'laklari.'],
            ],
            'Ichimlik' => [
                ['Gazli ichimlik', 8_000, 2, 6, '0.5 l gazli ichimlik.'],
                ['Shokoladli milkshake', 22_000, 4, 1, 'Qaymoq va shokolad bilan sovuq sut kokteyli.'],
                ['Kofe frappe', 20_000, 4, 20, 'Muzli kofe frappe, qaymoq bilan.'],
                ['Limonad', 15_000, 3, 22, 'Uy limonadi: limon, yalpiz va muz.'],
                ['Malinali limonad', 18_000, 3, 23, 'Yangi malinali limonad.'],
                ['O\'rik sharbati', 12_000, 3, 24, 'Yangi o\'rikdan tayyorlangan sharbat.'],
            ],
            'Sous' => [
                ['Souslar to\'plami', 6_000, 2, 56, 'Uchta sous: sarimsoqli, ketchup va ranch.'],
            ],
        ]);

        $this->ensureStaff($restaurant);

        $this->command->info(sprintf(
            'Istiqlol Food: %d taom, %d rasm bog\'landi.',
            $stats['products'],
            $stats['photos'],
        ));
    }
}
