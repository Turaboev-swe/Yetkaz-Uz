<?php

namespace Tests\Feature\Filament;

use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_access_admin_panel_only(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('restaurant')));
    }

    public function test_restaurant_owner_can_access_restaurant_panel_only(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        $this->assertTrue($owner->canAccessPanel(Filament::getPanel('restaurant')));
        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_inactive_staff_cannot_access_any_panel(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->inactive()->create();

        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('restaurant')));
        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_admin_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk();
        $this->get('/restaurant/login')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/restaurant')->assertRedirect('/restaurant/login');
    }

    public function test_owner_cannot_open_the_admin_dashboard(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        $this->actingAs($owner, 'staff')->get('/admin')->assertForbidden();
    }

    public function test_telegram_user_model_is_not_a_staff(): void
    {
        // users va staff — alohida jadval/guard. Telegram foydalanuvchi panelga kira olmaydi.
        $this->assertFalse(class_exists(User::class) && is_subclass_of(User::class, FilamentUser::class));
    }
}
