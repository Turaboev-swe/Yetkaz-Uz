<?php

namespace App\Services\Billing;

use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentBadge;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Restoran uchun HOZIR to'lanishi kerak hisob holati ("Joriy oy" sahifasi). `BillingCalculator::aggregated()`
 * qatori kerak (joriy va o'tgan oy hisoblari ustunlari bor — qo'shimcha so'rovsiz).
 *
 *  - fixed (oldindan): joriy oy hisobi
 *  - percent (keyin): o'tgan oy hisobi; o'tgan oy hisoblanmaydigan bo'lsa (billing boshlanishidan oldin,
 *    tarif yangi boshlangan) — «Oy oxirida»
 *  - tarifsiz / test restoran: «—»
 */
class PaymentStateResolver
{
    public function __construct(private readonly BillingCalculator $calculator) {}

    public function resolve(Restaurant $row, CarbonInterface|string|null $month = null, ?CarbonInterface $now = null): PaymentState
    {
        $tz = BillingCalculator::timezone();
        $now = $now !== null ? CarbonImmutable::instance($now)->setTimezone($tz) : CarbonImmutable::now($tz);
        $month = BillingCalculator::monthStart($month ?? $now);

        $tariff = $this->calculator->tariffFor($row, $month);

        if ($row->is_test || $tariff->plan === null) {
            return new PaymentState(PaymentBadge::NotApplicable);
        }

        if ($tariff->plan === BillingPlan::Fixed) {
            if (! $this->calculator->isBillable($row, $month)) {
                return new PaymentState(PaymentBadge::NotApplicable);
            }

            return $this->fromInvoice($row, 'billing_invoice_', $month, $now);
        }

        $period = $month->subMonth();

        if (($row->billing_prev_invoice_id ?? null) === null) {
            $prevBillable = BillingPlan::tryFrom((string) ($row->prev_period_plan ?? '')) === BillingPlan::Percent
                && $period->greaterThanOrEqualTo(BillingCalculator::startMonth())
                && ($row->billing_started_at === null
                    || BillingCalculator::monthStart($row->billing_started_at->format('Y-m-d'))->lessThanOrEqualTo($period));

            return $prevBillable
                ? new PaymentState(PaymentBadge::NoInvoice, $period)
                : new PaymentState(PaymentBadge::AtMonthEnd);
        }

        return $this->fromInvoice($row, 'billing_prev_invoice_', $period, $now);
    }

    private function fromInvoice(Restaurant $row, string $prefix, CarbonImmutable $period, CarbonImmutable $now): PaymentState
    {
        $id = $row->{$prefix.'id'} ?? null;

        if ($id === null) {
            return new PaymentState(PaymentBadge::NoInvoice, $period);
        }

        $status = InvoiceStatus::from((string) $row->{$prefix.'status'});
        $debt = $status === InvoiceStatus::Waived
            ? 0
            : max(0, (int) $row->{$prefix.'amount_due'} - (int) $row->{$prefix.'amount_paid'});

        $badge = match ($status) {
            InvoiceStatus::Waived => PaymentBadge::Waived,
            InvoiceStatus::Paid => PaymentBadge::Paid,
            InvoiceStatus::Partial => PaymentBadge::Partial,
            InvoiceStatus::Unpaid => substr((string) $row->{$prefix.'due_date'}, 0, 10) < $now->format('Y-m-d')
                ? PaymentBadge::Overdue
                : PaymentBadge::Unpaid,
        };

        return new PaymentState($badge, $period, (int) $id, $debt);
    }
}
