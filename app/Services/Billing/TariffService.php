<?php

namespace App\Services\Billing;

use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Models\Restaurant;
use App\Models\RestaurantBillingPlan;
use App\Models\RestaurantInvoice;
use App\Models\RestaurantPayment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restoran tarifini o'zgartirish: tarif TARIXIGA yozuv qo'shadi (effective_from — oyning 1-kuni),
 * `restaurants.billing_*` ("joriy tarif", forma uchun) ni JORIY oy uchun amaldagi yozuvga moslaydi
 * (kelajak yozuviga emas) va fixed tarifda joriy oy uchun hisob hali yo'q bo'lsa uni DARHOL yaratadi
 * (oy o'rtasida boshlanganda proratsiya bilan — 1-kungacha kutilmaydi). Joriy oydan fixed boshlansa
 * «Boshlangan sana» majburiy; sanasiz to'liq hisob yaratilmaydi.
 *
 * Joriy oy tuzatishi: shu oy uchun fixed hisob yaratilgan bo'lsa-yu, restoran aslida foizda bo'lishi
 * kerak bo'lsa, fixed -> percent ga o'tish mumkin — faqat shu hisobda faol (bekor qilinmagan) to'lov
 * bo'lmasa, tasdiq va sabab bilan. Fixed hisob `void` ga o'tadi (o'chirilmaydi). Tarixda joriy oy
 * yozuvi yangilanadi, keyingi oydagi unga teng yozuv olib tashlanadi.
 */
class TariffService
{
    public function __construct(
        private readonly BillingCalculator $calculator,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  CarbonInterface|string  $effectiveMonth  qaysi oydan (joriy yoki keyingi oy; o'tmish mumkin emas)
     * @param  string|null  $correctionReason  joriy oy fixed hisobini bekor qilish sababi (tuzatishda majburiy)
     * @param  bool  $correctionConfirmed  tuzatish tasdiqlandimi (tuzatishda majburiy)
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
        ?string $correctionReason = null,
        bool $correctionConfirmed = false,
    ): ?RestaurantBillingPlan {
        $effective = BillingCalculator::monthStart($effectiveMonth);

        return DB::transaction(function () use ($restaurant, $plan, $rateBp, $monthlyFee, $effective, $changedBy, $correctionReason, $correctionConfirmed): ?RestaurantBillingPlan {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            $entry = null;

            if ($this->check($restaurant, $plan, $rateBp, $monthlyFee, $effective, null, $correctionReason, $correctionConfirmed)) {
                $toVoid = $this->conflictingInvoice($restaurant, $plan, $effective, $correctionReason, $correctionConfirmed);

                if ($toVoid !== null) {
                    $this->invoices->void($toVoid, (string) $correctionReason, $changedBy);
                }

                $entry = RestaurantBillingPlan::query()->updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'effective_from' => $effective->format('Y-m-d')],
                    ['plan' => $plan, 'rate_bp' => $rateBp, 'monthly_fee' => $monthlyFee, 'created_by' => $changedBy],
                );

                $this->dropRedundantFutureEntries($restaurant);
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
     * @throws ValidationException o'tgan oy, noto'g'ri qiymat, shu oy hisobi boshqa tarif bilan yaratilgan
     *                             (tuzatish shartlari bajarilmasa) yoki tuzatishda sabab/tasdiq yo'q
     */
    public function check(
        Restaurant $restaurant,
        ?BillingPlan $plan,
        int $rateBp,
        int $monthlyFee,
        CarbonInterface|string $effectiveMonth,
        CarbonInterface|string|null $startedAt = null,
        ?string $correctionReason = null,
        bool $correctionConfirmed = false,
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

        $this->conflictingInvoice($restaurant, $plan, $effective, $correctionReason, $correctionConfirmed);

        // Fixed tarif JORIY oydan yangi boshlansa, «Boshlangan sana» majburiy va shu oy ichida bo'lishi
        // kerak (proratsiya shundan hisoblanadi). Keyingi oydan boshlansa sana kerak emas — 1-kundan.
        if ($plan === BillingPlan::Fixed && $effective->equalTo($current) && $inForce->plan !== BillingPlan::Fixed) {
            $this->assertStartedInMonth($startedAt ?? $restaurant->billing_started_at, $effective);
        }

        return true;
    }

    /**
     * Shu tarif o'zgarishi bilan bekor qilinadigan (void) hisob bormi — forma tuzatish maydonlarini
     * ko'rsatishi uchun. Hech narsa tashlamaydi. Faol to'lovi bor (yoki kechirilgan) hisob uchun null:
     * u tuzatilmaydi, forma saqlanganda `check()` o'zi rad etadi va sababini aytadi.
     */
    public function correctableInvoice(Restaurant $restaurant, ?BillingPlan $plan, CarbonInterface|string $effectiveMonth): ?RestaurantInvoice
    {
        if (! $restaurant->exists || $plan !== BillingPlan::Percent) {
            return null;
        }

        $effective = BillingCalculator::monthStart($effectiveMonth);
        $current = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()));

        if (! $effective->equalTo($current)) {
            return null;
        }

        $invoice = $this->invoiceFor($restaurant, $effective);

        if ($invoice === null || $invoice->plan !== BillingPlan::Fixed || $invoice->status === InvoiceStatus::Waived) {
            return null;
        }

        return RestaurantPayment::query()->active()->where('invoice_id', $invoice->id)->exists() ? null : $invoice;
    }

    private function assertStartedInMonth(CarbonInterface|string|null $startedAt, CarbonImmutable $month): void
    {
        if ($startedAt === null || $startedAt === '') {
            throw ValidationException::withMessages([
                'billing_started_at' => 'Joriy oydan qat‘iy tarif boshlanganda «Boshlangan sana» majburiy (birinchi oy kunlarga proporsional hisoblanadi).',
            ]);
        }

        $started = BillingCalculator::monthStart($startedAt instanceof CarbonInterface ? $startedAt->format('Y-m-d') : substr((string) $startedAt, 0, 10));

        if (! $started->equalTo($month)) {
            throw ValidationException::withMessages([
                'billing_started_at' => '«Boshlangan sana» '.BillingCalculator::monthLabel($month).' ichida bo‘lishi kerak.',
            ]);
        }
    }

    /**
     * `restaurants.billing_*` = JORIY oy uchun amaldagi tarif: tarix bo'yicha `effective_from <= joriy oy`
     * eng oxirgi yozuv (kelajak yozuvi emas — u alohida «keyingi tarif» sifatida ko'rsatiladi). Joriy oyda
     * amaldagi yozuv yo'q (faqat kelajak yozuvi bor) bo'lsa — tarifsiz. Tarix butunlay bo'sh bo'lsa tegilmaydi.
     * Hisob-kitob manbasi emas (u tarix jadvali).
     */
    public function syncCurrentTariff(Restaurant $restaurant): void
    {
        if (! RestaurantBillingPlan::query()->where('restaurant_id', $restaurant->id)->exists()) {
            return;
        }

        $month = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()))->format('Y-m-d');

        $entry = RestaurantBillingPlan::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('effective_from', '<=', $month)
            ->orderByDesc('effective_from')
            ->first();

        $values = $entry === null
            ? ['billing_plan' => null]
            : ['billing_plan' => $entry->plan, 'commission_rate_bp' => $entry->rate_bp, 'monthly_fee' => $entry->monthly_fee];

        $restaurant->forceFill($values)->save();
    }

    /**
     * Barcha tarif tarixi bor restoranlarda joriy tarifni sinxronlaydi (oy almashganda kelajak yozuvi
     * «joriy» bo'ladi). Qaytaradi: o'zgargan restoranlar soni.
     */
    public function syncAllCurrentTariffs(): int
    {
        $changed = 0;

        Restaurant::query()->whereHas('billingPlans')->orderBy('id')->each(function (Restaurant $restaurant) use (&$changed): void {
            $this->syncCurrentTariff($restaurant);

            if ($restaurant->wasChanged()) {
                $changed++;
            }
        });

        return $changed;
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

        // Darhol hisob faqat «Boshlangan sana» shu oy ichida bo'lganda (proratsiya shundan); sanasiz
        // to'liq hisob yaratilmaydi — keyingi 1-kun generatsiyasi yaratadi.
        if ($restaurant->billing_started_at === null
            || ! BillingCalculator::monthStart($restaurant->billing_started_at->format('Y-m-d'))->equalTo($month)) {
            return null;
        }

        return $this->invoiceFor($restaurant, $month) !== null ? null : $this->invoices->generate($restaurant, $month);
    }

    /** Shu oyning faol (bekor qilinmagan) hisobi. */
    private function invoiceFor(Restaurant $restaurant, CarbonImmutable $month): ?RestaurantInvoice
    {
        return RestaurantInvoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('period', $month->format('Y-m-d'))
            ->first();
    }

    /**
     * Bir oyga bitta faol hisob: effective oy uchun hisob allaqachon boshqa tarif bilan yaratilgan bo'lsa,
     * shu oydan o'zgartirib bo'lmaydi (keyingi oydan o'zgartiriladi) — ISTISNO: JORIY oyda fixed -> percent
     * tuzatish. U faqat shu hisobda faol to'lov bo'lmaganda, sabab va tasdiq bilan mumkin; bekor qilinadigan
     * hisobni qaytaradi.
     *
     * @throws ValidationException
     */
    private function conflictingInvoice(
        Restaurant $restaurant,
        ?BillingPlan $plan,
        CarbonImmutable $effective,
        ?string $reason,
        bool $confirmed,
    ): ?RestaurantInvoice {
        if ($plan === null) {
            return null;
        }

        $invoice = $this->invoiceFor($restaurant, $effective);

        if ($invoice === null || $invoice->plan === $plan) {
            return null;
        }

        $reject = fn (string $message) => ValidationException::withMessages(['effective_month' => $message]);
        $label = BillingCalculator::monthLabel($effective);
        $correctable = $invoice->plan === BillingPlan::Fixed && $plan === BillingPlan::Percent
            && $effective->equalTo(BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone())));

        if (! $correctable) {
            throw $reject("{$label} uchun hisob-kitob allaqachon «{$invoice->plan->label()}» tarifi bilan yaratilgan. Tarifni keyingi oydan o‘zgartiring.");
        }

        if (RestaurantPayment::query()->active()->where('invoice_id', $invoice->id)->exists()) {
            throw $reject("{$label} uchun «{$invoice->plan->label()}» hisobida faol to‘lov bor, shuning uchun uni bekor qilib tarifni shu oydan o‘zgartirib bo‘lmaydi. Avval to‘lovni bekor qiling yoki tarifni keyingi oydan o‘zgartiring.");
        }
        if ($invoice->status === InvoiceStatus::Waived) {
            throw $reject("{$label} uchun hisob kechirilgan — uni bekor qilib tarifni shu oydan o‘zgartirib bo‘lmaydi. Tarifni keyingi oydan o‘zgartiring.");
        }
        if (trim((string) $reason) === '') {
            throw ValidationException::withMessages(['billing_correction_reason' => "{$label} hisobi bekor qilinadi — tuzatish sababini kiriting."]);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['billing_correction_confirm' => "{$label} uchun «{$invoice->plan->label()}» hisobi bekor qilinishini tasdiqlang."]);
        }

        return $invoice;
    }

    /**
     * Tarix tozalash: joriy oydan KEYINGI yozuv oldingisi bilan bir xil bo'lib qolsa (masalan joriy oy
     * foizga tuzatilib, keyingi oydagi foiz yozuvi ortiqcha bo'lib qolgan) — olib tashlanadi. Joriy va
     * o'tgan oy yozuvlariga tegilmaydi.
     */
    private function dropRedundantFutureEntries(Restaurant $restaurant): void
    {
        $current = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()))->format('Y-m-d');
        $previous = null;

        RestaurantBillingPlan::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('effective_from')
            ->get()
            ->each(function (RestaurantBillingPlan $entry) use (&$previous, $current): void {
                if ($previous !== null && $entry->effective_from->format('Y-m-d') > $current
                    && (new BillingTariff($previous->plan, (int) $previous->rate_bp, (int) $previous->monthly_fee))
                        ->sameAs($entry->plan, (int) $entry->rate_bp, (int) $entry->monthly_fee)) {
                    $entry->delete();

                    return;
                }

                $previous = $entry;
            });
    }
}
