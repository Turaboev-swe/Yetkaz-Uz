<?php

namespace Tests\Feature\Filament;

use App\Filament\Restaurant\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Restaurant\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Restaurant\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private Staff $owner;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::factory()->create();
        $this->owner = Staff::factory()->owner($restaurant)->create();
        $this->category = Category::factory()->for($restaurant)->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs($this->owner, 'staff');
    }

    public function test_price_entered_in_som_is_stored_in_tiyin(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->category->id,
                'name' => 'Osh',
                'price' => 32000,           // so'm
                'prep_time_min' => 20,
                'is_available' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Osh',
            'price' => 3_200_000,          // tiyin
        ]);
    }

    public function test_edit_form_shows_price_in_som(): void
    {
        $product = Product::factory()->for($this->category)->create(['price' => 2_800_000]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['price' => '28000']);
    }

    public function test_price_change_is_recorded_in_history_with_staff_and_tiyin(): void
    {
        $product = Product::factory()->for($this->category)->create(['price' => 2_800_000]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['price' => 30000]) // so'm
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_price_history', [
            'product_id' => $product->id,
            'staff_id' => $this->owner->id,
            'old_price' => 2_800_000,
            'new_price' => 3_000_000,
        ]);
        $this->assertSame(1, $product->priceHistory()->count());
    }

    public function test_non_price_change_does_not_create_history(): void
    {
        $product = Product::factory()->for($this->category)->create(['price' => 2_800_000, 'name' => 'Old']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'New name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $product->priceHistory()->count());
    }

    public function test_product_list_has_the_quick_availability_toggle(): void
    {
        Product::factory()->for($this->category)->create();

        Livewire::test(ListProducts::class)
            ->assertTableColumnExists('is_available')
            ->assertOk();
    }

    public function test_toggling_availability_directly_does_not_create_price_history(): void
    {
        $product = Product::factory()->for($this->category)->create(['is_available' => true]);

        $product->update(['is_available' => false]);

        $this->assertFalse($product->fresh()->is_available);
        $this->assertSame(0, $product->priceHistory()->count());
    }
}
