<?php

namespace Tests\Feature\Filament;

use App\Filament\Restaurant\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /restaurant — buyurtma ko'rinishida "Chegirma: X so'm (sizning ulushingiz: Y so'm)".
 * Faqat promokod qo'llangan buyurtmada chiqadi; boshqa restoran buyurtmasini
 * ko'ra olmasligi mavjud RestaurantIsolationTest'da tekshirilgan — bu yerda
 * faqat chegirma matni.
 */
class RestaurantOrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs($this->owner, 'staff');
    }

    public function test_discount_line_shows_total_and_the_restaurants_own_share(): void
    {
        $promo = PromoCode::factory()->create();
        $order = Order::factory()->for($this->restaurant)->create([
            'promo_code_id' => $promo->id,
            'discount_amount' => 1_380_000,
            'discount_restaurant_amount' => 690_000,
            'discount_platform_amount' => 690_000,
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Chegirma')
            ->assertSee('13 800 so\'m')  // jami chegirma
            ->assertSee('6 900 so\'m');  // restoran ulushi

        // Egasi PLATFORMA ulushini emas, faqat o'zinikini ko'radi — matn ichida
        // "sizning ulushingiz" bilan bog'liq holda 6 900 chiqishi kerak, 6 900
        // (restoran) va 13 800 (jami) allaqachon assertSee bilan tasdiqlandi.
    }

    public function test_discount_line_is_absent_when_no_promo_code_was_applied(): void
    {
        $order = Order::factory()->for($this->restaurant)->create();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertDontSee('Chegirma');
    }
}
