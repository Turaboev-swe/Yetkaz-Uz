<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Pages\CurrentMonthBilling;
use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Admin\Widgets\BillingStats;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantBillingPlan;
use App\Models\RestaurantInvoice;
use App\Models\Staff;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\TariffService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Billing\Concerns\RecordsTariffs;
use Tests\TestCase;

/**
 * Tarif tarixi (restaurant_billing_plans) va to'lov vaqtlari: fixed OLDINDAN (oyning 1-kunidan),
 * percent KEYIN (oy tugagach); tarif almashishi, oy o'rtasida fixed, muddatlar, panel.
 */
class BillingTariffHistoryTest extends TestCase
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

    private function restaurant(?BillingPlan $plan = null, array $overrides = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'billing_plan' => null,
            'commission_rate_bp' => 500,
            'monthly_fee' => 10_000_000,
            'billing_started_at' => null,
            'is_test' => false,
        ], $overrides));
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

    private function invoice(Restaurant $r, string $period): ?RestaurantInvoice
    {
        return RestaurantInvoice::query()->where('restaurant_id', $r->id)->where('period', $period.'-01')->first();
    }

    // --- Tarif tarixi: davr uchun amalda bo'lgan tarif -------------------------------------------

    public function test_period_tariff_is_the_latest_history_entry_not_after_the_period(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 15_000_000, '2027-01-01');
        $calc = app(BillingCalculator::class);

        $this->assertNull($calc->tariffFor($r, '2026-10')->plan, 'birinchi yozuvdan oldin tarifsiz');
        $this->assertSame(BillingPlan::Percent, $calc->tariffFor($r, '2026-12')->plan);
        $this->assertSame(BillingPlan::Fixed, $calc->tariffFor($r, '2027-01')->plan);
        $this->assertSame(15_000_000, $calc->tariffFor($r, '2027-03')->monthlyFee);
    }

    public function test_invoice_uses_the_tariff_in_force_for_its_period_not_the_restaurant_columns(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 20_000_000, '2027-02-01'); // restaurants.* endi fixed
        $this->delivered($r, '2026-11-05 12:00', 10_000_000);
        $this->now('2026-12-15 12:00');

        $november = $this->invoices->generate($r->fresh(), '2026-11');

        $this->assertSame([BillingPlan::Percent, 500, 500_000], [$november->plan, $november->rate_bp, $november->amount_due]);
    }

    // --- Oldindan va keyin to'lov vaqtlari ---------------------------------------------------------

    public function test_fixed_invoice_is_created_on_the_first_day_of_its_own_month(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');

        $this->now('2026-11-01 06:00');
        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $invoice = $this->invoice($r, '2026-11');
        $this->assertNotNull($invoice, 'fixed noyabr hisobi 1-noyabrda yaratiladi');
        $this->assertSame([BillingPlan::Fixed, 10_000_000, InvoiceStatus::Unpaid], [$invoice->plan, $invoice->amount_due, $invoice->status]);
    }

    public function test_fixed_invoice_cannot_be_created_for_a_future_month(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->now('2026-11-20 12:00');

        $this->assertNull($this->invoices->generate($r, '2026-12'));
        $this->assertNotNull($this->invoices->generate($r, '2026-11'));
    }

    public function test_percent_invoice_is_rejected_until_the_month_is_over(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->delivered($r, '2026-11-05 12:00', 10_000_000);

        $this->now('2026-11-30 23:59'); // oxirgi kun — hali tugamagan
        $this->assertNull($this->invoices->generate($r, '2026-11'));

        $this->now('2026-12-01 00:01');
        $invoice = $this->invoices->generate($r, '2026-11');
        $this->assertSame(500_000, $invoice->amount_due);
    }

    public function test_command_skips_unfinished_month_for_percent_and_says_so(): void
    {
        $percent = $this->restaurant();
        $this->setTariff($percent, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $fixed = $this->restaurant();
        $this->setTariff($fixed, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->now('2026-11-20 12:00');

        $this->artisan('billing:generate-invoices', ['--period' => '2026-11'])
            ->expectsOutputToContain('yaratildi 1')
            ->expectsOutputToContain('1 ta foiz (percent) tarifidagi restoran o‘tkazib yuborildi')
            ->assertSuccessful();

        $this->assertNull($this->invoice($percent, '2026-11'));
        $this->assertNotNull($this->invoice($fixed, '2026-11'));
    }

    public function test_default_run_covers_the_previous_month_for_percent_and_the_current_month_for_fixed(): void
    {
        $percent = $this->restaurant();
        $this->setTariff($percent, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $fixed = $this->restaurant();
        $this->setTariff($fixed, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->delivered($percent, '2026-11-10 12:00', 10_000_000);

        $this->now('2026-12-01 06:00');
        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertSame(500_000, $this->invoice($percent, '2026-11')->amount_due, 'o‘tgan oy savdosi bo‘yicha');
        $this->assertNull($this->invoice($percent, '2026-12'), 'percent joriy oyi hali tugamagan');
        $this->assertNotNull($this->invoice($fixed, '2026-12'), 'fixed joriy oy uchun oldindan');
    }

    // --- Tarif almashishi -----------------------------------------------------------------------------

    public function test_percent_to_fixed_on_the_first_issues_both_invoices_with_the_right_tariffs(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 12_000_000, '2026-12-01');
        $this->delivered($r, '2026-11-10 12:00', 10_000_000);

        $this->now('2026-12-01 06:00');
        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $november = $this->invoice($r, '2026-11');
        $december = $this->invoice($r, '2026-12');
        $this->assertSame([BillingPlan::Percent, 500_000, '2026-12-10'], [$november->plan, $november->amount_due, $november->due_date->format('Y-m-d')]);
        $this->assertSame([BillingPlan::Fixed, 12_000_000, '2026-12-05'], [$december->plan, $december->amount_due, $december->due_date->format('Y-m-d')]);
    }

    public function test_fixed_to_percent_on_the_first_bills_the_old_month_in_advance_and_the_new_one_afterwards(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-12-01');

        $this->now('2026-11-01 06:00');
        $this->artisan('billing:generate-invoices')->assertSuccessful();
        $this->assertSame(BillingPlan::Fixed, $this->invoice($r, '2026-11')->plan, 'noyabr oldindan fixed bilan');

        $this->delivered($r, '2026-12-10 12:00', 20_000_000);

        $this->now('2026-12-01 06:00');
        $this->artisan('billing:generate-invoices')->assertSuccessful();
        $this->assertNull($this->invoice($r, '2026-12'), 'dekabr endi percent — oy tugamaguncha hisob yo‘q');
        $this->assertSame(BillingPlan::Fixed, $this->invoice($r, '2026-11')->plan, 'noyabr hisobi o‘zgarmaydi');

        $this->now('2027-01-01 06:00');
        $this->artisan('billing:generate-invoices')->assertSuccessful();
        $december = $this->invoice($r, '2026-12');
        $this->assertSame([BillingPlan::Percent, 1_000_000, '2027-01-10'], [$december->plan, $december->amount_due, $december->due_date->format('Y-m-d')]);
    }

    // --- Muddatlar --------------------------------------------------------------------------------------

    public function test_due_dates_fixed_day_of_its_month_and_percent_ten_days_after_the_month_ends(): void
    {
        $fixed = $this->restaurant();
        $this->setTariff($fixed, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $percent = $this->restaurant();
        $this->setTariff($percent, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->now('2026-12-15 12:00');

        $this->assertSame('2026-11-05', $this->invoices->generate($fixed, '2026-11')->due_date->format('Y-m-d'));
        $this->assertSame('2026-12-10', $this->invoices->generate($percent, '2026-11')->due_date->format('Y-m-d'));
    }

    public function test_fixed_due_day_is_configurable_and_clamped_to_the_month_length(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2027-01-01');
        $this->now('2027-03-10 12:00');

        config(['billing.fixed_due_day' => 7]);
        $this->assertSame('2027-01-07', $this->invoices->generate($r, '2027-01')->due_date->format('Y-m-d'));

        config(['billing.fixed_due_day' => 31]);
        $this->assertSame('2027-02-28', $this->invoices->generate($r, '2027-02')->due_date->format('Y-m-d'), 'fevralda 28-kun');
    }

    // --- Fixed: orders_count/orders_total faqat ma'lumot uchun ---------------------------------------

    public function test_fixed_invoice_orders_are_informational_only(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->delivered($r, '2026-11-05 12:00', 50_000_000);
        $this->now('2026-11-20 12:00');

        $invoice = $this->invoices->generate($r, '2026-11');
        $this->assertSame([1, 50_000_000, 10_000_000], [$invoice->orders_count, $invoice->orders_total, $invoice->amount_due]);

        $this->delivered($r, '2026-11-18 12:00', 50_000_000);
        $this->invoices->recalculate($invoice);

        $this->assertSame([2, 100_000_000, 10_000_000], [$invoice->fresh()->orders_count, $invoice->fresh()->orders_total, $invoice->fresh()->amount_due]);
    }

    // --- Oy o'rtasida fixed: darhol proratsiyali hisob ------------------------------------------------

    public function test_fixed_set_mid_month_creates_the_prorated_invoice_immediately(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => 'fixed', 'monthly_fee' => 100000, 'billing_started_at' => '2026-11-16', 'effective_month' => 'current'])
            ->call('save')
            ->assertHasNoFormErrors();

        $invoice = $this->invoice($r, '2026-11');
        $this->assertNotNull($invoice, 'hisob 1-kungacha kutilmaydi');
        $this->assertSame([BillingPlan::Fixed, 5_000_000, '2026-11-16'], [$invoice->plan, $invoice->amount_due, $invoice->billing_started_at->format('Y-m-d')]); // 15/30
        $this->assertSame('2026-11-05', $invoice->due_date->format('Y-m-d'));

        // Qayta saqlash ikkinchi hisob yaratmaydi.
        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['name' => 'Boshqa nom'])
            ->call('save');
        $this->assertSame(1, RestaurantInvoice::where('restaurant_id', $r->id)->count());
    }

    public function test_fixed_set_from_next_month_creates_no_invoice_now(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => 'fixed', 'monthly_fee' => 100000, 'effective_month' => 'next'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, RestaurantInvoice::where('restaurant_id', $r->id)->count());
        $this->assertSame('2026-12-01', $r->billingPlans()->first()->effective_from->format('Y-m-d'));
        $this->assertSame(BillingPlan::Fixed, $r->fresh()->billing_plan, 'restaurants.* = oxirgi yozuv');
    }

    public function test_fixed_set_for_the_current_month_without_a_start_date_bills_the_full_month_immediately(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();

        app(TariffService::class)->apply($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11');

        $this->assertSame(10_000_000, $this->invoice($r, '2026-11')->amount_due);
    }

    // --- Tarif tarixiga yozish ---------------------------------------------------------------------------

    public function test_form_change_writes_a_history_row_and_keeps_old_months_on_the_old_tariff(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => 'percent', 'commission_rate_bp' => 3, 'effective_month' => 'next'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([[500, '2026-11-01'], [300, '2026-12-01']], $r->billingPlans()->get()->map(fn ($e) => [$e->rate_bp, $e->effective_from->format('Y-m-d')])->all());
        $this->assertSame(500, app(BillingCalculator::class)->tariffFor($r, '2026-11')->rateBp);
        $this->assertSame(300, app(BillingCalculator::class)->tariffFor($r, '2026-12')->rateBp);
        $this->assertSame(300, $r->fresh()->commission_rate_bp);
    }

    public function test_unchanged_tariff_writes_no_history_row_and_saving_other_fields_does_not_shift_a_scheduled_tariff(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-12-01'); // keyingi oydan rejalashtirilgan

        // Forma rejalashtirilgan (kelgusi) tarifni ko'rsatadi; boshqa maydonni saqlash uni oldinga surmasligi kerak.
        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->assertFormSet(['billing_plan' => 'fixed', 'effective_month' => 'next'])
            ->fillForm(['name' => 'Yangi nom'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $r->billingPlans()->count());
        $this->assertSame(BillingPlan::Percent, app(BillingCalculator::class)->tariffFor($r, '2026-11')->plan);
        $this->assertSame(0, RestaurantInvoice::where('restaurant_id', $r->id)->count());
    }

    public function test_switching_to_percent_is_rejected_for_a_month_that_already_has_a_fixed_invoice(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->invoices->generate($r, '2026-11');

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => 'percent', 'name' => 'Saqlanmasligi kerak', 'effective_month' => 'current'])
            ->call('save')
            ->assertHasFormErrors(['effective_month']);

        $this->assertNotSame('Saqlanmasligi kerak', $r->fresh()->name, 'hech narsa saqlanmaydi');
        $this->assertSame(1, $r->billingPlans()->count());

        // Keyingi oydan esa mumkin.
        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => 'percent', 'effective_month' => 'next'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(BillingPlan::Percent, app(BillingCalculator::class)->tariffFor($r, '2026-12')->plan);
    }

    public function test_tariff_cannot_start_in_a_past_month(): void
    {
        $this->now('2026-12-10 12:00');
        $r = $this->restaurant();

        $this->expectException(ValidationException::class);

        app(TariffService::class)->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-11');
    }

    public function test_removing_the_tariff_is_recorded_as_a_plan_less_period(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');

        app(TariffService::class)->apply($r, null, 500, 10_000_000, '2026-12');

        $this->assertNull(app(BillingCalculator::class)->tariffFor($r, '2026-12')->plan);
        $this->assertNull($r->fresh()->billing_plan);
        $this->delivered($r, '2026-12-05 12:00', 10_000_000);
        $this->now('2027-01-05 12:00');
        $this->assertNull($this->invoices->generate($r, '2026-12'), 'tarifsiz davr hisoblanmaydi');
    }

    public function test_history_is_listed_on_the_restaurant_form(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 12_000_000, '2026-12-01');

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->assertSee('Noyabr 2026 dan: Foiz 5%')
            ->assertSee("Dekabr 2026 dan: Qat'iy 120 000 so'm (oldindan)");
    }

    // --- Data migration ---------------------------------------------------------------------------------

    public function test_data_migration_seeds_history_only_for_restaurants_with_a_plan_and_is_safe_to_rerun(): void
    {
        $percent = $this->restaurant(null, ['billing_plan' => BillingPlan::Percent, 'billing_started_at' => '2026-11-15']);
        $fixed = $this->restaurant(null, ['billing_plan' => BillingPlan::Fixed, 'monthly_fee' => 15_000_000]);
        $none = $this->restaurant();

        $migration = require base_path('database/migrations/2026_11_04_000001_create_restaurant_billing_plans.php');
        $migration->down();
        $migration->up();
        $migration->down();
        $migration->up();

        $rows = RestaurantBillingPlan::query()->orderBy('restaurant_id')->get();
        $this->assertSame([$percent->id, $fixed->id], $rows->pluck('restaurant_id')->all());
        $this->assertSame(['2026-11-01', '2026-11-01'], $rows->map(fn ($e) => $e->effective_from->format('Y-m-d'))->all());
        $this->assertSame([BillingPlan::Percent, 500], [$rows[0]->plan, $rows[0]->rate_bp]);
        $this->assertSame([BillingPlan::Fixed, 15_000_000], [$rows[1]->plan, $rows[1]->monthly_fee]);
        $this->assertSame(0, RestaurantBillingPlan::where('restaurant_id', $none->id)->count());
    }

    public function test_history_enforces_one_row_per_month_start_per_restaurant(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('restaurant_billing_plans')->insert(['restaurant_id' => $r->id, 'plan' => 'fixed', 'effective_from' => '2026-11-01', 'created_at' => now(), 'updated_at' => now()]);
    }

    // --- Panel: Joriy oy va statistika -------------------------------------------------------------------

    public function test_current_month_page_shows_the_fixed_invoice_state_and_percent_live_accrual(): void
    {
        $this->now('2026-11-20 12:00');
        $fixedUnpaid = $this->restaurant();
        $this->setTariff($fixedUnpaid, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $fixedPaid = $this->restaurant();
        $this->setTariff($fixedPaid, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $fixedNone = $this->restaurant(); // fixed, lekin hisob hali yo'q
        $this->setTariff($fixedNone, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $percent = $this->restaurant();
        $this->setTariff($percent, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->delivered($percent, '2026-11-05 12:00', 20_000_000);

        $this->invoices->generate($fixedUnpaid, '2026-11');
        $this->invoices->markPaid($this->invoices->generate($fixedPaid, '2026-11'), \App\Enums\BillingPaymentMethod::Cash, '2026-11-10');

        Livewire::test(CurrentMonthBilling::class)
            ->assertTableColumnStateSet('billing_state', InvoiceStatus::Unpaid->label(), record: $fixedUnpaid->id)
            ->assertTableColumnStateSet('billing_state', InvoiceStatus::Paid->label(), record: $fixedPaid->id)
            ->assertTableColumnStateSet('billing_state', 'Hisob yaratilmagan', record: $fixedNone->id)
            ->assertTableColumnStateSet('billing_state', 'Oy oxirida', record: $percent->id)
            ->assertTableColumnStateSet('accrued', "100 000 so'm", record: $fixedUnpaid->id)
            ->assertTableColumnStateSet('accrued', "10 000 so'm", record: $percent->id) // 5% of 200 000 so'm
            ->sortTable('accrued', 'desc')
            ->assertCanSeeTableRecords([$fixedUnpaid, $fixedPaid, $fixedNone, $percent]);
    }

    public function test_current_month_page_shows_the_tariff_in_force_this_month_not_a_scheduled_one(): void
    {
        $this->now('2026-11-20 12:00');
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-12-01');

        Livewire::test(CurrentMonthBilling::class)
            ->assertTableColumnStateSet('billing_state', 'Oy oxirida', record: $r->id)
            ->filterTable('period_plan', 'percent')->assertCanSeeTableRecords([$r])
            ->resetTableFilters()->filterTable('period_plan', 'fixed')->assertCanNotSeeTableRecords([$r]);
    }

    public function test_expected_income_is_fixed_invoices_plus_percent_live_accrual(): void
    {
        $this->now('2026-11-20 12:00');
        $fixed = $this->restaurant();
        $this->setTariff($fixed, BillingPlan::Fixed, 500, 20_000_000, '2026-11-01'); // 200 000 so'm
        $waived = $this->restaurant();
        $this->setTariff($waived, BillingPlan::Fixed, 500, 30_000_000, '2026-11-01');
        $percent = $this->restaurant();
        $this->setTariff($percent, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->delivered($percent, '2026-11-05 12:00', 20_000_000); // 5% = 10 000 so'm
        $this->invoices->generate($fixed, '2026-11');
        $this->invoices->waive($this->invoices->generate($waived, '2026-11'));

        Livewire::test(BillingStats::class)->assertSee("210 000 so'm"); // 200 000 + 10 000, kechirilgan hisobsiz
    }

    public function test_expected_income_counts_a_fixed_restaurant_without_an_invoice_yet(): void
    {
        $this->now('2026-11-20 12:00');
        $fixed = $this->restaurant();
        $this->setTariff($fixed, BillingPlan::Fixed, 500, 20_000_000, '2026-11-01');

        Livewire::test(BillingStats::class)->assertSee("200 000 so'm");
    }
}
