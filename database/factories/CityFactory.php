<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<City> */
class CityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'center_lat' => fake()->latitude(41.2, 41.4),   // Toshkent atrofi
            'center_lng' => fake()->longitude(69.1, 69.4),
            'is_active' => true,
        ];
    }
}
