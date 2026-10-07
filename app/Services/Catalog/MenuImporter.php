<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restoran menyusini papkadan import qilish (`php artisan menu:import`).
 *
 * Papka: `menu.json` + rasmlar. menu.json:
 *
 *   {"categories": [
 *     {"name": "Burgerlar", "products": [
 *       {"name": "Klassik burger", "description": "...", "price": 30000,
 *        "prep_time_min": 10, "image": "03.jpg"}
 *     ]}
 *   ]}
 *
 * (yoki to'g'ridan-to'g'ri kategoriyalar massivi). `price` — SO'MDA, bazaga
 * tiyinda yoziladi. `image` — papkadagi fayl nomi (ixtiyoriy).
 *
 * Qoidalar:
 * - Avval HAMMASI tekshiriladi (JSON, maydonlar, har rasm fayli) — bitta xato
 *   bo'lsa ham hech narsa yozilmaydi.
 * - Restoranda menyu bor bo'lsa — to'xtaydi ($replace = true aniq so'ralmasa).
 * - Yozish bitta tranzaksiyada; xato bo'lsa nusxalangan rasmlar ham o'chiriladi.
 * - Rasmlar `public` diskning `products/` papkasiga (panel yuklagan joy)
 *   o'zgarishsiz nusxalanadi — serverda qayta ishlanmaydi.
 */
class MenuImporter
{
    public const MANIFEST = 'menu.json';

    public const IMAGE_DIR = 'products';

    /** Panel bilan bir xil chegara (ProductResource: maxSize 4096 KB). */
    public const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * menu.json va rasmlarni o'qib, to'liq tekshiradi. Hech narsa yozmaydi.
     *
     * @return array<int, array{name:string, products:array<int, array{name:string, description:?string, price_tiyin:int, prep_time_min:int, image:?string}>}>
     *
     * @throws MenuImportException
     */
    public function parse(string $directory): array
    {
        $directory = rtrim($directory, '/\\');

        if (! is_dir($directory)) {
            throw new MenuImportException("Papka topilmadi: {$directory}");
        }

        $manifest = $directory.DIRECTORY_SEPARATOR.self::MANIFEST;
        if (! is_file($manifest)) {
            throw new MenuImportException('Papkada '.self::MANIFEST.' yo\'q: '.$directory);
        }

        $data = json_decode((string) file_get_contents($manifest), true);
        if (! is_array($data)) {
            throw new MenuImportException(self::MANIFEST.' — yaroqsiz JSON: '.json_last_error_msg());
        }

        $categories = array_is_list($data) ? $data : ($data['categories'] ?? null);

        $validator = Validator::make(['categories' => $categories], [
            'categories' => ['required', 'array', 'list', 'min:1'],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.products' => ['required', 'array', 'list', 'min:1'],
            'categories.*.products.*.name' => ['required', 'string', 'max:255'],
            'categories.*.products.*.description' => ['nullable', 'string', 'max:2000'],
            'categories.*.products.*.price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'categories.*.products.*.prep_time_min' => ['required', 'integer', 'min:0', 'max:600'],
            // Faqat fayl nomi — papkadan tashqariga chiqib bo'lmaydi (../, /).
            'categories.*.products.*.image' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
        ], [
            // CLI xabarlari — maydon yo'li bilan (categories.0.products.1.price), tarjima fayliga bog'lanmasdan.
            'required' => ':attribute — majburiy',
            'array' => ':attribute — ro\'yxat bo\'lishi kerak',
            'list' => ':attribute — ro\'yxat bo\'lishi kerak',
            'string' => ':attribute — matn bo\'lishi kerak',
            'integer' => ':attribute — butun son bo\'lishi kerak (narx so\'mda)',
            'min' => ':attribute — kamida :min',
            'max' => ':attribute — ko\'pi bilan :max',
            'regex' => ':attribute — faqat fayl nomi bo\'lsin (papka yo\'lisiz)',
        ]);

        if ($validator->fails()) {
            throw new MenuImportException(self::MANIFEST." xatolari:\n - ".implode("\n - ", $validator->errors()->all()));
        }

        $out = [];
        $missing = [];

        foreach ($categories as $category) {
            $products = [];

            foreach ($category['products'] as $product) {
                $image = filled($product['image'] ?? null) ? (string) $product['image'] : null;

                if ($image !== null && ($error = $this->imageError($directory, $image)) !== null) {
                    $missing[] = "{$product['name']}: {$error}";
                }

                $products[] = [
                    'name' => trim($product['name']),
                    'description' => filled($product['description'] ?? null) ? trim($product['description']) : null,
                    'price_tiyin' => (int) $product['price'] * 100,
                    'prep_time_min' => (int) $product['prep_time_min'],
                    'image' => $image,
                ];
            }

            $out[] = ['name' => trim($category['name']), 'products' => $products];
        }

        if ($missing !== []) {
            throw new MenuImportException("Rasm xatolari (import to'xtatildi):\n - ".implode("\n - ", $missing));
        }

        return $out;
    }

    public function hasMenu(Restaurant $restaurant): bool
    {
        return Category::query()->withoutGlobalScopes()->where('restaurant_id', $restaurant->id)->exists();
    }

    /**
     * Tekshirilgan menyuni bazaga yozadi. Hammasi yoki hech narsa.
     *
     * @param  array<int, array{name:string, products:array<int, array<string, mixed>>}>  $menu  parse() natijasi
     * @return array{categories:int, products:int, images:int, replaced_categories:int}
     *
     * @throws MenuImportException
     */
    public function import(Restaurant $restaurant, string $directory, array $menu, bool $replace = false): array
    {
        $directory = rtrim($directory, '/\\');
        $disk = Storage::disk('public');
        $copied = [];
        $oldImages = [];

        try {
            $result = DB::transaction(function () use ($restaurant, $directory, $menu, $replace, $disk, &$copied, &$oldImages) {
                // Bir vaqtда ikki import (yoki import + panel) menyuni aralashtirmasin.
                Restaurant::query()->whereKey($restaurant->id)->lockForUpdate()->first();

                $replaced = 0;
                if ($this->hasMenu($restaurant)) {
                    if (! $replace) {
                        throw new MenuImportException(
                            "«{$restaurant->name}» restoranida allaqachon menyu bor. Almashtirish uchun --replace bilan ishga tushiring.",
                        );
                    }

                    $oldImages = Product::query()->withoutGlobalScopes()
                        ->forRestaurant($restaurant->id)
                        ->whereNotNull('photo_url')
                        ->pluck('photo_url')->all();

                    // Taomlar va narx tarixi FK cascade bilan o'chadi. Eski buyurtmalar
                    // buzilmaydi — ular items snapshot'ida (jsonb) saqlangan.
                    $replaced = Category::query()->withoutGlobalScopes()
                        ->where('restaurant_id', $restaurant->id)->delete();
                }

                $products = 0;
                foreach ($menu as $ci => $categoryData) {
                    $category = Category::query()->create([
                        'restaurant_id' => $restaurant->id,
                        'name' => $categoryData['name'],
                        'sort_order' => $ci,
                        'is_active' => true,
                    ]);

                    foreach ($categoryData['products'] as $pi => $productData) {
                        $photo = null;
                        if ($productData['image'] !== null) {
                            $photo = $this->copyImage($disk, $directory, $productData['image']);
                            $copied[] = $photo;
                        }

                        Product::query()->create([
                            'category_id' => $category->id,
                            'name' => $productData['name'],
                            'description' => $productData['description'],
                            'price' => $productData['price_tiyin'],
                            'prep_time_min' => $productData['prep_time_min'],
                            'photo_url' => $photo,
                            'is_available' => true,
                            'sort_order' => $pi,
                        ]);
                        $products++;
                    }
                }

                return [
                    'categories' => count($menu),
                    'products' => $products,
                    'images' => count($copied),
                    'replaced_categories' => $replaced,
                ];
            });
        } catch (Throwable $e) {
            // Tranzaksiya qaytarildi — nusxalangan rasmlar ham qolmasin.
            $disk->delete($copied);

            throw $e;
        }

        $this->deleteOrphanImages($disk, $oldImages);

        return $result;
    }

    /** Fayl yo'q / o'qib bo'lmaydi / rasm emas / juda katta bo'lsa — sabab, aks holda null. */
    private function imageError(string $directory, string $image): ?string
    {
        $path = $directory.DIRECTORY_SEPARATOR.$image;

        if (! is_file($path) || ! is_readable($path)) {
            return "rasm fayli topilmadi — {$image}";
        }

        if (! in_array(strtolower(pathinfo($image, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
            return "ruxsat etilmagan kengaytma — {$image} (".implode(', ', self::IMAGE_EXTENSIONS).')';
        }

        if (filesize($path) > self::MAX_IMAGE_BYTES) {
            return "rasm 4 MB dan katta — {$image}";
        }

        if (@getimagesize($path) === false) {
            return "rasm emas yoki buzilgan — {$image}";
        }

        return null;
    }

    private function copyImage($disk, string $directory, string $image): string
    {
        $ext = strtolower(pathinfo($image, PATHINFO_EXTENSION));
        $target = self::IMAGE_DIR.'/'.Str::ulid()->toBase32().'.'.($ext === 'jpeg' ? 'jpg' : $ext);

        $stream = fopen($directory.DIRECTORY_SEPARATOR.$image, 'rb');
        try {
            if (! $disk->writeStream($target, $stream, ['visibility' => 'public'])) {
                throw new MenuImportException("Rasmni nusxalab bo'lmadi: {$image}");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $target;
    }

    /**
     * --replace: eski taomlarning lokal rasmlari, boshqa hech bir taom ishlatmasa,
     * o'chiriladi (to'liq URL'lar va boshqa joydagi fayllarga tegilmaydi).
     *
     * @param  array<int, string>  $paths
     */
    private function deleteOrphanImages($disk, array $paths): void
    {
        $local = array_values(array_unique(array_filter(
            $paths,
            fn (string $p) => str_starts_with($p, self::IMAGE_DIR.'/'),
        )));

        if ($local === []) {
            return;
        }

        $stillUsed = Product::query()->withoutGlobalScopes()
            ->whereIn('photo_url', $local)->pluck('photo_url')->all();

        $disk->delete(array_values(array_diff($local, $stillUsed)));
    }
}
