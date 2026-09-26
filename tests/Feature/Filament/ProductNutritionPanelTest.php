<?php

namespace Tests\Feature\Filament;

use App\Enums\NutritionStatus;
use App\Filament\Restaurant\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Restaurant\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Restaurant\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** /restaurant panelida AI kaloriya taxminini ko'rish, tasdiqlash, yashirish. */
class ProductNutritionPanelTest extends TestCase
{
    use RefreshDatabase;

    private Staff $owner;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::factory()->create();
        $this->owner = Staff::factory()->owner($restaurant)->create();
        $this->category = Category::factory()->for($restaurant)->create();
        $this->product = Product::factory()->for($this->category)->create([
            'name' => 'Lavash',
            'calories_estimate' => 620,
            'is_light' => false,
            'nutrition_status' => NutritionStatus::Pending,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs($this->owner, 'staff');
    }

    public function test_edit_form_is_prefilled_with_the_ai_suggestion(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->assertFormSet(['calories_estimate' => 620, 'is_light' => false])
            ->assertSee('AI taxmini — iltimos haqiqiy porsiyangizga qarab tekshiring');
    }

    public function test_approve_action_makes_it_approved(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->callFormComponentAction('approveNutritionAction', 'approveNutrition');

        $this->assertSame(NutritionStatus::Approved, $this->product->fresh()->nutrition_status);
        $this->assertSame(620, $this->product->fresh()->calories_estimate);
    }

    public function test_hide_action_makes_it_hidden(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->callFormComponentAction('hideNutritionAction', 'hideNutrition');

        $this->assertSame(NutritionStatus::Hidden, $this->product->fresh()->nutrition_status);
    }

    public function test_owner_editing_the_calories_auto_approves(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->fillForm(['calories_estimate' => 540, 'is_light' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $product = $this->product->fresh();
        $this->assertSame(540, $product->calories_estimate);
        $this->assertSame(NutritionStatus::Approved, $product->nutrition_status);
    }

    public function test_saving_unrelated_fields_does_not_approve(): void
    {
        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->fillForm(['price' => 30000])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(NutritionStatus::Pending, $this->product->fresh()->nutrition_status);
    }

    public function test_calories_entered_on_create_are_approved(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->category->id,
                'name' => 'Salat',
                'price' => 18000,
                'prep_time_min' => 10,
                'calories_estimate' => 150,
                'is_light' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $salad = Product::where('name', 'Salat')->first();
        $this->assertSame(150, $salad->calories_estimate);
        $this->assertTrue($salad->is_light);
        $this->assertSame(NutritionStatus::Approved, $salad->nutrition_status);
    }

    public function test_pending_filter_lists_only_products_awaiting_approval(): void
    {
        $approved = Product::factory()->for($this->category)->create([
            'name' => 'Osh', 'calories_estimate' => 700, 'nutrition_status' => NutritionStatus::Approved,
        ]);

        Livewire::test(ListProducts::class)
            ->filterTable('nutrition_pending')
            ->assertCanSeeTableRecords([$this->product])
            ->assertCanNotSeeTableRecords([$approved]);
    }

    public function test_another_restaurants_owner_cannot_open_or_approve(): void
    {
        $otherOwner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        $this->assertFalse($otherOwner->can('update', $this->product));

        $this->actingAs($otherOwner, 'staff')
            ->get("/restaurant/products/{$this->product->id}/edit")
            ->assertNotFound();

        Livewire::actingAs($otherOwner, 'staff');
        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
                ->callFormComponentAction('approveNutritionAction', 'approveNutrition');
        } finally {
            // Begona egasi uchun global scope yozuvni yashiradi — to'g'ridan-to'g'ri tekshiramiz.
            $this->assertSame(
                NutritionStatus::Pending,
                Product::withoutGlobalScopes()->find($this->product->id)->nutrition_status,
            );
        }
    }
}
