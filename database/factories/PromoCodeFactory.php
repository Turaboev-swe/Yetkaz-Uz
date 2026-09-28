<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromoCode> */
class PromoCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PROMO##??')),
            'discount_type' => DiscountType::Percent,
            'discount_value' => 20,
            'restaurant_share_percent' => 50, // baza standarti bilan bir xil — yarmini restoran, yarmini platforma
            'restaurant_id' => null,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function percent(int $value): static
    {
        return $this->state(fn () => ['discount_type' => DiscountType::Percent, 'discount_value' => $value]);
    }

    /** @param  int  $tiyin  Belgilangan chegirma summasi, tiyinда. */
    public function fixed(int $tiyin): static
    {
        return $this->state(fn () => ['discount_type' => DiscountType::Fixed, 'discount_value' => $tiyin]);
    }

    public function restaurantShare(int $percent): static
    {
        return $this->state(fn () => ['restaurant_share_percent' => $percent]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
