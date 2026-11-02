<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PosType;
use App\Events\OrderDispatchFailed;
use App\Events\PrintJobRequested;
use App\Filament\Admin\Resources\OrderResource\Pages\ListOrders as AdminListOrders;
use App\Filament\Admin\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Filament\Admin\Widgets\PrintAgentStatus;
use App\Jobs\DispatchOrderJob;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Print agent monitoringi FAQAT agenti sozlangan restoranlarga (pos_type = escpos VA print_agent_token bor)
 * tegishli: printeri yo'q restoranda banner, signal, «Chek chiqmadi» belgisi, admin jadvali — hech biri yo'q,
 * chunki ular uchun chek kutilmaydi.
 */
class PrintAgentConfiguredOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /** @return array<string, array{0: PosType, 1: ?string}> */
    public static function printerless(): array
    {
        return [
            'qo‘lda (manual)' => [PosType::Manual, null],
            'qo‘lda, lekin eski token qolgan' => [PosType::Manual, 'old-token'],
            'escpos, token yo‘q' => [PosType::EscPos, null],
            'escpos, bo‘sh token' => [PosType::EscPos, ''],
        ];
    }

    private function restaurant(PosType $pos, ?string $token, array $extra = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'R'.uniqid(), 'pos_type' => $pos, 'print_agent_token' => $token,
        ], $extra));
    }

    private function printer(array $extra = []): Restaurant
    {
        return $this->restaurant(PosType::EscPos, 'tok-'.uniqid(), $extra);
    }

    private function staleOrder(Restaurant $r, array $extra = []): Order
    {
        return Order::factory()->for($r)->for(User::factory())->create(array_replace([
            'status' => OrderStatus::Preparing, 'printed_at' => null, 'dispatch_failed_at' => null,
            'created_at' => now()->subMinutes(30),
        ], $extra));
    }

    #[DataProvider('printerless')]
    public function test_printerless_restaurant_is_not_configured_for_the_agent(PosType $pos, ?string $token): void
    {
        $r = $this->restaurant($pos, $token);

        $this->assertFalse($r->usesPrintAgent());
        $this->assertSame(0, Restaurant::query()->withPrintAgent()->count());
        $this->assertTrue($this->printer()->usesPrintAgent());
    }

    #[DataProvider('printerless')]
    public function test_check_unprinted_never_flags_orders_of_printerless_restaurants(PosType $pos, ?string $token): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $r = $this->restaurant($pos, $token);
        $order = $this->staleOrder($r);

        $this->artisan('print:check-unprinted')->assertSuccessful();

        $this->assertNull($order->fresh()->dispatch_failed_at);
        Event::assertNotDispatched(OrderDispatchFailed::class);
    }

    public function test_check_unprinted_still_flags_a_configured_printer_restaurant(): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $order = $this->staleOrder($this->printer());

        $this->artisan('print:check-unprinted')->assertSuccessful();

        $this->assertNotNull($order->fresh()->dispatch_failed_at);
        Event::assertDispatchedTimes(OrderDispatchFailed::class, 1);
    }

    public function test_kitchen_status_reports_no_printer_for_printerless_restaurants_and_hides_stale_failures(): void
    {
        $withPrinter = $this->printer(['name' => 'Printerli', 'print_agent_last_seen_at' => now()->subHour()]);
        $tokenless = $this->restaurant(PosType::EscPos, null, ['name' => 'Tokensiz']);
        $manual = $this->restaurant(PosType::Manual, null, ['name' => 'Qolda']);
        $staff = Staff::factory()->owner($withPrinter)->create();
        $staff->restaurants()->syncWithoutDetaching([$tokenless->id, $manual->id]);

        $real = $this->staleOrder($withPrinter, ['dispatch_failed_at' => now()]);
        // Eski/qolgan belgi (restoran keyin printerdan voz kechgan yoki qo'lda belgilangan) ko'rsatilmasligi kerak.
        $stale1 = $this->staleOrder($tokenless, ['dispatch_failed_at' => now()]);
        $stale2 = $this->staleOrder($manual, ['dispatch_failed_at' => now()]);

        $data = $this->actingAs($staff, 'staff')->getJson('/kitchen/print-status')->assertOk()->json('data');

        $byName = collect($data['restaurants'])->keyBy('name');
        $this->assertTrue($byName['Printerli']['uses_printer']);
        $this->assertFalse($byName['Printerli']['agent_online'], 'printerlining agenti jim');
        $this->assertFalse($byName['Tokensiz']['uses_printer']);
        $this->assertFalse($byName['Qolda']['uses_printer']);
        $this->assertSame([$real->id], $data['failed_order_ids']);
        $this->assertNotContains($stale1->id, $data['failed_order_ids']);
        $this->assertNotContains($stale2->id, $data['failed_order_ids']);
    }

    public function test_kitchen_orders_flag_dispatch_failed_only_for_printer_restaurants(): void
    {
        $withPrinter = $this->printer();
        $manual = $this->restaurant(PosType::Manual, null);
        $staff = Staff::factory()->owner($withPrinter)->create();
        $staff->restaurants()->syncWithoutDetaching([$manual->id]);

        $real = $this->staleOrder($withPrinter, ['dispatch_failed_at' => now()]);
        $stale = $this->staleOrder($manual, ['dispatch_failed_at' => now()]);

        $rows = collect($this->actingAs($staff, 'staff')->getJson('/kitchen/orders')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$real->id]['dispatch_failed']);
        $this->assertFalse($rows[$stale->id]['dispatch_failed'], 'printeri yo‘q restoranda «Chek chiqmadi» yo‘q');
    }

    public function test_order_print_failed_is_false_without_a_configured_agent_or_once_printed(): void
    {
        $printer = $this->printer();
        $manual = $this->restaurant(PosType::Manual, null);

        $this->assertTrue($this->staleOrder($printer, ['dispatch_failed_at' => now()])->printFailed());
        $this->assertFalse($this->staleOrder($printer, ['dispatch_failed_at' => now(), 'printed_at' => now()])->printFailed());
        $this->assertFalse($this->staleOrder($printer)->printFailed(), 'belgilanmagan');
        $this->assertFalse($this->staleOrder($manual, ['dispatch_failed_at' => now()])->printFailed());
    }

    public function test_dispatch_job_failure_does_not_flag_printerless_restaurants(): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $tokenless = $this->restaurant(PosType::EscPos, null);
        $order = $this->staleOrder($tokenless);

        (new DispatchOrderJob($order->id))->failed(new \RuntimeException('Reverb yo‘q'));

        $this->assertNull($order->fresh()->dispatch_failed_at);
        Event::assertNotDispatched(OrderDispatchFailed::class);

        // Sozlangan printerli restoranda esa belgi qo'yiladi.
        $real = $this->staleOrder($this->printer());
        (new DispatchOrderJob($real->id))->failed(new \RuntimeException('Reverb yo‘q'));
        $this->assertNotNull($real->fresh()->dispatch_failed_at);
        Event::assertDispatchedTimes(OrderDispatchFailed::class, 1);
    }

    // --- Admin ----------------------------------------------------------------------------------

    private function asAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
    }

    public function test_admin_widget_lists_only_configured_restaurants_and_hides_when_there_are_none(): void
    {
        $this->asAdmin();
        $this->restaurant(PosType::EscPos, null, ['name' => 'Tokensiz']);
        $this->restaurant(PosType::Manual, 'old-token', ['name' => 'Qolda']);

        $this->assertFalse(PrintAgentStatus::canView(), 'faqat printersizlar bor — jadval ko‘rinmaydi');

        $configured = $this->printer(['name' => 'Printerli']);
        $this->assertTrue(PrintAgentStatus::canView());

        Livewire::test(PrintAgentStatus::class)
            ->assertCanSeeTableRecords([$configured])
            ->assertCountTableRecords(1);
    }

    public function test_admin_restaurant_list_shows_no_agent_status_for_printerless_restaurants(): void
    {
        $this->asAdmin();
        $tokenless = $this->restaurant(PosType::EscPos, null, ['name' => 'Tokensiz']);
        $configured = $this->printer(['name' => 'Printerli']);

        Livewire::test(ListRestaurants::class)
            ->assertTableColumnStateSet('print_agent_last_seen_at', null, $tokenless->id)
            ->assertTableColumnStateSet('print_agent_last_seen_at', 'never', $configured->id);
    }

    public function test_admin_orders_failed_filter_excludes_printerless_restaurants(): void
    {
        $this->asAdmin();
        $real = $this->staleOrder($this->printer(), ['dispatch_failed_at' => now()]);
        $stale = $this->staleOrder($this->restaurant(PosType::Manual, null), ['dispatch_failed_at' => now()]);

        Livewire::test(AdminListOrders::class)
            ->filterTable('print_failed', true)
            ->assertCanSeeTableRecords([$real])
            ->assertCanNotSeeTableRecords([$stale]);
    }

    // --- Sinov cheki ------------------------------------------------------------------------------

    public function test_test_receipt_requires_a_configured_agent(): void
    {
        Event::fake([PrintJobRequested::class]);
        $tokenless = $this->restaurant(PosType::EscPos, null);

        $this->artisan('print-agent:test-receipt', ['restaurant' => (string) $tokenless->id])
            ->expectsOutputToContain("token: yo'q")
            ->assertFailed();

        Event::assertNotDispatched(PrintJobRequested::class);
    }
}
