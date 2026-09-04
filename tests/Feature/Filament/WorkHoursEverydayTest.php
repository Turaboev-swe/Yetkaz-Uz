<?php

namespace Tests\Feature\Filament;

use App\Filament\Restaurant\Pages\RestaurantSettings;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkHoursEverydayTest extends TestCase
{
    use RefreshDatabase;

    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private function actingOwnerOf(Restaurant $restaurant): void
    {
        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($restaurant)->create(), 'staff');
    }

    public function test_everyday_row_fills_all_seven_days_on_save(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingOwnerOf($restaurant);

        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => $restaurant->name,
                'work_hours' => [
                    ['day' => 'everyday', 'from' => '09:00', 'to' => '23:00'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $restaurant->refresh();

        // jsonb kalit tartibini saqlamaydi — 7 kun ham borligini tekshiramiz.
        $this->assertEqualsCanonicalizing(self::DAYS, array_keys($restaurant->work_hours));
        foreach (self::DAYS as $day) {
            $this->assertSame([['09:00', '23:00']], $restaurant->work_hours[$day]);
        }
    }

    public function test_editing_one_day_after_everyday_updates_only_that_day(): void
    {
        $restaurant = Restaurant::factory()->create([
            'work_hours' => array_fill_keys(self::DAYS, [['09:00', '23:00']]),
        ]);
        $this->actingOwnerOf($restaurant);

        // Forma jamlangan holda ochiladi (1 "Har kuni" qatori); foydalanuvchi
        // yakshanba uchun alohida qator qo'shadi.
        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => $restaurant->name,
                'work_hours' => [
                    ['day' => 'everyday', 'from' => '09:00', 'to' => '23:00'],
                    ['day' => 'sun', 'from' => '10:00', 'to' => '14:00'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $restaurant->refresh();

        $this->assertSame([['10:00', '14:00']], $restaurant->work_hours['sun']);
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            $this->assertSame([['09:00', '23:00']], $restaurant->work_hours[$day]);
        }
    }

    public function test_form_loads_collapsed_when_all_days_are_identical(): void
    {
        $restaurant = Restaurant::factory()->create([
            'work_hours' => array_fill_keys(self::DAYS, [['08:00', '22:00']]),
        ]);
        $this->actingOwnerOf($restaurant);

        $rows = Livewire::test(RestaurantSettings::class)
            ->get('data.work_hours');

        $this->assertCount(1, $rows);
        $this->assertSame('everyday', array_values($rows)[0]['day']);
    }
}
