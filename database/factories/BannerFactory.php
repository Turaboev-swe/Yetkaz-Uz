<?php

namespace Database\Factories;

use App\Enums\BannerTarget;
use App\Models\Banner;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Banner> */
class BannerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'image_path' => 'banners/'.fake()->uuid().'.jpg',
            'title' => fake()->words(3, true),
            'target_type' => BannerTarget::None,
            'restaurant_id' => null,
            'sort_order' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    /** Bosilganda shu restoran menyusi ochiladi. */
    public function forRestaurant(Restaurant $restaurant): static
    {
        return $this->state(fn () => [
            'target_type' => BannerTarget::Restaurant,
            'restaurant_id' => $restaurant->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
