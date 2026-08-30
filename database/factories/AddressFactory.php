<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Uy', 'Ish', null]),
            'lat' => fake()->latitude(41.2, 41.4),
            'lng' => fake()->longitude(69.1, 69.4),
            'address_text' => fake()->streetAddress(),
            'entrance' => (string) fake()->numberBetween(1, 6),
            'floor' => (string) fake()->numberBetween(1, 9),
            'apartment' => (string) fake()->numberBetween(1, 120),
            'note' => null,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
