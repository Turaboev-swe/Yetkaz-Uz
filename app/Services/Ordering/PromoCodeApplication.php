<?php

namespace App\Services\Ordering;

/**
 * PromoCodeService::quote() / applyForOrder() natijasi — buyurtmaga yoziladigan
 * chegirma SNAPSHOT'i (tiyinда, butun so'm). Promokodsiz buyurtma uchun `none()`.
 *
 * Buyurtma yaratilgach hisob-kitob faqat shu snapshot'dan olinadi — kodning
 * ulushi keyin o'zgartirilsa ham eski buyurtma o'zgarmaydi.
 */
final readonly class PromoCodeApplication
{
    public function __construct(
        public ?int $promoCodeId,
        public int $discountAmount,
        public int $restaurantAmount, // restoran qoplaydigan qism
        public int $platformAmount,   // platforma qoplaydigan qism (restoranga to'lanadi)
    ) {}

    public static function none(): self
    {
        return new self(null, 0, 0, 0);
    }

    /** @return array{promo_code_id: ?int, discount_amount: int, discount_restaurant_amount: int, discount_platform_amount: int} */
    public function toOrderAttributes(): array
    {
        return [
            'promo_code_id' => $this->promoCodeId,
            'discount_amount' => $this->discountAmount,
            'discount_restaurant_amount' => $this->restaurantAmount,
            'discount_platform_amount' => $this->platformAmount,
        ];
    }
}
