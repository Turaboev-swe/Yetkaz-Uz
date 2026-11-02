<?php

namespace App\Filament\Admin\Resources\RestaurantResource\Concerns;

use App\Enums\BillingPlan;
use App\Models\Restaurant;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\TariffService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/**
 * Restoran formasidagi "Tarif" bo'limi: tarif maydonlari to'g'ridan-to'g'ri restaurants jadvaliga
 * yozilmaydi, TariffService orqali tarif tarixiga yoziladi ("Qaysi oydan" bilan). Tarif tarixi
 * davr hisobining manbasi; restaurants.billing_* faqat "joriy tarif" sifatida sinxronlanadi.
 */
trait HandlesBillingTariff
{
    /** @var array<string, mixed> */
    protected array $billingInput = [];

    /** @param array<string, mixed> $data */
    protected function pullBillingInput(array $data): array
    {
        $this->billingInput = [];

        foreach (['billing_plan', 'commission_rate_bp', 'monthly_fee', 'effective_month'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->billingInput[$key] = $data[$key];
                unset($data[$key]);
            }
        }

        return $data;
    }

    /** Saqlashdan OLDIN: o'tgan oy / boshqa tarif bilan yaratilgan hisob bo'lsa hech narsa saqlanmaydi. */
    protected function validateBillingInput(Restaurant $restaurant): void
    {
        if ($this->billingInput === []) {
            return;
        }

        [$plan, $rateBp, $fee, $effective] = $this->billingValues();

        try {
            app(TariffService::class)->check($restaurant, $plan, $rateBp, $fee, $effective);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["data.{$key}" => $messages])->all(),
            );
        }
    }

    protected function applyBillingInput(Restaurant $restaurant): void
    {
        if ($this->billingInput === []) {
            return;
        }

        [$plan, $rateBp, $fee, $effective] = $this->billingValues();

        app(TariffService::class)->apply($restaurant->refresh(), $plan, $rateBp, $fee, $effective, Filament::auth()->id());
        $restaurant->refresh();
    }

    /** @return array{0: ?BillingPlan, 1: int, 2: int, 3: CarbonImmutable} */
    private function billingValues(): array
    {
        $input = $this->billingInput;
        $current = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()));

        return [
            BillingPlan::tryFrom((string) ($input['billing_plan'] ?? '')),
            (int) ($input['commission_rate_bp'] ?? config('billing.default_rate_bp')),
            (int) ($input['monthly_fee'] ?? config('billing.default_monthly_fee')),
            ($input['effective_month'] ?? 'current') === 'next' ? $current->addMonth() : $current,
        ];
    }
}
