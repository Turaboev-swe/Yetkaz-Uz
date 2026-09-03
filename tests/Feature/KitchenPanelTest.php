<?php

namespace Tests\Feature;

use App\Broadcasting\KitchenChannel;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Events\OrderStatusChanged;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KitchenPanelTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create(array_replace([
            'status' => OrderStatus::New,
            'delivery_type' => DeliveryType::Delivery,
        ], $attrs));
    }

    public function test_orders_endpoint_returns_only_own_active_orders(): void
    {
        $mine = $this->order(['status' => OrderStatus::Preparing]);
        $this->order(['status' => OrderStatus::Delivered]);              // yakunlangan
        $other = Order::factory()->for(Restaurant::factory()->for(District::factory()))->for(User::factory())
            ->create(['status' => OrderStatus::New, 'delivery_type' => DeliveryType::Delivery]);

        $data = $this->actingAs($this->owner, 'staff')
            ->getJson('/kitchen/orders')
            ->assertOk()
            ->json('data');

        $this->assertSame([$mine->id], array_column($data, 'id'));
        $this->assertNotContains($other->id, array_column($data, 'id'));
    }

    public function test_guest_is_redirected_to_kitchen_login(): void
    {
        $this->get('/kitchen')->assertRedirect('/kitchen/login');
    }

    public function test_platform_admin_cannot_access_kitchen(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'staff')->getJson('/kitchen/orders')->assertForbidden();
    }

    public function test_advance_moves_delivery_order_through_the_flow(): void
    {
        Event::fake([OrderStatusChanged::class]);
        Queue::fake();

        $order = $this->order();
        $act = fn () => $this->actingAs($this->owner, 'staff')->patchJson("/kitchen/orders/{$order->id}/advance");

        $act()->assertOk()->assertJsonPath('data.status', 'accepted');
        $act()->assertOk()->assertJsonPath('data.status', 'preparing');
        $act()->assertOk()->assertJsonPath('data.status', 'on_the_way');
        $this->assertNotNull($order->fresh()->dispatched_at);
        $act()->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertNotNull($order->fresh()->delivered_at);

        $this->assertSame(4, $order->statusHistory()->count());
        Event::assertDispatched(OrderStatusChanged::class, 4);
        Queue::assertPushed(NotifyCustomerOfStatusChange::class, 4);
    }

    public function test_pickup_order_skips_on_the_way(): void
    {
        Event::fake([OrderStatusChanged::class]);
        Queue::fake();

        $order = $this->order(['delivery_type' => DeliveryType::Pickup, 'status' => OrderStatus::Preparing]);

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_advance_rejects_foreign_restaurant_order(): void
    {
        $foreign = Order::factory()
            ->for(Restaurant::factory()->for(District::factory()))
            ->for(User::factory())
            ->create(['status' => OrderStatus::New, 'delivery_type' => DeliveryType::Delivery]);

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$foreign->id}/advance")
            ->assertStatus(404); // global scope -> topilmaydi (owner)
    }

    public function test_advance_on_finished_order_fails(): void
    {
        $order = $this->order(['status' => OrderStatus::Delivered]);

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance")
            ->assertStatus(422);
    }

    public function test_broadcast_channel_only_authorizes_own_restaurant(): void
    {
        $foreign = Restaurant::factory()->for(District::factory())->create();
        $channel = new KitchenChannel;

        $this->assertTrue($channel->join($this->owner, $this->restaurant->id));
        $this->assertFalse($channel->join($this->owner, $foreign->id));

        $kitchenStaff = Staff::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => StaffRole::KitchenStaff,
        ]);
        $this->assertTrue($channel->join($kitchenStaff, $this->restaurant->id));

        $admin = Staff::factory()->platformAdmin()->create();
        $this->assertFalse($channel->join($admin, $this->restaurant->id));
    }
}
