<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Restaurant\Pages\RestaurantSettings;
use App\Models\District;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestaurantLocationTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = Region::factory()->create(['name' => 'Andijon viloyati', 'code' => 'AN']);
        $this->district = District::factory()->for($this->region)->create([
            'name' => 'Asaka tumani',
            'center_lat' => 40.6392, 'center_lng' => 72.2378,
        ]);
    }

    public function test_admin_creates_restaurant_via_map_without_typing_coordinates(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        Livewire::test(CreateRestaurant::class)
            ->fillForm([
                'name' => 'Test oshxona',
                'region_id' => $this->region->id,
                'district_id' => $this->district->id,
                // xarita bosildi:
                'location' => ['lat' => 40.6401, 'lng' => 72.2390],
                'lat' => 40.6401,
                'lng' => 72.2390,
                'delivery_radius_km' => 5,
                'avg_prep_time_min' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $restaurant = Restaurant::firstWhere('name', 'Test oshxona');
        $this->assertNotNull($restaurant);
        $this->assertSame($this->district->id, $restaurant->district_id);
        $this->assertEqualsWithDelta(40.6401, (float) $restaurant->lat, 0.0001);
        $this->assertEqualsWithDelta(72.2390, (float) $restaurant->lng, 0.0001);
    }

    public function test_choosing_a_district_recenters_the_map_on_its_center(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        Livewire::test(CreateRestaurant::class)
            ->fillForm(['region_id' => $this->region->id])
            ->fillForm(['district_id' => $this->district->id])
            ->assertFormSet([
                'lat' => 40.6392,
                'lng' => 72.2378,
            ]);
    }

    public function test_edit_form_prefills_region_from_the_saved_district(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        $restaurant = Restaurant::factory()->for($this->district)->create(['lat' => 40.64, 'lng' => 72.24]);

        Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
            ->assertFormSet([
                'region_id' => $this->region->id,
                'district_id' => $this->district->id,
            ]);
    }

    public function test_owner_settings_page_updates_district_and_coordinates(): void
    {
        $restaurant = Restaurant::factory()->for($this->district)->create([
            'lat' => 40.6392, 'lng' => 72.2378, 'is_open' => true,
        ]);
        $owner = Staff::factory()->owner($restaurant)->create();

        $other = District::factory()->for($this->region)->create(['name' => 'Xonobod shahri']);

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs($owner, 'staff');

        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => $restaurant->name,
                'district_id' => $other->id,
                'lat' => 40.8144,
                'lng' => 72.9606,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $restaurant->refresh();
        $this->assertSame($other->id, $restaurant->district_id);
        $this->assertEqualsWithDelta(40.8144, (float) $restaurant->lat, 0.0001);
    }
}
