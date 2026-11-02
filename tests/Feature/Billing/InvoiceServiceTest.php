<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use App\Models\Staff;
use App\Services\Billing\InvoiceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\Concerns\RecordsTariffs;
use Tests\TestCase;

/** Hisob-kitob yaratish (servis + artisan buyrug'i + rejalashtirish) va to'lovlar. */
class InvoiceServiceTest extends TestCase
{
    use RecordsTariffs;
    use RefreshDatabase;

    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Percent hisob faqat oy tugagach yaratiladi — «hozir» noyabr tugagandan keyin.
        Carbon::setTestNow(Carbon::parse("2026-12-15 12:00", "Asia/Tashkent"));
        $this->service = app(InvoiceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        $restaurant = Restaurant::factory()->for(District::factory())->create(array_replace([
            'billing_plan' => BillingPlan::Percent,
            'commission_rate_bp' => 500,
            'monthly_fee' => 10_000_000,
            'is_test' => false,
        ], $overrides));

        return $this->recordTariff($restaurant);
    }

    private function delivered(Restaurant $r, string $tashkentTime, int $subtotal): Order
    {
        return Order::factory()->forRestaurant($r)->create([
            'status' => OrderStatus::Delivered,
            'delivered_at' => Carbon::parse($tashkentTime, 'Asia/Tashkent')->utc(),
            'subtotal' => $subtotal,
            'delivery_fee' => 0,
            'discount_amount' => 0,
            'discount_restaurant_amount' => 0,
            'discount_platform_amount' => 0,
            'total' => $subtotal,
        ]);
    }

    // --- Yaratish ----------------------------------------------------------------------

    public function test_generate_creates_an_invoice_with_a_tariff_snapshot(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);

        $invoice = $this->service->generate($r, '2026-11');

