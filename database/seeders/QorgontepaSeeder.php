<?php

namespace Database\Seeders;

use App\Enums\PosType;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Qo'rg'ontepa tumani — 3 test restorani (Fresh Food, Donix, BurgerGrill).
 *
 * Koordinatalar tasdiqlangan: Qo'rg'ontepa shahri markazi 40.7278, 72.7629.
 * Har restoran ~1 km oralig'ida (lng +0.0117° ≈ 1 km, lat 40.73° da).
 * district_id -> "Qo'rg'ontepa tumani" (Andijon shahri EMAS).
 *
 * Taom rasmlari: database/seeders/assets/products/<slug>.jpg (AI, z_image) —
 * seed paytida `public` diskka (`products/<slug>.jpg`) ko'chiriladi.
 * Egalar keyin panelдан o'z rasmlari bilan almashtira oladi.
 *
 * Narxlar tiyinda (1 so'm = 100 tiyin). `old_price` to'ldirilgan taom aksiyada.
 * Restoran egalari (staff) — StaffSeeder har restoranga bittadan yaratadi.
 */
class QorgontepaSeeder extends Seeder
{
    private const ASSETS = __DIR__.'/assets/products';

    public function run(): void
    {
        $district = District::where('name', "Qo'rg'ontepa tumani")->firstOrFail();

        $work = array_fill_keys(
            ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            [['09:00', '23:00']],
        );

        $common = [
            'district_id' => $district->id,
            'lat' => 40.7278,
            'avg_prep_time_min' => 20,
            'delivery_radius_km' => 8,
            'min_order_amount' => 3_000_000,
            'delivery_fee' => 1_000_000,
            'is_open' => true,
            'work_hours' => $work,
            'pos_type' => PosType::Manual,
            // Lokal test: buyurtma bildirishnomasi shu chatga (agar .env да bo'lsa).
            'notify_chat_id' => env('TELEGRAM_DEV_NOTIFY_CHAT_ID') ?: null,
        ];

        $fresh = Restaurant::create([...$common, 'name' => 'Fresh Food', 'lng' => 72.7629, 'phone' => '+998740010101']);
        $donix = Restaurant::create([...$common, 'name' => 'Donix', 'lng' => 72.7746, 'phone' => '+998740020202']);
        $burger = Restaurant::create([...$common, 'name' => 'BurgerGrill', 'lng' => 72.7863, 'phone' => '+998740030303']);

        // [nom, rasm_slug, narx, tayyorlash_daq, eski_narx(ixtiyoriy)]
        $this->menu($fresh, [
            'Salatlar' => [
                ['Sezar salat', 'sezar-salat', 3_500_000, 12, 4_000_000],
                ['Yunon salati', 'yunon-salati', 3_000_000, 10],
                ['Tovuq va avokado', 'tovuq-avokado', 3_800_000, 12],
            ],
            'Lavash' => [
                ['Tovuqli lavash', 'tovuqli-lavash', 2_800_000, 10],
                ['Sabzavotli lavash', 'sabzavotli-lavash', 2_500_000, 10, 3_000_000],
            ],
            'Ichimliklar' => [
                ['Smuzi (mango)', 'smuzi-mango', 2_000_000, 4],
                ['Ayron', 'ayron', 800_000, 1],
            ],
        ]);

        $this->menu($donix, [
            'Donarlar' => [
                ['Klassik donar', 'klassik-donar', 2_600_000, 10],
                ['Tovuqli donar', 'tovuqli-donar', 2_400_000, 10, 2_800_000],
                ["Go'shtli donar", 'goshtli-donar', 3_200_000, 12],
            ],
            'Snacklar' => [
                ['Fri kartoshka', 'fri-kartoshka', 1_200_000, 8],
                ['Naggets (6 dona)', 'naggets', 1_800_000, 9],
            ],
            'Ichimliklar' => [
                ['Coca-Cola 0.5', 'coca-cola', 900_000, 1],
            ],
        ]);

        $this->menu($burger, [
            'Burgerlar' => [
                ['Grill burger', 'grill-burger', 3_400_000, 13],
                ['Chizburger', 'chizburger', 3_000_000, 12],
                ['Dabl grill', 'dabl-grill', 4_500_000, 16, 5_000_000],
            ],
            "Qo'shimchalar" => [
                ['Fri kartoshka', 'fri-kartoshka', 1_300_000, 8],
                ['Piyoz halqalari', 'piyoz-halqalari', 1_600_000, 9],
            ],
            'Ichimliklar' => [
                ['Milkshake (shokolad)', 'milkshake', 2_200_000, 4],
            ],
        ]);

        $this->command->info("Qo'rg'ontepa: 3 restoran (Fresh Food, Donix, BurgerGrill), rasmlar bilan.");
    }

    /** @param  array<string, array<int, array<int, string|int|null>>>  $categories */
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

            foreach ($products as $row) {
                [$name, $slug, $price, $prep, $oldPrice] = array_pad($row, 5, null);

                Product::create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'price' => $price,
                    'old_price' => $oldPrice,
                    'prep_time_min' => $prep,
                    'photo_url' => $this->syncPhoto($slug),
                    'is_available' => true,
                    'sort_order' => $pSort++,
                ]);
            }
        }
    }

    /** Asset'ni `public` diskka ko'chiradi, saqlangan yo'lni qaytaradi. */
    private function syncPhoto(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $source = self::ASSETS."/{$slug}.jpg";
        if (! is_file($source)) {
            return null;
        }

        $path = "products/{$slug}.jpg";
        Storage::disk('public')->put($path, file_get_contents($source));

        return $path;
    }
}
