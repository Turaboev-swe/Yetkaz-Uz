<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Pages\CurrentMonthBilling;
use App\Filament\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Admin\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Admin\Resources\InvoiceResource\Widgets\InvoiceOrdersWidget;
use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Admin\Widgets\BillingStats;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use App\Models\Staff;
use App\Policies\RestaurantInvoicePolicy;
use App\Services\Billing\InvoiceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/** /admin → Obunalar: Joriy oy sahifasi, Hisob-kitoblar, restoran tarifi, statistika, ruxsatlar. */
class BillingPanelTest extends TestCase
{
    use RefreshDatabase;

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // To'lov sanasi kelajakda bo'lolmaydi (maxDate) — vaqt 2026-12-15 da qotiriladi.
        Carbon::setTestNow(Carbon::parse('2026-12-15 12:00', 'Asia/Tashkent'));
        $this->admin = Staff::factory()->platformAdmin()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->admin, 'admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'billing_plan' => BillingPlan::Percent,
            'commission_rate_bp' => 500,
            'monthly_fee' => 10_000_000,
            'is_test' => false,
        ], $overrides));
    }

    private function delivered(Restaurant $r, string $tashkentTime, int $subtotal): Order
    {
        return Order::factory()->forRestaurant($r)->create([
            'status' => OrderStatus::Delivered,
            'delivered_at' => Carbon::parse($tashkentTime, 'Asia/Tashkent')->utc(),
            'subtotal' => $subtotal, 'delivery_fee' => 0, 'discount_amount' => 0,
            'discount_restaurant_amount' => 0, 'discount_platform_amount' => 0, 'total' => $subtotal,
        ]);
    }

    private function invoice(Restaurant $r, string $period = '2026-11'): RestaurantInvoice
    {
        return app(InvoiceService::class)->generate($r, $period);
    }

    // --- Hisob-kitoblar ro'yxati va filtrlar --------------------------------------------------

    public function test_invoice_list_shows_invoices_and_filters_by_status_plan_month_and_restaurant(): void
    {
        $a = $this->restaurant(['name' => 'Alfa']);
        $b = $this->restaurant(['name' => 'Beta', 'billing_plan' => BillingPlan::Fixed]);
        $this->delivered($a, '2026-11-05 12:00', 5_000_000);
        $this->delivered($a, '2026-12-05 12:00', 5_000_000);
        $novA = $this->invoice($a);
        $novB = $this->invoice($b);
        $decA = $this->invoice($a, '2026-12');
        app(InvoiceService::class)->waive($novB);

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$novA, $novB, $decA])
            ->assertSee('Noyabr 2026')
            ->filterTable('status', 'waived')
            ->assertCanSeeTableRecords([$novB])->assertCanNotSeeTableRecords([$novA, $decA])
            ->resetTableFilters()
            ->filterTable('plan', 'percent')
            ->assertCanSeeTableRecords([$novA, $decA])->assertCanNotSeeTableRecords([$novB])
            ->resetTableFilters()
            ->filterTable('period', '2026-12-01')
            ->assertCanSeeTableRecords([$decA])->assertCanNotSeeTableRecords([$novA, $novB])
            ->resetTableFilters()
            ->filterTable('restaurant_id', $b->id)
            ->assertCanSeeTableRecords([$novB])->assertCanNotSeeTableRecords([$novA, $decA]);
    }

    public function test_money_is_shown_in_som_with_thousand_separators(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed]);
        $this->invoice($r);

        Livewire::test(ListInvoices::class)->assertSee("100 000 so'm");
    }

    // --- Amallar --------------------------------------------------------------------------------

    public function test_add_payment_action_converts_som_to_tiyin_and_updates_status(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed]); // 100 000 so'm
        $invoice = $this->invoice($r);

        Livewire::test(ListInvoices::class)
            ->callTableAction('addPayment', $invoice, ['amount' => 40000, 'method' => 'transfer', 'paid_at' => '2026-12-01', 'note' => 'Payme'])
            ->assertHasNoTableActionErrors();

        $invoice->refresh();
        $payment = $invoice->payments()->first();
        $this->assertSame(4_000_000, $payment->amount);               // 40 000 so'm = 4 000 000 tiyin
        $this->assertSame($this->admin->id, $payment->created_by);
        $this->assertSame([4_000_000, InvoiceStatus::Partial], [$invoice->amount_paid, $invoice->status]);

        Livewire::test(ListInvoices::class)
            ->callTableAction('addPayment', $invoice, ['amount' => 60000, 'method' => 'cash', 'paid_at' => '2026-12-02']);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_add_payment_validates_the_amount_and_hides_for_closed_invoices(): void
    {
        $invoice = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));

        Livewire::test(ListInvoices::class)
            ->callTableAction('addPayment', $invoice, ['amount' => 0, 'method' => 'cash', 'paid_at' => '2026-12-01'])
            ->assertHasTableActionErrors(['amount']);
        $this->assertSame(0, $invoice->payments()->count());

        Livewire::test(ListInvoices::class)
            ->callTableAction('addPayment', $invoice, ['amount' => 100000, 'method' => 'cash', 'paid_at' => '2026-12-01'])
            ->assertTableActionHidden('addPayment', $invoice->fresh())
            ->assertTableActionHidden('waive', $invoice->fresh())
            ->assertTableActionHidden('recalculate', $invoice->fresh())
            ->assertTableActionHidden('changeTariff', $invoice->fresh());
    }

    // --- "To'landi" va to'lovni bekor qilish ---------------------------------------------------------

    public function test_mark_paid_action_writes_the_remaining_debt_from_the_table(): void
    {
        $invoice = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed])); // 100 000 so'm
        app(InvoiceService::class)->addPayment($invoice, 3_000_000, BillingPaymentMethod::Cash, '2026-12-01');

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('markPaid', $invoice->fresh())
            ->mountTableAction('markPaid', $invoice)
            ->assertTableActionDataSet(fn (array $data): bool => $data['method'] === 'cash' && str_starts_with((string) $data['paid_at'], '2026-12-15'))
            ->unmountTableAction()
            ->callTableAction('markPaid', $invoice, ['method' => 'card', 'paid_at' => '2026-12-10'])
            ->assertHasNoTableActionErrors();

        $invoice->refresh();
        $payment = $invoice->payments()->first();
        $this->assertSame([7_000_000, BillingPaymentMethod::Card, $this->admin->id], [$payment->amount, $payment->method, $payment->created_by]);
        $this->assertSame([10_000_000, InvoiceStatus::Paid], [$invoice->amount_paid, $invoice->status]);
    }

    public function test_mark_paid_is_hidden_for_paid_and_waived_invoices(): void
    {
        $paid = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));
        app(InvoiceService::class)->markPaid($paid, BillingPaymentMethod::Cash, '2026-12-01');
        $waived = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));
        app(InvoiceService::class)->waive($waived);

        Livewire::test(ListInvoices::class)
            ->assertTableActionHidden('markPaid', $paid->fresh())
            ->assertTableActionHidden('markPaid', $waived->fresh());
    }

    public function test_mark_paid_action_on_the_view_page(): void
    {
        $invoice = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('markPaid')
            ->assertActionVisible('addPayment') // qisman to'lovlar uchun qoladi
            ->callAction('markPaid', ['method' => 'transfer', 'paid_at' => '2026-12-12'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('markPaid');

        $this->assertSame([10_000_000, InvoiceStatus::Paid], [$invoice->fresh()->amount_paid, $invoice->fresh()->status]);
    }

    public function test_cancel_payment_from_the_history_requires_a_reason_and_keeps_a_trace(): void
    {
        $invoice = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));
        $payment = app(InvoiceService::class)->markPaid($invoice, BillingPaymentMethod::Cash, '2026-12-01');
        $rm = fn () => Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class]);

        $rm()->callTableAction('cancelPayment', $payment, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertFalse($payment->fresh()->isCancelled());

        $rm()->callTableAction('cancelPayment', $payment, ['reason' => 'Noto‘g‘ri restoran'])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('cancelPayment', $payment->fresh())
            ->assertSee('Bekor qilingan')
            ->assertSee('Noto‘g‘ri restoran');

        $payment->refresh();
        $this->assertSame([$this->admin->id, 'Noto‘g‘ri restoran'], [$payment->cancelled_by, $payment->cancel_reason]);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertSame([0, InvoiceStatus::Unpaid], [$invoice->fresh()->amount_paid, $invoice->fresh()->status]);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_cancel_payment_is_hidden_on_waived_invoices(): void
    {
        $invoice = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));
        $payment = app(InvoiceService::class)->addPayment($invoice, 1_000_000, BillingPaymentMethod::Cash, '2026-12-01');
        app(InvoiceService::class)->waive($invoice->fresh());

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class])
            ->assertTableActionHidden('cancelPayment', $payment);
    }

    public function test_stats_ignore_cancelled_payments(): void
    {
        $invoice = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]), '2026-11');
        $svc = app(InvoiceService::class);
        $svc->cancelPayment($svc->addPayment($invoice, 2_000_000, BillingPaymentMethod::Cash, '2026-12-03'), 'Xato');
        $svc->addPayment($invoice->fresh(), 3_000_000, BillingPaymentMethod::Cash, '2026-12-04');

        Livewire::test(BillingStats::class)->assertSee("30 000 so'm")->assertDontSee("50 000 so'm");
    }

    public function test_waive_action_closes_the_invoice_with_zero_debt(): void
    {
        $invoice = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));

        Livewire::test(ListInvoices::class)->callTableAction('waive', $invoice)->assertHasNoTableActionErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Waived, $invoice->status);
        $this->assertSame(0, $invoice->debt());
    }

    public function test_recalculate_action_refreshes_numbers_but_keeps_the_invoice_tariff_snapshot(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $invoice = $this->invoice($r);
        $this->delivered($r, '2026-11-06 12:00', 5_000_000);
        $r->update(['commission_rate_bp' => 1000]); // restoranning yangi tarifi hisobga ta'sir qilmaydi

        Livewire::test(ListInvoices::class)->callTableAction('recalculate', $invoice);

        $invoice->refresh();
        $this->assertSame([2, 10_000_000, 500, 500_000], [$invoice->orders_count, $invoice->orders_total, $invoice->rate_bp, $invoice->amount_due]);
    }

    public function test_change_tariff_action_converts_units_requires_a_reason_and_leaves_the_restaurant_alone(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 10_000_000);
        $invoice = $this->invoice($r); // 5% = 500 000

        Livewire::test(ListInvoices::class)
            ->callTableAction('changeTariff', $invoice, ['plan' => 'percent', 'rate' => 2.5, 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame(500, $invoice->fresh()->rate_bp);

        Livewire::test(ListInvoices::class)
            ->callTableAction('changeTariff', $invoice, ['plan' => 'percent', 'rate' => 2.5, 'reason' => 'Kelishuv']);

        $invoice->refresh();
        $this->assertSame([250, 250_000], [$invoice->rate_bp, $invoice->amount_due]);
        $this->assertStringContainsString('Kelishuv', $invoice->notes);
        $this->assertStringContainsString($this->admin->name, $invoice->notes);
        $this->assertSame(500, $r->fresh()->commission_rate_bp);

        Livewire::test(ListInvoices::class)
            ->callTableAction('changeTariff', $invoice, ['plan' => 'fixed', 'fee' => 120000, 'reason' => 'Qat‘iyga o‘tish']);

        $this->assertSame([BillingPlan::Fixed, 12_000_000, 12_000_000], [$invoice->fresh()->plan, $invoice->fresh()->fixed_fee, $invoice->fresh()->amount_due]);
    }

    public function test_change_tariff_action_is_also_on_the_view_page(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 10_000_000);
        $invoice = $this->invoice($r);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('changeTariff', ['plan' => 'percent', 'rate' => 3, 'reason' => 'Ko‘rish sahifasidan'])
            ->assertHasNoActionErrors();

        $this->assertSame([300, 300_000], [$invoice->fresh()->rate_bp, $invoice->fresh()->amount_due]);
    }

    public function test_view_page_shows_the_proration_for_a_prorated_fixed_invoice(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-16']);
        $invoice = $this->invoice($r);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertSee('15/30')
            ->assertSee("50 000 so'm");
    }

    // --- Ko'rish sahifasi -----------------------------------------------------------------------------

    public function test_view_page_lists_orders_in_the_invoice_and_payment_history(): void
    {
        $r = $this->restaurant();
        $in = $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $outside = $this->delivered($r, '2026-12-05 12:00', 7_000_000);
        $invoice = $this->invoice($r);
        app(InvoiceService::class)->addPayment($invoice, 100_000, \App\Enums\BillingPaymentMethod::Card, '2026-12-01', 'birinchi bo‘lak', $this->admin->id);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])->assertSuccessful();

        Livewire::test(InvoiceOrdersWidget::class, ['record' => $invoice])
            ->assertCanSeeTableRecords([$in])
            ->assertCanNotSeeTableRecords([$outside]);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
            ->assertSee('birinchi bo‘lak')
            ->assertSee("1 000 so'm");
    }

    // --- Joriy oy sahifasi -------------------------------------------------------------------------------

    public function test_current_month_page_lists_restaurants_with_live_numbers_sorting_and_plan_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00', 'Asia/Tashkent'));
        $big = $this->restaurant(['name' => 'Katta']);
        $small = $this->restaurant(['name' => 'Kichik']);
        $fixed = $this->restaurant(['name' => 'Qat‘iy', 'billing_plan' => BillingPlan::Fixed]);
        $none = $this->restaurant(['name' => 'Tarifsiz', 'billing_plan' => null]);
        $test = $this->restaurant(['name' => 'Test', 'is_test' => true]);
        $this->delivered($big, '2026-11-05 12:00', 20_000_000);
        $this->delivered($small, '2026-11-06 12:00', 2_000_000);
        $this->delivered($big, '2026-10-30 12:00', 99_000_000); // o'tgan oy

        $page = Livewire::test(CurrentMonthBilling::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$big, $small, $fixed, $none])
            ->assertCanNotSeeTableRecords([$test])
            ->assertTableColumnStateSet('billing_orders_count', 1, record: $big->id)
            ->assertTableColumnStateSet('accrued', "10 000 so'm", record: $big->id)    // 5% of 200 000 so'm
            ->assertTableColumnStateSet('accrued', "100 000 so'm", record: $fixed->id)
            ->assertTableColumnStateSet('accrued', '—', record: $none->id)
            ->sortTable('billing_orders_total', 'desc')
            ->assertCanSeeTableRecords([$big, $small, $fixed, $none], inOrder: true);

        $page->filterTable('billing_plan', 'none')
            ->assertCanSeeTableRecords([$none])->assertCanNotSeeTableRecords([$big, $small, $fixed]);
        $page->resetTableFilters()->filterTable('billing_plan', 'fixed')
            ->assertCanSeeTableRecords([$fixed])->assertCanNotSeeTableRecords([$big, $none]);
    }

    // --- Statistika --------------------------------------------------------------------------------------------

    public function test_stats_widget_numbers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-10 12:00', 'Asia/Tashkent'));
        $a = $this->restaurant();                                                 // foiz
        $b = $this->restaurant(['billing_plan' => BillingPlan::Fixed]);           // qat'iy
        $this->restaurant(['billing_plan' => null]);                              // tarifsiz
        $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'is_test' => true]); // hisobga kirmaydi
        $this->delivered($a, '2026-12-03 12:00', 10_000_000);                     // dekabr: 5% = 500 000
        $this->delivered($a, '2026-11-03 12:00', 10_000_000);
        $novA = $this->invoice($a);                                               // 500 000 qarz
        $novB = $this->invoice($b);                                               // 10 000 000
        app(InvoiceService::class)->addPayment($novB, 4_000_000, \App\Enums\BillingPaymentMethod::Cash, '2026-12-02');  // shu oy tushdi
        app(InvoiceService::class)->addPayment($novA, 100_000, \App\Enums\BillingPaymentMethod::Cash, '2026-11-28');    // o'tgan oy
        $waived = $this->invoice($this->restaurant(['billing_plan' => BillingPlan::Fixed]));
        app(InvoiceService::class)->waive($waived);                               // qarzga kirmaydi

        $stats = collect((new ReflectionMethod(BillingStats::class, 'getStats'))->invoke(new BillingStats))
            ->mapWithKeys(fn ($s) => [(string) $s->getLabel() => $s]);

        // Dekabrda kutilayotgan: a: 5% of 100 000 so'm = 5 000; b: 100 000; waived restoran ham qat'iy: 100 000
        $this->assertSame("205 000 so'm", (string) $stats['Shu oy kutilayotgan daromad']->getValue());
        // Qarz: A: 5 000 − 1 000 = 4 000; B: 100 000 − 40 000 = 60 000; kechirilgan hisobga kirmaydi
        $this->assertSame("64 000 so'm", (string) $stats['Umumiy qarz']->getValue());
        $this->assertSame("40 000 so'm", (string) $stats['Shu oy tushgan to‘lovlar']->getValue());
        $this->assertSame('Foiz: 1 · Qat‘iy: 2', (string) $stats['Tariflar']->getValue());
        $this->assertStringContainsString('Tarifsiz restoranlar: 1', (string) $stats['Tariflar']->getDescription());
    }

    // --- Restoran tarifi formasi -----------------------------------------------------------------------------------

    public function test_restaurant_form_saves_the_tariff_converting_units(): void
    {
        $r = $this->restaurant(['billing_plan' => null]);

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => 'percent', 'commission_rate_bp' => 2.5, 'monthly_fee' => 150000, 'billing_started_at' => '2026-11-15'])
            ->call('save')
            ->assertHasNoFormErrors();

        $r->refresh();
        $this->assertSame(BillingPlan::Percent, $r->billing_plan);
        $this->assertSame([250, 15_000_000], [$r->commission_rate_bp, $r->monthly_fee]);   // 2,5% -> 250 bp; so'm -> tiyin
        $this->assertSame('2026-11-15', $r->billing_started_at->format('Y-m-d'));

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->assertFormSet(['billing_plan' => 'percent', 'commission_rate_bp' => 2.5, 'monthly_fee' => 150000]);
    }

    public function test_blank_rate_and_fee_fall_back_to_the_defaults_and_plan_can_stay_unset(): void
    {
        $r = $this->restaurant(['billing_plan' => null, 'commission_rate_bp' => 300, 'monthly_fee' => 1]);

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => null, 'commission_rate_bp' => null, 'monthly_fee' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $r->refresh();
        $this->assertNull($r->billing_plan);
        $this->assertSame([500, 10_000_000], [$r->commission_rate_bp, $r->monthly_fee]);
    }

    public function test_existing_restaurants_get_no_plan_automatically(): void
    {
        $this->assertNull(Restaurant::factory()->create()->fresh()->billing_plan);
    }

    // --- Ruxsatlar -------------------------------------------------------------------------------------------------------

    public function test_only_platform_admin_can_open_the_billing_pages(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        $this->actingAs($this->admin, 'admin')->get('/admin/hisob-kitoblar')->assertOk();
        $this->actingAs($this->admin, 'admin')->get('/admin/joriy-oy')->assertOk();

        // Admin guard'i setUp'da kirgan — egasini tekshirishdan oldin chiqaramiz.
        auth('admin')->forgetUser();

        $this->actingAs($owner, 'staff')->get('/admin/hisob-kitoblar')->assertRedirect('/admin/login');
        $this->actingAs($owner, 'staff')->get('/admin/joriy-oy')->assertRedirect('/admin/login');
    }

    public function test_only_platform_admin_can_cancel_payments_and_nobody_can_delete_them(): void
    {
        $policy = app(\App\Policies\RestaurantPaymentPolicy::class);
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->make();
        $payment = new \App\Models\RestaurantPayment;

        $this->assertTrue($policy->cancel($this->admin, $payment));
        $this->assertFalse($policy->cancel($owner, $payment));
        $this->assertFalse($policy->delete($this->admin, $payment));
    }

    public function test_policy_restricts_every_ability_to_platform_admin(): void
    {
        $policy = app(RestaurantInvoicePolicy::class);
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->make();
        $kitchen = Staff::factory()->kitchenStaff(Restaurant::factory()->create())->make();
        $invoice = new RestaurantInvoice;

        foreach (['viewAny', 'view', 'addPayment', 'markPaid', 'recalculate', 'changeTariff', 'waive'] as $ability) {
            $this->assertTrue($policy->{$ability}($this->admin, $invoice), $ability);
            $this->assertFalse($policy->{$ability}($owner, $invoice), "{$ability}: egasi");
            $this->assertFalse($policy->{$ability}($kitchen, $invoice), "{$ability}: oshxona xodimi");
        }
        foreach (['create', 'update', 'delete'] as $ability) {
            $this->assertFalse($policy->{$ability}($this->admin, $invoice), $ability);
        }
    }
}