        $this->assertSame('2026-11-01', $invoice->period->format('Y-m-d'));
        $this->assertSame(BillingPlan::Percent, $invoice->plan);
        $this->assertSame([500, 10_000_000], [$invoice->rate_bp, $invoice->fixed_fee]);
        $this->assertSame([1, 5_000_000, 250_000, 0], [$invoice->orders_count, $invoice->orders_total, $invoice->amount_due, $invoice->amount_paid]);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame('2026-12-10', $invoice->due_date->format('Y-m-d'));
    }

    public function test_generate_skips_restaurants_without_plan_test_restaurants_and_months_before_the_start(): void
    {
        $this->assertNull($this->service->generate($this->restaurant(['billing_plan' => null]), '2026-11'));
        $this->assertNull($this->service->generate($this->restaurant(['is_test' => true]), '2026-11'));
        $this->assertNull($this->service->generate($this->restaurant(), '2026-10'));
        $this->assertSame(0, RestaurantInvoice::count());
    }

    public function test_is_idempotent_and_refreshes_unpaid_invoices(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $first = $this->service->generate($r, '2026-11');

        $this->delivered($r, '2026-11-06 12:00', 5_000_000); // keyin yana buyurtma "yetkazildi" deb kiritilgan
        $second = $this->service->generate($r, '2026-11');

        $this->assertSame(1, RestaurantInvoice::count());
        $this->assertSame($first->id, $second->id);
        $this->assertSame([2, 500_000], [$second->orders_count, $second->amount_due]);
    }

    public function test_paid_and_waived_invoices_are_never_touched_again(): void
    {
        $paid = $this->restaurant();
        $waived = $this->restaurant();
        foreach ([$paid, $waived] as $r) {
            $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        }
        $paidInvoice = $this->service->generate($paid, '2026-11');
        $waivedInvoice = $this->service->generate($waived, '2026-11');
        $this->service->addPayment($paidInvoice, 250_000, BillingPaymentMethod::Cash, '2026-12-01');
        $this->service->waive($waivedInvoice);

        // Tarif va buyurtmalar o'zgardi.
        foreach ([$paid, $waived] as $r) {
            $r->update(['commission_rate_bp' => 1000]);
            $this->delivered($r, '2026-11-07 12:00', 5_000_000);
            $this->service->generate($r, '2026-11');
        }

        $this->assertSame([500, 1, 250_000], [$paidInvoice->fresh()->rate_bp, $paidInvoice->fresh()->orders_count, $paidInvoice->fresh()->amount_due]);
        $this->assertSame([500, 1, 250_000, InvoiceStatus::Waived], [$waivedInvoice->fresh()->rate_bp, $waivedInvoice->fresh()->orders_count, $waivedInvoice->fresh()->amount_due, $waivedInvoice->fresh()->status]);
    }

    public function test_old_invoices_keep_their_snapshot_when_the_tariff_changes_later(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $november = $this->service->generate($r, '2026-11');
        $this->service->addPayment($november, 250_000, BillingPaymentMethod::Card, '2026-12-02');

        $this->setTariff($r, BillingPlan::Fixed, 500, 20_000_000, '2026-12-01'); // dekabrdan qat'iy
        $december = $this->service->generate($r, '2026-12');

        $this->assertSame(BillingPlan::Percent, $november->fresh()->plan);
        $this->assertSame(250_000, $november->fresh()->amount_due);
        $this->assertSame([BillingPlan::Fixed, 20_000_000], [$december->plan, $december->amount_due]);
    }

    // --- To'lovlar ----------------------------------------------------------------------

    public function test_payments_move_the_invoice_through_unpaid_partial_paid(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed]); // 100 000 so'm
        $invoice = $this->service->generate($r, '2026-11');
        $admin = Staff::factory()->platformAdmin()->create();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);

        $this->service->addPayment($invoice, 4_000_000, BillingPaymentMethod::Cash, '2026-12-01', 'birinchi', $admin->id);
        $this->assertSame([4_000_000, InvoiceStatus::Partial, 6_000_000], [$invoice->fresh()->amount_paid, $invoice->fresh()->status, $invoice->fresh()->debt()]);

        $this->service->addPayment($invoice, 6_000_000, BillingPaymentMethod::Transfer, '2026-12-03');
        $this->assertSame([10_000_000, InvoiceStatus::Paid, 0], [$invoice->fresh()->amount_paid, $invoice->fresh()->status, $invoice->fresh()->debt()]);

        $this->assertSame(2, $invoice->payments()->count());
        $this->assertSame($admin->id, $invoice->payments()->where('note', 'birinchi')->first()->created_by);
    }

    public function test_invalid_and_waived_payments_are_rejected(): void
    {
        $invoice = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');

        try {
            $this->service->addPayment($invoice, 0, BillingPaymentMethod::Cash, '2026-12-01');
            $this->fail('ValidationException kutilgan edi');
        } catch (ValidationException) {
        }

        $this->service->waive($invoice);

        try {
            $this->service->addPayment($invoice->fresh(), 100, BillingPaymentMethod::Cash, '2026-12-01');
            $this->fail('ValidationException kutilgan edi');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame(0, $invoice->fresh()->debt(), 'kechirilgan hisobda qarz yo‘q');
    }

    // --- "To'landi" (qolgan qarzni to'liq yozish) ------------------------------------------------------

    public function test_mark_paid_writes_the_whole_remaining_debt_as_one_payment(): void
    {
        $invoice = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11'); // 100 000 so'm
        $admin = Staff::factory()->platformAdmin()->create();
        $this->service->addPayment($invoice, 4_000_000, BillingPaymentMethod::Cash, '2026-12-01');

        $payment = $this->service->markPaid($invoice, BillingPaymentMethod::Transfer, '2026-12-05', null, $admin->id);

        $this->assertSame([6_000_000, BillingPaymentMethod::Transfer, $admin->id], [$payment->amount, $payment->method, $payment->created_by]);
        $this->assertSame([10_000_000, InvoiceStatus::Paid, 0], [$invoice->amount_paid, $invoice->status, $invoice->debt()]);
        $this->assertSame(2, $invoice->payments()->count());
    }

    public function test_mark_paid_from_unpaid_pays_the_full_amount(): void
    {
        $invoice = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');

        $payment = $this->service->markPaid($invoice, BillingPaymentMethod::Cash, '2026-12-01');

        $this->assertSame(10_000_000, $payment->amount);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_mark_paid_is_rejected_when_there_is_no_debt_or_the_invoice_is_waived(): void
    {
        $paid = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');
        $this->service->markPaid($paid, BillingPaymentMethod::Cash, '2026-12-01');
        $waived = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');
        $this->service->waive($waived);

        foreach ([$paid->fresh(), $waived->fresh()] as $invoice) {
            try {
                $this->service->markPaid($invoice, BillingPaymentMethod::Cash, '2026-12-02');
                $this->fail('ValidationException kutilgan edi');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(1, $paid->payments()->count(), 'ikkinchi bosish ikkinchi to‘lov yozmaydi');
        $this->assertSame(0, $waived->payments()->count());
    }

    // --- To'lovni bekor qilish --------------------------------------------------------------------------

    public function test_cancel_payment_keeps_the_record_and_recalculates_paid_amount_and_status(): void
    {
        $invoice = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');
        $first = $this->service->addPayment($invoice, 4_000_000, BillingPaymentMethod::Cash, '2026-12-01');
        $second = $this->service->addPayment($invoice, 6_000_000, BillingPaymentMethod::Card, '2026-12-02');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $admin = Staff::factory()->platformAdmin()->create();

        $this->service->cancelPayment($second, 'Xato kiritilgan', $admin->id);

        $second->refresh();
        $this->assertTrue($second->isCancelled());
        $this->assertSame(['Xato kiritilgan', $admin->id], [$second->cancel_reason, $second->cancelled_by]);
        $this->assertSame([4_000_000, InvoiceStatus::Partial, 6_000_000], [$invoice->fresh()->amount_paid, $invoice->fresh()->status, $invoice->fresh()->debt()]);
        $this->assertSame(2, $invoice->payments()->count(), 'yozuv o‘chirilmaydi');

        $this->service->cancelPayment($first, 'Qaytarildi');
        $this->assertSame([0, InvoiceStatus::Unpaid], [$invoice->fresh()->amount_paid, $invoice->fresh()->status]);
    }

    public function test_cancelled_payment_does_not_count_toward_later_payments(): void
    {
        $invoice = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');
        $payment = $this->service->markPaid($invoice, BillingPaymentMethod::Cash, '2026-12-01');
        $this->service->cancelPayment($payment, 'Xato');

        $again = $this->service->markPaid($invoice->fresh(), BillingPaymentMethod::Card, '2026-12-03');

        $this->assertSame(10_000_000, $again->amount, 'qarz bekor qilingan to‘lovdan keyin qayta to‘liq');
        $this->assertSame([10_000_000, InvoiceStatus::Paid], [$invoice->fresh()->amount_paid, $invoice->fresh()->status]);
    }

    public function test_cancel_payment_requires_a_reason_and_rejects_waived_invoices_and_repeats(): void
    {
        $invoice = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');
        $payment = $this->service->addPayment($invoice, 4_000_000, BillingPaymentMethod::Cash, '2026-12-01');

        try {
            $this->service->cancelPayment($payment, '  ');
            $this->fail('sabab majburiy');
        } catch (ValidationException) {
        }
        $this->assertFalse($payment->fresh()->isCancelled());

        $this->service->cancelPayment($payment, 'Xato');
        try {
            $this->service->cancelPayment($payment->fresh(), 'Yana');
            $this->fail('ikkinchi marta bekor qilib bo‘lmaydi');
        } catch (ValidationException) {
        }

        $other = $this->service->addPayment($invoice->fresh(), 1_000_000, BillingPaymentMethod::Cash, '2026-12-02');
        $this->service->waive($invoice->fresh());
        try {
            $this->service->cancelPayment($other, 'Kechirilgan hisob');
            $this->fail('kechirilgan hisobda rad etilishi kerak');
        } catch (ValidationException) {
        }
        $this->assertFalse($other->fresh()->isCancelled());
        $this->assertSame(InvoiceStatus::Waived, $invoice->fresh()->status);
    }

    // --- Qayta hisoblash: faqat snapshot bilan -------------------------------------------------

    public function test_recalculate_uses_the_invoice_snapshot_not_the_current_restaurant_tariff(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $invoice = $this->service->generate($r, '2026-11'); // 5% = 250 000
        $this->delivered($r, '2026-11-06 12:00', 5_000_000); // keyin yana buyurtma

        // Restoran tarifi o'zgardi — hisobga ta'sir qilmasligi kerak.
        $r->update(['billing_plan' => BillingPlan::Fixed, 'commission_rate_bp' => 1000, 'monthly_fee' => 30_000_000]);

        $this->service->recalculate($invoice);
        $invoice->refresh();

        $this->assertSame(BillingPlan::Percent, $invoice->plan);
        $this->assertSame([500, 10_000_000], [$invoice->rate_bp, $invoice->fixed_fee], 'snapshot o‘zgarmadi');
        $this->assertSame([2, 10_000_000, 500_000], [$invoice->orders_count, $invoice->orders_total, $invoice->amount_due], '5% (snapshot) bilan');
    }

    public function test_generate_on_an_existing_invoice_does_not_change_the_snapshot(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $this->service->generate($r, '2026-11');

        $r->update(['commission_rate_bp' => 1000]);
        $again = $this->service->generate($r, '2026-11');

        $this->assertSame([500, 250_000], [$again->rate_bp, $again->amount_due]);
    }

    public function test_recalculation_keeps_payments_and_updates_the_status(): void
    {
        $r = $this->restaurant();
        $order = $this->delivered($r, '2026-11-05 12:00', 10_000_000); // 5% = 500 000
        $invoice = $this->service->generate($r, '2026-11');
        $this->service->addPayment($invoice, 300_000, BillingPaymentMethod::Cash, '2026-12-01');
        $this->assertSame(InvoiceStatus::Partial, $invoice->fresh()->status);

        // Buyurtma summasi tuzatildi: 6 000 000 -> 5% = 300 000, to'liq to'langan bo'ladi
        $order->update(['subtotal' => 6_000_000, 'total' => 6_000_000]);
        $this->service->recalculate($invoice);

        $this->assertSame([300_000, 300_000, InvoiceStatus::Paid], [$invoice->fresh()->amount_due, $invoice->fresh()->amount_paid, $invoice->fresh()->status]);
    }

    public function test_recalculate_does_not_touch_paid_and_waived_invoices(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $invoice = $this->service->generate($r, '2026-11');
        $this->service->addPayment($invoice, 250_000, BillingPaymentMethod::Cash, '2026-12-01');
        $this->delivered($r, '2026-11-06 12:00', 5_000_000);

        $this->service->recalculate($invoice);

        $this->assertSame([1, 250_000, InvoiceStatus::Paid], [$invoice->fresh()->orders_count, $invoice->fresh()->amount_due, $invoice->fresh()->status]);
    }

    // --- Tarifni o'zgartirish -------------------------------------------------------------------

    public function test_change_tariff_updates_only_the_invoice_snapshot_and_recalculates_with_a_note(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 10_000_000); // 5% = 500 000
        $invoice = $this->service->generate($r, '2026-11');
        $this->service->addPayment($invoice, 300_000, BillingPaymentMethod::Cash, '2026-12-01');
        $admin = Staff::factory()->platformAdmin()->create(['name' => 'Alisher']);

        $this->service->changeTariff($invoice, BillingPlan::Percent, 300, 10_000_000, 'Shartnomada 3% kelishilgan', $admin->id);
        $invoice->refresh();

        $this->assertSame([300, 300_000, 300_000, InvoiceStatus::Paid], [$invoice->rate_bp, $invoice->amount_due, $invoice->amount_paid, $invoice->status]);
        $this->assertStringContainsString('Alisher', $invoice->notes);
        $this->assertStringContainsString('foiz 5% → foiz 3%', $invoice->notes);
        $this->assertStringContainsString('Shartnomada 3% kelishilgan', $invoice->notes);

        $this->assertSame(500, $r->fresh()->commission_rate_bp, 'restoranning tarifiga tegilmaydi');
        $this->assertSame(BillingPlan::Percent, $r->fresh()->billing_plan);
    }

    public function test_change_tariff_can_switch_the_plan_to_fixed(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 10_000_000);
        $invoice = $this->service->generate($r, '2026-11');

        $this->service->changeTariff($invoice, BillingPlan::Fixed, 500, 15_000_000, 'Qat‘iy tarifga o‘tdi');

        $invoice->refresh();
        $this->assertSame([BillingPlan::Fixed, 15_000_000, 15_000_000], [$invoice->plan, $invoice->fixed_fee, $invoice->amount_due]);
        $this->assertStringContainsString('foiz 5% → qat‘iy', $invoice->notes);
    }

    public function test_change_tariff_requires_a_reason_and_valid_numbers(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000); // summa > 0 — hisob ochiq qoladi
        $invoice = $this->service->generate($r, '2026-11');

        foreach ([
            ['   ', 300, 10_000_000],
            ['sabab', 10_001, 10_000_000],
            ['sabab', 300, -1],
        ] as [$reason, $bp, $fee]) {
            try {
                $this->service->changeTariff($invoice, BillingPlan::Percent, $bp, $fee, $reason);
                $this->fail('ValidationException kutilgan edi');
            } catch (ValidationException) {
            }
        }

        $this->assertSame([500, null], [$invoice->fresh()->rate_bp, $invoice->fresh()->notes]);
    }

    public function test_change_tariff_is_rejected_for_paid_and_waived_invoices(): void
    {
        $r = $this->restaurant();
        $paid = $this->service->generate($r, '2026-11'); // 0 buyurtma -> 0 summa -> darhol paid
        $this->assertSame(InvoiceStatus::Paid, $paid->status);

        $fixed = $this->service->generate($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');
        $this->service->waive($fixed);

        foreach ([$paid, $fixed->fresh()] as $invoice) {
            try {
                $this->service->changeTariff($invoice, BillingPlan::Fixed, 500, 1_000_000, 'sabab');
                $this->fail('ValidationException kutilgan edi');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(500, $paid->fresh()->rate_bp);
        $this->assertSame(10_000_000, $fixed->fresh()->fixed_fee);
    }

    // --- Fixed tarif: birinchi oy proratsiyasi -------------------------------------------------

    public function test_first_invoice_is_prorated_for_a_fixed_plan_started_mid_month(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-16']);

        $november = $this->service->generate($r, '2026-11'); // 15/30
        $december = $this->service->generate($r, '2026-12'); // to'liq

        $this->assertSame([5_000_000, 10_000_000], [$november->amount_due, $december->amount_due]);
        $this->assertSame('2026-11-16', $november->billing_started_at->format('Y-m-d'), 'snapshot');
    }

    public function test_prorated_invoice_stays_prorated_after_recalculation_even_if_the_restaurant_date_changes(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-16']);
        $invoice = $this->service->generate($r, '2026-11');

        $r->update(['billing_started_at' => '2026-11-01']);
        $this->service->recalculate($invoice);

        $this->assertSame(5_000_000, $invoice->fresh()->amount_due);
    }

    public function test_change_tariff_keeps_the_proration(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-16']);
        $invoice = $this->service->generate($r, '2026-11');

        $this->service->changeTariff($invoice, BillingPlan::Fixed, 500, 20_000_000, 'Oylik oshirildi');

        $this->assertSame(10_000_000, $invoice->fresh()->amount_due, '200 000 × 15/30');
    }
}
