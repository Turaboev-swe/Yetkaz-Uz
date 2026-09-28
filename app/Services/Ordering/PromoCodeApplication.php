<?php

namespace App\Services\Ordering;

/**
 * PromoCodeService::quote() / applyForOrder() natijasi — buyurtmaga yozib
 * qo'yiladigan chegirma snapshoti (tiyinда). Promokodsiz buyurtma uchun `none()`.
 */
final readonly class PromoCodeApplication
{
    public function __construct(
        public ?int $promoCodeId,
        public int $discountAmount,
        public int $restaurantShare,
        public int $platformShare,
    ) {}

    public static function none(): self
    {
        return new self(null, 0, 0, 0);
    }

    /** @return array{promo_code_id: ?int, discount_amount: int, discount_restaurant_share: int, discount_platform_share: int} */
    public function toOrderAttributes(): array
    {
        return [
            'promo_code_id' => $this->promoCodeId,
            'discount_amount' => $this->discountAmount,
            'discount_restaurant_share' => $this->restaurantShare,
            'discount_platform_share' => $this->platformShare,
        ];
    }
}
