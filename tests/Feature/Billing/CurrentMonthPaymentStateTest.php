<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentBadge;
use App\Filament\Admin\Pages\CurrentMonthBilling;
use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\District;
use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use App\Models\Staff;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentStateResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Billing\Concerns\RecordsTariffs;
use Tests\TestCase;

/** "Joriy oy" → «To'lov holati» ustuni: fixed — joriy oy hisobi, percent — o'tgan oy hisobi; badge, bosish, filtr. */
class CurrentMonthPaymentStateTest extends TestCase
{
    use RecordsTariffs;
    use RefreshDatabase;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = app(InvoiceService::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function now(string $tashkent): void
    {
        Carbon::setTestNow(Carbon::parse($tashkent, 'Asia/Tashkent'));
    }

    private function restaurant(BillingPlan $plan, string $from = '2026-11-01', array $overrides = []): Restaurant
    {
        $r = Restaurant::factory()->for(District::factory())->create(array_replace(['is_test' => false, 'billing_started_at' => null], $overrides));
        $this->setTariff($r, $plan, 500, 10_000_000, $from);

        return $r;
    }

    private function untariffed(): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(['is_test' => false, 'billing_plan' => null]);
    }

    private function invoice(Restaurant $r, string $period): RestaurantInvoice
    {
        return RestaurantInvoice::query()->where('restaurant_id', $r->id)->where('period', $period.'-01')->firstOrFail();
    }

    private function state(Restaurant $r)
    {
        $row = app(\App\Services\Billing\BillingCalculator::class)->aggregated(Carbon::now('Asia/Tashkent')->startOfMonth())->whereKey($r->id)->firstOrFail();

        return app(PaymentStateResolver::class)->resolve($row);
    }

    // --- Holatlar -------------------------------------------------------------------------------

    public function test_fixed_restaurants_show_this_months_invoice_state(): void
    {
        $paid = $this->restaurant(BillingPlan::Fixed);
        $partial = $this->restaurant(BillingPlan::Fixed);
        $unpaid = $this->restaurant(BillingPlan::Fixed);
        $waived = $this->restaurant(BillingPlan::Fixed);
        $overdue = $this->restaurant(BillingPlan::Fixed);
        $noInvoice = $this->restaurant(BillingPlan::Fixed);

        $this->now('2026-11-01 06:00'); // oddiy generatsiya, muddat 5-noyabr
        foreach ([$paid, $partial, $waived, $overdue] as $r) {
            $this->invoices->generate($r, '2026-11');
        }
        $this->invoices->markPaid($this->invoice($paid, '2026-11'), BillingPaymentMethod::Cash, '2026-11-02');
        $this->invoices->addPayment($this->invoice($partial, '2026-11'), 4_000_000, BillingPaymentMethod::Cash, '2026-11-02');
        $this->invoices->waive($this->invoice($waived, '2026-11'));

        $this->now('2026-11-04 12:00'); // muddat hali o'tmagan
        $this->invoices->generate($unpaid, '2026-11'); // kech yaratilgan: 4 + 4 = 8-noyabr

        $this->now('2026-11-04 12:00');
        $this->assertSame(PaymentBadge::Paid, $this->state($paid)->badge);
        $this->assertSame([PaymentBadge::Partial, 6_000_000], [$this->state($partial)->badge, $this->state($partial)->debt]);
        $this->assertSame(PaymentBadge::Unpaid, $this->state($unpaid)->badge);
        $this->assertSame([PaymentBadge::Waived, 0], [$this->state($waived)->badge, $this->state($waived)->debt]);
        $this->assertSame(PaymentBadge::Unpaid, $this->state($overdue)->badge, '4-noyabr: muddat (5-noyabr) hali o‘tmagan');
        $this->assertSame(PaymentBadge::NoInvoice, $this->state($noInvoice)->badge);

        $this->now('2026-11-06 00:01'); // 5-noyabrdan keyin
        $this->assertSame(PaymentBadge::Overdue, $this->state($overdue)->badge);
        $this->assertSame(PaymentBadge::Paid, $this->state($paid)->badge, 'to‘langan muddati o‘tmaydi');
        $this->assertSame(PaymentBadge::Partial, $this->state($partial)->badge);
        $this->assertSame(PaymentBadge::Unpaid, $this->state($unpaid)->badge, 'kech yaratilgan hisob muddati 8-noyabr');
    }

