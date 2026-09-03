<?php

namespace Tests\Feature\Filament;

use App\Enums\StaffRole;
use App\Filament\Admin\Resources\StaffResource\Pages\CreateStaff;
use App\Filament\Admin\Resources\StaffResource\Pages\EditStaff;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffTelegramChatIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
    }

    public function test_create_staff_persists_telegram_chat_id(): void
    {
        $restaurant = Restaurant::factory()->create();

        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Oshpaz Aziz',
                'email' => 'aziz@example.com',
                'telegram_chat_id' => 424299,
                'role' => StaffRole::KitchenStaff->value,
                'restaurant_id' => $restaurant->id,
                'password' => 'secret-pass',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(424299, Staff::where('email', 'aziz@example.com')->value('telegram_chat_id'));
    }

    public function test_telegram_chat_id_is_optional_and_can_be_cleared(): void
    {
        $staff = Staff::factory()
            ->owner(Restaurant::factory()->create())
            ->withTelegramChatId(555000111)
            ->create();

        Livewire::test(EditStaff::class, ['record' => $staff->getRouteKey()])
            ->assertFormSet(['telegram_chat_id' => 555000111])
            ->fillForm(['telegram_chat_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($staff->refresh()->telegram_chat_id);
    }

    public function test_non_numeric_telegram_chat_id_is_rejected(): void
    {
        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'X',
                'email' => 'x@example.com',
                'telegram_chat_id' => 'not-a-number',
                'role' => StaffRole::PlatformAdmin->value,
                'password' => 'secret-pass',
            ])
            ->call('create')
            ->assertHasFormErrors(['telegram_chat_id']);
    }
}
