<?php

namespace Tests\Feature\Stock;

use App\Events\ProductStockChanged;
use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** /kitchen "Menyu": taom «Tugadi» (qoldiq = 0) / «Bor» (kunlik qoldiq yoki cheksiz) — bir bosishda, restoran izolyatsiyasi bilan. */
class KitchenMenuTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Category $category;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake([ProductStockChanged::class]);

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->category = Category::factory()->for($this->restaurant)->create(['name' => 'Taomlar', 'is_active' => true, 'sort_order' => 1]);
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    private function product(?int $stock = null, array $extra = [], ?Category $category = null): Product
    {
        return Product::factory()->for($category ?? $this->category)->create(array_replace([
            'name' => 'Osh', 'is_available' => true, 'stock_quantity' => $stock,
        ], $extra));
    }

    private function stockOf(Product $p): ?int
    {
        return DB::table('products')->where('id', $p->id)->value('stock_quantity');
    }

    private function setSoldOut(Staff $staff, Product $p, mixed $soldOut)
    {
        return $this->actingAs($staff, 'staff')->patchJson("/kitchen/products/{$p->id}/sold-out", ['sold_out' => $soldOut]);
    }

    private function foreignProduct(?int $stock = 7): Product
    {
        $other = Restaurant::factory()->for(District::factory())->create();

        return $this->product($stock, ['name' => 'Begona'], Category::factory()->for($other)->create(['is_active' => true]));
    }

    // ---- ro'yxat ---------------------------------------------------------------------------------------------------

    public function test_the_menu_lists_own_active_categories_with_stock_state(): void
    {
        $this->product(null, ['name' => 'Cheksiz', 'sort_order' => 1]);
        $this->product(0, ['name' => 'Tugagan', 'sort_order' => 2]);
        $this->product(4, ['name' => 'Kam', 'sort_order' => 3, 'daily_stock' => 20, 'daily_reset_time' => '06:00']);
        $this->product(null, ['name' => 'Yashirin', 'sort_order' => 4, 'is_available' => false]);
        $inactive = Category::factory()->for($this->restaurant)->create(['name' => 'Yopiq', 'is_active' => false]);
        $this->product(1, ['name' => 'Ko\'rinmaydi'], $inactive);
        $this->foreignProduct();

        $data = $this->actingAs($this->owner, 'staff')->getJson('/kitchen/menu')->assertOk()->json('data');

        $this->assertCount(1, $data, 'faqat faol kategoriya, faqat o\'z restorani');
        $this->assertSame('Taomlar', $data[0]['name']);
        $this->assertSame(['Cheksiz', 'Tugagan', 'Kam', 'Yashirin'], array_column($data[0]['products'], 'name'));

        [$cheksiz, $tugagan, $kam, $yashirin] = $data[0]['products'];
        $this->assertSame([null, false], [$cheksiz['stock'], $cheksiz['sold_out']]);
        $this->assertSame([0, true], [$tugagan['stock'], $tugagan['sold_out']]);
        $this->assertSame([4, 20, false], [$kam['stock'], $kam['daily_stock'], $kam['sold_out']]);
        $this->assertTrue($yashirin['hidden'], 'restoran panelida yashirilgan taom belgilanadi');
        $this->assertFalse($cheksiz['hidden']);
    }

    public function test_the_menu_query_count_does_not_grow_with_the_number_of_products(): void
    {
        $this->product(1);
        DB::enableQueryLog();
        $this->actingAs($this->owner, 'staff')->getJson('/kitchen/menu')->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 3) as $i) {
            $c = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
            foreach (range(1, 4) as $j) {
                $this->product($j, ['name' => "T$i-$j"], $c);
            }
        }
        DB::flushQueryLog();
        $this->actingAs($this->owner, 'staff')->getJson('/kitchen/menu')->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()), 'N+1 yo\'q');
    }

    public function test_a_foreign_restaurants_menu_is_404(): void
    {
        $foreign = $this->foreignProduct()->category->restaurant_id;

        $this->actingAs($this->owner, 'staff')->getJson("/kitchen/menu?restaurant_id={$foreign}")->assertNotFound();
    }

    public function test_a_multi_restaurant_staff_can_open_each_assigned_restaurants_menu(): void
    {
        $second = Restaurant::factory()->for(District::factory())->create();
        $cat = Category::factory()->for($second)->create(['name' => 'Ikkinchi', 'is_active' => true]);
        $this->product(3, ['name' => 'Ikkinchi taom'], $cat);
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->alsoAssignedTo($second)->create();

        $names = collect($this->actingAs($staff, 'staff')->getJson("/kitchen/menu?restaurant_id={$second->id}")->assertOk()->json('data.0.products'))->pluck('name');

        $this->assertSame(['Ikkinchi taom'], $names->all());
    }

    public function test_guests_and_platform_admins_cannot_use_the_menu(): void
    {
        $p = $this->product(5);

        $this->getJson('/kitchen/menu')->assertUnauthorized();
        $this->patchJson("/kitchen/products/{$p->id}/sold-out", ['sold_out' => true])->assertUnauthorized();

        $admin = Staff::factory()->platformAdmin()->create();
        $this->actingAs($admin, 'staff')->getJson('/kitchen/menu')->assertForbidden();
        $this->setSoldOut($admin, $p, true)->assertForbidden();
        $this->assertSame(5, $this->stockOf($p));
    }

    // ---- Tugadi ----------------------------------------------------------------------------------------------------

    public function test_sold_out_sets_the_stock_to_zero_and_announces_it(): void
    {
        $p = $this->product(5);

        $this->setSoldOut($this->owner, $p, true)
            ->assertOk()
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.sold_out', true);

        $this->assertSame(0, $this->stockOf($p));
        $this->assertTrue($p->fresh()->isSoldOut());
        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->restaurantId === $this->restaurant->id
            && $e->products[0]['id'] === $p->id && $e->products[0]['sold_out'] === true);
    }

    public function test_sold_out_works_for_an_unlimited_product_and_does_not_touch_is_available(): void
    {
        $p = $this->product(null);

        $this->setSoldOut($this->owner, $p, true)->assertOk();

        $this->assertSame(0, $this->stockOf($p));
        $this->assertTrue($p->fresh()->is_available, 'is_available ga tegilmaydi');
    }

    public function test_a_second_sold_out_tap_changes_nothing_and_announces_nothing(): void
    {
        $p = $this->product(0);

        $this->setSoldOut($this->owner, $p, true)->assertOk()->assertJsonPath('data.sold_out', true);

        Event::assertNotDispatched(ProductStockChanged::class);
    }

    public function test_a_sold_out_product_cannot_be_ordered_afterwards(): void
    {
        $p = $this->product(null);
        $this->setSoldOut($this->owner, $p, true)->assertOk();

        try {
            app(StockService::class)->reserve([['product_id' => $p->id, 'name' => 'Osh', 'qty' => 1]]);
            $this->fail('Tugadi qilingan taom band qilindi');
        } catch (InsufficientStockException $e) {
            $this->assertSame('Afsus, Osh tugab qoldi.', $e->getMessage());
        }
    }

    // ---- Bor -------------------------------------------------------------------------------------------------------

    public function test_available_restores_the_daily_stock_when_there_is_one(): void
    {
        $p = $this->product(0, ['daily_stock' => 30, 'daily_reset_time' => '06:00']);

        $this->setSoldOut($this->owner, $p, false)
            ->assertOk()
            ->assertJsonPath('data.stock', 30)
            ->assertJsonPath('data.sold_out', false);

        $this->assertSame(30, $this->stockOf($p));
        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->products[0]['stock'] === 30);
    }

    public function test_available_makes_the_product_unlimited_when_there_is_no_daily_stock(): void
    {
        $p = $this->product(0);

        $this->setSoldOut($this->owner, $p, false)->assertOk()->assertJsonPath('data.stock', null)->assertJsonPath('data.sold_out', false);

        $this->assertNull($this->stockOf($p));
    }

    public function test_a_stale_available_tap_never_overwrites_a_stock_that_was_already_raised(): void
    {
        // Boshqa qurilma / restoran paneli qoldiqni 8 ga oshirgan; planshetda esa hali «Tugadi» ko'rinib turgan edi.
        $p = $this->product(8, ['daily_stock' => 30, 'daily_reset_time' => '06:00']);

        $this->setSoldOut($this->owner, $p, false)->assertOk()->assertJsonPath('data.stock', 8);

        $this->assertSame(8, $this->stockOf($p));
        Event::assertNotDispatched(ProductStockChanged::class);
    }

    public function test_sold_out_then_available_round_trips_through_the_daily_stock(): void
    {
        $p = $this->product(12, ['daily_stock' => 40, 'daily_reset_time' => '06:00']);

        $this->setSoldOut($this->owner, $p, true)->assertOk();
        $this->assertSame(0, $this->stockOf($p));

        $this->setSoldOut($this->owner, $p, false)->assertOk();
        $this->assertSame(40, $this->stockOf($p));
    }

    // ---- izolyatsiya va validatsiya ---------------------------------------------------------------------------------

    public function test_a_foreign_restaurants_product_is_404_and_untouched(): void
    {
        $foreign = $this->foreignProduct(7);

        $this->setSoldOut($this->owner, $foreign, true)->assertNotFound();

        $kitchen = Staff::factory()->kitchenStaff($this->restaurant)->create();
        $this->setSoldOut($kitchen, $foreign, true)->assertNotFound();

        $this->assertSame(7, $this->stockOf($foreign));
        Event::assertNotDispatched(ProductStockChanged::class);
    }

    public function test_a_missing_product_is_404(): void
    {
        $this->actingAs($this->owner, 'staff')->patchJson('/kitchen/products/999999/sold-out', ['sold_out' => true])->assertNotFound();
    }

    public function test_an_inactive_staff_member_is_refused(): void
    {
        $p = $this->product(5);
        $inactive = Staff::factory()->owner($this->restaurant)->inactive()->create();

        $this->setSoldOut($inactive, $p, true)->assertForbidden();
        $this->assertSame(5, $this->stockOf($p));
    }

    public function test_sold_out_must_be_an_explicit_boolean(): void
    {
        $p = $this->product(5);

        $this->setSoldOut($this->owner, $p, null)->assertUnprocessable()->assertJsonValidationErrors('sold_out');
        $this->setSoldOut($this->owner, $p, 'xa')->assertUnprocessable();

        $this->assertSame(5, $this->stockOf($p));
    }
}
