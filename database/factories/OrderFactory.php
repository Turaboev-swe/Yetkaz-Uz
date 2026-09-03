<?php

namespace Database\Factories;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(3_000_000, 20_000_000);
        $deliveryFee = fake()->randomElement([0, 1_000_000, 1_500_000]);

        return [
            'order_number' => 'YT-'.fake()->unique()->numerify('######'),
            'user_id' => User::factory(),
            'restaurant_id' => Restaurant::factory(),
            'address_id' => Address::factory(),
            'delivery_type' => DeliveryType::Delivery,
            'items' => [
                ['product_id' => 1, 'name' => 'Lag\'mon', 'price' => 2_500_000, 'qty' => 2, 'note' => null],
            ],
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $subtotal + $deliveryFee,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'eta_minutes' => fake()->numberBetween(25, 60),
            'distance_km' => fake()->randomFloat(2, 0.5, 8),
        ];
    }

    public function status(OrderStatus|string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function forRestaurant(Restaurant|int $restaurant): static
    {
        return $this->state(fn () => [
            'restaurant_id' => $restaurant instanceof Restaurant ? $restaurant->id : $restaurant,
        ]);
    }

    /** Buyurtma qachon berilgan (created_at). Hisobot testlari uchun. */
    public function placedAt(Carbon|string $at): static
    {
        return $this->state(fn () => ['created_at' => Carbon::parse($at)])
            ->afterCreating(fn (Order $order) => $order->forceFill(['created_at' => Carbon::parse($at)])->saveQuietly());
    }

    /** Yetkazilgan: status + dispatched_at + delivered_at. */
    public function delivered(Carbon|string|null $deliveredAt = null): static
    {
        return $this->state(function () use ($deliveredAt) {
            $delivered = $deliveredAt ? Carbon::parse($deliveredAt) : Carbon::now();

            return [
                'status' => OrderStatus::Delivered,
                'payment_status' => PaymentStatus::Paid,
                'dispatched_at' => $delivered->copy()->subMinutes(15),
                'delivered_at' => $delivered,
            ];
        });
    }

    public function cancelled(Carbon|string|null $cancelledAt = null): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => $cancelledAt ? Carbon::parse($cancelledAt) : Carbon::now(),
        ]);
    }

    public function printFailed(): static
    {
        return $this->state(fn () => ['dispatch_failed_at' => Carbon::now()]);
    }

    /**
     * `items` jsonb ni aniq belgilash (top-taomlar testи uchun).
     *
     * @param  list<array{product_id:int, name:string, price:int, qty:int}>  $lines
     */
    public function items(array $lines): static
    {
        return $this->state(fn () => [
            'items' => array_map(fn ($l) => $l + ['note' => null], $lines),
        ]);
    }
}
