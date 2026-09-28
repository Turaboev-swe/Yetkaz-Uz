<?php

namespace App\Services\Ordering;

use App\Models\PromoCode;
use App\Models\Restaurant;
use Illuminate\Validation\ValidationException;

/**
 * Promokodni tekshiradi va chegirmani platforma/restoran o'rtasida bo'ladi.
 *
 * YAXLITLASH QOIDASI (aniq belgilangan, o'zgarmas) — BUTUN SO'M darajasida:
 *   - chegirma butun so'mgacha pastga (PromoCode::discountFor);
 *   - restoran ulushi butun so'mgacha PASTGA (floor), TOQ SO'M QOLDIG'I
 *     PLATFORMAGA. Masalan 15 005 so'm chegirma, ulush 50%:
 *     restoran = floor(15 005 * 50 / 100) = 7 502 so'm, platforma = 7 503 so'm.
 *
 * Ikkala ulush ham butun so'm va yig'indisi HAR DOIM `discount_amount` ga
 * teng — hisobot/CSV'da (so'mда ko'rsatiladi) ham aniq mos keladi. Tiyin
 * darajasida bo'linsa 15 005 so'm 7 502,50 + 7 502,50 bo'lib, ekranda
 * 7 502 + 7 502 = 15 004 chiqardi (1 so'm yo'qolardi).
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

    /**
     * `$discountAmount` — butun so'm (tiyinда, 100 ga karrali). Restoran ulushi
     * so'mда hisoblanib pastga yaxlitlanadi, qolgani platformaga.
     *
     * @return array{0: int, 1: int} [restoran ulushi, platforma ulushi] — tiyinда
     */
    private function split(int $discountAmount, int $restaurantSharePercent): array
    {
        $restaurantShare = intdiv($discountAmount * $restaurantSharePercent, 100 * 100) * 100;
        $platformShare = $discountAmount - $restaurantShare;

        return [$restaurantShare, $platformShare];
    }
}
