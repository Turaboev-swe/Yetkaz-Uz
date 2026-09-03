<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /kitchen/login — Filament EMAS. kitchen_staff faqat shu yerdan kiradi
 * (Filament panellariga canAccessPanel() dan o'tolmaydi).
 */
class KitchenAuthTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create();
    }

    public function test_guest_hitting_kitchen_is_sent_to_kitchen_login(): void
    {
        $this->get('/kitchen')->assertRedirect('/kitchen/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/kitchen/login')->assertOk()->assertSee(__('messages.kitchen.title'));
    }

    public function test_kitchen_staff_can_log_in(): void
    {
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->create(['password' => 'secret-pw-123']);

        $this->post('/kitchen/login', ['email' => $staff->email, 'password' => 'secret-pw-123'])
            ->assertRedirect('/kitchen');

        $this->assertAuthenticatedAs($staff, 'staff');
        $this->get('/kitchen')->assertOk();
    }

    public function test_restaurant_owner_can_also_log_in_to_kitchen(): void
    {
        $owner = Staff::factory()->owner($this->restaurant)->create(['password' => 'secret-pw-123']);

        $this->post('/kitchen/login', ['email' => $owner->email, 'password' => 'secret-pw-123'])
            ->assertRedirect('/kitchen');
        $this->assertAuthenticatedAs($owner, 'staff');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->create(['password' => 'secret-pw-123']);

        $this->from('/kitchen/login')
            ->post('/kitchen/login', ['email' => $staff->email, 'password' => 'nope'])
            ->assertRedirect('/kitchen/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest('staff');
    }

    public function test_platform_admin_cannot_use_kitchen_login(): void
    {
        $admin = Staff::factory()->platformAdmin()->create(['password' => 'secret-pw-123']);

        $this->from('/kitchen/login')
            ->post('/kitchen/login', ['email' => $admin->email, 'password' => 'secret-pw-123'])
            ->assertRedirect('/kitchen/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest('staff');
    }

    public function test_inactive_kitchen_staff_cannot_log_in(): void
    {
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->inactive()->create(['password' => 'secret-pw-123']);

        $this->from('/kitchen/login')
            ->post('/kitchen/login', ['email' => $staff->email, 'password' => 'secret-pw-123'])
            ->assertRedirect('/kitchen/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest('staff');
    }

    public function test_logout(): void
    {
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->create();

        $this->actingAs($staff, 'staff')->post('/kitchen/logout')->assertRedirect('/kitchen/login');
        $this->assertGuest('staff');
    }

    public function test_already_authorized_staff_visiting_login_goes_to_kitchen(): void
    {
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->create();

        $this->actingAs($staff, 'staff')->get('/kitchen/login')->assertRedirect('/kitchen');
    }
}
