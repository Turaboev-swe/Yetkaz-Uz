<?php

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Region> */
class RegionFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name.' viloyati',
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'is_active' => true,
        ];
    }
}
