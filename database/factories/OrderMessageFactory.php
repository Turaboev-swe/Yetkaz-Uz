<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderMessage> */
class OrderMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'sender_type' => OrderMessage::SENDER_CUSTOMER,
            'sender_id' => null,
            'body' => fake()->sentence(6),
        ];
    }

    public function fromRestaurant(): static
    {
        return $this->state(fn () => ['sender_type' => OrderMessage::SENDER_RESTAURANT]);
    }
}
