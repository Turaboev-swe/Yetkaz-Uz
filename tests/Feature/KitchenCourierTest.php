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

    /** Eski frontend courier_type yubormaydi — courier_staff_id borligidan o'zi xulosa chiqariladi. */
    public function test_courier_type_is_inferred_as_own_staff_when_only_staff_id_is_sent(): void
    {
        $courier = Staff::factory()->kitchenStaff($this->restaurant)->create();
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_staff_id' => $courier->id])
            ->assertOk();

        $this->assertSame('own_staff', $order->fresh()->courier_type->value);
    }

    public function test_explicit_own_staff_type_without_a_staff_id_is_courierless_but_typed(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_type' => 'own_staff'])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_the_way');

        $order->refresh();
        $this->assertSame('own_staff', $order->courier_type->value);
        $this->assertNull($order->courier_staff_id);
        $this->assertNull($order->courier_name);
        $this->assertNull($order->courier_phone);
    }

    // --- PATCH advance — Royal Taxi ---

    public function test_taxi_courier_hardcodes_the_name_and_normalizes_the_phone(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", [
                'courier_type' => 'taxi',
                'courier_phone' => '+998 90 111 22 33',
                // Frontend courier_name yubormaydi, lekin xatarga qarshi — yuborilsa ham e'tiborsiz qoldiriladi.
                'courier_name' => 'Boshqa nom',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_the_way');

        $order->refresh();
        $this->assertSame('taxi', $order->courier_type->value);
        $this->assertSame('Royal Taxi', $order->courier_name);
        $this->assertSame('+998901112233', $order->courier_phone);
        $this->assertNull($order->courier_staff_id);
    }

    /**
     * Prefikssiz mahalliy raqam ("901112233", 9 xonali) — App\Support\Phone
     * qoidasi bo'yicha ENDI to'g'ri hisoblanadi, avtomatik +998 qo'shiladi.
     */
    public function test_taxi_courier_phone_without_998_prefix_is_auto_completed(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", [
                'courier_type' => 'taxi',
                'courier_phone' => '901112233',
            ])
            ->assertOk();

        $this->assertSame('+998901112233', $order->fresh()->courier_phone);
    }

    /** "0" bilan boshlanadigan mahalliy format ham avtomatik to'g'irlanadi. */
    public function test_taxi_courier_phone_with_leading_zero_is_auto_completed(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", [
                'courier_type' => 'taxi',
                'courier_phone' => '0901112233',
            ])
            ->assertOk();

        $this->assertSame('+998901112233', $order->fresh()->courier_phone);
    }

    public function test_taxi_courier_without_a_valid_phone_is_rejected(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", [
                'courier_type' => 'taxi',
                'courier_phone' => '12345', // juda qisqa
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('courier_phone');

        $this->assertSame('preparing', $order->fresh()->status->value);
    }

    public function test_taxi_courier_without_any_phone_is_rejected(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", ['courier_type' => 'taxi'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('courier_phone');
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
