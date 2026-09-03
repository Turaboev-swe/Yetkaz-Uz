<?php

namespace Database\Factories;

use App\Enums\StaffRole;
use App\Models\Restaurant;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Staff> */
class StaffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'telegram_chat_id' => null,
            'password' => 'password',
            'role' => StaffRole::RestaurantOwner,
            'restaurant_id' => Restaurant::factory(),
            'is_active' => true,
        ];
    }

    /** Bildirishnoma chat ID bilan (keyingi bosqich — xabar yuborish). */
    public function withTelegramChatId(int $chatId = 424299): static
    {
        return $this->state(fn () => ['telegram_chat_id' => $chatId]);
    }

    public function platformAdmin(): static
    {
        return $this->state(fn () => [
            'role' => StaffRole::PlatformAdmin,
            'restaurant_id' => null,
        ]);
    }

    public function owner(Restaurant|int $restaurant): static
    {
        return $this->state(fn () => [
            'role' => StaffRole::RestaurantOwner,
            'restaurant_id' => $restaurant instanceof Restaurant ? $restaurant->id : $restaurant,
        ]);
    }

    public function kitchenStaff(Restaurant|int $restaurant): static
    {
        return $this->state(fn () => [
            'role' => StaffRole::KitchenStaff,
            'restaurant_id' => $restaurant instanceof Restaurant ? $restaurant->id : $restaurant,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
