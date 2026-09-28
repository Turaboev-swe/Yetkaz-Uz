<?php

namespace App\Services\Ordering;

use App\Models\PromoCode;
use App\Models\Restaurant;
use Illuminate\Validation\ValidationException;

/**
 * Promokodni tekshiradi va chegirmani platforma/restoran o'rtasida bo'ladi.
 *
 * YAXLITLASH QOIDASI (aniq belgilangan, o'zgarmas): restoran ulushi PASTGA
 * yaxlitlanadi (floor), TOQ QOLDIQ PLATFORMAGA ketadi. Masalan 1005 tiyin
 * chegirma, ulush 50% bo'lsa: restoran = floor(1005*50/100) = 502,
 * platforma = 1005 - 502 = 503. Bu ikkalasining yig'indisi HAR DOIM
 * `discount_amount` ga teng bo'lishini kafolatlaydi (float xatosi yo'q,
 * bir tiyin ham yo'qolmaydi/ortiqcha hosil bo'lmaydi).
 */
class PromoCodeService
{
    /**
     * @throws ValidationException Kod topilmasa, faol bo'lmasa, muddati
     *                             o'tgan yoki boshqa restoranга bog'langan bo'lsa.
     */
    public function apply(?string $code, Restaurant $restaurant, int $subtotal): PromoCodeApplication
    {
        if (blank($code)) {
            return PromoCodeApplication::none();
        }

        $promo = PromoCode::query()->where('code', mb_strtoupper(trim($code)))->first();

        if ($promo === null || ! $promo->isUsableFor($restaurant)) {
            throw ValidationException::withMessages(['promo_code' => __('messages.promo_code_invalid')]);
        }

        $discount = $promo->discountFor($subtotal);
        [$restaurantShare, $platformShare] = $this->split($discount, $promo->restaurant_share_percent);

        return new PromoCodeApplication($promo->id, $discount, $restaurantShare, $platformShare);
    }

    /** @return array{0: int, 1: int} [restoran ulushi, platforma ulushi] */
    private function split(int $discountAmount, int $restaurantSharePercent): array
    {
        $restaurantShare = intdiv($discountAmount * $restaurantSharePercent, 100);
        $platformShare = $discountAmount - $restaurantShare;

        return [$restaurantShare, $platformShare];
    }
}
