<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Jobs\NotifyCustomerOfCancellation;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * OrderStatusService poyga himoyasi (deterministik): chaqiruvchi eskirgan nusxa bilan kelsa ham, o'tish tranzaksiya ichida
 * qatorni qulflab BAZADAGI joriy holatni qayta tekshiradi. Haqiqiy parallel variant — OrderStatusRaceTest.
 */
class OrderStatusLockTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Event::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->product = Product::factory()->for(Category::factory()->for($this->restaurant)->create())->create(['stock_quantity' => 6]);
    }

    private function order(OrderStatus $status = OrderStatus::New): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create([
            'status' => $status,
            'delivery_type' => DeliveryType::Delivery,
            'stock_reserved' => [$this->product->id => 4],
        ]);
    }

    /** Eskirgan nusxalar: ikki "planshet" buyurtmani hali bir xil holatda ko'rib turibdi. */
    private function copies(Order $order): array
    {
        return [Order::withoutGlobalScopes()->findOrFail($order->id), Order::withoutGlobalScopes()->findOrFail($order->id)];
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity');
    }

    private function history(Order $order): array
    {
        return DB::table('order_status_history')->where('order_id', $order->id)->orderBy('id')->pluck('status')->all();
    }

    public function test_a_stale_cancel_cannot_cancel_an_order_the_kitchen_already_accepted(): void
    {
        [$admin, $kitchen] = $this->copies($order = $this->order());
        $service = app(OrderStatusService::class);

        $service->advance($kitchen, 'staff:1');   // oshxona qabul qildi

        try {
            $service->cancel($admin, 'Sabab', 'admin:9');   // admin hali "new" ni ko'rib turgan edi
            $this->fail('eskirgan bekor qilish o\'tib ketdi');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("allaqachon o'zgargan (hozir: Tasdiqlandi)", $e->getMessage());
        }

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertNull($order->cancelled_at);
        $this->assertSame(['accepted'], $this->history($order));
        $this->assertSame(6, $this->stock(), 'qoldiq band bo\'lib qoladi — buyurtma qabul qilingan');
        Queue::assertNotPushed(NotifyCustomerOfCancellation::class);
    }

    public function test_a_stale_accept_cannot_resurrect_an_order_the_admin_already_cancelled(): void
    {
        [$admin, $kitchen] = $this->copies($order = $this->order());
        $service = app(OrderStatusService::class);

        $service->cancel($admin, 'Taom tugab qoldi', 'admin:9');

        try {
            $service->advance($kitchen, 'staff:1');   // oshxona planshetida karta hali "new"
            $this->fail('bekor qilingan buyurtma qabul qilindi');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("allaqachon o'zgargan (hozir: Bekor qilindi)", $e->getMessage());
        }

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(['cancelled'], $this->history($order), 'accepted tarixga yozilmagan');
        $this->assertSame(10, $this->stock(), 'qoldiq bir marta qaytgan');
    }

    public function test_two_stale_accepts_advance_the_order_only_one_step(): void
    {
        [$a, $b] = $this->copies($order = $this->order());
        $service = app(OrderStatusService::class);

        $service->advance($a, 'staff:1');

        $this->expectException(ValidationException::class);
        try {
            $service->advance($b, 'staff:2');   // eskirgan nusxa new -> accepted ni ikkinchi marta yozmoqchi
        } finally {
            $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
            $this->assertSame(['accepted'], $this->history($order));
        }
    }

    public function test_the_conflict_message_follows_the_locale(): void
    {
        [$a, $b] = $this->copies($order = $this->order());
        app(OrderStatusService::class)->advance($a, 'staff:1');

        app()->setLocale('ru');
        try {
            app(OrderStatusService::class)->cancel($b, 'Причина', 'admin:9');
            $this->fail('o\'tib ketdi');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Статус заказа уже изменён (сейчас: Подтверждён)', $e->getMessage());
        }
    }

    public function test_a_normal_chain_of_transitions_is_not_affected(): void
    {
        $order = $this->order();
        $service = app(OrderStatusService::class);

        $service->advance($order, 'staff:1');   // new -> accepted
        $service->advance($order, 'staff:1');   // -> preparing
        $service->cancel($order, 'Band', 'admin:9');

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(['accepted', 'preparing', 'cancelled'], $this->history($order));
        $this->assertSame(10, $this->stock());
    }

    public function test_a_vanished_order_gives_a_clear_error_instead_of_a_crash(): void
    {
        [$a] = $this->copies($order = $this->order());
        DB::table('orders')->where('id', $order->id)->delete();

        $this->expectException(ValidationException::class);
        app(OrderStatusService::class)->cancel($a, 'Sabab', 'admin:9');
    }
}
