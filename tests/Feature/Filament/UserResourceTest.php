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
}
