<?php

namespace Database\Seeders;

use App\Enums\PosType;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

/**
 * Test uchun namuna ma'lumot: Andijon shahri, 2 restoran, har birida 2 kategoriya
 * va 5-6 taom. Narxlar tiyinda (1 so'm = 100 tiyin). Koordinatalar Andijon markazi
 * atrofida — markazdan lokatsiya yuborgan tester ikkala restoranni ham ko'radi.
 *
 * AndijanGeoSeeder'dan keyin ishlaydi (DatabaseSeeder tartibiga qarang).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $district = District::where('name', 'Andijon shahri')->firstOrFail();

        $work = [
            'mon' => [['09:00', '23:00']], 'tue' => [['09:00', '23:00']],
            'wed' => [['09:00', '23:00']], 'thu' => [['09:00', '23:00']],
            'fri' => [['09:00', '23:00']], 'sat' => [['10:00', '23:00']],
            'sun' => [['10:00', '23:00']],
        ];

        $milliy = Restaurant::create([
            'name' => 'Milliy Taomlar',
            'district_id' => $district->id,
            'lat' => 40.782500, 'lng' => 72.350000,
            'phone' => '+998741234567',
            'avg_prep_time_min' => 25,
            'delivery_radius_km' => 7,
            'min_order_amount' => 5_000_000,
            'delivery_fee' => 1_500_000,
            'is_open' => true,
            'work_hours' => $work,
            'pos_type' => PosType::Manual,
        ]);

        $fast = Restaurant::create([
            'name' => 'Evos Burger',
            'district_id' => $district->id,
            'lat' => 40.788000, 'lng' => 72.325000,
            'phone' => '+998741112233',
            'avg_prep_time_min' => 15,
            'delivery_radius_km' => 10,
            'min_order_amount' => 3_000_000,
            'delivery_fee' => 1_000_000,
            'is_open' => true,
            'work_hours' => $work,
            'pos_type' => PosType::EscPos,
            'printer_host' => '192.168.1.50',
        ]);

        $this->menu($milliy, [
            'Milliy taomlar' => [
                ['Osh', 3_200_000, 30],
                ["Lag'mon", 2_800_000, 20],
                ['Manti (5 dona)', 2_500_000, 25],
                ['Norin', 3_000_000, 15],
            ],
            'Ichimliklar' => [
                ['Choy (choynak)', 500_000, 3],
                ['Coca-Cola 0.5', 900_000, 1],
            ],
        ]);

        $this->menu($fast, [
            'Burgerlar' => [
                ['Klassik burger', 2_600_000, 12],
                ['Chizburger', 2_900_000, 12],
                ['Dabl burger', 3_900_000, 15],
            ],
            'Qo\'shimchalar' => [
                ['Fri kartoshka', 1_200_000, 8],
                ['Naggets (6 dona)', 1_800_000, 10],
            ],
        ]);
    }

    /** @param  array<string, array<int, array{0:string,1:int,2:int}>>  $categories */
    private function menu(Restaurant $restaurant, array $categories): void
    {
        $sort = 0;

        foreach ($categories as $categoryName => $products) {
            $category = Category::create([
                'restaurant_id' => $restaurant->id,
                'name' => $categoryName,
                'sort_order' => $sort++,
                'is_active' => true,
            ]);

            $pSort = 0;

            foreach ($products as [$name, $price, $prep]) {
                Product::create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'price' => $price,
                    'prep_time_min' => $prep,
                    'is_available' => true,
                    'sort_order' => $pSort++,
                ]);
            }
        }
    }
}