    public function test_the_due_day_itself_is_not_overdue_yet(): void
    {
        $r = $this->restaurant(BillingPlan::Fixed);
        $this->now('2026-11-01 06:00');
        $this->invoices->generate($r, '2026-11'); // muddat 5-noyabr

        $this->now('2026-11-05 23:59');
        $this->assertSame(PaymentBadge::Unpaid, $this->state($r)->badge);
    }

    public function test_restaurants_without_a_tariff_and_test_restaurants_show_a_dash(): void
    {
        $this->now('2026-11-20 12:00');
        $none = $this->untariffed();
        $test = $this->restaurant(BillingPlan::Fixed, overrides: ['is_test' => true]);

        $this->assertSame(PaymentBadge::NotApplicable, $this->state($none)->badge);
        $this->assertSame(PaymentBadge::NotApplicable, $this->state($test)->badge);
    }

    public function test_percent_restaurants_show_last_months_invoice_with_the_month_name(): void
    {
        $this->now('2026-12-05 12:00'); // percent noyabr hisobi muddati — 10-dekabr
        $r = $this->restaurant(BillingPlan::Percent);
        $this->delivered($r, 10_000_000);
        $this->invoices->generate($r, '2026-11'); // percent noyabr hisobi — dekabrda to'lanadi

        Livewire::test(CurrentMonthBilling::class)
            ->assertTableColumnStateSet('payment_state', 'To‘lanmagan', record: $r->id)
            ->assertSee('Noyabr');

        $this->assertSame([PaymentBadge::Unpaid, '2026-11-01'], [$this->state($r)->badge, $this->state($r)->period->format('Y-m-d')]);
    }

    public function test_percent_with_a_partial_payment_shows_the_remaining_debt(): void
    {
        $this->now('2026-12-15 12:00');
        $r = $this->restaurant(BillingPlan::Percent);
        $this->delivered($r, 10_000_000); // 5% = 500 000 tiyin
        $this->invoices->addPayment($this->invoices->generate($r, '2026-11'), 200_000, BillingPaymentMethod::Cash, '2026-12-02');

        Livewire::test(CurrentMonthBilling::class)
            ->assertTableColumnStateSet('payment_state', "Qisman · 3 000 so'm", record: $r->id);
    }

    public function test_percent_before_billing_started_or_for_a_brand_new_tariff_shows_at_month_end(): void
    {
        $this->now('2026-11-20 12:00');
        $beforeStart = $this->restaurant(BillingPlan::Percent); // o'tgan oy (oktabr) billing boshlanishidan oldin
        $this->now('2026-12-15 12:00');
        $fresh = $this->restaurant(BillingPlan::Percent, '2026-12-01'); // tarif joriy oydan, o'tgan oyda tarif yo'q

        $this->now('2026-11-20 12:00');
        $this->assertSame(PaymentBadge::AtMonthEnd, $this->state($beforeStart)->badge);
        $this->now('2026-12-15 12:00');
        $this->assertSame(PaymentBadge::AtMonthEnd, $this->state($fresh)->badge);
        $this->assertNull($this->state($fresh)->period);
    }

    public function test_percent_without_last_months_invoice_after_the_month_ended_shows_no_invoice(): void
    {
        $this->now('2026-12-02 12:00');
        $r = $this->restaurant(BillingPlan::Percent);

        $state = $this->state($r);

        $this->assertSame(PaymentBadge::NoInvoice, $state->badge);
        $this->assertSame('2026-11-01', $state->period->format('Y-m-d'));
    }

