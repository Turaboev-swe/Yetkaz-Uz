<?php

namespace Tests\Feature\Filament;

use App\Filament\Restaurant\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Restaurant\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Restaurant\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ENG MUHIM: restaurant_owner faqat o'z restoranini ko'radi va tahrirlaydi.
 * Global scope + Policy, faqat Filament UI'da yashirish emas.
 */
class RestaurantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restA;

    private Restaurant $restB;

    private Staff $ownerA;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $city = City::factory()->create();
        $this->restA = Restaurant::factory()->for($city)->create(['name' => 'A restoran']);
        $this->restB = Restaurant::factory()->for($city)->create(['name' => 'B restoran']);

        $this->ownerA = Staff::factory()->owner($this->restA)->create();

        $catA = Category::factory()->for($this->restA)->create(['name' => 'A kat']);
        $catB = Category::factory()->for($this->restB)->create(['name' => 'B kat']);
        $this->productA = Product::factory()->for($catA)->create(['name' => 'A taom']);
        $this->productB = Product::factory()->for($catB)->create(['name' => 'B taom']);

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
    }

    public function test_owner_sees_only_own_products_in_the_list(): void
    {
        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$this->productA])
            ->assertCanNotSeeTableRecords([$this->productB]);
    }

    public function test_owner_sees_only_own_categories(): void
    {
        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(ListCategories::class)
            ->assertCanSeeTableRecords(Category::where('restaurant_id', $this->restA->id)->get())
            ->assertCanNotSeeTableRecords(Category::where('restaurant_id', $this->restB->id)->get());
    }

    public function test_opening_another_restaurants_product_edit_is_not_found(): void
    {
        $this->actingAs($this->ownerA, 'staff');

        $this->get("/restaurant/products/{$this->productB->id}/edit")->assertNotFound();
        $this->get("/restaurant/products/{$this->productA->id}/edit")->assertOk();
    }

    public function test_livewire_edit_page_rejects_foreign_record(): void
    {
        Livewire::actingAs($this->ownerA, 'staff');

        // Global scope tufayli begona yozuv topilmaydi -> 404 (ModelNotFoundException).
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditProduct::class, ['record' => $this->productB->getRouteKey()]);
    }

    public function test_policy_denies_updating_a_foreign_product(): void
    {
        $this->assertTrue($this->ownerA->can('update', $this->productA));
        $this->assertFalse($this->ownerA->can('update', $this->productB));
        $this->assertFalse($this->ownerA->can('view', $this->productB));
    }

    public function test_owner_only_sees_own_orders(): void
    {
        $orderA = Order::factory()->create(['restaurant_id' => $this->restA->id]);
        $orderB = Order::factory()->create(['restaurant_id' => $this->restB->id]);

        auth('staff')->setUser($this->ownerA);

        $ids = Order::query()->pluck('id')->all();
        $this->assertContains($orderA->id, $ids);
        $this->assertNotContains($orderB->id, $ids);
    }

    public function test_platform_admin_bypasses_the_scope(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        auth('staff')->setUser($admin);

        $this->assertSame(2, Product::count());
        $this->assertTrue($admin->can('update', $this->productB));
    }

    public function test_scope_is_noop_without_staff_auth(): void
    {
        // Mini App API / bot / CLI konteksti — staff auth yo'q.
        $this->assertSame(2, Product::count());
        $this->assertSame(2, Category::count());
    }
}
