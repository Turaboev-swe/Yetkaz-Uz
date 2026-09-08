<?php

namespace Database\Seeders\Concerns;

use App\Enums\PosType;
use App\Enums\StaffRole;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyu seederlari uchun umumiy yordamchilar (Vanilla, Fresh Food, Istiqlol Food,
 * Sushi Xan). Barchasi idempotent:
 *
 *  - restoran `name` bo'yicha updateOrCreate (mavjud bo'lsa sozlamalari yangilanadi)
 *  - menyu har safar to'liq qayta quriladi (kategoriyalar o'chiriladi -> mahsulotlar
 *    FK cascade bilan ketadi -> qaytadan yaratiladi). Buyurtmalar `items` jsonb
 *    snapshot saqlagani uchun buzilmaydi.
 *  - restoran egasi (staff) har seed'da xavfsiz parol bilan yangilanadi; parol
 *    faqat faylga yoziladi (ekranga chiqmaydi), fayl joriy parolni saqlaydi.
 *
 * Narxlar so'mda beriladi, bazaga tiyinda yoziladi (so'm * 100).
 * Rasm: database/seeders/assets/products-hf/NN.jpg (600x600) -> public disk
 *       products/NN.jpg. photo_url = "products/NN.jpg" yoki null.
 */
trait SeedsRestaurantMenu
{
    private const HF_ASSETS = __DIR__.'/../assets/products-hf';

    /** Qo'rg'ontepa tumanidagi restoranni yaratadi/yangilaydi. */
    protected function upsertRestaurant(string $name, float $lng, array $overrides = []): Restaurant
    {
        $district = District::where('name', "Qo'rg'ontepa tumani")->firstOrFail();

        $work = array_fill_keys(
            ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            [['09:00', '23:00']],
        );

        return Restaurant::updateOrCreate(['name' => $name], [
            'district_id' => $district->id,
            'lat' => 40.7278,
            'lng' => $lng,
            'avg_prep_time_min' => 20,
            'delivery_radius_km' => 8,
            'min_order_amount' => 3_000_000,
            'delivery_fee' => 1_000_000,
            'is_open' => true,
            'work_hours' => $work,
            'pos_type' => PosType::Manual,
            'notify_chat_id' => env('TELEGRAM_DEV_NOTIFY_CHAT_ID') ?: null,
            ...$overrides,
        ]);
    }

    /**
     * Menyuni to'liq qayta quradi.
     *
     * @param  array<string, array<int, array{0:string,1:int,2:int,3:int|null,4:string}>>  $tree
     *                                                                                            'Kategoriya' => [ [nom, narx_som, tayyorlash_daq, rasm_NN|null, tavsif], ... ]
     * @return array{products:int, photos:int}
     */
    protected function rebuildMenu(Restaurant $restaurant, array $tree): array
    {
        $restaurant->categories()->delete();

        $products = 0;
        $photos = 0;
        $catSort = 0;

        foreach ($tree as $categoryName => $items) {
            $category = Category::create([
                'restaurant_id' => $restaurant->id,
                'name' => $categoryName,
                'sort_order' => $catSort++,
                'is_active' => true,
            ]);

            $sort = 0;
            foreach ($items as [$name, $som, $prep, $photoNn, $description]) {
                $photoUrl = $this->syncPhoto($photoNn);
                if ($photoUrl !== null) {
                    $photos++;
                }

                Product::create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'description' => $description,
                    'price' => $som * 100,
                    'photo_url' => $photoUrl,
                    'prep_time_min' => $prep,
                    'is_available' => true,
                    'sort_order' => $sort++,
                ]);
                $products++;
            }
        }

        return ['products' => $products, 'photos' => $photos];
    }

    /** assets/products-hf/NN.jpg -> public/products/NN.jpg; "products/NN.jpg" qaytaradi. */
    protected function syncPhoto(?int $nn): ?string
    {
        if ($nn === null) {
            return null;
        }

        $source = sprintf('%s/%02d.jpg', self::HF_ASSETS, $nn);
        if (! is_file($source)) {
            return null;
        }

        $path = sprintf('products/%02d.jpg', $nn);
        Storage::disk('public')->put($path, file_get_contents($source));

        return $path;
    }

    /**
     * Restoran xodimlari:
     *  - restaurant_owner ({slug}@yetkaz.uz) — faqat yo'q bo'lsa yaratiladi;
     *    paroli xavfsiz generatsiya qilinib faylga yoziladi (lokal:
     *    storage/app/credentials, prod: /root). Ekranga hech qachon chiqmaydi.
     *  - kitchen_staff (oshxona.{slug}@yetkaz.uz) — /kitchen sinovi uchun,
     *    lokal test paroli bilan (StaffSeeder bilan bir xil konvensiya).
     */
    protected function ensureStaff(Restaurant $restaurant): void
    {
        // StaffSeeder bilan bir xil konvensiya: "Fresh Food" -> "fresh.food".
        $slug = (string) Str::of($restaurant->name)->slug('.')->lower();
        $ownerEmail = "{$slug}@yetkaz.uz";

        // Egasi doim xavfsiz parol bilan bo'ladi (StaffSeeder qat'iy parol
        // qo'ygan bo'lsa ham). Har seed'da yangi parol generatsiya qilinib
        // faylga yoziladi — fayl har doim joriy parolni saqlaydi.
        $existed = Staff::where('email', $ownerEmail)->exists();
        $password = Str::password(16, letters: true, numbers: true, symbols: false, spaces: false);

        Staff::updateOrCreate(['email' => $ownerEmail], [
            'name' => $restaurant->name.' — egasi',
            'password' => $password,
            'role' => StaffRole::RestaurantOwner,
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
        ]);

        $this->writeCredentials($restaurant->name, $slug, $ownerEmail, $password);
        $this->command?->line(sprintf(
            '  egasi %s: %s  ->  parol fayl yangilandi',
            $existed ? 'yangilandi' : 'yaratildi',
            $ownerEmail,
        ));

        Staff::updateOrCreate(
            ['email' => "oshxona.{$slug}@yetkaz.uz"],
            [
                'name' => $restaurant->name.' — oshxona',
                'password' => 'yetkaz12345',
                'role' => StaffRole::KitchenStaff,
                'restaurant_id' => $restaurant->id,
                'telegram_chat_id' => env('TELEGRAM_DEV_NOTIFY_CHAT_ID') ?: null,
                'is_active' => true,
            ],
        );
    }

    private function writeCredentials(string $restaurantName, string $slug, string $email, string $password): void
    {
        $prodPath = "/root/{$slug}-credentials.txt";
        $localPath = storage_path("app/credentials/{$slug}.txt");

        $body = implode("\n", [
            "Restoran:  {$restaurantName}",
            'Panel:     /restaurant',
            "Email:     {$email}",
            "Parol:     {$password}",
            'Yaratildi: '.now()->format('Y-m-d H:i:s'),
            '',
        ]);

        $target = is_dir('/root') && is_writable('/root') ? $prodPath : $localPath;

        if (! is_dir(dirname($target))) {
            @mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, $body);
        @chmod($target, 0600);

        $this->command?->line("     parol fayli: {$target}");
    }
}
