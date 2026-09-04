<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KitchenCourierTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    private function order(): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create([
            'status' => OrderStatus::Preparing,
            'delivery_type' => DeliveryType::Delivery,
        ]);
    }

    // --- GET /kitchen/couriers ---

    public function test_courier_list_shows_only_active_staff_of_own_restaurant(): void
    {
        $mineActive = Staff::factory()->kitchenStaff($this->restaurant)->withPhone('+998901112233')->create(['name' => 'Alisher']);
        Staff::factory()->kitchenStaff($this->restaurant)->inactive()->create(['name' => 'Bekzod']);

        $otherRestaurant = Restaurant::factory()->for(District::factory())->create();
        Staff::factory()->kitchenStaff($otherRestaurant)->create(['name' => 'Sardor']);

        $data = $this->actingAs($this->owner, 'staff')
            ->getJson('/kitchen/couriers')
            ->assertOk()
            ->json('data');

        $names = array_column($data, 'name');
        $this->assertContains('Alisher', $names);
        $this->assertContains($this->owner->name, $names); // egasi ham kuryer bo'la oladi
        $this->assertNotContains('Bekzod', $names);        // faol emas
        $this->assertNotContains('Sardor', $names);        // boshqa restoran

        $alisher = collect($data)->firstWhere('name', 'Alisher');
        $this->assertSame('+998901112233', $alisher['phone']);
    }

    // --- PATCH advance — kuryer tanlash ---

    public function test_selecting_a_courier_snapshots_id_name_and_phone(): void
    {
        $courier = Staff::factory()->kitchenStaff($this->restaurant)
            ->withPhone('+998901112233')->create(['name' => 'Alisher']);
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_staff_id' => $courier->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_the_way');

        $order->refresh();
        $this->assertSame($courier->id, $order->courier_staff_id);
        $this->assertSame('Alisher', $order->courier_name);
        $this->assertSame('+998901112233', $order->courier_phone);
        Queue::assertPushed(NotifyCustomerOfStatusChange::class);
    }

    public function test_courier_snapshot_is_kept_when_staff_details_change_later(): void
    {
        $courier = Staff::factory()->kitchenStaff($this->restaurant)
            ->withPhone('+998901112233')->create(['name' => 'Alisher']);
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_staff_id' => $courier->id])
            ->assertOk();

        $courier->update(['name' => 'Alisher Karimov', 'phone' => '+998900000000']);

        $order->refresh();
        $this->assertSame('Alisher', $order->courier_name);
        $this->assertSame('+998901112233', $order->courier_phone);
    }

    public function test_courier_without_phone_still_works(): void
    {
        $courier = Staff::factory()->kitchenStaff($this->restaurant)->create(['name' => 'Alisher', 'phone' => null]);
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_staff_id' => $courier->id])
            ->assertOk();

        $order->refresh();
        $this->assertSame($courier->id, $order->courier_staff_id);
        $this->assertSame('Alisher', $order->courier_name);
        $this->assertNull($order->courier_phone);
    }

    public function test_continue_without_courier_leaves_all_fields_empty(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_the_way');

        $order->refresh();
        $this->assertNull($order->courier_staff_id);
        $this->assertNull($order->courier_name);
        $this->assertNull($order->courier_phone);
    }

    public function test_courier_from_another_restaurant_is_rejected(): void
    {
        $otherRestaurant = Restaurant::factory()->for(District::factory())->create();
        $foreignCourier = Staff::factory()->kitchenStaff($otherRestaurant)->create();
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_staff_id' => $foreignCourier->id])
            ->assertStatus(422);

        $this->assertSame('preparing', $order->fresh()->status->value);
    }

    public function test_inactive_courier_is_rejected(): void
    {
        $inactive = Staff::factory()->kitchenStaff($this->restaurant)->inactive()->create();
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_staff_id' => $inactive->id])
            ->assertStatus(422);
    }
}
