<?php

namespace Tests\Feature\Filament;

use App\Enums\DiscountType;
use App\Enums\PromoCodeError;
use App\Filament\Admin\Resources\PromoCodeResource\Pages\CreatePromoCode;
use App\Filament\Admin\Resources\PromoCodeResource\Pages\EditPromoCode;
use App\Filament\Admin\Resources\PromoCodeResource\Pages\ListPromoCodes;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Promo-kod muddati: admin Toshkent vaqtini kiritadi va ko'radi, bazada UTC.
 * Avval zonasiz picker kiritilgan vaqtni UTC deb saqlardi — kod 5 soat kech
 * kuchga kirib, 5 soat kech tugardi.
 */
class PromoCodeValidityTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
    }

    private function createOctoberFirstCode(): PromoCode
    {
        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'OKTABR1',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 10,
                'restaurant_share_percent' => 50,
                'restaurants' => [$this->restaurant->id],
                'starts_at' => '2026-10-01 00:00:00', // Toshkent
                'ends_at' => '2026-10-01 23:59:00',   // Toshkent
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return PromoCode::where('code', 'OKTABR1')->firstOrFail();
    }

    /** @return array{starts_at: string, ends_at: string} */
    private function raw(PromoCode $promo): array
    {
        $row = DB::table('promo_codes')->where('id', $promo->id)->first(['starts_at', 'ends_at']);

        return ['starts_at' => (string) $row->starts_at, 'ends_at' => (string) $row->ends_at];
    }

    public function test_tashkent_input_is_stored_as_utc(): void
    {
        $promo = $this->createOctoberFirstCode();

        $this->assertSame(
            ['starts_at' => '2026-09-30 19:00:00', 'ends_at' => '2026-10-01 18:59:00'],
            $this->raw($promo),
        );
    }

    public function test_stored_utc_is_shown_in_tashkent_in_the_form_and_table(): void
    {
        $promo = PromoCode::factory()->at($this->restaurant)->create();
        DB::table('promo_codes')->where('id', $promo->id)->update([
            'starts_at' => '2026-09-30 19:00:00',
            'ends_at' => '2026-10-01 18:59:00',
        ]);

        Livewire::test(EditPromoCode::class, ['record' => $promo->getRouteKey()])
            ->assertFormSet([
                'starts_at' => '2026-10-01 00:00:00',
                'ends_at' => '2026-10-01 23:59:00',
            ]);

        Livewire::test(ListPromoCodes::class)
            ->assertTableColumnFormattedStateSet('ends_at', '01.10.2026 23:59', $promo->fresh())
            ->assertTableColumnFormattedStateSet('starts_at', '01.10.2026 00:00', $promo->fresh());
    }

    public function test_saving_the_edit_form_unchanged_does_not_shift_the_times(): void
    {
        $promo = $this->createOctoberFirstCode();

        Livewire::test(EditPromoCode::class, ['record' => $promo->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['starts_at' => '2026-09-30 19:00:00', 'ends_at' => '2026-10-01 18:59:00'],
            $this->raw($promo),
        );
    }

    public function test_code_starts_at_tashkent_midnight_and_ends_after_23_59(): void
    {
        $promo = $this->createOctoberFirstCode()->load('restaurants');
        $at = fn (string $tashkent) => Carbon::parse($tashkent, 'Asia/Tashkent')->utc();

        $this->assertSame(PromoCodeError::NotStarted, $promo->rejectionFor($this->restaurant, $at('2026-09-30 23:59:59')));
        $this->assertNull($promo->rejectionFor($this->restaurant, $at('2026-10-01 00:00:00')));
        $this->assertNull($promo->rejectionFor($this->restaurant, $at('2026-10-01 12:00:00')));
        $this->assertNull($promo->rejectionFor($this->restaurant, $at('2026-10-01 23:59:00')));
        $this->assertSame(PromoCodeError::Expired, $promo->rejectionFor($this->restaurant, $at('2026-10-01 23:59:01')));
        $this->assertSame(PromoCodeError::Expired, $promo->rejectionFor($this->restaurant, $at('2026-10-02 00:00:00')));
    }
}
