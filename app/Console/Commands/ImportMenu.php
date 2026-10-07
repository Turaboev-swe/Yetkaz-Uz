<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\Catalog\MenuImporter;
use App\Services\Catalog\MenuImportException;
use Illuminate\Console\Command;

/**
 * Restoran menyusini papkadan import qiladi (menu.json + rasmlar).
 * Format va qoidalar — App\Services\Catalog\MenuImporter.
 *
 *   php artisan menu:import 12 storage/app/menu-import/yetkaz-test --dry-run
 *   php artisan menu:import 12 storage/app/menu-import/yetkaz-test
 *   php artisan menu:import 12 storage/app/menu-import/yetkaz-test --replace
 */
class ImportMenu extends Command
{
    protected $signature = 'menu:import
        {restaurant_id : Restoran ID}
        {directory : menu.json va rasmlar turgan papka (nisbiy yo\'l — loyiha ildizidan)}
        {--dry-run : Hech narsa yozmasdan nima qo\'shilishini ko\'rsatish}
        {--replace : Restoranda menyu bor bo\'lsa — uni o\'chirib, yangisini yozish}';

    protected $description = 'Restoran menyusini papkadan (menu.json + rasmlar) import qiladi';

    public function handle(MenuImporter $importer): int
    {
        $restaurant = Restaurant::query()->withoutGlobalScopes()->find((int) $this->argument('restaurant_id'));

        if ($restaurant === null) {
            $this->error('Restoran topilmadi: ID '.$this->argument('restaurant_id'));

            return self::FAILURE;
        }

        $directory = $this->resolveDirectory((string) $this->argument('directory'));
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        try {
            $menu = $importer->parse($directory);
        } catch (MenuImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restoran: #{$restaurant->id} «{$restaurant->name}»".($restaurant->is_test ? ' 🧪 test' : ''));
        $this->info("Papka: {$directory}");
        $this->renderPlan($menu);

        $hasMenu = $importer->hasMenu($restaurant);

        if ($dryRun) {
            if ($hasMenu && ! $replace) {
                $this->warn("Restoranda allaqachon menyu bor — haqiqiy import to'xtaydi (--replace kerak).");
                $this->line('DRY RUN — hech narsa yozilmadi.');

                return self::FAILURE;
            }

            if ($hasMenu) {
                $this->warn("--replace: mavjud menyu (kategoriyalar va taomlar) o'chiriladi.");
            }

            $this->line("DRY RUN — hech narsa yozilmadi. Tekshiruv o'tdi: barcha maydonlar va rasmlar joyida.");

            return self::SUCCESS;
        }

        try {
            $result = $importer->import($restaurant, $directory, $menu, $replace);
        } catch (MenuImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['replaced_categories'] > 0) {
            $this->warn("Eski menyu o'chirildi: {$result['replaced_categories']} kategoriya.");
        }

        $this->info("Import tugadi: {$result['categories']} kategoriya, {$result['products']} taom, {$result['images']} rasm.");

        return self::SUCCESS;
    }

    private function resolveDirectory(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    /** @param  array<int, array{name:string, products:array<int, array<string, mixed>>}>  $menu */
    private function renderPlan(array $menu): void
    {
        $rows = [];
        foreach ($menu as $category) {
            foreach ($category['products'] as $product) {
                $rows[] = [
                    $category['name'],
                    $product['name'],
                    number_format(intdiv($product['price_tiyin'], 100), 0, '.', ' ')." so'm",
                    $product['prep_time_min'].' daq',
                    $product['image'] ?? '—',
                ];
            }
        }

        $this->table(['Kategoriya', 'Taom', 'Narx', 'Tayyorlash', 'Rasm'], $rows);
        $this->line(count($menu).' kategoriya, '.count($rows).' taom.');
    }
}
