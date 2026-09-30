<?php

namespace Tests\Feature\Filament;

use App\Enums\StaffRole;
use App\Filament\Admin\Resources\StaffResource\Pages\CreateStaff;
use App\Filament\Admin\Resources\StaffResource\Pages\EditStaff;
use App\Filament\Admin\Resources\StaffResource\Pages\ListStaff;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** /admin xodim formasi: restoranlarni ko'p tanlash (restaurant_staff pivot). */
class StaffRestaurantsFormTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $sushi;

    private Restaurant $vanilla;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->sushi = Restaurant::factory()->create(['name' => 'Sushi Xan']);
        $this->vanilla = Restaurant::factory()->create(['name' => 'Vanilla']);
    }

    private function admin(): Staff
    {
        return Staff::factory()->platformAdmin()->create();
    }

    public function test_kitchen_staff_can_be_assigned_to_several_restaurants(): void
    {
        Livewire::actingAs($this->admin(), 'admin');

        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Umumiy oshpaz',
                'email' => 'umumiy@example.com',
                'role' => StaffRole::KitchenStaff->value,
                'restaurant_ids' => [$this->vanilla->id, $this->sushi->id],
                'password' => 'secret-pass',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = Staff::where('email', 'umumiy@example.com')->firstOrFail();
        $this->assertSame($this->vanilla->id, $staff->restaurant_id); // birinchi tanlangani — asosiy
        $this->assertEqualsCanonicalizing([$this->sushi->id, $this->vanilla->id], $staff->restaurantIds());
    }

    public function test_at_least_one_restaurant_is_required_for_kitchen_roles(): void
    {
        Livewire::actingAs($this->admin(), 'admin');

        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'X',
                'email' => 'x@example.com',
                'role' => StaffRole::KitchenStaff->value,
                'restaurant_ids' => [],
                'password' => 'secret-pass',
            ])
            ->call('create')
            ->assertHasFormErrors(['restaurant_ids' => 'required']);

        $this->assertDatabaseMissing('staff', ['email' => 'x@example.com']);
    }

    public function test_platform_admin_is_created_without_restaurants(): void
    {
        Livewire::actingAs($this->admin(), 'admin');

        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Admin 2',
                'email' => 'admin2@example.com',
                'role' => StaffRole::PlatformAdmin->value,
                'password' => 'secret-pass',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $admin = Staff::where('email', 'admin2@example.com')->firstOrFail();
        $this->assertNull($admin->restaurant_id);
        $this->assertSame([], $admin->restaurantIds());
    }

    public function test_edit_shows_primary_first_and_adding_a_restaurant_keeps_the_primary(): void
    {
        Livewire::actingAs($this->admin(), 'admin');
        $owner = Staff::factory()->owner($this->vanilla)->create();

        Livewire::test(EditStaff::class, ['record' => $owner->getRouteKey()])
            ->assertFormSet(['restaurant_ids' => [$this->vanilla->id]])
            ->fillForm(['restaurant_ids' => [$this->sushi->id, $this->vanilla->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $owner->refresh();
        $this->assertSame($this->vanilla->id, $owner->restaurant_id); // /restaurant paneli o'zgarmaydi
        $this->assertEqualsCanonicalizing([$this->sushi->id, $this->vanilla->id], $owner->restaurantIds());
    }

    public function test_removing_the_primary_makes_the_first_remaining_one_primary(): void
    {
        Livewire::actingAs($this->admin(), 'admin');
        $cook = Staff::factory()->kitchenStaff($this->sushi)->alsoAssignedTo($this->vanilla)->create();

        Livewire::test(EditStaff::class, ['record' => $cook->getRouteKey()])
            ->assertFormSet(['restaurant_ids' => [$this->sushi->id, $this->vanilla->id]])
            ->fillForm(['restaurant_ids' => [$this->vanilla->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $cook->refresh();
        $this->assertSame($this->vanilla->id, $cook->restaurant_id);
        $this->assertSame([$this->vanilla->id], $cook->restaurantIds());
    }

    public function test_list_shows_all_assigned_restaurants(): void
    {
        Livewire::actingAs($this->admin(), 'admin');
        $cook = Staff::factory()->kitchenStaff($this->sushi)->alsoAssignedTo($this->vanilla)->create();

        // Filtr variantlarida ham nomlar bor — shuning uchun assertSee emas, qator ustuni holati.
        Livewire::test(ListStaff::class)
            ->assertCanSeeTableRecords([$cook])
            ->assertTableColumnStateSet('restaurants.name', ['Sushi Xan', 'Vanilla'], $cook);
    }
}
