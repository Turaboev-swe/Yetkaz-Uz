<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentBadge;
use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Admin\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Filament\Admin\Widgets\BillingStats;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantBillingPlan;
use App\Models\RestaurantInvoice;
use App\Models\RestaurantPayment;
use App\Models\Scopes\ExcludeVoidInvoices;
use App\Models\Staff;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentStateResolver;
use App\Services\Billing\TariffService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Billing\Concerns\RecordsTariffs;
use Tests\TestCase;

/**
 * Joriy oy tarifini tuzatish (fixed -> percent): fixed hisob `void` ga o'tadi, tarix yangilanadi,
 * ortiqcha kelajak yozuvi olib tashlanadi; restaurants.billing_* = JORIY oy tarifi.
 * Production holati (Fresh Food): fixed noyabr + percent dekabr (A qoidasi tufayli), to'lovsiz fixed hisob.
 */
class BillingTariffCorrectionTest extends TestCase
{
    use RecordsTariffs;
    use RefreshDatabase;

    private InvoiceService $invoices;

    private TariffService $tariffs;

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = app(InvoiceService::class);
        $this->tariffs = app(TariffService::class);
        $this->admin = Staff::factory()->platformAdmin()->create(['name' => 'Aziz Admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->admin, 'admin');
        $this->now('2026-11-03 12:00');
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

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'billing_plan' => null,
            'commission_rate_bp' => 500,
            'monthly_fee' => 10_000_000,
            'billing_started_at' => null,
            'is_test' => false,
        ], $overrides));
    }

    /**
     * Production'dagi Fresh Food holati: tarix = fixed (noyabr) + percent (dekabr), noyabr fixed hisobi
     * to'lovsiz; restaurants.billing_* esa kelajak (percent) yozuvini ko'rsatadi.
     */
    private function freshFood(bool $brokenColumns = false): Restaurant
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-12-01');
        $this->invoices->generate($r, '2026-11');

        if ($brokenColumns) {
            $r->forceFill(['billing_plan' => BillingPlan::Percent])->save(); // eski sinxron xatosi: kelajak yozuvi «joriy»
        }

        return $r->fresh();
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

    /** @return list<array{0: string, 1: string}> [[sana, tarif]] */
    private function history(Restaurant $r): array
    {
        return RestaurantBillingPlan::query()->where('restaurant_id', $r->id)->orderBy('effective_from')->get()
            ->map(fn ($e) => [$e->effective_from->format('Y-m-d'), $e->plan?->value ?? 'none'])->all();
    }

    private function allInvoices(Restaurant $r)
    {
        return RestaurantInvoice::withoutGlobalScope(ExcludeVoidInvoices::class)->where('restaurant_id', $r->id)->orderBy('id')->get();
    }

    // --- Fresh Food senariysi ---------------------------------------------------------------------

    public function test_fresh_food_scenario_noyabr_becomes_percent_and_december_generates_a_percent_invoice(): void
    {
        $r = $this->freshFood();
        $fixedInvoice = $this->allInvoices($r)->first();
        $this->assertSame([BillingPlan::Fixed, InvoiceStatus::Unpaid, 10_000_000], [$fixedInvoice->plan, $fixedInvoice->status, $fixedInvoice->amount_due]);
        $this->assertSame([['2026-11-01', 'fixed'], ['2026-12-01', 'percent']], $this->history($r));

        $this->tariffs->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-11', $this->admin->id, 'Fresh Food foizda kelishilgan edi', true);

        // Fixed hisob: bekor qilindi (o'chirilmadi), sabab/kim/qachon izohda.
        $voided = RestaurantInvoice::withoutGlobalScope(ExcludeVoidInvoices::class)->find($fixedInvoice->id);
        $this->assertSame(InvoiceStatus::Void, $voided->status);
        $this->assertStringContainsString('Fresh Food foizda kelishilgan edi', $voided->notes);
        $this->assertStringContainsString('Aziz Admin', $voided->notes);
        $this->assertStringContainsString('03.11.2026 12:00', $voided->notes);
        $this->assertStringContainsString('BEKOR QILINDI', $voided->notes);

        // Tarix: noyabr yangilandi, dekabr (endi ortiqcha) olib tashlandi.
        $this->assertSame([['2026-11-01', 'percent']], $this->history($r));
        $this->assertSame(BillingPlan::Percent, app(BillingCalculator::class)->tariffFor($r, '2026-11')->plan);

        // restaurants.billing_* = joriy tarif, noyabr uchun faol hisob yo'q (void ko'rinmaydi).
        $this->assertSame(BillingPlan::Percent, $r->fresh()->billing_plan);
        $this->assertSame(0, RestaurantInvoice::where('restaurant_id', $r->id)->count());

        // 1-dekabr: noyabr savdosining 5% i bo'yicha percent hisob yaratiladi (void'ga qaramay).
        $this->delivered($r, '2026-11-10 12:00', 40_000_000);
        $this->delivered($r, '2026-11-20 12:00', 20_000_000);
        $this->now('2026-12-01 06:00');
        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $november = RestaurantInvoice::where('restaurant_id', $r->id)->where('period', '2026-11-01')->get();
        $this->assertCount(1, $november);
        $this->assertSame([BillingPlan::Percent, 500, 2, 60_000_000, 3_000_000], [
            $november[0]->plan, $november[0]->rate_bp, $november[0]->orders_count, $november[0]->orders_total, $november[0]->amount_due,
        ]);
        $this->assertSame(2, $this->allInvoices($r)->count(), 'void + yangi percent: ikkalasi bazada');
        $this->assertNull(RestaurantInvoice::where('restaurant_id', $r->id)->where('period', '2026-12-01')->first(), 'dekabr uchun fixed hisob yaratilmaydi');
    }

    public function test_fresh_food_scenario_through_the_restaurant_form(): void
    {
        $r = $this->freshFood();

        // Sabab va tasdiq maydonlari ko'rinadi va majburiy.
        $component = Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->assertFormFieldIsHidden('billing_correction_reason')
            ->fillForm(['billing_plan' => 'percent', 'commission_rate_bp' => 5, 'effective_month' => 'current'])
            ->assertFormFieldIsVisible('billing_correction_reason')
            ->assertFormFieldIsVisible('billing_correction_confirm')
            ->call('save')
            ->assertHasFormErrors(['billing_correction_reason', 'billing_correction_confirm']);

        $this->assertSame(InvoiceStatus::Unpaid, $this->allInvoices($r)->first()->status, 'tasdiqsiz hech narsa o‘zgarmaydi');

        $component
            ->fillForm(['billing_correction_reason' => 'Xato fixed qo‘yilgan', 'billing_correction_confirm' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(InvoiceStatus::Void, $this->allInvoices($r)->first()->status);
        $this->assertSame([['2026-11-01', 'percent']], $this->history($r));
        $this->assertSame(BillingPlan::Percent, $r->fresh()->billing_plan);
        $this->assertStringContainsString('Xato fixed qo‘yilgan', $this->allInvoices($r)->first()->notes);
    }

    // --- Rad etish ---------------------------------------------------------------------------------

    public function test_correction_is_rejected_when_the_fixed_invoice_has_an_active_payment(): void
    {
        $r = $this->freshFood();
        $invoice = $this->allInvoices($r)->first();
        $payment = $this->invoices->addPayment($invoice, 1_000_000, BillingPaymentMethod::Cash, '2026-11-03');

        try {
            $this->tariffs->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-11', $this->admin->id, 'sabab', true);
            $this->fail('faol to‘lovli hisob bekor qilinmaydi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_month', $e->errors());
            $this->assertStringContainsString('faol to‘lov bor', $e->errors()['effective_month'][0]);
        }

        $this->assertSame(InvoiceStatus::Partial, $this->allInvoices($r)->first()->status);
        $this->assertSame([['2026-11-01', 'fixed'], ['2026-12-01', 'percent']], $this->history($r), 'tarix o‘zgarmaydi');

        // Forma ham to'lovli hisobda tuzatish maydonlarini ko'rsatmaydi va saqlashni rad etadi.
        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->fillForm(['billing_plan' => 'percent', 'effective_month' => 'current'])
            ->assertFormFieldIsHidden('billing_correction_reason')
            ->call('save')
            ->assertHasFormErrors(['effective_month']);

        // To'lov bekor qilingach (faol to'lov yo'q) tuzatish mumkin.
        $this->invoices->cancelPayment($payment, 'xato kiritilgan', $this->admin->id);
        $this->tariffs->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-11', $this->admin->id, 'sabab', true);
        $this->assertSame(InvoiceStatus::Void, $this->allInvoices($r)->first()->status);
    }

    public function test_correction_requires_reason_and_confirmation(): void
    {
        $r = $this->freshFood();

        foreach ([[null, true, 'billing_correction_reason'], ['  ', true, 'billing_correction_reason'], ['sabab', false, 'billing_correction_confirm']] as [$reason, $confirmed, $key]) {
            try {
                $this->tariffs->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-11', $this->admin->id, $reason, $confirmed);
                $this->fail('sabab va tasdiq majburiy');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        $this->assertSame(InvoiceStatus::Unpaid, $this->allInvoices($r)->first()->status);
        $this->assertSame([['2026-11-01', 'fixed'], ['2026-12-01', 'percent']], $this->history($r));
    }

    public function test_only_fixed_to_percent_in_the_current_month_is_a_correction(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-10-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->invoices->generate($r, '2026-11');

        // fixed -> fixed (boshqa summa) joriy oyda: avvalgidek rad etilmaydi/void qilinmaydi — A qoidasi bu holda boshqa tarif emas.
        $this->tariffs->apply($r, BillingPlan::Fixed, 500, 12_000_000, '2026-11');
        $this->assertSame(InvoiceStatus::Unpaid, $this->allInvoices($r)->first()->status);

        $this->assertSame(12_000_000, $r->fresh()->monthly_fee);

        // O'tgan oyni tuzatib bo'lmaydi.
        $this->now('2026-12-02 12:00');
        $this->expectException(ValidationException::class);
        $this->tariffs->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-11');
    }

    // --- Void: qarz, statistika, to'lov holati, filtrlar -------------------------------------------

    public function test_void_invoice_is_excluded_from_debt_stats_and_payment_state(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 11_000_000, '2026-11-01');
        $other = $this->restaurant();
        $this->setTariff($other, BillingPlan::Fixed, 500, 33_000_000, '2026-11-01');
        $voidable = $this->invoices->generate($r, '2026-11');
        $this->invoices->generate($other, '2026-11');
        $debt = function (): string {
            $stat = collect((fn () => $this->getStats())->call(new BillingStats))->first(fn ($s) => (string) $s->getLabel() === 'Umumiy qarz');

            return (string) $stat->getValue();
        };

        $this->assertSame("440 000 so'm", $debt()); // 110 000 + 330 000

        $this->invoices->void($voidable, 'xato', $this->admin->id);

        // Umumiy qarz: faqat boshqa restoran (void'ning 110 000 i yo'q).
        $this->assertSame("330 000 so'm", $debt());
        $this->assertSame(0, RestaurantInvoice::withoutGlobalScope(ExcludeVoidInvoices::class)->find($voidable->id)->debt());
        $this->assertSame(1, RestaurantInvoice::count());
        $this->assertSame(0, $r->invoices()->count());

        // To'lov holati: void'ga qaramay «Hisob yaratilmagan» (muddati o'tgan emas).
        $this->now('2026-11-30 12:00');
        $row = app(BillingCalculator::class)->aggregated('2026-11')->whereKey($r->id)->first();
        $this->assertNull($row->billing_invoice_id);
        $this->assertSame(PaymentBadge::NoInvoice, app(PaymentStateResolver::class)->resolve($row, '2026-11')->badge);
    }

    public function test_void_invoice_allows_a_new_invoice_for_the_same_period_but_two_active_ones_remain_impossible(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $first = $this->invoices->generate($r, '2026-11');

        // Faol hisob ustiga ikkinchisi — partial unique index to'sadi.
        try {
            DB::transaction(fn () => RestaurantInvoice::create($first->only(['restaurant_id', 'plan', 'rate_bp', 'fixed_fee', 'amount_due', 'due_date', 'status']) + ['period' => '2026-11-01']));
            $this->fail('bir oyga ikkita faol hisob');
        } catch (QueryException $e) {
            $this->assertStringContainsString('restaurant_invoices_restaurant_period_active_unique', $e->getMessage());
        }

        $this->invoices->void($first, 'tuzatish');
        $second = $this->invoices->generate($r, '2026-11');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(InvoiceStatus::Unpaid, $second->status);
        $this->assertSame(2, $this->allInvoices($r)->count());
        $this->assertSame(1, RestaurantInvoice::where('restaurant_id', $r->id)->count());
    }

    public function test_actions_on_a_void_invoice_are_rejected(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $invoice = $this->invoices->void($this->invoices->generate($r, '2026-11'), 'xato');

        foreach ([
            fn () => $this->invoices->addPayment($invoice, 100, BillingPaymentMethod::Cash, '2026-11-03'),
            fn () => $this->invoices->markPaid($invoice, BillingPaymentMethod::Cash, '2026-11-03'),
            fn () => $this->invoices->changeTariff($invoice, BillingPlan::Percent, 500, 0, 'sabab'),
            fn () => $this->invoices->waive($invoice),
            fn () => $this->invoices->void($invoice, 'yana'),
        ] as $action) {
            try {
                $action();
                $this->fail('bekor qilingan hisobga amal bajarilmaydi');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame(0, RestaurantPayment::count());
    }

    public function test_void_requires_a_reason(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $invoice = $this->invoices->generate($r, '2026-11');

        $this->expectException(ValidationException::class);
        $this->invoices->void($invoice, ' ');
    }

    // --- Keyingi oy yozuvi ---------------------------------------------------------------------------

    public function test_a_next_month_entry_equal_to_the_current_one_is_dropped(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-12-01');
        $this->setTariff($r, BillingPlan::Percent, 300, 10_000_000, '2027-02-01');

        // Dekabrni ham foiz 5% qilib qo'yamiz: u noyabrdan farq qilmay qoladi -> olib tashlanadi. Fevral (3%) qoladi.
        $this->tariffs->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-12');

        $this->assertSame([['2026-11-01', 'percent'], ['2027-02-01', 'percent']], $this->history($r));
        $this->assertSame(300, $r->billingPlans()->get()->last()->rate_bp);
    }

    public function test_past_history_is_never_touched_by_cleanup(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-09-01');
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-10-01'); // o'tmishda takror yozuv
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');

        $this->tariffs->apply($r, BillingPlan::Percent, 500, 10_000_000, '2026-11');

        $this->assertSame([['2026-09-01', 'percent'], ['2026-10-01', 'percent'], ['2026-11-01', 'percent']], $this->history($r));
    }

    // --- Joriy tarif sinxroni ----------------------------------------------------------------------------

    public function test_restaurant_columns_show_the_current_tariff_not_the_future_one(): void
    {
        $r = $this->restaurant();
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Fixed, 500, 20_000_000, '2026-12-01');

        $fresh = $r->fresh();
        $this->assertSame([BillingPlan::Percent, 500], [$fresh->billing_plan, $fresh->commission_rate_bp]);
        $this->assertSame(BillingPlan::Percent, $fresh->currentBillingEntry()->plan);
        $this->assertSame(BillingPlan::Fixed, $fresh->upcomingBillingEntry()->plan);

        // Oy almashganda (1-dekabr) kelajak yozuvi joriy bo'ladi.
        $this->now('2026-12-01 00:10');
        $this->artisan('billing:sync-tariffs')->expectsOutputToContain('o‘zgargan restoranlar — 1')->assertSuccessful();
        $fresh = $r->fresh();
        $this->assertSame([BillingPlan::Fixed, 20_000_000], [$fresh->billing_plan, $fresh->monthly_fee]);
        $this->assertNull($fresh->upcomingBillingEntry());

        // Takror ishga tushirish hech narsani o'zgartirmaydi.
        $this->artisan('billing:sync-tariffs')->expectsOutputToContain('o‘zgargan restoranlar — 0')->assertSuccessful();
    }

    public function test_only_a_future_entry_means_no_current_tariff(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Percent]);
        $this->setTariff($r, BillingPlan::Fixed, 500, 20_000_000, '2026-12-01');

        $this->assertNull($r->fresh()->billing_plan);
        $this->assertSame(BillingPlan::Fixed, $r->fresh()->upcomingBillingEntry()->plan);
    }

    public function test_restaurants_without_history_are_left_alone(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'monthly_fee' => 7_000_000]);

        $this->assertSame(0, $this->tariffs->syncAllCurrentTariffs());
        $this->assertSame(BillingPlan::Fixed, $r->fresh()->billing_plan);
    }

    public function test_sync_migration_repairs_production_state(): void
    {
        // Fresh Food: restaurants.* = dekabr (percent), aslida joriy (noyabr) fixed.
        $r = $this->freshFood(brokenColumns: true);
        $this->assertSame(BillingPlan::Percent, $r->billing_plan);

        $noHistory = $this->restaurant(['billing_plan' => BillingPlan::Fixed]); // tarixsiz — tegilmaydi
        $futureOnly = $this->restaurant(['billing_plan' => BillingPlan::Percent]);
        RestaurantBillingPlan::create(['restaurant_id' => $futureOnly->id, 'plan' => BillingPlan::Fixed, 'rate_bp' => 500, 'monthly_fee' => 10_000_000, 'effective_from' => '2026-12-01']);

        (require database_path('migrations/2026_11_07_000002_sync_current_billing_tariffs.php'))->up();

        $this->assertSame(BillingPlan::Fixed, $r->fresh()->billing_plan);
        $this->assertSame(BillingPlan::Fixed, $noHistory->fresh()->billing_plan);
        $this->assertNull($futureOnly->fresh()->billing_plan);
        $this->assertSame([['2026-11-01', 'fixed'], ['2026-12-01', 'percent']], $this->history($r), 'tarix o‘zgarmaydi');
        $this->assertSame(1, $this->allInvoices($r)->count());
    }

    // --- Ko'rsatish: «Dekabrdan: Foiz 5%» ---------------------------------------------------------------

    public function test_upcoming_tariff_is_shown_on_the_form_and_in_the_list(): void
    {
        $r = $this->restaurant(['name' => 'Kelajak Kafe']);
        $this->setTariff($r, BillingPlan::Fixed, 500, 10_000_000, '2026-11-01');
        $this->setTariff($r, BillingPlan::Percent, 500, 10_000_000, '2026-12-01');

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->assertFormSet(['billing_plan' => 'fixed', 'effective_month' => 'current'])
            ->assertSee('Dekabrdan: Foiz 5%');

        Livewire::test(ListRestaurants::class)
            ->assertSee("Qat'iy 100 000 so'm (oldindan)")
            ->assertSee('Dekabrdan: Foiz 5%');

        $plain = $this->restaurant(['name' => 'Oddiy Kafe']);
        $this->setTariff($plain, BillingPlan::Percent, 500, 10_000_000, '2026-11-01');
        Livewire::test(EditRestaurant::class, ['record' => $plain->getRouteKey()])->assertDontSee('Keyingi tarif');
    }
}
