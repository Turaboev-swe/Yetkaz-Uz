<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PosType;
use App\Events\OrderDispatchFailed;
use App\Filament\Admin\Resources\OrderResource\Pages\ListOrders as AdminListOrders;
use App\Filament\Admin\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Filament\Admin\Widgets\PrintAgentStatus;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Print agent monitoringi: heartbeat, «agent o'chiq», «chek chiqmadi» (2 daqiqa), pending filtri, panel va admin. */
class PrintAgentMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function restaurant(PosType $pos = PosType::EscPos, string $token = 'tok-1', array $extra = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'Chek Resto', 'pos_type' => $pos, 'print_agent_token' => $token,
        ], $extra));
    }

    private function order(Restaurant $r, OrderStatus $status = OrderStatus::New, ?Carbon $createdAt = null, array $extra = []): Order
    {
        $order = Order::factory()->for($r)->for(User::factory())->create(array_replace([
            'status' => $status,
            'printed_at' => null,
            'dispatch_failed_at' => null,
        ], $extra));

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $order->fresh();
    }

    // --- heartbeat --------------------------------------------------------------------------

    public function test_heartbeat_records_last_seen_for_the_token_owner_only(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $mine = $this->restaurant(token: 'tok-mine');
        $other = $this->restaurant(token: 'tok-other', extra: ['name' => 'Boshqa']);

        $this->postJson('/api/agent/heartbeat', [], ['Authorization' => 'Bearer tok-mine'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('2026-11-10 09:00:00', $mine->fresh()->print_agent_last_seen_at->format('Y-m-d H:i:s'));
        $this->assertNull($other->fresh()->print_agent_last_seen_at);
    }

    public function test_heartbeat_requires_a_valid_token(): void
    {
        $this->restaurant();

        $this->postJson('/api/agent/heartbeat')->assertUnauthorized();
        $this->postJson('/api/agent/heartbeat', [], ['Authorization' => 'Bearer wrong'])->assertForbidden();
    }

    public function test_agent_status_never_online_offline_after_two_minutes_of_silence(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $r = $this->restaurant();
        $this->assertSame('never', $r->printAgentStatus());
        $this->assertFalse($r->printAgentOnline());

        $r->forceFill(['print_agent_last_seen_at' => now()->subSeconds(119)])->save();
        $this->assertSame('online', $r->fresh()->printAgentStatus());

        $r->forceFill(['print_agent_last_seen_at' => now()->subSeconds(121)])->save();
        $this->assertSame('offline', $r->fresh()->printAgentStatus());
    }

    // --- pending ------------------------------------------------------------------------------

    public function test_pending_skips_cancelled_and_delivered_orders_but_keeps_the_active_ones(): void
    {
        $r = $this->restaurant();
        $active = collect([OrderStatus::New, OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::OnTheWay])
            ->map(fn (OrderStatus $s) => $this->order($r, $s)->id)->all();
        $this->order($r, OrderStatus::Cancelled);
        $this->order($r, OrderStatus::Delivered);

        $ids = collect($this->getJson('/api/agent/orders/pending', ['Authorization' => 'Bearer tok-1'])->assertOk()->json('data'))
            ->pluck('order_id')->all();

        $this->assertEqualsCanonicalizing($active, $ids);
    }

    public function test_pending_window_comes_from_config(): void
    {
        $r = $this->restaurant();
        $recent = $this->order($r, createdAt: now()->subHours(2));
        $old = $this->order($r, createdAt: now()->subHours(5));

        $ids = fn () => collect($this->getJson('/api/agent/orders/pending', ['Authorization' => 'Bearer tok-1'])->json('data'))->pluck('order_id')->all();

        $this->assertSame([$recent->id], $ids());

        config(['print.pending_window_hours' => 6]);
        $this->assertEqualsCanonicalizing([$recent->id, $old->id], $ids());
    }

    // --- «Chek chiqmadi» (2 daqiqa) ---------------------------------------------------------

    public function test_unprinted_order_is_flagged_after_two_minutes_without_relying_on_reverb(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Event::fake([OrderDispatchFailed::class]);
        $r = $this->restaurant();
        $young = $this->order($r, createdAt: now()->subSeconds(90));
        $late = $this->order($r, createdAt: now()->subSeconds(130));

        $this->artisan('print:check-unprinted')->assertSuccessful();

        $this->assertNull($young->fresh()->dispatch_failed_at, '2 daqiqa o‘tmagan');
        $this->assertNotNull($late->fresh()->dispatch_failed_at);
        Event::assertDispatched(OrderDispatchFailed::class, fn (OrderDispatchFailed $e) => $e->orderId === $late->id
            && $e->restaurantId === $r->id && $e->broadcastOn()[0]->name === "private-kitchen.{$r->id}");
        Event::assertDispatchedTimes(OrderDispatchFailed::class, 1);
    }

    public function test_flagging_is_idempotent_and_skips_printed_cancelled_and_non_printer_restaurants(): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $r = $this->restaurant();
        $manual = $this->restaurant(PosType::Manual, 'tok-m', ['name' => 'Qo‘lda']);
        $old = now()->subMinutes(10);
        $unprinted = $this->order($r, createdAt: $old);
        $printed = $this->order($r, createdAt: $old, extra: ['printed_at' => now()]);
        $cancelled = $this->order($r, OrderStatus::Cancelled, $old);
        $manualOrder = $this->order($manual, createdAt: $old);

        $this->artisan('print:check-unprinted')->assertSuccessful();
        $this->artisan('print:check-unprinted')->assertSuccessful();

        $this->assertNotNull($unprinted->fresh()->dispatch_failed_at);
        $this->assertNull($printed->fresh()->dispatch_failed_at);
        $this->assertNull($cancelled->fresh()->dispatch_failed_at);
        $this->assertNull($manualOrder->fresh()->dispatch_failed_at);
        Event::assertDispatchedTimes(OrderDispatchFailed::class, 1); // qayta ishga tushirilganda takror signal yo'q
    }

    public function test_orders_older_than_the_pending_window_are_flagged_so_they_stay_visible(): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $r = $this->restaurant();
        $stale = $this->order($r, OrderStatus::Preparing, now()->subHours(5)); // agent buni baribir chop etmaydi

        $this->artisan('print:check-unprinted')->assertSuccessful();

        $this->assertNotNull($stale->fresh()->dispatch_failed_at);
        $ids = collect($this->getJson('/api/agent/orders/pending', ['Authorization' => 'Bearer tok-1'])->json('data'))->pluck('order_id');
        $this->assertNotContains($stale->id, $ids->all());
    }

    public function test_a_delivered_unprinted_order_is_flagged_for_history_without_a_kitchen_signal(): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $r = $this->restaurant();
        $delivered = $this->order($r, OrderStatus::Delivered, now()->subMinutes(30));

        $this->artisan('print:check-unprinted')->assertSuccessful();

        $this->assertNotNull($delivered->fresh()->dispatch_failed_at);
        Event::assertNotDispatched(OrderDispatchFailed::class);
    }

    public function test_flag_is_cleared_when_the_agent_prints_later(): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $r = $this->restaurant();
        $order = $this->order($r, createdAt: now()->subMinutes(10));
        $this->artisan('print:check-unprinted');
        $this->assertNotNull($order->fresh()->dispatch_failed_at);

        $this->postJson("/api/agent/orders/{$order->id}/printed", [], ['Authorization' => 'Bearer tok-1'])->assertOk();

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->printed_at);
        $this->assertNull($fresh->dispatch_failed_at);
    }

    public function test_check_runs_every_minute_in_the_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'print:check-unprinted'));

        $this->assertNotNull($event, 'print:check-unprinted rejalashtirilmagan');
        $this->assertSame('* * * * *', $event->expression);
    }

    // --- Oshxona paneli ------------------------------------------------------------------------

    public function test_kitchen_print_status_reports_the_agent_and_failed_active_orders_of_own_restaurants(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $mine = $this->restaurant(extra: ['print_agent_last_seen_at' => now()->subMinutes(5)]);
        $foreign = $this->restaurant(token: 'tok-f', extra: ['name' => 'Begona']);
        $owner = Staff::factory()->owner($mine)->create();

        $failedActive = $this->order($mine, OrderStatus::Preparing, extra: ['dispatch_failed_at' => now()]);
        $this->order($mine, OrderStatus::Delivered, extra: ['dispatch_failed_at' => now()]);   // faol emas
        $this->order($mine, OrderStatus::New, extra: ['dispatch_failed_at' => now(), 'printed_at' => now()]); // chiqqan
        $this->order($foreign, OrderStatus::New, extra: ['dispatch_failed_at' => now()]);      // begona

        $data = $this->actingAs($owner, 'staff')->getJson('/kitchen/print-status')->assertOk()->json('data');

        $this->assertSame([$failedActive->id], $data['failed_order_ids']);
        $this->assertCount(1, $data['restaurants']);
        $this->assertSame(
            [$mine->id, true, 'offline', false],
            [$data['restaurants'][0]['id'], $data['restaurants'][0]['uses_printer'], $data['restaurants'][0]['agent_status'], $data['restaurants'][0]['agent_online']],
        );
    }

    public function test_kitchen_print_status_shows_an_online_agent_and_ignores_non_printer_restaurants(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $r = $this->restaurant(extra: ['print_agent_last_seen_at' => now()->subSeconds(30)]);
        $owner = Staff::factory()->owner($r)->create();

        $data = $this->actingAs($owner, 'staff')->getJson('/kitchen/print-status')->json('data');
        $this->assertSame([true, 'online'], [$data['restaurants'][0]['agent_online'], $data['restaurants'][0]['agent_status']]);

        $r->update(['pos_type' => PosType::Manual]);
        $data = $this->actingAs($owner, 'staff')->getJson('/kitchen/print-status')->json('data');
        $this->assertFalse($data['restaurants'][0]['uses_printer'], 'qo‘lda restoranda agent ogohlantirishi chiqmaydi');
    }

    public function test_kitchen_print_status_requires_a_logged_in_staff(): void
    {
        $this->getJson('/kitchen/print-status')->assertUnauthorized();
    }

    // --- Admin ---------------------------------------------------------------------------------

    private function asAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
    }

    public function test_admin_widget_lists_printer_restaurants_with_agent_state_and_failed_counts(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->asAdmin();
        $silent = $this->restaurant(extra: ['name' => 'Jim Resto', 'print_agent_last_seen_at' => now()->subMinutes(10)]);
        $alive = $this->restaurant(token: 'tok-a', extra: ['name' => 'Tirik Resto', 'print_agent_last_seen_at' => now()->subSeconds(20)]);
        $never = $this->restaurant(token: 'tok-n', extra: ['name' => 'Yangi Resto']);
        $manual = $this->restaurant(PosType::Manual, 'tok-m', ['name' => 'Qo‘lda Resto']);
        $this->order($silent, OrderStatus::New, extra: ['dispatch_failed_at' => now()]);

        Livewire::test(PrintAgentStatus::class)
            ->assertCanSeeTableRecords([$never, $silent, $alive], inOrder: true) // jim/ulanmaganlar birinchi
            ->assertCanNotSeeTableRecords([$manual])
            ->assertTableColumnStateSet('agent_status', 'online', $alive->id)
            ->assertTableColumnStateSet('agent_status', 'offline', $silent->id)
            ->assertTableColumnStateSet('agent_status', 'never', $never->id)
            ->assertTableColumnStateSet('unprinted_active_count', 1, $silent->id)
            ->assertSee('Printer agenti o‘chiq')
            ->assertSee('Hech qachon ulanmagan');
    }

    public function test_admin_widget_is_hidden_when_no_restaurant_uses_a_printer(): void
    {
        $this->restaurant(PosType::Manual);

        $this->assertFalse(PrintAgentStatus::canView());

        $this->restaurant(PosType::EscPos, 'tok-x', ['name' => 'Printerli']);
        $this->assertTrue(PrintAgentStatus::canView());
    }

    public function test_admin_restaurants_list_shows_the_agent_column_for_printer_restaurants(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->asAdmin();
        $silent = $this->restaurant(extra: ['print_agent_last_seen_at' => now()->subMinutes(10)]);
        $manual = $this->restaurant(PosType::Manual, 'tok-m', ['name' => 'Qo‘lda Resto']);

        Livewire::test(ListRestaurants::class)
            ->assertTableColumnStateSet('print_agent_last_seen_at', 'offline', $silent->id)
            ->assertTableColumnStateSet('print_agent_last_seen_at', null, $manual);
    }

    public function test_admin_orders_list_filters_orders_whose_receipt_did_not_print(): void
    {
        $this->asAdmin();
        $r = $this->restaurant();
        $failed = $this->order($r, extra: ['dispatch_failed_at' => now()]);
        $printed = $this->order($r, extra: ['printed_at' => now()]);
        $waiting = $this->order($r);

        Livewire::test(AdminListOrders::class)
            ->filterTable('print_failed', true)
            ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$printed, $waiting])
            ->filterTable('print_failed', false)
            ->assertCanSeeTableRecords([$printed])->assertCanNotSeeTableRecords([$failed, $waiting]);
    }
}
