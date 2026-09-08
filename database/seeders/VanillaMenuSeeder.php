<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsRestaurantMenu;
use Illuminate\Database\Seeder;

/**
 * Vanilla — Qo'rg'ontepa tumani. To'liq menyu (foydalanuvchi tasdiqlagan narxlar).
 *
 * Donix uslubi: har narx punkti ALOHIDA mahsulot qatori (pitsa o'lchami, KFC
 * dona/gramm, 2x hot dog/gamburger). O'lcham taom nomida ko'rsatiladi. Bir ta'mning
 * barcha o'lchamlari bir xil rasmni ishlatadi.
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
                ['Hot Dog Oddiy', 13_000, 8, 34, 'Klassik hot dog: yumshoq bulochkada sosiska va gorchitsa.'],
                ['Hot Dog Oddiy 2x', 15_000, 8, 34, 'Ikki sosiskali klassik hot dog, gorchitsa bilan.'],
                ['Hot Dog Kanada', 18_000, 8, 32, 'Karamellangan piyoz, pishloq sousi va gorchitsa bilan.'],
                ['Hot Dog Kanada 2x', 20_000, 8, 32, 'Ikki sosiskali Kanada hot dog: piyoz, pishloq sousi va gorchitsa.'],
                ['Hot Dog Tovuqli', 25_000, 9, 33, 'Tovuqli sosiska, erigan pishloq, tuzlangan bodring va sabzavot bilan.'],
                ['Hot Dog Mol go\'shti', 28_000, 9, 59, 'Mol go\'shtli sosiskalar, cheddar sousi va qarsildoq qovurilgan piyoz bilan.'],
                ['Hot Dog Stripsli', 28_000, 9, 35, 'Tovuq stripslari, pishloq sousi va qarsildoq piyoz bilan.'],
                ['Hot Dog Evos', 22_000, 8, 61, 'Bekonli hot dog, qarsildoq qovurilgan piyoz va ketchup bilan.'],
                ['Hot Dog Dazili', 40_000, 10, 63, "To'ldirilgan gourmet hot dog: grill sosiska, erigan pishloq va qovurilgan piyoz. Pishloq qo'shish +3 000 so'm."],
            ],
            'Gamburger' => [
                ['Vanilla Burger', 50_000, 12, 70, 'Yirik mol go\'shti kotleti, karamellangan piyoz, rukkola va maxsus sous, brioche bulochkada.'],
                ['Gamburger Kotleti', 30_000, 10, 3, 'Mol go\'shtli kotlet, cheddar, pomidor va salat.'],
                ['Gamburger Kotleti 2x', 40_000, 11, 3, 'Ikki qavat mol go\'shtli kotlet, cheddar, pomidor va salat.'],
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
                ['KFC Qanotchalar 3 ta', 22_000, 12, 27, 'Qovurilgan tovuq qanotchalari — 3 dona.'],
                ['KFC Qanotchalar 6 ta', 38_000, 13, 27, 'Qovurilgan tovuq qanotchalari — 6 dona.'],
                ['KFC Qanotchalar 9 ta', 50_000, 14, 27, 'Qovurilgan tovuq qanotchalari — 9 dona.'],
                ['KFC Oyoqchalar 3 ta', 30_000, 13, 28, 'Qovurilgan tovuq oyoqchalari — 3 dona.'],
                ['KFC Oyoqchalar 6 ta', 53_000, 14, 28, 'Qovurilgan tovuq oyoqchalari — 6 dona.'],
                ['KFC Oyoqchalar 9 ta', 78_000, 15, 28, 'Qovurilgan tovuq oyoqchalari — 9 dona.'],
                ['KFC Stripslar 100 g', 20_000, 11, 7, 'Tovuq stripslari — 100 gramm.'],
                ['KFC Stripslar 200 g', 30_000, 11, 7, 'Tovuq stripslari — 200 gramm.'],
                ['KFC Stripslar 300 g', 40_000, 12, 7, 'Tovuq stripslari — 300 gramm.'],
                ['KFC Stripslar 500 g', 70_000, 13, 7, 'Tovuq stripslari — 500 gramm.'],
                ['KFC Stripslar 1000 g', 120_000, 15, 7, 'Tovuq stripslari — 1000 gramm.'],
            ],
            'Pizza' => [
                ['Pizza 4 Syra Kichik', 68_000, 14, 45, "To'rt xil pishloqli pitsa — kichik o'lcham."],
                ['Pizza 4 Syra O\'rtacha', 95_000, 15, 45, "To'rt xil pishloqli pitsa — o'rtacha o'lcham."],
                ['Pizza 4 Syra Katta', 115_000, 16, 45, "To'rt xil pishloqli pitsa — katta o'lcham."],
                ['Pizza Pepperoni Kichik', 58_000, 14, 39, 'Pepperoni kolbasa va mozzarella — kichik o\'lcham.'],
                ['Pizza Pepperoni O\'rtacha', 80_000, 15, 39, 'Pepperoni kolbasa va mozzarella — o\'rtacha o\'lcham.'],
                ['Pizza Pepperoni Katta', 100_000, 16, 39, 'Pepperoni kolbasa va mozzarella — katta o\'lcham.'],
                ['Pizza Gribnaya Kichik', 58_000, 14, 40, 'Qo\'ziqorin, piyoz va pomidor — kichik o\'lcham.'],
                ['Pizza Gribnaya O\'rtacha', 82_000, 15, 40, 'Qo\'ziqorin, piyoz va pomidor — o\'rtacha o\'lcham.'],
                ['Pizza Gribnaya Katta', 100_000, 16, 40, 'Qo\'ziqorin, piyoz va pomidor — katta o\'lcham.'],
                ['Pizza Bayram Kichik', 70_000, 15, 48, 'Go\'shtli assorti: pepperoni, vetchina va kolbasa — kichik o\'lcham.'],
                ['Pizza Bayram O\'rtacha', 95_000, 16, 48, 'Go\'shtli assorti: pepperoni, vetchina va kolbasa — o\'rtacha o\'lcham.'],
                ['Pizza Bayram Katta', 120_000, 17, 48, 'Go\'shtli assorti: pepperoni, vetchina va kolbasa — katta o\'lcham.'],
                ['Pizza Khanskaya Kichik', 95_000, 15, 46, 'Xon uslubidagi to\'q pitsa: kolbasa, go\'sht va ko\'kat — kichik o\'lcham.'],
                ['Pizza Khanskaya O\'rtacha', 115_000, 16, 46, 'Xon uslubidagi to\'q pitsa: kolbasa, go\'sht va ko\'kat — o\'rtacha o\'lcham.'],
                ['Pizza Khanskaya Katta', 150_000, 17, 46, 'Xon uslubidagi to\'q pitsa: kolbasa, go\'sht va ko\'kat — katta o\'lcham.'],
                ['Pizza Rancho Kichik', 65_000, 14, 47, 'Tovuq, pishloq va ko\'kat — kichik o\'lcham.'],
                ['Pizza Rancho O\'rtacha', 85_000, 15, 47, 'Tovuq, pishloq va ko\'kat — o\'rtacha o\'lcham.'],
                ['Pizza Rancho Katta', 105_000, 16, 47, 'Tovuq, pishloq va ko\'kat — katta o\'lcham.'],
                ['Pizza Kurinaya Kichik', 60_000, 14, 41, 'Grill tovuq, rayhon va mozzarella — kichik o\'lcham.'],
                ['Pizza Kurinaya O\'rtacha', 83_000, 15, 41, 'Grill tovuq, rayhon va mozzarella — o\'rtacha o\'lcham.'],
                ['Pizza Kurinaya Katta', 100_000, 16, 41, 'Grill tovuq, rayhon va mozzarella — katta o\'lcham.'],
                ['Pizza Syrnaya Kichik', 50_000, 13, 44, 'Mozzarella va pomidor sousi (margarita) — kichik o\'lcham.'],
                ['Pizza Syrnaya O\'rtacha', 73_000, 14, 44, 'Mozzarella va pomidor sousi (margarita) — o\'rtacha o\'lcham.'],
                ['Pizza Syrnaya Katta', 90_000, 15, 44, 'Mozzarella va pomidor sousi (margarita) — katta o\'lcham.'],
                ['Pizza Kombo Kichik', 70_000, 15, 42, 'Sabzavot va go\'sht: qalampir, qo\'ziqorin, zaytun — kichik o\'lcham.'],
                ['Pizza Kombo O\'rtacha', 93_000, 16, 42, 'Sabzavot va go\'sht: qalampir, qo\'ziqorin, zaytun — o\'rtacha o\'lcham.'],
                ['Pizza Kombo Katta', 115_000, 17, 42, 'Sabzavot va go\'sht: qalampir, qo\'ziqorin, zaytun — katta o\'lcham.'],
                ['Pizza Tsezar Kichik', 70_000, 15, 43, 'Sezar uslubi: tovuq, qalampir va sous — kichik o\'lcham.'],
                ['Pizza Tsezar O\'rtacha', 90_000, 16, 43, 'Sezar uslubi: tovuq, qalampir va sous — o\'rtacha o\'lcham.'],
                ['Pizza Tsezar Katta', 110_000, 17, 43, 'Sezar uslubi: tovuq, qalampir va sous — katta o\'lcham.'],
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
                ['MacCoffe (kichik)', 6_000, 3, null, '3-in-1 MacCoffee — kichik.'],
                ['MacCoffe (katta)', 8_000, 3, null, '3-in-1 MacCoffee — katta.'],
                ['Capuchino paketda (kichik)', 8_000, 3, null, 'Paketli kapuchino — kichik.'],
                ['Capuchino paketda (katta)', 10_000, 3, null, 'Paketli kapuchino — katta.'],
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
