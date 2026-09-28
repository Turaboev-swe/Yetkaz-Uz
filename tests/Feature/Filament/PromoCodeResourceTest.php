<?php

namespace Tests\Feature\Filament;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\PromoCodeResource\Pages\CreatePromoCode;
use App\Filament\Admin\Resources\PromoCodeResource\Pages\EditPromoCode;
use App\Filament\Admin\Resources\PromoCodeResource\Pages\ListPromoCodes;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Policies\PromoCodePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /admin — Promokodlar: faqat platform_admin boshqaradi. so'm/tiyin va
 * foiz/summa mutatorlari (chegirma turiga qarab), kodning katta harfga
 * o'girilishi, va restoranga bog'lash.
 */
class PromoCodeResourceTest extends TestCase
{
    use RefreshDatabase;

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Staff::factory()->platformAdmin()->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_platform_admin_sees_the_promo_code_list(): void
    {
        $promo = PromoCode::factory()->create(['code' => 'YOZGI20']);
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(ListPromoCodes::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$promo])
            ->assertSee('YOZGI20');
    }

    public function test_creating_a_percent_code_stores_the_raw_percentage(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'kuz30',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 30,
                'restaurant_share_percent' => 50,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('promo_codes', [
            'code' => 'KUZ30', // katta harfga o'girilgan
            'discount_type' => 'percent',
            'discount_value' => 30,
        ]);
    }

    public function test_creating_a_fixed_code_converts_som_entry_to_tiyin(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'FIXSUM',
                'discount_type' => DiscountType::Fixed->value,
                'discount_value' => 15_000, // so'm kiritildi
                'restaurant_share_percent' => 50,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('promo_codes', [
            'code' => 'FIXSUM',
            'discount_type' => 'fixed',
            'discount_value' => 15_000_00, // tiyinда saqlangan
        ]);
    }

    public function test_edit_form_shows_the_fixed_value_back_in_som(): void
    {
        $promo = PromoCode::factory()->fixed(15_000_00)->create();
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(EditPromoCode::class, ['record' => $promo->getRouteKey()])
            ->assertFormSet(['discount_value' => 15_000]);
    }

    public function test_edit_form_shows_the_percent_value_unchanged(): void
    {
        $promo = PromoCode::factory()->percent(25)->create();
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(EditPromoCode::class, ['record' => $promo->getRouteKey()])
            ->assertFormSet(['discount_value' => 25]);
    }

    public function test_code_must_be_unique(): void
    {
        PromoCode::factory()->create(['code' => 'BIRINCHI']);
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'birinchi', // katta/kichik harf farqsiz — bir xilga o'giradi
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 10,
                'restaurant_share_percent' => 50,
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_restaurant_share_defaults_to_zero_with_a_clear_hint(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreatePromoCode::class)
            ->assertFormSet(['restaurant_share_percent' => 0])
            ->assertSee('Restoran qoplaydigan ulush (%)')
            ->assertSee('0 — hammasini platforma qoplaydi');
    }

    public function test_restaurant_share_must_be_between_0_and_100(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        foreach ([-1, 101] as $invalid) {
            Livewire::test(CreatePromoCode::class)
                ->fillForm([
                    'code' => 'ULUSH'.abs($invalid),
                    'discount_type' => DiscountType::Percent->value,
                    'discount_value' => 10,
                    'restaurant_share_percent' => $invalid,
                ])
                ->call('create')
                ->assertHasFormErrors(['restaurant_share_percent']);
        }
    }

    public function test_usage_limits_are_saved(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'LIMITLI',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 10,
                'restaurant_share_percent' => 50,
                'per_user_limit' => 1,
                'total_usage_limit' => 500,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('promo_codes', ['code' => 'LIMITLI', 'per_user_limit' => 1, 'total_usage_limit' => 500]);
    }

    public function test_limits_are_optional_and_must_be_positive(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'NOLLIMIT',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 10,
                'restaurant_share_percent' => 50,
                'per_user_limit' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['per_user_limit']);
    }

    public function test_usage_column_counts_only_non_cancelled_orders_against_the_limit(): void
    {
        $promo = PromoCode::factory()->create(['code' => 'SANA', 'total_usage_limit' => 10]);
        Order::factory()->count(2)->create(['promo_code_id' => $promo->id, 'status' => OrderStatus::Delivered]);
        Order::factory()->create(['promo_code_id' => $promo->id, 'status' => OrderStatus::Cancelled]);
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(ListPromoCodes::class)
            ->assertTableColumnStateSet('usages_count', '2 / 10', $promo);
    }

    public function test_can_restrict_a_code_to_one_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Donix']);
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'DONIXONLY',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 10,
                'restaurant_share_percent' => 50,
                'restaurant_id' => $restaurant->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('promo_codes', ['code' => 'DONIXONLY', 'restaurant_id' => $restaurant->id]);
    }

    public function test_restaurant_filter_narrows_the_list(): void
    {
        $restaurant = Restaurant::factory()->create();
        $scoped = PromoCode::factory()->create(['code' => 'FAQATBU', 'restaurant_id' => $restaurant->id]);
        $global = PromoCode::factory()->create(['code' => 'HAMMAGA', 'restaurant_id' => null]);
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(ListPromoCodes::class)
            ->filterTable('restaurant_id', $restaurant->id)
            ->assertCanSeeTableRecords([$scoped])
            ->assertCanNotSeeTableRecords([$global]);
    }

    public function test_active_filter(): void
    {
        $active = PromoCode::factory()->create(['code' => 'FAOL']);
        $inactive = PromoCode::factory()->inactive()->create(['code' => 'OCHIQ']);
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(ListPromoCodes::class)
            ->filterTable('is_active', '1')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    }

    // --- Avtorizatsiya: faqat platform_admin --------------------------------

    public function test_policy_is_platform_admin_only(): void
    {
        $policy = app(PromoCodePolicy::class);
        $admin = Staff::factory()->platformAdmin()->make();
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->make();
        $promo = PromoCode::factory()->make();

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->update($admin, $promo));
        $this->assertTrue($policy->delete($admin, $promo));

        $this->assertFalse($policy->viewAny($owner));
        $this->assertFalse($policy->create($owner));
        $this->assertFalse($policy->update($owner, $promo));
        $this->assertFalse($policy->delete($owner, $promo));
    }

    public function test_restaurant_owner_cannot_open_the_admin_promo_codes_page(): void
    {
        // Diqqat: bu yerda `admin` guardga hech kim (setUp'da ham) oldindan
        // kiritilmagan bo'lishi kerak — aks holda shu HTTP so'rov haqiqiy
        // panel gate'ini emas, oldingi sessiyani sinaydi.
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        $this->actingAs($owner, 'staff')->get('/admin/promo-codes')->assertRedirect('/admin/login');
    }
}
