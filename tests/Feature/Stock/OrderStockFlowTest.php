<?php

namespace Tests\Feature\Stock;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InsufficientStockException;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ordering\OrderStatusService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/** Qoldiq buyurtma oqimida: yaratishda atomik kamayish, bekor qilishda qaytish (barcha yo'llar), idempotentlik. */
class OrderStockFlowTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Restaurant $restaurant;

    private Category $category;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Queue::fake();
        Event::fake([OrderStatusChanged::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 930001, 'phone' => '+998901112233', 'language' => 'uz']);
        Address::factory()->for($this->user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Qoldiq Resto', 'lat' => 40.7833, 'lng' => 72.3506, 'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 10, 'delivery_fee' => 1_000_000, 'min_order_amount' => 0,
        ]);
        $this->category = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(?int $stock, string $name = 'Osh', int $price = 3_200_000): Product
    {
        return Product::factory()->for($this->category)->create([
            'name' => $name, 'price' => $price, 'is_available' => true, 'stock_quantity' => $stock,
        ]);
    }

    private function stock(Product $p): ?int
    {
        return DB::table('products')->where('id', $p->id)->value('stock_quantity');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));
    }

    /** @param  array<int, array{product_id: int, qty: int}>  $items */
    private function orderRequest(array $items, array $extra = []): TestResponse
    {
        return $this->postJson('/api/orders', array_replace([
            'restaurant_id' => $this->restaurant->id,
            'delivery_type' => 'pickup',
            'items' => $items,
        ], $extra), $this->headers());
    }

    private function place(array $items): Order
    {
        $id = $this->orderRequest($items)->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function accepted(Order $order): Order
    {
        $order->update(['status' => OrderStatus::Accepted]);

        return $order->fresh();
    }

    // --- Yaratish -----------------------------------------------------------------------------------

    public function test_placing_an_order_decrements_limited_stock_and_records_what_was_reserved(): void
    {
        $osh = $this->product(10);
        $non = $this->product(5, 'Non', 400_000);

        $order = $this->place([['product_id' => $osh->id, 'qty' => 3], ['product_id' => $non->id, 'qty' => 2]]);

        $this->assertSame(7, $this->stock($osh));
        $this->assertSame(3, $this->stock($non));
        $this->assertEqualsCanonicalizing([$osh->id => 3, $non->id => 2], $order->stock_reserved);
    }

    public function test_unlimited_products_are_untouched_and_nothing_is_reserved_for_them(): void
    {
        $cheksiz = $this->product(null, 'Cheksiz');

        $order = $this->place([['product_id' => $cheksiz->id, 'qty' => 40]]);

        $this->assertNull($this->stock($cheksiz));
        $this->assertSame([], $order->stock_reserved);
    }

    public function test_insufficient_stock_is_refused_with_a_clear_message_and_nothing_is_created(): void
    {
        $osh = $this->product(2);

        $this->orderRequest([['product_id' => $osh->id, 'qty' => 3]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kechirasiz, Osh dan faqat 2 ta qoldi.')
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('shortages.0.product_id', $osh->id)
            ->assertJsonPath('shortages.0.available', 2);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(2, $this->stock($osh));
        Queue::assertNothingPushed();
    }

    public function test_the_refusal_message_follows_the_customers_language(): void
    {
        $osh = $this->product(2);
        $this->user->update(['language' => 'ru']);

        $this->orderRequest([['product_id' => $osh->id, 'qty' => 3]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Извините, Osh осталось только 2 шт.');
    }

    public function test_a_sold_out_product_cannot_be_ordered(): void
    {
        $osh = $this->product(0);

        $this->orderRequest([['product_id' => $osh->id, 'qty' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kechirasiz, Osh tugagan.');
    }

    public function test_a_short_line_rolls_back_the_other_lines_too(): void
    {
        $osh = $this->product(5);
        $non = $this->product(1, 'Non');

        $this->orderRequest([['product_id' => $osh->id, 'qty' => 2], ['product_id' => $non->id, 'qty' => 2]])->assertStatus(422);

        $this->assertSame(5, $this->stock($osh), 'birinchi qatorning kamayishi qaytdi');
        $this->assertSame(1, $this->stock($non));
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_the_same_product_repeated_in_the_cart_is_summed_before_checking(): void
    {
        $osh = $this->product(3);

        $this->orderRequest([['product_id' => $osh->id, 'qty' => 2], ['product_id' => $osh->id, 'qty' => 2]])
            ->assertStatus(422)
            ->assertJsonPath('shortages.0.available', 3);

        $this->assertSame(3, $this->stock($osh));
    }

    public function test_an_invalid_promo_code_after_the_reserve_rolls_the_stock_back(): void
    {
        $osh = $this->product(5);

        $this->orderRequest([['product_id' => $osh->id, 'qty' => 2]], ['promo_code' => 'YOQ-KOD'])->assertStatus(422);

        $this->assertSame(5, $this->stock($osh), 'butun tranzaksiya rollback');
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_the_last_orders_in_sequence_the_second_one_is_refused(): void
    {
        $osh = $this->product(1);

        $this->place([['product_id' => $osh->id, 'qty' => 1]]);
        $this->orderRequest([['product_id' => $osh->id, 'qty' => 1]])->assertStatus(422)->assertJsonPath('message', 'Kechirasiz, Osh tugagan.');

        $this->assertSame(0, $this->stock($osh));
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    public function test_restock_makes_the_product_orderable_again(): void
    {
        $osh = $this->product(0);
        $this->orderRequest([['product_id' => $osh->id, 'qty' => 1]])->assertStatus(422);

        $osh->update(['stock_quantity' => 4]);   // restoran sonni oshirdi

        $this->place([['product_id' => $osh->id, 'qty' => 2]]);
        $this->assertSame(2, $this->stock($osh));
    }

    public function test_a_product_hidden_by_the_restaurant_is_still_refused_regardless_of_stock(): void
    {
        $osh = $this->product(10);
        $osh->update(['is_available' => false]);

        $this->orderRequest([['product_id' => $osh->id, 'qty' => 1]])->assertStatus(422);
        $this->assertSame(10, $this->stock($osh));
    }

    public function test_estimate_warns_about_short_lines_without_failing(): void
    {
        $osh = $this->product(1);
        $non = $this->product(9, 'Non');

        $this->postJson('/api/orders/estimate', [
            'restaurant_id' => $this->restaurant->id,
            'delivery_type' => 'pickup',
            'items' => [['product_id' => $osh->id, 'qty' => 3], ['product_id' => $non->id, 'qty' => 1]],
        ], $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data.stock_shortages')
            ->assertJsonPath('data.stock_shortages.0.product_id', $osh->id)
            ->assertJsonPath('data.stock_shortages.0.available', 1);

        $this->assertSame(1, $this->stock($osh), 'estimate hech narsa band qilmaydi');

        $this->postJson('/api/orders/estimate', ['restaurant_id' => $this->restaurant->id, 'delivery_type' => 'pickup'], $this->headers())
            ->assertOk()->assertJsonPath('data.stock_shortages', []);
    }

    // --- Bekor qilish: barcha yo'llar -------------------------------------------------------------------

    public function test_cancelling_from_the_kitchen_panel_returns_the_stock(): void
    {
        $osh = $this->product(10);
        $order = $this->accepted($this->place([['product_id' => $osh->id, 'qty' => 4]]));
        $this->assertSame(6, $this->stock($osh));

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/cancel", ['reason' => 'Taom tugab qoldi'])
            ->assertOk();

        $this->assertSame(10, $this->stock($osh));
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->stock_released_at);
    }

    public function test_cancelling_through_the_service_the_telegram_bot_path_returns_the_stock(): void
    {
        $osh = $this->product(10);
        $order = $this->accepted($this->place([['product_id' => $osh->id, 'qty' => 4]]));

        // Telegram oshxona boti ham shu servisni chaqiradi (KitchenCancelReasonHandler / Conversation).
        app(OrderStatusService::class)->cancel($order, 'Restoran band', 'kitchen:77');

        $this->assertSame(10, $this->stock($osh));
    }

    public function test_cancelling_twice_never_returns_the_stock_twice(): void
    {
        $osh = $this->product(10);
        $order = $this->accepted($this->place([['product_id' => $osh->id, 'qty' => 4]]));

        $this->actingAs($this->owner, 'staff')->patchJson("/kitchen/orders/{$order->id}/cancel", ['reason' => 'Sabab'])->assertOk();
        $this->actingAs($this->owner, 'staff')->patchJson("/kitchen/orders/{$order->id}/cancel", ['reason' => 'Sabab'])->assertStatus(422);

        $this->assertSame(10, $this->stock($osh));
    }

    public function test_two_stale_copies_of_the_same_order_cancelled_back_to_back_return_once(): void
    {
        // Ikki oqim (panel va bot) bir vaqtda: ikkalasi ham buyurtmani hali "accepted" deb ko'radi.
        $osh = $this->product(10);
        $placed = $this->accepted($this->place([['product_id' => $osh->id, 'qty' => 4]]));
        $copyA = Order::withoutGlobalScopes()->findOrFail($placed->id);
        $copyB = Order::withoutGlobalScopes()->findOrFail($placed->id);

        $service = app(OrderStatusService::class);
        $service->cancel($copyA, 'Panel', 'kitchen:1');
        $service->cancel($copyB, 'Bot', 'kitchen:2');   // eskirgan nusxa — status tekshiruvidan o'tadi

        $this->assertSame(10, $this->stock($osh), 'ledger tufayli ikkinchisi hech narsa qo‘shmaydi');
    }

    public function test_cancelling_an_order_for_an_unlimited_product_leaves_it_unlimited(): void
    {
        $cheksiz = $this->product(null, 'Cheksiz');
        $order = $this->accepted($this->place([['product_id' => $cheksiz->id, 'qty' => 2]]));

        app(OrderStatusService::class)->cancel($order, 'Sabab', 'kitchen:1');

        $this->assertNull($this->stock($cheksiz));
    }

    public function test_cancelling_one_order_returns_only_its_own_quantity(): void
    {
        $osh = $this->product(10);
        $first = $this->accepted($this->place([['product_id' => $osh->id, 'qty' => 3]]));
        $this->place([['product_id' => $osh->id, 'qty' => 2]]);
        $this->assertSame(5, $this->stock($osh));

        app(OrderStatusService::class)->cancel($first, 'Sabab', 'kitchen:1');

        $this->assertSame(8, $this->stock($osh), 'faqat o‘zining 3 tasi');
    }

    public function test_a_partial_return_followed_by_a_cancel_returns_exactly_the_reserved_total(): void
    {
        $osh = $this->product(10);
        $order = $this->accepted($this->place([['product_id' => $osh->id, 'qty' => 3]]));

        app(StockService::class)->release($order, [$osh->id => 1]);   // qisman buyurtmada "yo'q" qator (bitta dona)
        $this->assertSame(8, $this->stock($osh));

        app(OrderStatusService::class)->cancel($order->fresh(), 'Sabab', 'kitchen:1');

        $this->assertSame(10, $this->stock($osh), '1 + 2 = 3 — ortiqcha emas');
    }

    public function test_non_cancel_transitions_and_delivery_never_touch_the_stock(): void
    {
        $osh = $this->product(10);
        $order = $this->place([['product_id' => $osh->id, 'qty' => 4]]);
        $service = app(OrderStatusService::class);

        foreach ([OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Delivered] as $step) {
            $service->advance($order->fresh(), 'kitchen:1');
            $this->assertSame(6, $this->stock($osh), "{$step->value} dan keyin ham qoldiq o‘zgarmaydi");
        }

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_released_at);
    }

    public function test_a_rejected_cancel_of_a_new_order_leaves_the_stock_reserved(): void
    {
        // Hozirgi qoida (OrderStatusService::CANCELLABLE): qabul qilinmagan (new) buyurtmani bekor qilib bo'lmaydi.
        $osh = $this->product(10);
        $order = $this->place([['product_id' => $osh->id, 'qty' => 4]]);

        $this->actingAs($this->owner, 'staff')->patchJson("/kitchen/orders/{$order->id}/cancel", ['reason' => 'Sabab'])->assertStatus(422);

        $this->assertSame(6, $this->stock($osh));
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_release_failing_inside_the_transition_rolls_the_cancellation_back(): void
    {
        $osh = $this->product(10);
        $order = $this->accepted($this->place([['product_id' => $osh->id, 'qty' => 4]]));

        $this->app->bind(StockService::class, fn () => new class extends StockService
        {
            public function release(Order $order, ?array $only = null): array
            {
                throw new \RuntimeException('bazada xato');
            }
        });

        try {
            app(OrderStatusService::class)->cancel($order, 'Sabab', 'kitchen:1');
            $this->fail();
        } catch (\RuntimeException) {
            // kutilgan
        }

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status, 'qoldiq qaytmasa bekor ham bo‘lmaydi (bitta tranzaksiya)');
        $this->assertSame(6, $this->stock($osh));
    }

    public function test_the_exception_class_is_what_the_api_turns_into_a_422(): void
    {
        $this->assertTrue(is_subclass_of(InsufficientStockException::class, \Exception::class));
        $this->assertTrue(method_exists(InsufficientStockException::class, 'render'));
    }

    public function test_a_promo_order_still_works_with_stock(): void
    {
        $osh = $this->product(5);
        PromoCode::factory()->at($this->restaurant)->percent(10)->restaurantShare(50)->create(['code' => 'MINUS10']);

        $order = $this->place([['product_id' => $osh->id, 'qty' => 2]]);   // promo bu testda ishlatilmaydi
        $this->assertSame(3, $this->stock($osh));
        $this->assertSame([$osh->id => 2], $order->stock_reserved);
    }
}
