<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->randomElement(['Lag\'mon', 'Osh', 'Manti', 'Somsa', 'Shashlik', 'Burger', 'Lavash']),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->numberBetween(1_500_000, 8_000_000), // tiyin
            'photo_url' => null,
            'prep_time_min' => fake()->numberBetween(5, 30),
            'is_available' => true,
            'sort_order' => fake()->numberBetween(0, 30),
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn () => ['is_available' => false]);
    }
}
