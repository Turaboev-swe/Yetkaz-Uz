<?php

namespace App\Services\Billing;

use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use App\Models\RestaurantPayment;
use App\Models\Staff;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hisob-kitoblarni yaratish/yangilash va to'lovlarni yozish (barcha yozuvlar shu yerda).
 */
class InvoiceService
{
    public function __construct(private readonly BillingCalculator $calculator) {}

    /**
     * Restoran uchun shu oy hisob-kitobini yaratadi yoki yangilaydi (idempotent).
     *  - hisoblanmaydigan restoran/oy (tarifsiz, test, boshlanishdan oldin) -> null
     *  - fixed oldindan (davrning 1-kunidan), percent faqat oy tugagach; tugamagan oy uchun
     *    percent -> null (BillingCalculator::canInvoice)
     *  - yangi hisobda tarif snapshot'i DAVR uchun amalda bo'lgan tarifdan (tarix) olinadi
     *  - mavjud hisobda snapshot O'ZGARMAYDI: to'lanmagan/qisman hisob faqat qayta hisoblanadi
     *    (recalculate), `paid` / `waived` hisobga TEGILMAYDI
     */
    public function generate(Restaurant $restaurant, CarbonInterface|string $period): ?RestaurantInvoice
    {
        $monthStart = BillingCalculator::monthStart($period);

        if (! $this->calculator->isBillable($restaurant, $monthStart)) {
            return null;
        }

        $plan = $this->calculator->tariffFor($restaurant, $monthStart)->plan;

        if (! BillingCalculator::canInvoice($plan, $monthStart)) {
            return null;
        }

        return DB::transaction(function () use ($restaurant, $monthStart): RestaurantInvoice {
            $existing = RestaurantInvoice::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('period', $monthStart->format('Y-m-d'))
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->applyRecalculation($existing);
            }

            $result = $this->calculator->calculate($restaurant, $monthStart);

            return RestaurantInvoice::create([
                'restaurant_id' => $restaurant->id,
                'period' => $monthStart->format('Y-m-d'),
                'plan' => $result->plan,
                'rate_bp' => $result->rateBp,
                'fixed_fee' => $result->fixedFee,
                'billing_started_at' => $restaurant->billing_started_at?->format('Y-m-d'),
                'orders_count' => $result->ordersCount,
                'orders_total' => $result->ordersTotal,
                'amount_due' => $result->amountDue,
                'amount_paid' => 0,
                'status' => InvoiceStatus::fromAmounts($result->amountDue, 0),
                'due_date' => self::dueDate($result->plan, $monthStart)->format('Y-m-d'),
            ]);
        });
    }

    /**
     * To'lov muddati: fixed (oldindan) — shu oyning `billing.fixed_due_day`-kuni; percent (keyin) —
     * oy tugagach `billing.due_days` kun.
     */
    public static function dueDate(?BillingPlan $plan, CarbonInterface $monthStart): CarbonImmutable
    {
        $start = CarbonImmutable::instance($monthStart);

        return $plan === BillingPlan::Fixed
            ? $start->day(min((int) config('billing.fixed_due_day'), $start->daysInMonth))
            : $start->addMonth()->addDays((int) config('billing.due_days'))->subDay();
    }

    /**
     * Hisobni o'zining SNAPSHOT tarifi bilan qayta hisoblaydi. Faqat orders_count, orders_total
     * va amount_due yangilanadi (plan / rate_bp / fixed_fee / billing_started_at tegilmaydi);
     * to'langan summa saqlanadi, holat qayta aniqlanadi. `paid` / `waived` hisobga tegilmaydi.
     */
    public function recalculate(RestaurantInvoice $invoice): RestaurantInvoice
    {
        return DB::transaction(function () use ($invoice): RestaurantInvoice {
            $locked = RestaurantInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            $result = $locked->isSettled() ? $locked : $this->applyRecalculation($locked);

            $invoice->setRawAttributes($result->getAttributes(), true);

            return $invoice;
        });
    }

    private function applyRecalculation(RestaurantInvoice $invoice): RestaurantInvoice
    {
        if ($invoice->isSettled()) {
            return $invoice;
        }

        $result = $this->calculator->calculateForInvoice($invoice);

        $invoice->orders_count = $result->ordersCount;
        $invoice->orders_total = $result->ordersTotal;
        $invoice->amount_due = $result->amountDue;

        return $this->syncStatus($invoice);
    }

    /**
     * Hisob-kitob tarif snapshot'ini o'zgartiradi (faqat shu hisob; restoranning tarifiga tegilmaydi)
     * va summani yangi tarif bilan qayta hisoblaydi. Sabab majburiy; o'zgarish izohga (notes) yoziladi.
     * Yopiq (paid/waived) hisobda mumkin emas.
     *
     * @param  int  $rateBp  stavka (bp, 500 = 5%)
     * @param  int  $fixedFee  oylik to'lov, tiyin
     *
     * @throws ValidationException
     */
    public function changeTariff(
        RestaurantInvoice $invoice,
        BillingPlan $plan,
        int $rateBp,
        int $fixedFee,
        string $reason,
        ?int $changedBy = null,
    ): RestaurantInvoice {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Tarifni o‘zgartirish sababini kiriting.']);
        }
        if ($rateBp < 0 || $rateBp > 10_000) {
            throw ValidationException::withMessages(['rate_bp' => 'Stavka 0 dan 100% gacha bo‘lishi kerak.']);
        }
        if ($fixedFee < 0) {
            throw ValidationException::withMessages(['fixed_fee' => 'Oylik to‘lov manfiy bo‘lmasligi kerak.']);
        }

        return DB::transaction(function () use ($invoice, $plan, $rateBp, $fixedFee, $reason, $changedBy): RestaurantInvoice {
            $locked = RestaurantInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->isSettled()) {
                throw ValidationException::withMessages(['plan' => 'Yopilgan (to‘langan yoki kechirilgan) hisobda tarifni o‘zgartirib bo‘lmaydi.']);
            }

            $before = $this->tariffText($locked->plan, (int) $locked->rate_bp, (int) $locked->fixed_fee);
            $after = $this->tariffText($plan, $rateBp, $fixedFee);
            $who = $changedBy !== null ? (Staff::query()->find($changedBy)?->name ?? "#{$changedBy}") : 'tizim';
            $when = now(BillingCalculator::timezone())->format('d.m.Y H:i');

            $locked->plan = $plan;
            $locked->rate_bp = $rateBp;
            $locked->fixed_fee = $fixedFee;
            $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '')."[{$when}] {$who}: tarif {$before} → {$after}. Sabab: {$reason}");

            $result = $this->calculator->calculateForInvoice($locked);
            $locked->orders_count = $result->ordersCount;
            $locked->orders_total = $result->ordersTotal;
            $locked->amount_due = $result->amountDue;

            $this->syncStatus($locked);
            $invoice->setRawAttributes($locked->getAttributes(), true);

            return $invoice;
        });
    }

    private function tariffText(BillingPlan $plan, int $rateBp, int $fixedFee): string
    {
        return $plan === BillingPlan::Percent
            ? 'foiz '.rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').'%'
            : 'qat‘iy '.Money::soms($fixedFee);
    }

    /**
     * Tarif tarixi bor restoranlar uchun (test restoranlarsiz). `pending` — shu davrda percent
     * tarifdagi, lekin oyi hali tugamagan restoranlar (hisob yaratilmadi).
     *
     * @return array{created: int, updated: int, skipped: int, pending: int}
     */
    public function generateAll(CarbonInterface|string $period): array
    {
        $monthStart = BillingCalculator::monthStart($period);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'pending' => 0];

        Restaurant::query()
            ->whereHas('billingPlans')
            ->where('is_test', false)
            ->orderBy('id')
            ->each(function (Restaurant $restaurant) use ($monthStart, &$stats): void {
                $plan = $this->calculator->tariffFor($restaurant, $monthStart)->plan;

                if ($plan === BillingPlan::Percent && $this->calculator->isBillable($restaurant, $monthStart)
                    && ! BillingCalculator::canInvoice($plan, $monthStart)) {
                    $stats['pending']++;

                    return;
                }

                $before = RestaurantInvoice::query()
                    ->where('restaurant_id', $restaurant->id)
                    ->where('period', $monthStart->format('Y-m-d'))
                    ->first();

                $invoice = $this->generate($restaurant, $monthStart);

                if ($invoice === null || ($before?->isSettled() ?? false)) {
                    $stats['skipped']++;
                } elseif ($before === null) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
            });

        return $stats;
    }

    /**
     * To'lov qo'shadi (summa TIYINDA) va hisob holatini yangilaydi.
     *
     * @throws ValidationException
     */
    public function addPayment(
        RestaurantInvoice $invoice,
        int $amountTiyin,
        BillingPaymentMethod $method,
        CarbonInterface|string $paidAt,
        ?string $note = null,
        ?int $createdBy = null,
    ): RestaurantPayment {
        if ($amountTiyin <= 0) {
            throw ValidationException::withMessages(['amount' => 'To‘lov summasi noldan katta bo‘lishi kerak.']);
        }

        return DB::transaction(function () use ($invoice, $amountTiyin, $method, $paidAt, $note, $createdBy): RestaurantPayment {
            $locked = RestaurantInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Waived) {
                throw ValidationException::withMessages(['amount' => 'Kechirilgan hisobga to‘lov qo‘shib bo‘lmaydi.']);
            }

            $payment = RestaurantPayment::create([
                'invoice_id' => $locked->id,
                'restaurant_id' => $locked->restaurant_id,
                'amount' => $amountTiyin,
                'paid_at' => $paidAt instanceof CarbonInterface ? $paidAt->format('Y-m-d') : $paidAt,
                'method' => $method,
                'note' => filled($note) ? trim($note) : null,
                'created_by' => $createdBy,
            ]);

            $this->syncStatus($locked);
            $invoice->refresh();

            return $payment;
        });
    }

    /**
     * "To'landi": qolgan qarzni to'liq to'lov sifatida yozadi (summa avtomatik).
     * Qarz yo'q yoki hisob kechirilgan bo'lsa rad etiladi.
     *
     * @throws ValidationException
     */
    public function markPaid(
        RestaurantInvoice $invoice,
        BillingPaymentMethod $method,
        CarbonInterface|string $paidAt,
        ?string $note = null,
        ?int $createdBy = null,
    ): RestaurantPayment {
        return DB::transaction(function () use ($invoice, $method, $paidAt, $note, $createdBy): RestaurantPayment {
            $locked = RestaurantInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Waived) {
                throw ValidationException::withMessages(['amount' => 'Kechirilgan hisobga to‘lov qo‘shib bo‘lmaydi.']);
            }

            $debt = $locked->debt();
            if ($debt <= 0) {
                throw ValidationException::withMessages(['amount' => 'Bu hisob-kitobda qarz yo‘q.']);
            }

            $payment = $this->addPayment($locked, $debt, $method, $paidAt, $note, $createdBy);
            $invoice->refresh();

            return $payment;
        });
    }

    /**
     * To'lovni bekor qiladi: yozuv o'chirilmaydi (cancelled_at/by/reason), hisobning amount_paid
     * va holati qayta hisoblanadi. Sabab majburiy. Kechirilgan hisobda va allaqachon bekor
     * qilingan to'lovda rad etiladi.
     *
     * @throws ValidationException
     */
    public function cancelPayment(RestaurantPayment $payment, string $reason, ?int $cancelledBy = null): RestaurantInvoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'To‘lovni bekor qilish sababini kiriting.']);
        }

        return DB::transaction(function () use ($payment, $reason, $cancelledBy): RestaurantInvoice {
            $invoice = RestaurantInvoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $locked = RestaurantPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($invoice->status === InvoiceStatus::Waived) {
                throw ValidationException::withMessages(['reason' => 'Kechirilgan hisobda to‘lovni bekor qilib bo‘lmaydi.']);
            }
            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['reason' => 'Bu to‘lov allaqachon bekor qilingan.']);
            }

            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
                'cancel_reason' => $reason,
            ])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);

            return $this->syncStatus($invoice);
        });
    }

    /** Hisobni kechiradi (qarz 0, to'lov tarixi saqlanadi). */
    public function waive(RestaurantInvoice $invoice, ?string $note = null): RestaurantInvoice
    {
        $invoice->update([
            'status' => InvoiceStatus::Waived,
            'notes' => filled($note) ? trim($note) : $invoice->notes,
        ]);

        return $invoice;
    }

    /** `amount_paid` ni to'lovlar yig'indisidan qayta hisoblaydi; kechirilgan holat o'zgarmaydi. */
    public function syncStatus(RestaurantInvoice $invoice): RestaurantInvoice
    {
        $paid = (int) RestaurantPayment::query()->active()->where('invoice_id', $invoice->id)->sum('amount');

        $invoice->amount_paid = $paid;
        if ($invoice->status !== InvoiceStatus::Waived) {
            $invoice->status = InvoiceStatus::fromAmounts((int) $invoice->amount_due, $paid);
        }
        $invoice->save();

        return $invoice;
    }
}
