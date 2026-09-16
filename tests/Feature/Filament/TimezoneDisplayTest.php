<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\OrderResource\Pages\ListOrders as AdminListOrders;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers as AdminListUsers;
use App\Filament\Restaurant\Pages\Ratings;
use App\Filament\Restaurant\Resources\OrderResource\Pages\ListOrders as RestaurantListOrders;
use App\Filament\Restaurant\Resources\OrderResource\Pages\ViewOrder as RestaurantViewOrder;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Baza UTC'da saqlanadi (APP_TIMEZONE=UTC) — Filament jadval/ko'rish
 * sahifalarida ko'rsatilganda Toshkent vaqtiga (+5 soat) o'girilishi kerak
 * (AppServiceProvider::configureFilamentTimezone() — TextColumn/TextEntry
 * global standart). Ikkala panel: admin va restaurant.
 */
class TimezoneDisplayTest extends TestCase
{
    use RefreshDatabase;

    // UTC 23:51 (15-sen) -> Toshkent (UTC+5) 04:51 (16-sen) — kun ham
    // o'zgaradi, shu sababli sana bo'yicha ham noto'g'ri konvertatsiyani
    // ushlaydi (faqat soatni emas).
    private const UTC_INSTANT = '2026-09-15 23:51:00';

    private const TASHKENT_LABEL = '16.09.2026 04:51';

    public function test_admin_orders_list_shows_tashkent_time(): void
    {
        $restaurant = Restaurant::factory()->create();
        Order::factory()->forRestaurant($restaurant)->placedAt(self::UTC_INSTANT)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        Livewire::test(AdminListOrders::class)->assertSee(self::TASHKENT_LABEL);
    }

    public function test_admin_users_list_shows_tashkent_time(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['created_at' => Carbon::parse(self::UTC_INSTANT, 'UTC')])->saveQuietly();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        Livewire::test(AdminListUsers::class)->assertSee(self::TASHKENT_LABEL);
    }

    public function test_restaurant_orders_list_shows_tashkent_time(): void
    {
        $restaurant = Restaurant::factory()->create();
        Order::factory()->forRestaurant($restaurant)->placedAt(self::UTC_INSTANT)->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');

        Livewire::test(RestaurantListOrders::class)->assertSee(self::TASHKENT_LABEL);
    }

    public function test_restaurant_order_view_page_shows_tashkent_time(): void
    {
        $restaurant = Restaurant::factory()->create();
        $order = Order::factory()->forRestaurant($restaurant)->placedAt(self::UTC_INSTANT)->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');

        Livewire::test(RestaurantViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee(self::TASHKENT_LABEL);
    }

    public function test_restaurant_ratings_page_shows_tashkent_time(): void
    {
        $restaurant = Restaurant::factory()->create();
        Order::factory()->forRestaurant($restaurant)->create([
            'status' => OrderStatus::Delivered,
            'rating' => 5,
            'rating_comment' => 'Zo\'r!',
            'rated_at' => Carbon::parse(self::UTC_INSTANT, 'UTC'),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');

        Livewire::test(Ratings::class)->assertSee(self::TASHKENT_LABEL);
    }
}
