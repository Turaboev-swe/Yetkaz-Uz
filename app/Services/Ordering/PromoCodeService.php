<?php

namespace App\Services\Ordering;

use App\Enums\PromoCodeError;
use App\Exceptions\PromoCodeException;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Promokodni tekshiradi va chegirmani platforma/restoran o'rtasida bo'ladi.
 *
 * Ikki kirish nuqtasi:
 *   - quote()         — POST /api/promo-codes/validate: oldindan ko'rsatish, qulfsiz;
 *   - applyForOrder() — OrderService::place(): tranzaksiya ichida, qatorni qulflab.
 * Ikkalasi bir xil tekshiruv va hisobdan o'tadi (evaluate) — ko'rsatilgan
 * chegirma va buyurtmaga yoziladigan chegirma bir manbadan.
 *
 * Ishlatilish = shu kod bilan yaratilgan BEKOR QILINMAGAN buyurtma
 * (PromoCode::usages) — bekor qilinsa limit qaytadi.
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
     * Oldindan ko'rsatish (checkout'dagi "Qo'llash"). Qatorni qulflamaydi —
     * yakuniy tekshiruv buyurtma yaratishda applyForOrder() da qulf ostida
     * qayta bajariladi (orada limit tugagan bo'lishi mumkin).
     *
     * @throws PromoCodeException aniq sabab bilan
     */
    public function quote(string $code, Restaurant $restaurant, int $subtotal, User $user): PromoCodeApplication
    {
        return $this->evaluate($this->find($code, lock: false), $restaurant, $subtotal, $user);
    }

    /**
     * Buyurtma uchun — FAQAT tranzaksiya ichida. Promokod qatori tranzaksiya
     * oxirigacha qulflanadi (SELECT ... FOR UPDATE): bir vaqtda kelgan ikkinchi
     * buyurtma shu qatorni kutadi va limitni birinchisi yozilgach (commit)
     * sanaydi — oxirgi bo'sh o'rinni ikki mijoz birdaniga egallay olmaydi.
     *
     * @throws PromoCodeException aniq sabab bilan
     */
    public function applyForOrder(?string $code, Restaurant $restaurant, int $subtotal, User $user): PromoCodeApplication
    {
        if (blank($code)) {
            return PromoCodeApplication::none();
        }

        // Tranzaksiyasiz FOR UPDATE darhol qo'yib yuboriladi (auto-commit) —
        // poyga holatidan himoya jimgina yo'qolardi.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PromoCodeService::applyForOrder() tranzaksiya ichida chaqirilishi shart.');
        }

        return $this->evaluate($this->find($code, lock: true), $restaurant, $subtotal, $user);
    }

    private function find(string $code, bool $lock): ?PromoCode
    {
        return PromoCode::query()
            ->where('code', mb_strtoupper(trim($code)))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function evaluate(?PromoCode $promo, Restaurant $restaurant, int $subtotal, User $user): PromoCodeApplication
    {
        $error = $promo === null ? PromoCodeError::NotFound : $this->rejection($promo, $restaurant, $user);

        if ($error !== null) {
            throw new PromoCodeException($error);
        }

        $discount = $promo->discountFor($subtotal);
        [$restaurantShare, $platformShare] = $this->split($discount, $promo->restaurant_share_percent);

        return new PromoCodeApplication($promo->id, $discount, $restaurantShare, $platformShare);
    }

    private function rejection(PromoCode $promo, Restaurant $restaurant, User $user): ?PromoCodeError
    {
        if (($error = $promo->rejectionFor($restaurant)) !== null) {
            return $error;
        }

        if ($promo->total_usage_limit !== null && $promo->usages()->count() >= $promo->total_usage_limit) {
            return PromoCodeError::UsageLimitReached;
        }

        if ($promo->per_user_limit !== null
            && $promo->usages()->where('user_id', $user->id)->count() >= $promo->per_user_limit) {
            return PromoCodeError::UserLimitReached;
        }

        return null;
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
