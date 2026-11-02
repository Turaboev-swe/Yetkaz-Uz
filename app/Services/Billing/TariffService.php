<?php

namespace App\Services\Billing;

use App\Enums\BillingPlan;
use App\Models\Restaurant;
use App\Models\RestaurantBillingPlan;
use App\Models\RestaurantInvoice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restoran tarifini o'zgartirish: tarif TARIXIGA yozuv qo'shadi (effective_from — oyning 1-kuni),
 * `restaurants.billing_*` ("joriy tarif", forma uchun) ni eng oxirgi yozuvga moslaydi va fixed
 * tarifda joriy oy uchun hisob hali yo'q bo'lsa uni DARHOL yaratadi (oy o'rtasida boshlanganda
 * proratsiya bilan — 1-kungacha kutilmaydi).
 */
class TariffService
{
    public function __construct(
        private readonly BillingCalculator $calculator,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  CarbonInterface|string  $effectiveMonth  qaysi oydan (joriy yoki keyingi oy; o'tmish mumkin emas)
     * @return RestaurantBillingPlan|null yangi/yangilangan yozuv; tarif o'zgarmagan bo'lsa null
     *
     * @throws ValidationException
     */
    public function apply(
        Restaurant $restaurant,
        ?BillingPlan $plan,
        int $rateBp,
        int $monthlyFee,
        CarbonInterface|string $effectiveMonth,
        ?int $changedBy = null,
    ): ?RestaurantBillingPlan {
        $effective = BillingCalculator::monthStart($effectiveMonth);

        return DB::transaction(function () use ($restaurant, $plan, $rateBp, $monthlyFee, $effective, $changedBy): ?RestaurantBillingPlan {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            $entry = null;

            if ($this->check($restaurant, $plan, $rateBp, $monthlyFee, $effective)) {
                $entry = RestaurantBillingPlan::query()->updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'effective_from' => $effective->format('Y-m-d')],
                    ['plan' => $plan, 'rate_bp' => $rateBp, 'monthly_fee' => $monthlyFee, 'created_by' => $changedBy],
                );
            }

            $this->syncCurrentTariff($restaurant);
            $this->ensureCurrentMonthInvoice($restaurant);

            return $entry;
        });
    }

    /**
     * Tarif o'zgarishini tekshiradi (hech narsa yozmaydi). Qaytaradi: yangi tarix yozuvi kerakmi
     * (shu oydan amalda bo'lgan tarif bilan bir xil bo'lsa — yo'q).
     *
     * @throws ValidationException o'tgan oy, noto'g'ri qiymat yoki shu oy hisobi boshqa tarif bilan yaratilgan
     */
    public function check(
        Restaurant $restaurant,
        ?BillingPlan $plan,
        int $rateBp,
        int $monthlyFee,
        CarbonInterface|string $effectiveMonth,
    ): bool {
        $effective = BillingCalculator::monthStart($effectiveMonth);
        $current = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()));

        if ($effective->lessThan($current)) {
            throw ValidationException::withMessages(['effective_month' => 'Tarifni o‘tgan oydan boshlab o‘zgartirib bo‘lmaydi.']);
        }
        if ($rateBp < 0 || $rateBp > 10_000) {
            throw ValidationException::withMessages(['commission_rate_bp' => 'Stavka 0 dan 100% gacha bo‘lishi kerak.']);
        }
        if ($monthlyFee < 0) {
            throw ValidationException::withMessages(['monthly_fee' => 'Oylik to‘lov manfiy bo‘lmasligi kerak.']);
        }

        $inForce = $this->calculator->tariffFor($restaurant, $effective);

        if ($inForce->sameAs($plan, $rateBp, $monthlyFee) || ($plan === null && $inForce->plan === null)) {
            return false;
        }

        $this->guardExistingInvoice($restaurant, $plan, $effective);

        return true;
    }

    /**
     * `restaurants.billing_*` = tarix bo'yicha eng oxirgi yozuv (ko'rsatish/forma uchun; hisob-kitob
     * manbasi emas). Tarix bo'sh bo'lsa tegilmaydi.
     */
    public function syncCurrentTariff(Restaurant $restaurant): void
    {
        $latest = RestaurantBillingPlan::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('effective_from')
            ->first();

        if ($latest === null) {
            return;
        }

        $restaurant->forceFill([
            'billing_plan' => $latest->plan,
            'commission_rate_bp' => $latest->rate_bp,
            'monthly_fee' => $latest->monthly_fee,
        ])->save();
    }

    /**
     * Joriy oyda fixed tarif amalda bo'lsa-yu, bu oy uchun hisob hali yo'q bo'lsa — darhol yaratadi
     * (billing_started_at oy o'rtasida bo'lsa proratsiya bilan). Mavjud hisobga tegmaydi.
     */
    public function ensureCurrentMonthInvoice(Restaurant $restaurant): ?RestaurantInvoice
    {
        $month = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()));

        if ($this->calculator->tariffFor($restaurant, $month)->plan !== BillingPlan::Fixed) {
            return null;
        }

        $exists = RestaurantInvoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('period', $month->format('Y-m-d'))
            ->exists();

        return $exists ? null : $this->invoices->generate($restaurant, $month);
    }

    /**
     * Bir oyga bitta hisob (unique restaurant+period): effective oy uchun hisob allaqachon boshqa
     * tarif bilan yaratilgan bo'lsa, shu oydan o'zgartirib bo'lmaydi (masalan joriy oy fixed hisobi
     * bor — percent'ga keyingi oydan o'tiladi).
     */
    private function guardExistingInvoice(Restaurant $restaurant, ?BillingPlan $plan, CarbonImmutable $effective): void
    {
        if ($plan === null) {
            return;
        }

        $invoice = RestaurantInvoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('period', $effective->format('Y-m-d'))
            ->first();

        if ($invoice !== null && $invoice->plan !== $plan) {
            throw ValidationException::withMessages([
                'effective_month' => BillingCalculator::monthLabel($effective).' uchun hisob-kitob allaqachon «'.$invoice->plan->label().'» tarifi bilan yaratilgan. Tarifni keyingi oydan o‘zgartiring.',
            ]);
        }
    }
}
