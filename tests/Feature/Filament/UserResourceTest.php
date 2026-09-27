<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Filament\Admin\Widgets\UsersOverviewStats;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Policies\UserPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * /admin — "Mijozlar" (UserResource): FAQAT platform_admin ko'radi, FAQAT ko'rish
 * (yaratish/tahrirlash/o'chirish yo'q). Bu — mijozning operatsion ma'lumoti
 * (telefon, til, ro'yxatdan o'tgan sana), profil bot orqali o'zgaradi.
 */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // --- Ro'yxat: ustunlar, qidiruv, filtrlar ---------------------------

    public function test_platform_admin_sees_the_customer_list(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $customer = User::factory()->create(['full_name' => 'Ali Valiyev', 'phone' => '+998901112233']);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$customer])
            ->assertSee('Ali Valiyev')
            ->assertSee('+998901112233');
    }

    public function test_order_count_column_reflects_the_customers_orders(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $customer = User::factory()->create();
        Order::factory()->count(3)->for($customer)->create();
        $other = User::factory()->create();

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('orders_count', 3, $customer)
            ->assertTableColumnStateSet('orders_count', 0, $other);
    }

    public function test_last_delivered_at_and_total_spent_columns_count_only_delivered_orders(): void
    {
        Carbon::setTestNow('2026-09-21 12:00:00');
        $admin = Staff::factory()->platformAdmin()->create();
        $customer = User::factory()->create();
        Order::factory()->for($customer)->delivered('2026-09-18 10:00:00')->create(['total' => 30_000_00]);
        Order::factory()->for($customer)->delivered('2026-09-20 10:00:00')->create(['total' => 50_000_00]);
        Order::factory()->for($customer)->cancelled('2026-09-21 09:00:00')->create(['total' => 999_000_00]);

        $neverOrdered = User::factory()->create();

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('last_delivered_at', '2026-09-20 10:00:00', $customer)
            ->assertTableColumnStateSet('delivered_total_tiyin', 80_000_00, $customer)
            ->assertTableColumnStateSet('last_delivered_at', null, $neverOrdered)
            ->assertTableColumnFormattedStateSet('delivered_total_tiyin', '0 so\'m', $neverOrdered);

        Carbon::setTestNow();
    }

    public function test_orders_count_column_is_sortable(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $few = User::factory()->create();
        Order::factory()->count(1)->for($few)->create();
        $many = User::factory()->create();
        Order::factory()->count(5)->for($many)->create();

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->sortTable('orders_count')
            ->assertCanSeeTableRecords([$few, $many], inOrder: true)
            ->sortTable('orders_count', 'desc')
            ->assertCanSeeTableRecords([$many, $few], inOrder: true);
    }

    public function test_last_delivered_at_column_is_sortable(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $olderBuyer = $this->customerWithDeliveredOrder(now()->subDays(10));
        $recentBuyer = $this->customerWithDeliveredOrder(now()->subDay());

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->sortTable('last_delivered_at')
            ->assertCanSeeTableRecords([$olderBuyer, $recentBuyer], inOrder: true);
    }

    // --- Faollik filtri ---

    private function customerWithDeliveredOrder(Carbon|string $deliveredAt): User
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->delivered($deliveredAt)->create();

        return $user;
    }

    public function test_activity_filter_active_30_days(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $active = $this->customerWithDeliveredOrder(now()->subDays(10));
        $inactive = $this->customerWithDeliveredOrder(now()->subDays(60));

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->filterTable('activity', 'active_30')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    }

    public function test_activity_filter_inactive(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $wentQuiet = $this->customerWithDeliveredOrder(now()->subDays(45));
        $stillActive = $this->customerWithDeliveredOrder(now()->subDays(5));
        $neverOrdered = User::factory()->create();

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->filterTable('activity', 'inactive')
            ->assertCanSeeTableRecords([$wentQuiet])
            ->assertCanNotSeeTableRecords([$stillActive, $neverOrdered]);
    }

    public function test_search_by_name_or_phone(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $match = User::factory()->create(['full_name' => 'Bexruz Aliyev', 'phone' => '+998907776655']);
        $other = User::factory()->create(['full_name' => 'Shahnoza', 'phone' => '+998901112233']);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->searchTable('Bexruz')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListUsers::class)
            ->searchTable('998907776655')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_status_column_shows_completed_vs_start_only(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $completed = User::factory()->create();
        $startOnly = User::factory()->incomplete()->create();

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('profile_completed', true, $completed)
            ->assertTableColumnStateSet('profile_completed', false, $startOnly);
    }

    public function test_profile_completed_filter(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $completed = User::factory()->create();
        $startOnly = User::factory()->incomplete()->create();

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->filterTable('profile_completed', '1')
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$startOnly]);

        Livewire::test(ListUsers::class)
            ->filterTable('profile_completed', '0')
            ->assertCanSeeTableRecords([$startOnly])
            ->assertCanNotSeeTableRecords([$completed]);
    }

    public function test_language_filter(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $uz = User::factory()->create(['language' => 'uz']);
        $ru = User::factory()->create(['language' => 'ru']);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->filterTable('language', 'ru')
            ->assertCanSeeTableRecords([$ru])
            ->assertCanNotSeeTableRecords([$uz]);
    }

    public function test_registered_between_filter(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $old = User::factory()->create(['created_at' => now()->subDays(20)]);
        $recent = User::factory()->create(['created_at' => now()->subDay()]);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListUsers::class)
            ->filterTable('registered_between', ['from' => now()->subDays(5)->toDateString(), 'until' => null])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }

    // --- Faqat ko'rish: yaratish/tahrirlash/o'chirish yo'q --------------

    public function test_resource_has_no_create_edit_or_delete_pages(): void
    {
        $pages = array_keys(UserResource::getPages());

        $this->assertSame(['index', 'view'], $pages);
    }

    public function test_user_policy_never_allows_create_update_delete(): void
    {
        $policy = app(UserPolicy::class);
        $admin = Staff::factory()->platformAdmin()->make();
        $customer = User::factory()->make();

        $this->assertFalse($policy->create($admin));
        $this->assertFalse($policy->update($admin, $customer));
        $this->assertFalse($policy->delete($admin, $customer));
    }

    // --- Avtorizatsiya: faqat platform_admin ----------------------------

    public function test_user_policy_view_any_is_platform_admin_only(): void
    {
        $policy = app(UserPolicy::class);

        $admin = Staff::factory()->platformAdmin()->make();
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->make();
        $kitchen = Staff::factory()->kitchenStaff(Restaurant::factory()->create())->make();

        $this->assertTrue($policy->viewAny($admin));
        $this->assertFalse($policy->viewAny($owner));
        $this->assertFalse($policy->viewAny($kitchen));
    }

    public function test_platform_admin_can_open_the_users_page_over_http(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'admin')->get('/admin/users')->assertOk();
    }

    public function test_restaurant_owner_cannot_open_the_admin_users_page(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        // owner faqat 'staff' guard bilan kiradi (o'z sessiyasi /restaurant uchun) —
        // /admin ga esa mehmon sifatida qaraladi, login sahifasiga yo'naltiriladi.
        $this->actingAs($owner, 'staff')->get('/admin/users')->assertRedirect('/admin/login');
    }

    public function test_kitchen_staff_cannot_open_the_admin_users_page(): void
    {
        $kitchen = Staff::factory()->kitchenStaff(Restaurant::factory()->create())->create();

        $this->actingAs($kitchen, 'staff')->get('/admin/users')->assertRedirect('/admin/login');
    }

    // --- Dashboard: qisqa statistika widget ------------------------------

    public function test_users_overview_widget_renders_for_platform_admin(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        Livewire::actingAs($admin, 'admin');

        Livewire::test(UsersOverviewStats::class)->assertOk();
    }

    public function test_users_overview_widget_counts_are_correct(): void
    {
        Carbon::setTestNow('2026-09-11 10:00:00');

        User::factory()->count(4)->create(['created_at' => now()]);                // bugun
        User::factory()->count(2)->create(['created_at' => now()->subDays(3)]);     // shu oy, bugun emas
        User::factory()->count(3)->create(['created_at' => now()->subMonths(2)]);   // o'tgan oylarda

        $method = new ReflectionMethod(UsersOverviewStats::class, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke(new UsersOverviewStats);

        $values = array_map(fn ($stat) => $stat->getValue(), $stats);

        $this->assertSame('9', $values[0]); // jami: 4 + 2 + 3
        $this->assertSame('4', $values[1]); // bugun
        $this->assertSame('6', $values[2]); // shu oy: 4 (bugun) + 2

        Carbon::setTestNow();
    }

    public function test_users_overview_widget_shows_start_vs_completed_split(): void
    {
        User::factory()->count(3)->create();               // to'liq
        User::factory()->count(2)->incomplete()->create();  // faqat /start

        $method = new ReflectionMethod(UsersOverviewStats::class, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke(new UsersOverviewStats);

        $values = array_map(fn ($stat) => $stat->getValue(), $stats);

        $this->assertSame('5', $values[3]); // jami /start bosganlar: 3 + 2
        $this->assertSame('3', $values[4]); // to'liq ro'yxatdan o'tganlar
    }

    // --- Dashboard: faol / qaytgan mijozlar ------------------------------

    public function test_active_customers_stat_counts_only_delivered_within_30_days(): void
    {
        Carbon::setTestNow('2026-09-21 12:00:00');

        $active = User::factory()->create();
        Order::factory()->for($active)->delivered(now()->subDays(10))->create();

        $inactive = User::factory()->create();
        Order::factory()->for($inactive)->delivered(now()->subDays(40))->create();

        $cancelledOnly = User::factory()->create();
        Order::factory()->for($cancelledOnly)->cancelled(now()->subDay())->create();

        $method = new ReflectionMethod(UsersOverviewStats::class, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke(new UsersOverviewStats);

        $this->assertSame('1', $stats[5]->getValue()); // Faol mijozlar (30 kun)

        Carbon::setTestNow();
    }

    public function test_active_customers_stat_shows_change_versus_previous_30_days(): void
    {
        Carbon::setTestNow('2026-09-21 12:00:00');

        // Joriy 30 kun: 2 faol mijoz.
        User::factory()->count(2)->create()->each(
            fn (User $u) => Order::factory()->for($u)->delivered(now()->subDays(5))->create(),
        );
        // Oldingi 30 kun (31-60 kun oldin): 1 faol mijoz edi.
        $u = User::factory()->create();
        Order::factory()->for($u)->delivered(now()->subDays(45))->create();

        $method = new ReflectionMethod(UsersOverviewStats::class, 'getStats');
        $method->setAccessible(true);
        $stat = $method->invoke(new UsersOverviewStats)[5];

        $this->assertSame('2', $stat->getValue());
        $this->assertSame('+1 oldingi 30 kunga nisbatan', $stat->getDescription());

        Carbon::setTestNow();
    }

    public function test_returning_customers_stat_requires_two_or_more_delivered_orders(): void
    {
        $returning = User::factory()->create();
        Order::factory()->for($returning)->delivered(now()->subDays(10))->create();
        Order::factory()->for($returning)->delivered(now()->subDays(2))->create();

        $oneTime = User::factory()->create();
        Order::factory()->for($oneTime)->delivered(now()->subDays(2))->create();

        $method = new ReflectionMethod(UsersOverviewStats::class, 'getStats');
        $method->setAccessible(true);
        $stats = $method->invoke(new UsersOverviewStats);

        $this->assertSame('1', $stats[6]->getValue()); // Qaytgan mijozlar
    }
}
