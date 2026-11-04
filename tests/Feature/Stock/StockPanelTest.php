<?php

namespace Tests\Feature\Stock;

use App\Filament\Restaurant\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Restaurant\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Restaurant\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Restoran paneli: mahsulot formasida qoldiq/kunlik qoldiq, ro'yxatda inline tahrir, «Tugadi», polling. */
class StockPanelTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $owner;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
        $this->category = Category::factory()->for($this->restaurant)->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs($this->owner, 'staff');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(?int $stock = null, array $extra = []): Product
    {
        return Product::factory()->for($this->category)->create(array_replace(['stock_quantity' => $stock], $extra));
    }

    private function base(array $extra = []): array
    {
        return array_replace([
            'category_id' => $this->category->id, 'name' => 'Osh', 'price' => 32000, 'prep_time_min' => 20, 'is_available' => true,
        ], $extra);
    }

    // --- Forma -----------------------------------------------------------------------------------

    public function test_the_form_has_the_stock_fields_with_uzbek_labels(): void
    {
        $component = Livewire::test(CreateProduct::class);
        $form = $component->instance()->form;

        $this->assertSame('Qoldiq (dona)', $form->getComponent('data.stock_quantity')->getLabel());
        $this->assertSame('Kunlik qoldiq', $form->getComponent('data.daily_stock')->getLabel());
        $this->assertSame('Qayta tiklanish vaqti', $form->getComponent('data.daily_reset_time')->getLabel());
    }

    public function test_a_product_can_be_created_without_stock_and_stays_unlimited(): void
    {
        Livewire::test(CreateProduct::class)->fillForm($this->base())->call('create')->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Osh')->firstOrFail();
        $this->assertNull($product->stock_quantity);
        $this->assertNull($product->daily_stock);
        $this->assertNull($product->daily_reset_time);
    }

    public function test_a_product_can_be_created_with_a_stock_quantity_and_zero_is_kept_as_zero(): void
    {
        Livewire::test(CreateProduct::class)->fillForm($this->base(['stock_quantity' => 25]))->call('create')->assertHasNoFormErrors();
        $this->assertSame(25, Product::query()->where('name', 'Osh')->value('stock_quantity'));

        Livewire::test(CreateProduct::class)->fillForm($this->base(['name' => 'Tugagan', 'stock_quantity' => 0]))->call('create')->assertHasNoFormErrors();
        $this->assertSame(0, Product::query()->where('name', 'Tugagan')->value('stock_quantity'), '0 — Tugadi (NULL emas)');
    }

    public function test_a_negative_stock_is_rejected(): void
    {
        Livewire::test(CreateProduct::class)->fillForm($this->base(['stock_quantity' => -1]))->call('create')
            ->assertHasFormErrors(['stock_quantity']);
    }

    public function test_daily_stock_requires_a_reset_time(): void
    {
        Livewire::test(CreateProduct::class)->fillForm($this->base(['daily_stock' => 30]))->call('create')
            ->assertHasFormErrors(['daily_reset_time' => 'required']);

        $this->assertSame(0, Product::query()->where('name', 'Osh')->count());
    }

    public function test_daily_stock_with_a_reset_time_is_saved(): void
    {
        Livewire::test(CreateProduct::class)->fillForm($this->base(['stock_quantity' => 10, 'daily_stock' => 30, 'daily_reset_time' => '06:00']))
            ->call('create')->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Osh')->firstOrFail();
        $this->assertSame(30, $product->daily_stock);
        $this->assertStringStartsWith('06:00', (string) $product->daily_reset_time);
        $this->assertSame(10, $product->stock_quantity);
    }

    public function test_the_edit_form_shows_the_current_stock_and_saving_other_fields_keeps_it(): void
    {
        $product = $this->product(7);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['stock_quantity' => 7])
            ->fillForm(['name' => 'Yangi nom'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertSame('Yangi nom', $product->fresh()->name);
    }

    public function test_clearing_the_stock_in_the_form_makes_the_product_unlimited(): void
    {
        $product = $this->product(7);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['stock_quantity' => null])->call('save')->assertHasNoFormErrors();

        $this->assertNull($product->fresh()->stock_quantity);
    }

    public function test_saving_a_daily_setting_marks_the_last_reset_but_changing_only_the_stock_does_not(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $product = $this->product(5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['daily_stock' => 40, 'daily_reset_time' => '06:00'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($product->fresh()->stock_reset_at->equalTo(now()), 'sozlama saqlangan lahza');

        Carbon::setTestNow('2026-11-03 12:00:00');
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['stock_quantity' => 3])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($product->fresh()->stock_reset_at->equalTo(Carbon::parse('2026-11-03 10:00:00')), 'faqat qoldiq o‘zgarsa tegilmaydi');
    }

    public function test_an_owner_cannot_set_stock_on_another_restaurants_product(): void
    {
        $other = Restaurant::factory()->for(District::factory())->create();
        $foreign = Product::factory()->for(Category::factory()->for($other)->create())->create(['stock_quantity' => 4]);

        Livewire::test(ListProducts::class)
            ->call('updateTableColumnState', 'stock_quantity', (string) $foreign->getKey(), '999');

        $this->assertSame(4, $foreign->fresh()->stock_quantity, 'rol izolyatsiyasi: boshqa restoran mahsuloti o‘zgarmaydi');
    }

    // --- Ro'yxat: inline tahrir -------------------------------------------------------------------------

    public function test_inline_edit_sets_the_stock(): void
    {
        $product = $this->product(5);

        Livewire::test(ListProducts::class)->call('updateTableColumnState', 'stock_quantity', (string) $product->getKey(), '12');

        $this->assertSame(12, $product->fresh()->stock_quantity);
    }

    public function test_inline_edit_zero_means_sold_out_and_blank_means_unlimited(): void
    {
        $product = $this->product(5);
        $component = Livewire::test(ListProducts::class);

        $component->call('updateTableColumnState', 'stock_quantity', (string) $product->getKey(), '0');
        $this->assertSame(0, $product->fresh()->stock_quantity, '0 — Tugadi');
        $this->assertTrue($product->fresh()->isSoldOut());

        $component->call('updateTableColumnState', 'stock_quantity', (string) $product->getKey(), '');
        $this->assertNull($product->fresh()->stock_quantity, 'bo‘sh — cheklanmagan');
    }

    public function test_inline_edit_rejects_invalid_values(): void
    {
        $product = $this->product(5);
        $component = Livewire::test(ListProducts::class);

        foreach (['-3', 'abc', '2.5', '99999999'] as $bad) {
            $component->call('updateTableColumnState', 'stock_quantity', (string) $product->getKey(), $bad);
            $this->assertSame(5, $product->fresh()->stock_quantity, "«{$bad}» saqlanmasligi kerak");
        }
    }

    public function test_inline_edit_restocks_a_sold_out_product(): void
    {
        $product = $this->product(0);

        Livewire::test(ListProducts::class)->call('updateTableColumnState', 'stock_quantity', (string) $product->getKey(), '10');

        $this->assertFalse($product->fresh()->isSoldOut());
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_the_list_shows_a_sold_out_badge_only_for_zero_stock(): void
    {
        $zero = $this->product(0, ['name' => 'TuganganTaom']);
        $few = $this->product(3, ['name' => 'KamTaom']);
        $unlimited = $this->product(null, ['name' => 'CheksizTaom']);

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('stock_state', 'Tugadi', $zero)
            ->assertTableColumnStateSet('stock_state', null, $few)
            ->assertTableColumnStateSet('stock_state', null, $unlimited)
            ->assertTableColumnStateSet('stock_quantity', 3, $few)
            ->assertTableColumnStateSet('stock_quantity', null, $unlimited);
    }

    public function test_re_saving_an_unchanged_daily_setting_keeps_the_last_reset_time(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $product = $this->product(5, ['daily_stock' => 40, 'daily_reset_time' => '06:00:00']);
        $product->forceFill(['stock_reset_at' => '2026-11-03 01:00:00'])->saveQuietly();

        Carbon::setTestNow('2026-11-03 12:00:00');
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'Boshqa nom'])->call('save')->assertHasNoFormErrors();

        $this->assertTrue($product->fresh()->stock_reset_at->equalTo(Carbon::parse('2026-11-03 01:00:00')), 'forma 06:00 yuborsa ham o‘zgarmagan');
    }

    public function test_the_sold_out_filter_lists_only_zero_stock_products(): void
    {
        $zero = $this->product(0, ['name' => 'Nol']);
        $some = $this->product(4, ['name' => 'Bor']);
        $unlimited = $this->product(null, ['name' => 'Cheksiz']);

        Livewire::test(ListProducts::class)
            ->filterTable('sold_out')
            ->assertCanSeeTableRecords([$zero])
            ->assertCanNotSeeTableRecords([$some, $unlimited]);
    }

    public function test_the_table_refreshes_itself_so_new_orders_show_up(): void
    {
        $component = Livewire::test(ListProducts::class);

        $this->assertSame('15s', $component->instance()->getTable()->getPollingInterval());
    }
}
