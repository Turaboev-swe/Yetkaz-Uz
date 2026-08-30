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
            'district_id' => null,
            'label' => fake()->randomElement([Address::LABEL_HOME, Address::LABEL_WORK, null]),
            'lat' => fake()->latitude(40.4, 41.0),
            'lng' => fake()->longitude(71.9, 73.0),
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
