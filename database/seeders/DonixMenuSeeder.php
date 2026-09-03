<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

/**
 * Donix restorani — to'liq menyu (bir martalik, prod ma'lumot kiritish).
 *
 *   php artisan db:seed --class=DonixMenuSeeder --force
 *
 * Narxlar tiyinda (so'm * 100). `photo_url` bo'sh qoldiriladi — rasmlar keyin
 * alohida generatsiya qilinadi (`description` prompt uchun batafsil yozilgan).
 * `updateOrCreate` — qayta ishga tushirilsa dublikat bo'lmaydi.
 *
 * DatabaseSeeder ga ULANMAGAN (bu bitta real restoran ma'lumoti).
 */
class DonixMenuSeeder extends Seeder
{
    private const PREP = [
        'LAVASH' => 10, 'HAGGI' => 10, 'NONBURGER' => 10, 'SENDVICH' => 9,
        'LONGER' => 9, 'HOT DOG' => 8, 'BURGER' => 10, 'SNEKLAR' => 5,
        'SETLAR (COMBO)' => 12, "SOUSLAR VA QO'SHIMCHALAR" => 3,
    ];

    /** @var array<string, list<array{string, int, string}>>  [nom, narx_som, tavsif] */
    private const MENU = [
        'LAVASH' => [
            ['Lavash', 32_000, "Yupqa lavash non ichida qovurilgan mol go'shti bo'laklari, yangi bodring va pomidor, to'g'ralgan karam, kartoshka fri va sarimsoqli oq sous bilan zich o'ralgan."],
            ['Tovuqli', 28_000, "Yupqa lavash non ichida qovurilgan tovuq filesi bo'laklari, yangi bodring, pomidor, karam, kartoshka fri va oq sous bilan o'ralgan."],
            ['Mini', 22_000, "Kichik o'lchamli lavash: qovurilgan mol go'shti, yangi sabzavot, kartoshka fri va sarimsoqli sous yupqa nonda o'ralgan."],
            ['Tovuqli Mini', 20_000, "Kichik lavash: qovurilgan tovuq filesi, yangi sabzavot (bodring, pomidor, karam), kartoshka fri va oq sous yupqa nonda."],
        ],
        'HAGGI' => [
            ['Mini', 25_000, "Kichik haggi: qat-qat yupqa xamir ichida go'sht va sabzavot qatlami, tovada tillarang qarsildoq qobiqqacha qovurilgan."],
            ['Katta', 40_000, "Katta haggi: qatlamli yupqa xamir ichida ko'p miqdorda qovurilgan go'sht, eritilgan pishloq va sabzavot, tashqarisi qarsildoq."],
        ],
        'NONBURGER' => [
            ['Nonburger (mosh po\'stloq)', 30_000, "Maxsus mosh unli qobiqli yumaloq non ichida qovurilgan mol go'shti kotleti, to'g'ralgan karam, bodring, pomidor va burger sous."],
            ['Nonburger (sir/pishloqli)', 31_000, "Yumshoq yumaloq non ichida mol go'shti kotleti, eritilgan sarg'ish pishloq bo'lagi, yangi sabzavot va sous."],
        ],
        'SENDVICH' => [
            ['Sendvich', 36_000, "Uzun bulochka ichida qovurilgan mol go'shti va tovuq bo'laklari, eritilgan pishloq, to'g'ralgan karam, bodring, pomidor va aralash sous."],
        ],
        'LONGER' => [
            ['Standart', 22_000, "Uzunchoq bulochka ichida uzun go'shtli kotlet, marinadlangan bodring, to'g'ralgan karam va ketchup-mayonez sous."],
            ['Katta', 31_000, "Katta longer: ikkita uzun go'shtli kotlet, eritilgan pishloq, sabzavot va sous uzunchoq nonda."],
        ],
        'HOT DOG' => [
            ['Canada', 12_000, "Klassik hot-dog: uzun yumshoq bulochka ichida issiq sosiska, ketchup va xantal sous."],
            ['Canada pishloqli', 21_000, "Hot-dog: bulochka ichida issiq sosiska va eritilgan pishloq, ketchup hamda xantal bilan."],
            ['Hot dog 2x', 15_000, "Ikki sosiskali hot-dog: uzun bulochka ichida yonma-yon ikkita sosiska, ketchup va xantal sous."],
            ['Katta hot dog 5x', 25_000, "Katta hot-dog: uzun bulochka ichida beshta sosiska qatori, ketchup, xantal va qovurilgan piyoz."],
        ],
        'BURGER' => [
            ['Gamburger', 33_000, "Klassik gamburger: yumaloq kunjutli bulochka ichida mol go'shti kotleti, marinadlangan bodring, piyoz halqalari, pomidor, karam va burger sous."],
            ['Chizburger', 36_000, "Chizburger: mol go'shti kotleti va eritilgan sarg'ish pishloq bo'lagi, bodring, piyoz, pomidor va sous yumaloq bulochkada."],
            ['Dabl-Burger', 46_000, "Ikkita mol go'shti kotleti, ikki qavat marinadlangan bodring va piyoz, pomidor, karam va sous katta bulochkada."],
            ['Dabl-Chizburger', 49_000, "Ikkita go'shtli kotlet, ikkita eritilgan pishloq bo'lagi, bodring, piyoz, pomidor va burger sous."],
            ['Mega Burger', 49_000, "Katta burger: qalin mol go'shti kotleti, ikki xil pishloq, bekon, qovurilgan piyoz halqalari va maxsus sous."],
            ['Chicken Zinger', 30_000, "Achchiq qadoqda qovurilgan qarsildoq tovuq filesi, to'g'ralgan karam, marinadlangan bodring va achchiqroq oq sous yumaloq bulochkada."],
        ],
        'SNEKLAR' => [
            ['Nuggets', 16_000, "Tovuq go'shtidan tayyorlangan qarsildoq qobiqli nagetslar (6-8 dona), sous bilan."],
            ['Strips', 15_000, "Ziravorli qarsildoq qobiqda qovurilgan uzun tovuq file bo'laklari (3-4 dona), sous bilan."],
            ['Dvojka', 12_000, "Ikki turdagi snek to'plami: nagets va strips aralashmasi, bitta sous bilan."],
            ['Fri', 13_000, "Klassik kartoshka fri: tuzlangan, oltinrang qovurilgan uzun kartoshka bo'laklari."],
            ['Derevenskiy', 14_000, "Qishloqcha kartoshka: po'stlog'i bilan bo'laklangan, ziravorda qovurilgan kartoshka tilimlari."],
        ],
        'SETLAR (COMBO)' => [
            ['Combo 3x', 130_000, "Katta to'plam: uchta asosiy taom (burger yoki lavash), uchta kartoshka fri va uchta salqin ichimlik."],
            ['Combo Sendvich', 40_000, "Bitta sendvich, kartoshka fri va salqin ichimlik to'plami."],
            ['Combo Lavash', 48_000, "Bitta lavash, kartoshka fri va salqin ichimlik to'plami."],
            ['Combo Burger', 50_000, "Bitta burger, kartoshka fri va salqin ichimlik to'plami."],
            ['Combo Nonburger', 95_000, "Ikkita nonburger, ikkita kartoshka fri va ikkita salqin ichimlik to'plami."],
            ['Combo Longer', 79_000, "Ikkita longer, ikkita kartoshka fri va ikkita salqin ichimlik to'plami."],
        ],
        "SOUSLAR VA QO'SHIMCHALAR" => [
            ['Souslar (Pishloqli, Ketchup, Sarimsoqli)', 3_000, "Qo'shimcha sous porsiyasi kichik idishda: pishloqli, ketchup yoki sarimsoqli oq sous (tanlov bo'yicha)."],
            ['Tuzlangan qalampir', 3_000, "Marinadlangan achchiq yashil qalampir porsiyasi."],
        ],
    ];

    public function run(): void
    {
        $donix = Restaurant::query()->where('name', 'Donix')->sole();

        $catSort = 0;

        foreach (self::MENU as $categoryName => $products) {
            $category = Category::updateOrCreate(
                ['restaurant_id' => $donix->id, 'name' => $categoryName],
                ['sort_order' => $catSort++, 'is_active' => true],
            );

            $pSort = 0;

            foreach ($products as [$name, $som, $description]) {
                Product::updateOrCreate(
                    ['category_id' => $category->id, 'name' => $name],
                    [
                        'description' => $description,
                        'price' => $som * 100, // so'm -> tiyin
                        'prep_time_min' => self::PREP[$categoryName],
                        'is_available' => true,
                        'sort_order' => $pSort++,
                    ],
                );
            }
        }

        $cats = Category::where('restaurant_id', $donix->id)->count();
        $prods = Product::whereIn('category_id', Category::where('restaurant_id', $donix->id)->pluck('id'))->count();
        $this->command->info("Donix menyusi: {$cats} kategoriya, {$prods} taom.");
    }
}
