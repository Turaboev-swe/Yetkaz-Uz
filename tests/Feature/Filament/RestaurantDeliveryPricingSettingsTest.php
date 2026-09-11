<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Restaurant\Pages\RestaurantSettings;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /restaurant sozlamalari — masofaga qarab yetkazish narxi maydonlari
 * (free_delivery_radius_km, price_per_km): ikkalasi ham IXTIYORIY, bo'sh
 * qoldirilsa `null` saqlanadi (0 EMAS — 0 "km narxi bepul" degani bo'lardi).
 */
class RestaurantDeliveryPricingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingOwnerOf(Restaurant $restaurant): void
    {
        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');
    }

    public function test_price_per_km_is_saved_in_tiyin(): void
    {
        $restaurant = Restaurant::factory()->create(['price_per_km' => null]);
        $this->actingOwnerOf($restaurant);

        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => $restaurant->name,
                'price_per_km' => 2_000, // so'm
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(200_000, $restaurant->fresh()->price_per_km); // tiyin
    }

    public function test_leaving_price_per_km_blank_keeps_it_null_not_zero(): void
    {
        $restaurant = Restaurant::factory()->create(['price_per_km' => 200_000]);
        $this->actingOwnerOf($restaurant);

        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => $restaurant->name,
                'price_per_km' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($restaurant->fresh()->price_per_km);
    }

    public function test_free_delivery_radius_km_is_saved_as_is(): void
    {
        $restaurant = Restaurant::factory()->create(['free_delivery_radius_km' => null]);
        $this->actingOwnerOf($restaurant);

        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => $restaurant->name,
                'free_delivery_radius_km' => 2.5,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(2.5, $restaurant->fresh()->free_delivery_radius_km, 0.001);
    }

    public function test_admin_panel_form_saves_the_same_fields(): void
    {
        $restaurant = Restaurant::factory()->create(['price_per_km' => null, 'free_delivery_radius_km' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
            ->fillForm([
                'free_delivery_radius_km' => 1.5,
                'price_per_km' => 1_500,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $restaurant->refresh();
        $this->assertEqualsWithDelta(1.5, $restaurant->free_delivery_radius_km, 0.001);
        $this->assertSame(150_000, $restaurant->price_per_km);
    }
}
