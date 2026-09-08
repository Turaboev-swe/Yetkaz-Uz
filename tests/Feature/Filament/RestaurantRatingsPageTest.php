<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderStatus;
use App\Filament\Restaurant\Pages\Ratings;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestaurantRatingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restA;

    private Restaurant $restB;

    private Staff $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $district = District::factory()->create();
        $this->restA = Restaurant::factory()->for($district)->create(['name' => 'A restoran']);
        $this->restB = Restaurant::factory()->for($district)->create(['name' => 'B restoran']);
        $this->ownerA = Staff::factory()->owner($this->restA)->create();

        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
    }

    private function rated(Restaurant $r, int $stars, ?string $comment = null): Order
    {
        return Order::factory()->for($r)->for(User::factory())->create([
            'status' => OrderStatus::Delivered,
            'rating' => $stars,
            'rating_comment' => $comment,
            'rated_at' => now(),
        ]);
    }

    public function test_page_renders_for_the_owner(): void
    {
        Livewire::actingAs($this->ownerA, 'staff');
        Livewire::test(Ratings::class)->assertOk();
    }

    public function test_owner_sees_only_own_restaurant_ratings(): void
    {
        $mine = $this->rated($this->restA, 5, 'Zo\'r');
        $foreign = $this->rated($this->restB, 1, 'Yomon edi');

        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(Ratings::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertDontSee('Yomon edi');   // begona izoh matnи chiqmasin
    }

    public function test_unrated_orders_are_not_listed(): void
    {
        $rated = $this->rated($this->restA, 4);
        $unrated = Order::factory()->for($this->restA)->for(User::factory())->create([
            'status' => OrderStatus::Delivered, 'rating' => null,
        ]);

        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(Ratings::class)
            ->assertCanSeeTableRecords([$rated])
            ->assertCanNotSeeTableRecords([$unrated]);
    }

    public function test_rating_without_a_comment_is_still_listed(): void
    {
        $noComment = $this->rated($this->restA, 5, null);

        Livewire::actingAs($this->ownerA, 'staff');
        Livewire::test(Ratings::class)->assertCanSeeTableRecords([$noComment]);
    }

    public function test_kitchen_staff_cannot_access_the_page_or_panel(): void
    {
        $kitchen = Staff::factory()->kitchenStaff($this->restA)->create();

        auth('staff')->setUser($kitchen);
        $this->assertFalse(Ratings::canAccess());
        $this->assertFalse($kitchen->canAccessPanel(Filament::getPanel('restaurant')));
    }

    public function test_platform_admin_is_not_this_panel(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        auth('staff')->setUser($admin);

        $this->assertFalse(Ratings::canAccess());
    }

    public function test_rating_filter_narrows_the_list(): void
    {
        $five = $this->rated($this->restA, 5);
        $two = $this->rated($this->restA, 2);

        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(Ratings::class)
            ->filterTable('rating', 5)
            ->assertCanSeeTableRecords([$five])
            ->assertCanNotSeeTableRecords([$two]);
    }

    public function test_with_comment_filter(): void
    {
        $withComment = $this->rated($this->restA, 4, 'Yaxshi');
        $withoutComment = $this->rated($this->restA, 4, null);

        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(Ratings::class)
            ->filterTable('has_comment', true)
            ->assertCanSeeTableRecords([$withComment])
            ->assertCanNotSeeTableRecords([$withoutComment]);
    }

    public function test_customer_phone_is_never_rendered(): void
    {
        $user = User::factory()->create(['full_name' => 'Oybek', 'phone' => '+998901234567']);
        Order::factory()->for($this->restA)->for($user)->create([
            'status' => OrderStatus::Delivered, 'rating' => 5, 'rated_at' => now(),
        ]);

        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(Ratings::class)
            ->assertSee('Oybek')
            ->assertDontSee('+998901234567');
    }

    public function test_header_stats_use_the_cached_values(): void
    {
        $this->restA->update(['cached_average_rating' => 4.6, 'cached_ratings_count' => 9]);

        Livewire::actingAs($this->ownerA, 'staff');

        Livewire::test(Ratings::class)
            ->assertSee('4.6')
            ->assertSee('9');
    }
}