    public function test_percent_after_switching_from_fixed_shows_the_last_fixed_invoice_that_is_still_open(): void
    {
        $r = $this->restaurant(BillingPlan::Fixed, '2026-11-01');
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-12-01');
        $this->now('2026-11-01 06:00');
        $this->invoices->generate($r, '2026-11');

        $this->now('2026-12-10 12:00');
        $state = $this->state($r);

        $this->assertSame([PaymentBadge::Overdue, '2026-11-01'], [$state->badge, $state->period->format('Y-m-d')]);
    }

    // --- Ustun ko'rinishi --------------------------------------------------------------------------

    public function test_the_column_sits_right_after_the_tariff_column_and_renders_all_states(): void
    {
        $this->now('2026-11-20 12:00');
        $fixed = $this->restaurant(BillingPlan::Fixed);
        $this->invoices->generate($fixed, '2026-11');

        $page = Livewire::test(CurrentMonthBilling::class)->assertSuccessful();
        $names = collect($page->instance()->getTable()->getColumns())->keys()->values()->all();

        $this->assertSame($names[array_search('period_plan', $names) + 1], 'payment_state');
        $page->assertTableColumnStateSet('payment_state', 'To‘lanmagan', record: $fixed->id)
            ->assertTableColumnStateSet('payment_state', '—', record: $this->untariffed()->id);
    }

    public function test_overdue_is_labelled_separately_from_unpaid(): void
    {
        $r = $this->restaurant(BillingPlan::Fixed);
        $this->now('2026-11-01 06:00');
        $this->invoices->generate($r, '2026-11');
        $this->now('2026-11-20 12:00');

        Livewire::test(CurrentMonthBilling::class)
            ->assertTableColumnStateSet('payment_state', 'Muddati o‘tgan', record: $r->id);
    }

    // --- Bosish -------------------------------------------------------------------------------------

