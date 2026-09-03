<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\PosType;
use App\Events\OrderDispatchFailed;
use App\Events\PrintJobRequested;
use App\Jobs\DispatchOrderJob;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Dispatch\OrderDispatcher;
use App\Services\Dispatch\ReceiptFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class OrderDispatchTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function restaurant(PosType $pos = PosType::EscPos, string $token = 'tok-123'): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create([
            'name' => 'Chek Resto', 'pos_type' => $pos, 'print_agent_token' => $token,
        ]);
    }

    private function order(PosType $pos = PosType::EscPos, string $type = 'delivery', ?Restaurant $restaurant = null): Order
    {
        $restaurant ??= $this->restaurant($pos);

        return Order::factory()->for($restaurant)->for(User::factory(['phone' => '+998900000000']))->create([
            'delivery_type' => DeliveryType::from($type),
            'items' => [['product_id' => 1, 'name' => "Lag'mon", 'price' => 2_500_000, 'qty' => 2, 'prep' => 20, 'note' => null]],
            'note' => 'eshik oldiga',
            'total' => 6_000_000,
        ]);
    }

    public function test_escpos_order_broadcasts_a_print_job(): void
    {
        Event::fake([PrintJobRequested::class]);
        $order = $this->order(PosType::EscPos);

        (new DispatchOrderJob($order->id))->handle(app(OrderDispatcher::class));

        Event::assertDispatched(PrintJobRequested::class, function (PrintJobRequested $e) use ($order) {
            $decoded = base64_decode($e->escposBase64);

            return $e->orderId === $order->id
                && $e->restaurantId === $order->restaurant_id
                && $e->broadcastOn()[0]->name === "private-restaurant.{$order->restaurant_id}.print"
                && str_contains($decoded, $order->order_number)
                && str_contains($e->text, 'YETKAZISH')
                && str_contains($e->text, "Lag'mon");
        });
    }

    public function test_manual_order_does_not_print(): void
    {
        Event::fake([PrintJobRequested::class]);
        $order = $this->order(PosType::Manual);

        (new DispatchOrderJob($order->id))->handle(app(OrderDispatcher::class));

        Event::assertNotDispatched(PrintJobRequested::class);
    }

    public function test_already_printed_order_is_skipped(): void
    {
        Event::fake([PrintJobRequested::class]);
        $order = $this->order(PosType::EscPos);
        $order->forceFill(['printed_at' => now()])->save();

        (new DispatchOrderJob($order->id))->handle(app(OrderDispatcher::class));

        Event::assertNotDispatched(PrintJobRequested::class);
    }

    public function test_failure_marks_order_and_warns_kitchen(): void
    {
        Event::fake([OrderDispatchFailed::class]);
        $order = $this->order(PosType::EscPos);

        (new DispatchOrderJob($order->id))->failed(new \RuntimeException('Reverb uzilgan'));

        $this->assertNotNull($order->fresh()->dispatch_failed_at);
        Event::assertDispatched(OrderDispatchFailed::class, fn ($e) => $e->orderId === $order->id);
    }

    public function test_receipt_shows_pickup_clearly(): void
    {
        $order = $this->order(PosType::EscPos, 'pickup');

        $receipt = app(ReceiptFormatter::class)->format($order);

        $this->assertStringContainsString('OLIB KETISH', $receipt['text']);
        $this->assertStringNotContainsString('YETKAZISH', $receipt['text']);
        $this->assertStringContainsString('60 000 som', $receipt['text']);
        $this->assertStringContainsString('IZOH', $receipt['text']);
    }

    public function test_agent_broadcast_auth_requires_matching_token_and_channel(): void
    {
        $order = $this->order(PosType::EscPos);
        $rid = $order->restaurant_id;

        $this->postJson('/api/agent/broadcasting/auth', [
            'socket_id' => '111.222',
            'channel_name' => "private-restaurant.{$rid}.print",
        ], ['Authorization' => 'Bearer tok-123'])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        // boshqa restoran kanali
        $this->postJson('/api/agent/broadcasting/auth', [
            'socket_id' => '111.222',
            'channel_name' => 'private-restaurant.99999.print',
        ], ['Authorization' => 'Bearer tok-123'])->assertForbidden();

        // noto'g'ri token
        $this->postJson('/api/agent/broadcasting/auth', [
            'socket_id' => '111.222',
            'channel_name' => "private-restaurant.{$rid}.print",
        ], ['Authorization' => 'Bearer wrong'])->assertForbidden();

        // tokensiz
        $this->postJson('/api/agent/broadcasting/auth', [
            'socket_id' => '111.222',
            'channel_name' => "private-restaurant.{$rid}.print",
        ])->assertUnauthorized();
    }

    public function test_agent_confirm_sets_printed_at(): void
    {
        $order = $this->order(PosType::EscPos);
        $order->forceFill(['dispatch_failed_at' => now()])->save();

        $this->postJson("/api/agent/orders/{$order->id}/printed", [], ['Authorization' => 'Bearer tok-123'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->printed_at);
        $this->assertNull($fresh->dispatch_failed_at);
    }

    public function test_pending_returns_unprinted_recent_orders_with_receipts(): void
    {
        $r = $this->restaurant(PosType::EscPos, 'tok-abc');
        $order = $this->order(PosType::EscPos, restaurant: $r);
        $printed = $this->order(PosType::EscPos, restaurant: $r);
        $printed->forceFill(['printed_at' => now()])->save();
        $old = $this->order(PosType::EscPos, restaurant: $r);
        $old->forceFill(['created_at' => now()->subHours(5)])->save();

        $rows = $this->getJson('/api/agent/orders/pending', ['Authorization' => 'Bearer tok-abc'])
            ->assertOk()
            ->json('data');

        $this->assertSame([$order->id], array_column($rows, 'order_id'));
        $this->assertStringContainsString($order->order_number, base64_decode($rows[0]['escpos']));
    }

    public function test_agent_confirm_rejects_foreign_order(): void
    {
        $order = $this->order(PosType::EscPos);
        $other = Restaurant::factory()->for(District::factory())->create(['print_agent_token' => 'other-tok']);

        $this->postJson("/api/agent/orders/{$order->id}/printed", [], ['Authorization' => 'Bearer other-tok'])
            ->assertStatus(404);
    }
}
