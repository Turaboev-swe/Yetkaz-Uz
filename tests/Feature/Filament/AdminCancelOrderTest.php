<?php

namespace Tests\Feature\Filament;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Admin\Resources\OrderResource\Pages\ViewOrder;
use App\Jobs\NotifyCustomerOfCancellation;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** /admin → Buyurtmalar → «Bekor qilish»: faqat platform_admin, new | accepted | preparing, sabab majburiy. */
class AdminCancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Product $product;

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Event::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->product = Product::factory()->for(Category::factory()->for($this->restaurant)->create())->create(['stock_quantity' => 6]);
        $this->admin = Staff::factory()->platformAdmin()->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->admin, 'admin');
    }

    private function order(OrderStatus $status): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create([
            'status' => $status,
            'delivery_type' => DeliveryType::Delivery,
            'stock_reserved' => [$this->product->id => 4],
        ]);
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity');
    }

    /** @return array<string, array{OrderStatus}> */
    public static function cancellable(): array
    {
        return ['new' => [OrderStatus::New], 'accepted' => [OrderStatus::Accepted], 'preparing' => [OrderStatus::Preparing]];
    }

    #[DataProvider('cancellable')]
    public function test_admin_cancels_from_the_order_page_with_a_reason(OrderStatus $status): void
    {
        $order = $this->order($status);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('cancelOrder')
            ->callAction('cancelOrder', ['reason' => 'Restoran yopilgan'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Restoran yopilgan', $order->cancellation_reason);
        $this->assertNotNull($order->cancelled_at);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'status' => 'cancelled', 'changed_by' => "admin:{$this->admin->id}",
        ]);
        Queue::assertPushed(NotifyCustomerOfCancellation::class, fn ($j) => $j->orderId === $order->id);
        $this->assertSame(10, $this->stock(), 'band qilingan qoldiq qaytdi');
    }

    public function test_the_reason_is_required_and_nothing_changes_without_it(): void
    {
        $order = $this->order(OrderStatus::Accepted);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('cancelOrder', ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertSame(6, $this->stock());
        Queue::assertNotPushed(NotifyCustomerOfCancellation::class);
    }

    public function test_a_whitespace_only_reason_is_refused_by_the_service(): void
    {
        $order = $this->order(OrderStatus::New);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('cancelOrder', ['reason' => '   ']);

        $this->assertSame(OrderStatus::New, $order->refresh()->status);
        $this->assertSame(6, $this->stock());
    }

    public function test_the_action_is_hidden_once_the_order_can_no_longer_be_cancelled(): void
    {
        foreach ([OrderStatus::OnTheWay, OrderStatus::Delivered, OrderStatus::Cancelled] as $status) {
            Livewire::test(ViewOrder::class, ['record' => $this->order($status)->getRouteKey()])
                ->assertActionHidden('cancelOrder');
        }
    }

    public function test_a_stale_page_gets_a_clear_message_when_the_kitchen_moved_the_order_on(): void
    {
        $order = $this->order(OrderStatus::Preparing);

        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);

        // Sahifa ochiq turganda restoran buyurtmani yo'lga chiqargan (bekor qilib bo'lmaydigan holat).
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::OnTheWay->value]);

        $page->callAction('cancelOrder', ['reason' => 'Kech']);

        $this->assertSame(OrderStatus::OnTheWay, $order->refresh()->status);
        $this->assertSame(6, $this->stock());
        Queue::assertNotPushed(NotifyCustomerOfCancellation::class);
    }

    public function test_the_list_row_action_cancels_too(): void
    {
        $order = $this->order(OrderStatus::New);
        $done = $this->order(OrderStatus::Delivered);

        Livewire::test(ListOrders::class)
            ->assertTableActionVisible('cancelOrder', $order)
            ->assertTableActionHidden('cancelOrder', $done)
            ->callTableAction('cancelOrder', $order, ['reason' => 'Dublikat'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame('Dublikat', $order->cancellation_reason);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'changed_by' => "admin:{$this->admin->id}"]);
    }

    public function test_a_restaurant_owner_never_sees_the_admin_cancel_action(): void
    {
        $owner = Staff::factory()->owner($this->restaurant)->create();
        Livewire::actingAs($owner, 'admin');
        $order = $this->order(OrderStatus::New);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('cancelOrder');

        $this->assertSame(OrderStatus::New, $order->refresh()->status);
    }
}
