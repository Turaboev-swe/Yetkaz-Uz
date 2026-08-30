<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(10_000_000, 999_999_999),
            'full_name' => fake()->name(),
            'phone' => '+998'.fake()->numberBetween(900_000_000, 999_999_999),
            'language' => fake()->randomElement(User::LANGUAGES),
            'profile_completed' => true,
            'last_seen_at' => now(),
        ];
    }

    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'full_name' => null,
            'phone' => null,
            'profile_completed' => false,
        ]);
    }
}