    public function test_clicking_an_unpaid_badge_opens_the_existing_mark_paid_modal_and_writes_the_remaining_debt(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant(BillingPlan::Fixed);
        $invoice = $this->invoices->generate($r, '2026-11');
        $this->invoices->addPayment($invoice, 3_000_000, BillingPaymentMethod::Cash, '2026-11-10');

        Livewire::test(CurrentMonthBilling::class)
            ->callTableColumnAction('payment_state', (string) $r->id)
            ->assertSet('mountedTableActions', ['markPaid'])
            ->setTableActionData(['method' => 'card', 'paid_at' => '2026-11-20'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $invoice->refresh();
        $payment = $invoice->payments()->first();
        $this->assertSame([7_000_000, BillingPaymentMethod::Card], [$payment->amount, $payment->method]);
        $this->assertSame([10_000_000, InvoiceStatus::Paid], [$invoice->amount_paid, $invoice->status]);
    }

    public function test_clicking_a_paid_or_waived_badge_opens_the_invoice_page_and_changes_nothing(): void
    {
        $this->now('2026-11-20 12:00');
        $paidR = $this->restaurant(BillingPlan::Fixed);
        $waivedR = $this->restaurant(BillingPlan::Fixed);
        $paid = $this->invoices->generate($paidR, '2026-11');
        $this->invoices->markPaid($paid, BillingPaymentMethod::Cash, '2026-11-10');
        $waived = $this->invoices->generate($waivedR, '2026-11');
        $this->invoices->waive($waived);

        Livewire::test(CurrentMonthBilling::class)
            ->callTableColumnAction('payment_state', (string) $paidR->id)
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $paid->id]));
        Livewire::test(CurrentMonthBilling::class)
            ->callTableColumnAction('payment_state', (string) $waivedR->id)
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $waived->id]));

        // Bir bosishda «to'lanmagan»ga qaytarilmaydi: to'lov ham, holat ham joyida.
        $this->assertSame([InvoiceStatus::Paid, 10_000_000, false], [$paid->fresh()->status, $paid->fresh()->amount_paid, $paid->payments()->first()->isCancelled()]);
        $this->assertSame(InvoiceStatus::Waived, $waived->fresh()->status);
    }

    public function test_clicking_no_invoice_creates_the_invoice_for_that_restaurant_and_period(): void
    {
        $this->now('2026-12-02 12:00');
        $fixedR = $this->restaurant(BillingPlan::Fixed, '2026-11-01'); // dekabr hisobi yo'q (scheduler o'tkazib yuborgan)
        $percentR = $this->restaurant(BillingPlan::Percent, '2026-11-01'); // noyabr (o'tgan oy) hisobi yo'q

        Livewire::test(CurrentMonthBilling::class)
            ->callTableColumnAction('payment_state', (string) $fixedR->id)
            ->assertSet('mountedTableActions', ['createInvoice'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        $this->assertSame(BillingPlan::Fixed, $this->invoice($fixedR, '2026-12')->plan);

        Livewire::test(CurrentMonthBilling::class)
            ->callTableColumnAction('payment_state', (string) $percentR->id)
            ->callMountedTableAction();
        $invoice = $this->invoice($percentR, '2026-11');
        $this->assertSame([BillingPlan::Percent, 0], [$invoice->plan, RestaurantInvoice::where('restaurant_id', $percentR->id)->where('period', '2026-12-01')->count()]);
    }

    public function test_clicking_a_dash_or_at_month_end_does_nothing(): void
    {
        $this->now('2026-11-20 12:00');
        $none = $this->untariffed();
        $percent = $this->restaurant(BillingPlan::Percent);

        Livewire::test(CurrentMonthBilling::class)
            ->callTableColumnAction('payment_state', (string) $none->id)
            ->assertSet('mountedTableActions', [])
            ->callTableColumnAction('payment_state', (string) $percent->id)
            ->assertSet('mountedTableActions', [])
            ->assertNoRedirect();
    }

    // --- Filtr ----------------------------------------------------------------------------------------

    public function test_filter_by_payment_state_finds_debtors_quickly(): void
    {
        $paid = $this->restaurant(BillingPlan::Fixed);
        $partial = $this->restaurant(BillingPlan::Fixed);
        $overdue = $this->restaurant(BillingPlan::Fixed);
        $waived = $this->restaurant(BillingPlan::Fixed);
        $none = $this->restaurant(BillingPlan::Fixed);

        $this->now('2026-11-01 06:00');
        foreach ([$paid, $partial, $overdue, $waived] as $r) {
            $this->invoices->generate($r, '2026-11');
        }
        $this->invoices->markPaid($this->invoice($paid, '2026-11'), BillingPaymentMethod::Cash, '2026-11-02');
        $this->invoices->addPayment($this->invoice($partial, '2026-11'), 1_000_000, BillingPaymentMethod::Cash, '2026-11-02');
        $this->invoices->waive($this->invoice($waived, '2026-11'));
        $this->now('2026-11-20 12:00');

        Livewire::test(CurrentMonthBilling::class)
            ->filterTable('payment_state', 'debt')
            ->assertCanSeeTableRecords([$partial, $overdue])->assertCanNotSeeTableRecords([$paid, $waived, $none])
            ->resetTableFilters()->filterTable('payment_state', 'overdue')
            ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$partial, $paid])
            ->resetTableFilters()->filterTable('payment_state', 'paid')
            ->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$partial, $overdue])
            ->resetTableFilters()->filterTable('payment_state', 'waived')
            ->assertCanSeeTableRecords([$waived])->assertCanNotSeeTableRecords([$paid])
            ->resetTableFilters()->filterTable('payment_state', 'no_invoice')
            ->assertCanSeeTableRecords([$none])->assertCanNotSeeTableRecords([$paid, $partial]);
    }

    private function delivered(Restaurant $r, int $subtotal): void
    {
        \App\Models\Order::factory()->forRestaurant($r)->create([
            'status' => \App\Enums\OrderStatus::Delivered,
            'delivered_at' => Carbon::parse('2026-11-10 12:00', 'Asia/Tashkent')->utc(),
            'subtotal' => $subtotal, 'delivery_fee' => 0, 'discount_amount' => 0,
            'discount_restaurant_amount' => 0, 'discount_platform_amount' => 0, 'total' => $subtotal,
        ]);
    }
}
