<?php

namespace Database\Factories;

use App\Models\District;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<District> */
class DistrictFactory extends Factory
{
    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'name' => fake()->unique()->city().' tumani',
            // Andijon viloyati atrofida
            'center_lat' => fake()->latitude(40.4, 41.0),
            'center_lng' => fake()->longitude(71.9, 73.0),
            'is_active' => true,
        ];
    }
}
