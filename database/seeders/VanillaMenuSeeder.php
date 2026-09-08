<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsRestaurantMenu;
use Illuminate\Database\Seeder;

/**
 * Vanilla — Qo'rg'ontepa tumani. To'liq menyu (foydalanuvchi tasdiqlagan narxlar).
 *
 * O'lchamli taomlarda (pitsa Kichik/O'rtacha/Katta, KFC porsiyalari, 2x hot dog)
 * asosiy narx eng kichik variant, qolganlari tavsifda ko'rsatilgan — hozircha
 * variant/opsiya tizimi yo'q.
 */
class VanillaMenuSeeder extends Seeder
{
    use SeedsRestaurantMenu;

    public function run(): void
    {
        $restaurant = $this->upsertRestaurant('Vanilla', 72.7980);

        // [nom, narx_som, tayyorlash_daq, rasm_NN|null, tavsif]
        $stats = $this->rebuildMenu($restaurant, [
            'Hot Dog' => [
                ['Hot Dog Oddiy', 13_000, 8, 34, "Klassik hot dog: yumshoq bulochkada sosiska va gorchitsa. 2x — 15 000 so'm."],
                ['Hot Dog Kanada', 18_000, 8, 32, "Karamellangan piyoz, pishloq sousi va gorchitsa bilan. 2x — 20 000 so'm."],
                ['Hot Dog Tovuqli', 25_000, 9, 33, 'Tovuqli sosiska, erigan pishloq, tuzlangan bodring va sabzavot bilan.'],
                ['Hot Dog Mol go\'shti', 28_000, 9, 59, 'Mol go\'shtli sosiskalar, cheddar sousi va qarsildoq qovurilgan piyoz bilan.'],
                ['Hot Dog Stripsli', 28_000, 9, 35, 'Tovuq stripslari, pishloq sousi va qarsildoq piyoz bilan.'],
                ['Hot Dog Evos', 22_000, 8, 61, 'Bekonli hot dog, qarsildoq qovurilgan piyoz va ketchup bilan.'],
                ['Hot Dog Dazili', 40_000, 10, 63, "To'ldirilgan gourmet hot dog: grill sosiska, erigan pishloq va qovurilgan piyoz. Pishloq qo'shish +3 000 so'm."],
            ],
            'Gamburger' => [
                ['Vanilla Burger', 50_000, 12, 70, 'Yirik mol go\'shti kotleti, karamellangan piyoz, rukkola va maxsus sous, brioche bulochkada.'],
                ['Gamburger Kotleti', 30_000, 10, 3, "Mol go'shtli kotlet, cheddar, pomidor va salat. 2x kotlet — 40 000 so'm."],
                ['Gamburger Tovuqli', 28_000, 10, 66, 'Qarsildoq qovurilgan tovuq filesi, salat va sous, brioche bulochkada.'],
                ['Gamburger Stripsli', 33_000, 10, 37, 'Ikki qatlam tovuq stripsi, pishloq sousi va salat.'],
                ['Club Sandwich', 35_000, 10, 67, 'Uch qavatli klub-sendvich: tovuq, pishloq, pomidor va salat.'],
                ['Non Kabob Tovuqli', 33_000, 10, 29, "O'zbek noni ichida grill tovuq, bodring, pomidor va sous. Pishloq qo'shish +3 000 so'm."],
                ['Non Kabob Mol go\'shti', 38_000, 11, 64, "To'ldirilgan non ichida qiyma mol go'shti va erigan pishloq. Pishloq qo'shish +3 000 so'm."],
            ],
            'Lavash' => [
                ['Lavash Roll Tovuqli', 33_000, 9, 10, 'Grill tovuq, sabzavot va sous yupqa lavashda o\'ralgan.'],
                ['Lavash Roll Mol go\'shti', 38_000, 9, 11, 'Grill mol go\'shti, sabzavot va sous yupqa lavashda.'],
                ['Meksikanskiy Tovuqli', 30_000, 9, 15, 'Achchiq meksika uslubi: tovuq, qalampir va achchiq sous.'],
                ['Meksikanskiy Mol go\'shti', 35_000, 9, 36, 'Achchiq meksika uslubi: mol go\'shti, qalampir va achchiq sous.'],
                ['Arabskiy Tovuqli', 33_000, 9, null, 'Arab uslubidagi lavash: tovuq, sabzavot va oq sous.'],
                ['Arabskiy Mol go\'shti', 38_000, 9, 38, 'Arab uslubidagi lavash: mol go\'shti, sabzavot va oq sous.'],
                ['Lavash Stripsli', 38_000, 9, 9, 'Tovuq stripslari, sabzavot va sous lavashda.'],
                ['Tandir Lavash Tovuqli', 38_000, 10, null, 'Tandirda pishirilgan nonda tovuqli lavash.'],
                ['Tandir Lavash Mol go\'shti', 40_000, 10, null, 'Tandirda pishirilgan nonda mol go\'shtli lavash.'],
            ],
            'Vanilla Donar & Setlar' => [
                ['Tovuqli Set Donar', 50_000, 12, null, 'Tovuqli donar to\'plami: donar, fri kartoshka va ichimlik.'],
                ['Mol Go\'sht Donar', 65_000, 12, null, 'Mol go\'shtli donar to\'plami.'],
                ['Vanilla Danar Tovuq go\'shtli', 38_000, 10, null, 'Tovuq go\'shtli vanilla donar.'],
                ['Vanilla Danar Mol go\'shtli', 43_000, 10, null, 'Mol go\'shtli vanilla donar.'],
            ],
            'KFC' => [
                ['KFC Qanotchalar', 22_000, 12, 27, "Qovurilgan tovuq qanotchalari. 3 ta — 22 000, 6 ta — 38 000, 9 ta — 50 000 so'm."],
                ['KFC Oyoqchalar', 30_000, 13, 28, "Qovurilgan tovuq oyoqchalari. 3 ta — 30 000, 6 ta — 53 000, 9 ta — 78 000 so'm."],
                ['KFC Stripslar', 20_000, 11, 7, "Tovuq stripslari. 100 g — 20 000, 200 g — 30 000, 300 g — 40 000, 500 g — 70 000, 1000 g — 120 000 so'm."],
            ],
            'Pizza' => [
                ['Pizza 4 Syra', 68_000, 15, 45, "To'rt xil pishloqli pitsa. Kichik 68 000 · O'rtacha 95 000 · Katta 115 000 so'm."],
                ['Pizza Pepperoni', 58_000, 15, 39, "Pepperoni kolbasa va mozzarella. Kichik 58 000 · O'rtacha 80 000 · Katta 100 000 so'm."],
                ['Pizza Gribnaya', 58_000, 15, 40, "Qo'ziqorin, piyoz va pomidor. Kichik 58 000 · O'rtacha 82 000 · Katta 100 000 so'm."],
                ['Pizza Bayram', 70_000, 16, 48, "Go'shtli assorti: pepperoni, vetchina va kolbasa. Kichik 70 000 · O'rtacha 95 000 · Katta 120 000 so'm."],
                ['Pizza Khanskaya', 95_000, 16, 46, "Xon uslubidagi to'q pitsa: kolbasa, go'sht va ko'kat. Kichik 95 000 · O'rtacha 115 000 · Katta 150 000 so'm."],
                ['Pizza Rancho', 65_000, 15, 47, "Tovuq, pishloq va ko'kat. Kichik 65 000 · O'rtacha 85 000 · Katta 105 000 so'm."],
                ['Pizza Kurinaya', 60_000, 15, 41, "Grill tovuq, rayhon va mozzarella. Kichik 60 000 · O'rtacha 83 000 · Katta 100 000 so'm."],
                ['Pizza Syrnaya', 50_000, 14, 44, "Mozzarella va pomidor sousi (margarita). Kichik 50 000 · O'rtacha 73 000 · Katta 90 000 so'm."],
                ['Pizza Kombo', 70_000, 16, 42, "Sabzavot va go'sht: qalampir, qo'ziqorin, zaytun. Kichik 70 000 · O'rtacha 93 000 · Katta 115 000 so'm."],
                ['Pizza Tsezar', 70_000, 15, 43, "Sezar uslubi: tovuq, qalampir va sous. Kichik 70 000 · O'rtacha 90 000 · Katta 110 000 so'm."],
            ],
            'Shirinliklar' => [
                ['San Sebastian', 35_000, 5, null, 'Kuydirilgan cheesecake (San Sebastian uslubi).'],
                ['Tvorojniy', 20_000, 5, null, 'Tvorogli yengil shirinlik.'],
                ['Snikers', 25_000, 5, null, 'Snickers uslubidagi qatlamli shirinlik.'],
                ['Milka', 25_000, 5, null, 'Milka shokoladli shirinlik.'],
                ['Belgiyskiy', 65_000, 6, null, 'Belgiya shokoladli tort.'],
            ],
            'Kokteyl & Moxito' => [
                ['Molochniy koktel Shokoladli', 25_000, 5, null, 'Shokoladli sovuq sut kokteyli.'],
                ['Molochniy koktel Bananli', 25_000, 5, null, 'Bananli sovuq sut kokteyli.'],
                ['Molochniy koktel Malinali', 25_000, 5, null, 'Malinali sovuq sut kokteyli.'],
                ['Moxito Malinali', 20_000, 5, null, 'Malinali mohito (alkogolsiz).'],
                ['Moxito Yalpizli', 20_000, 5, null, 'Klassik yalpizli mohito (alkogolsiz).'],
                ['Moxito Qulupnayli', 20_000, 5, null, 'Qulupnayli mohito (alkogolsiz).'],
                ['Moxito Ocean', 20_000, 5, null, "Ko'k «Ocean» mohito (alkogolsiz)."],
            ],
            'Muzqaymoqlar' => [
                ['Muzqaymoq Plombir', 8_000, 3, null, 'Klassik plombir muzqaymoq.'],
                ['Muzqaymoq Shokoladli', 8_000, 3, null, 'Shokoladli muzqaymoq.'],
                ['Muzqaymoq Malinali', 8_000, 3, null, 'Malinali muzqaymoq.'],
                ['Muzqaymoq Bananli', 8_000, 3, null, 'Bananli muzqaymoq.'],
                ['Muzqaymoq Mevali', 8_000, 3, null, 'Mevali muzqaymoq.'],
                ['Muzqaymoq Yong\'oqli', 18_000, 3, null, 'Yong\'oqli muzqaymoq.'],
                ['Muzqaymoq Snikers', 18_000, 3, null, 'Snickersli muzqaymoq.'],
                ['Muzqaymoq Gonkong', 40_000, 5, null, 'Gonkong uslubidagi vaflili muzqaymoq.'],
            ],
            'Coffee & Choy' => [
                ['Americano', 18_000, 4, null, 'Issiq americano kofe.'],
                ['Espresso', 18_000, 4, null, 'Klassik espresso.'],
                ['Latte', 22_000, 4, null, 'Sutli latte.'],
                ['Capuchino', 20_000, 4, null, 'Sutli kapuchino.'],
                ['Qora choy', 6_000, 3, null, 'Qora choy (choynak).'],
                ['Ko\'k choy', 8_000, 3, null, 'Ko\'k choy (choynak).'],
                ['MacCoffe', 6_000, 3, null, "3-in-1 MacCoffee. Katta — 8 000 so'm."],
                ['Capuchino (paketda)', 8_000, 3, null, "Paketli kapuchino. Katta — 10 000 so'm."],
                ['1 Chashka choy', 3_000, 2, null, 'Bir chashka qora choy.'],
                ['1 Choynak choy', 5_000, 2, null, 'Bir choynak qora choy.'],
                ['Novvot choy', 10_000, 3, null, 'Novvot bilan qora choy.'],
                ['Limon choy', 15_000, 3, null, 'Limonli issiq choy.'],
                ['Mevali choy', 20_000, 4, null, 'Mevali issiq choy.'],
            ],
        ]);

        $this->ensureStaff($restaurant);

        $this->command->info(sprintf(
            'Vanilla: %d taom, %d rasm bog\'landi.',
            $stats['products'],
            $stats['photos'],
        ));
    }
}
