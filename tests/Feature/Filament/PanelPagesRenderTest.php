<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\Reports as AdminReports;
use App\Filament\Admin\Resources\OrderResource\Pages\ListOrders as AdminListOrders;
use App\Filament\Admin\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Filament\Admin\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Filament\Admin\Resources\StaffResource\Pages\CreateStaff;
use App\Filament\Admin\Resources\StaffResource\Pages\ListStaff;
use App\Filament\Admin\Widgets\OrdersTrendChart;
use App\Filament\Admin\Widgets\PlatformOrdersStats;
use App\Filament\Admin\Widgets\TopRestaurantsChart;
use App\Filament\Restaurant\Pages\Reports as RestaurantReports;
use App\Filament\Restaurant\Pages\RestaurantSettings;
use App\Filament\Restaurant\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Restaurant\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Restaurant\Widgets\RestaurantOrdersStats;
use App\Filament\Restaurant\Widgets\RestaurantOrdersTrendChart;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_render(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'staff');

        Livewire::test(ListRestaurants::class)->assertOk();
        Livewire::test(CreateRestaurant::class)->assertOk();
        Livewire::test(ListStaff::class)->assertOk();
        Livewire::test(CreateStaff::class)->assertOk();
        Livewire::test(AdminListOrders::class)->assertOk();
        Livewire::test(AdminReports::class)->assertOk();
        Livewire::test(PlatformOrdersStats::class)->assertOk();
        Livewire::test(OrdersTrendChart::class)->assertOk();
        Livewire::test(TopRestaurantsChart::class)->assertOk();
    }

    public function test_restaurant_pages_render(): void
    {
        $restaurant = Restaurant::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');

        Livewire::test(ListCategories::class)->assertOk();
        Livewire::test(ListOrders::class)->assertOk();
        Livewire::test(RestaurantSettings::class)->assertOk();
        Livewire::test(RestaurantReports::class)->assertOk();
        Livewire::test(RestaurantOrdersStats::class)->assertOk();
        Livewire::test(RestaurantOrdersTrendChart::class)->assertOk();
    }

    public function test_restaurant_settings_saves_work_hours_and_is_open(): void
    {
        $restaurant = Restaurant::factory()->create(['is_open' => true]);
        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');

        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => $restaurant->name,
                'is_open' => false,
                'work_hours' => [
                    ['day' => 'mon', 'from' => '10:00', 'to' => '22:00'],
                    ['day' => 'tue', 'from' => '10:00', 'to' => '22:00'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $restaurant->refresh();
        $this->assertFalse($restaurant->is_open);
        $this->assertSame([['10:00', '22:00']], $restaurant->work_hours['mon']);
    }
}
