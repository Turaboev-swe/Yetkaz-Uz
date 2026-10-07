<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Catalog\MenuImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

/**
 * `php artisan menu:import {restaurant_id} {papka}` (MenuImporter):
 * menu.json + rasmlar -> kategoriyalar, taomlar (narx so'mdan tiyinga),
 * rasmlar public diskka. Hammasi yoki hech narsa.
 */
class MenuImportCommandTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 PNG — getimagesize() uchun haqiqiy rasm. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dir;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->restaurant = Restaurant::factory()->create(['name' => 'Yetkaz Test']);
        $this->dir = sys_get_temp_dir().'/menu-import-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function menu(array $categories, array $images = ['a.png', 'b.png']): void
    {
        file_put_contents($this->dir.'/menu.json', json_encode(['categories' => $categories], JSON_UNESCAPED_UNICODE));
        foreach ($images as $image) {
            file_put_contents($this->dir.'/'.$image, base64_decode(self::PNG));
        }
    }

    private function validMenu(): array
    {
        return [
            ['name' => 'Burgerlar', 'products' => [
                ['name' => 'Klassik burger', 'description' => "Mol go'shti", 'price' => 30000, 'prep_time_min' => 10, 'image' => 'a.png'],
                ['name' => 'Tovuqli burger', 'description' => 'Tovuq', 'price' => 28000, 'prep_time_min' => 10, 'image' => 'b.png'],
            ]],
            ['name' => 'Ichimliklar', 'products' => [
                ['name' => 'Kola', 'price' => 8000, 'prep_time_min' => 1],
            ]],
        ];
    }

    private function import(array $options = []): PendingCommand
    {
        return $this->artisan('menu:import', ['restaurant_id' => $this->restaurant->id, 'directory' => $this->dir, ...$options]);
    }

    public function test_imports_categories_products_prices_in_tiyin_and_images(): void
    {
        $this->menu($this->validMenu());

        $this->import()->assertSuccessful();

        $categories = Category::query()->where('restaurant_id', $this->restaurant->id)->orderBy('sort_order')->get();
        $this->assertSame(['Burgerlar', 'Ichimliklar'], $categories->pluck('name')->all());

        $burger = Product::query()->where('name', 'Klassik burger')->sole();
        $this->assertSame(3_000_000, $burger->price);
        $this->assertSame(10, $burger->prep_time_min);
        $this->assertSame("Mol go'shti", $burger->description);
        $this->assertTrue($burger->is_available);
        $this->assertStringStartsWith('products/', $burger->photo_url);
        Storage::disk('public')->assertExists($burger->photo_url);
        // Rasm o'zgarishsiz nusxalanadi (serverda qayta ishlanmaydi).
        $this->assertSame(base64_decode(self::PNG), Storage::disk('public')->get($burger->photo_url));

        $this->assertNull(Product::query()->where('name', 'Kola')->sole()->photo_url);
        $this->assertSame([0, 1], Product::query()->where('category_id', $categories[0]->id)->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->menu($this->validMenu());

        $this->import(['--dry-run' => true])
            ->expectsOutputToContain('Klassik burger')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_stops_when_restaurant_already_has_a_menu(): void
    {
        Category::factory()->for($this->restaurant)->create(['name' => 'Eski']);
        $this->menu($this->validMenu());

        $this->import()->expectsOutputToContain('allaqachon menyu bor')->assertFailed();
        $this->import(['--dry-run' => true])->assertFailed();

        $this->assertSame(['Eski'], Category::query()->pluck('name')->all());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replace_swaps_the_menu_and_removes_orphan_images(): void
    {
        Storage::disk('public')->put('products/old.jpg', 'x');
        $old = Category::factory()->for($this->restaurant)->create(['name' => 'Eski']);
        Product::factory()->for($old)->create(['photo_url' => 'products/old.jpg']);
        $this->menu($this->validMenu());

        $this->import(['--replace' => true])->assertSuccessful();

        $this->assertSame(['Burgerlar', 'Ichimliklar'], Category::query()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(3, Product::query()->count());
        Storage::disk('public')->assertMissing('products/old.jpg');
    }

    public function test_missing_image_aborts_the_whole_import(): void
    {
        $this->menu($this->validMenu(), images: ['a.png']); // b.png yo'q

        $this->import()->expectsOutputToContain('b.png')->assertFailed();

        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_invalid_manifest_aborts_with_reasons(): void
    {
        $menu = $this->validMenu();
        unset($menu[0]['products'][1]['price']);
        $menu[1]['products'][0]['image'] = '../secret.png';
        $this->menu($menu);

        $this->import()->expectsOutputToContain('price')->assertFailed();

        $this->assertSame(0, Product::query()->count());
    }

    public function test_failure_mid_import_rolls_back_rows_and_copied_images(): void
    {
        $this->menu($this->validMenu());
        $importer = app(MenuImporter::class);
        $menu = $importer->parse($this->dir);

        // Ikkinchi kategoriyada xato — birinchisi, uning taomlari va rasmlari allaqachon yozilgan.
        Category::creating(fn (Category $c) => $c->name === 'Ichimliklar' ? throw new RuntimeException('DB xato') : null);

        try {
            $importer->import($this->restaurant, $this->dir, $menu);
            $this->fail('Xato kutilgan edi');
        } catch (RuntimeException $e) {
            $this->assertSame('DB xato', $e->getMessage());
        }

        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unknown_restaurant_fails(): void
    {
        $this->menu($this->validMenu());

        $this->artisan('menu:import', ['restaurant_id' => 999999, 'directory' => $this->dir])
            ->expectsOutputToContain('Restoran topilmadi')
            ->assertFailed();
    }
}
