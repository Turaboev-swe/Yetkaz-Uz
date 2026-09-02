<?php

namespace Tests\Feature\Reporting;

use App\Filament\Restaurant\Pages\Reports as RestaurantReports;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Restoran egasi hisobotда FAQAT o'z restorani sonlarini ko'radi.
 * `RestaurantIsolationTest.php` naqshi.
 */
class RestaurantReportIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_report_excludes_other_restaurants(): void
    {
        $mine = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        Order::factory()->count(2)->forRestaurant($mine)->placedAt(now()->startOfMonth()->addDay())->create();
        Order::factory()->count(7)->forRestaurant($other)->placedAt(now()->startOfMonth()->addDay())->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($mine)->create(), 'staff');

        $data = Livewire::test(RestaurantReports::class)->instance()->getViewData();

        $this->assertSame(2, $data['summary']['orders']);
    }
}
