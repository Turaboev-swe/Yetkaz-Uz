<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(3_000_000, 20_000_000);
        $deliveryFee = fake()->randomElement([0, 1_000_000, 1_500_000]);

        return [
            'order_number' => strtoupper(Str::random(8)),
            'user_id' => User::factory(),
            'restaurant_id' => Restaurant::factory(),
            'address_id' => Address::factory(),
            'items' => [
                ['product_id' => 1, 'name' => 'Lag\'mon', 'price' => 2_500_000, 'qty' => 2, 'note' => null],
            ],
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $subtotal + $deliveryFee,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'status' => 'new',
            'eta_minutes' => fake()->numberBetween(25, 60),
            'distance_km' => fake()->randomFloat(2, 0.5, 8),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
