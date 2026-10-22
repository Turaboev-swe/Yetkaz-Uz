<?php

namespace Tests\Feature\Filament;

use App\Filament\Restaurant\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /restaurant taomlar ro'yxati: rasmsiz taomlarda "Rasm yo'q" belgisi va "Rasmsiz mahsulotlar" filtri.
 */
class ProductPhotoIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::factory()->create();
        $this->category = Category::factory()->for($restaurant)->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');
    }

    private function product(?string $photo, string $name): Product
    {
        return Product::factory()->for($this->category)->create(['name' => $name, 'photo_url' => $photo]);
    }

    public function test_badge_is_shown_only_for_products_without_a_photo(): void
    {
        $with = $this->product('products/osh.jpg', 'Osh rasmli');
        $without = $this->product(null, 'Osh rasmsiz');

        $component = Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$with, $without]);

        $component->assertTableColumnStateSet('photo_missing', "Rasm yo'q", record: $without);
        $component->assertTableColumnStateSet('photo_missing', null, record: $with);
        $this->assertSame(1, substr_count($component->html(), "Rasm yo'q") + substr_count($component->html(), 'Rasm yo&#039;q') + substr_count($component->html(), 'Rasm yo&#39;q'));
    }

    public function test_empty_and_whitespace_values_count_as_no_photo(): void
    {
        $empty = $this->product('', 'Bo‘sh');
        $spaces = $this->product('   ', 'Bo‘shliq');

        $component = Livewire::test(ListProducts::class);

        $component->assertTableColumnStateSet('photo_missing', "Rasm yo'q", record: $empty);
        $component->assertTableColumnStateSet('photo_missing', "Rasm yo'q", record: $spaces);
    }

    public function test_no_photo_filter_lists_only_products_without_photo(): void
    {
        $with = $this->product('products/osh.jpg', 'Rasmli');
        $url = $this->product('https://cdn.example.com/x.jpg', 'URL rasmli');
        $null = $this->product(null, 'Null');
        $empty = $this->product('', 'Bo‘sh');
        $spaces = $this->product('  ', 'Bo‘shliq');

        Livewire::test(ListProducts::class)
            ->filterTable('no_photo')
            ->assertCanSeeTableRecords([$null, $empty, $spaces])
            ->assertCanNotSeeTableRecords([$with, $url]);
    }

    public function test_without_the_filter_all_products_are_listed(): void
    {
        $with = $this->product('products/osh.jpg', 'Rasmli');
        $without = $this->product(null, 'Rasmsiz');

        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$with, $without]);
    }

    public function test_filter_never_leaks_another_restaurants_products(): void
    {
        $mine = $this->product(null, 'Mening rasmsizim');
        $foreign = Product::factory()
            ->for(Category::factory()->for(Restaurant::factory()->create())->create())
            ->create(['name' => 'Begona', 'photo_url' => null]);

        Livewire::test(ListProducts::class)
            ->filterTable('no_photo')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign]);
    }
}
