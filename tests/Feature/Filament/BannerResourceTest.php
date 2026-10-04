<?php

namespace Tests\Feature\Filament;

use App\Enums\BannerTarget;
use App\Filament\Admin\Resources\BannerResource\Pages\CreateBanner;
use App\Filament\Admin\Resources\BannerResource\Pages\EditBanner;
use App\Filament\Admin\Resources\BannerResource\Pages\ListBanners;
use App\Models\Banner;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Policies\BannerPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /admin → Bannerlar: faqat platform_admin. Rasm turi (JPG/PNG/WebP) va hajmi
 * (≤ 500 KB) tekshiriladi; muddat Toshkent vaqtida kiritiladi, bazada UTC.
 */
class BannerResourceTest extends TestCase
{
    use RefreshDatabase;

    private Staff $admin;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = Staff::factory()->platformAdmin()->create();
        $this->restaurant = Restaurant::factory()->create(['name' => 'Donix']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(array $overrides = []): array
    {
        return array_replace([
            'image_path' => UploadedFile::fake()->create('banner.jpg', 300, 'image/jpeg'),
            'title' => 'Kuzgi aksiya',
            'target_type' => BannerTarget::None->value,
            'sort_order' => 1,
            'is_active' => true,
            'starts_at' => '2026-10-01 00:00:00', // Toshkent
            'ends_at' => '2026-10-01 23:59:00',   // Toshkent
        ], $overrides);
    }

    /** Factory faqat yo'l yozadi — tahrirlash formasi/jadval uchun fayl diskda ham bo'lsin. */
    private function withImage(Banner $banner): Banner
    {
        Storage::disk('public')->put($banner->image_path, 'img');

        return $banner;
    }

    // --- Yaratish va muddat (Toshkent → UTC) --------------------------------

    public function test_admin_creates_a_banner_with_tashkent_times_stored_as_utc(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreateBanner::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = Banner::sole();
        $this->assertStringStartsWith('banners/', $banner->image_path);
        Storage::disk('public')->assertExists($banner->image_path);
        $this->assertSame(BannerTarget::None, $banner->target_type);
        $this->assertNull($banner->restaurant_id);

        $row = DB::table('banners')->where('id', $banner->id)->first(['starts_at', 'ends_at']);
        $this->assertSame('2026-09-30 19:00:00', (string) $row->starts_at);
        $this->assertSame('2026-10-01 18:59:00', (string) $row->ends_at);
    }

    public function test_stored_utc_is_shown_in_tashkent_in_the_form_and_table(): void
    {
        $banner = $this->withImage(Banner::factory()->create([
            'starts_at' => '2026-09-30 19:00:00',
            'ends_at' => '2026-10-01 18:59:00',
        ]));
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->assertFormSet([
                'starts_at' => '2026-10-01 00:00:00',
                'ends_at' => '2026-10-01 23:59:00',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // O'zgartirmay saqlash vaqtni surmaydi.
        $row = DB::table('banners')->where('id', $banner->id)->first(['starts_at', 'ends_at']);
        $this->assertSame('2026-09-30 19:00:00', (string) $row->starts_at);
        $this->assertSame('2026-10-01 18:59:00', (string) $row->ends_at);

        Livewire::test(ListBanners::class)
            ->assertTableColumnFormattedStateSet('starts_at', '01.10.2026 00:00', $banner->fresh())
            ->assertTableColumnFormattedStateSet('ends_at', '01.10.2026 23:59', $banner->fresh());
    }

    public function test_end_must_be_after_start(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreateBanner::class)
            ->fillForm($this->form(['ends_at' => '2026-09-30 23:00:00']))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        $this->assertSame(0, Banner::count());
    }

    // --- Rasm turi va hajmi -------------------------------------------------

    public function test_png_and_webp_are_accepted_and_exactly_500_kb_fits(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        foreach ([['a.png', 'image/png'], ['b.webp', 'image/webp']] as [$name, $mime]) {
            Livewire::test(CreateBanner::class)
                ->fillForm($this->form(['image_path' => UploadedFile::fake()->create($name, 500, $mime)]))
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(2, Banner::count());
    }

    public function test_image_larger_than_500_kb_is_rejected(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreateBanner::class)
            ->fillForm($this->form(['image_path' => UploadedFile::fake()->create('katta.jpg', 501, 'image/jpeg')]))
            ->call('create')
            ->assertHasFormErrors(['image_path']);

        $this->assertSame(0, Banner::count());
    }

    public function test_other_file_types_are_rejected(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        foreach ([['anim.gif', 'image/gif'], ['logo.svg', 'image/svg+xml'], ['hujjat.pdf', 'application/pdf']] as [$name, $mime]) {
            Livewire::test(CreateBanner::class)
                ->fillForm($this->form(['image_path' => UploadedFile::fake()->create($name, 50, $mime)]))
                ->call('create')
                ->assertHasFormErrors(['image_path']);
        }

        $this->assertSame(0, Banner::count());
    }

    public function test_form_tells_the_recommended_size_and_limits(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreateBanner::class)
            ->assertSee('1200×600')
            ->assertSee('2:1')
            ->assertSee('500 KB');
    }

    // --- Bosilganda: restoran menyusi ---------------------------------------

    public function test_restaurant_target_requires_a_restaurant(): void
    {
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(CreateBanner::class)
            ->fillForm($this->form(['target_type' => BannerTarget::Restaurant->value, 'restaurant_id' => null]))
            ->call('create')
            ->assertHasFormErrors(['restaurant_id' => 'required']);

        Livewire::test(CreateBanner::class)
            ->fillForm($this->form(['target_type' => BannerTarget::Restaurant->value, 'restaurant_id' => $this->restaurant->id]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->restaurant->id, Banner::sole()->restaurant_id);
    }

    public function test_switching_back_to_nothing_drops_the_restaurant_link(): void
    {
        $banner = $this->withImage(Banner::factory()->forRestaurant($this->restaurant)->create());
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->fillForm(['target_type' => BannerTarget::None->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $banner->refresh();
        $this->assertSame(BannerTarget::None, $banner->target_type);
        $this->assertNull($banner->restaurant_id);
    }

    // --- Ro'yxat ------------------------------------------------------------

    public function test_list_shows_thumbnail_and_status(): void
    {
        $live = $this->withImage(Banner::factory()->create(['title' => 'Hozirgi']));
        $off = Banner::factory()->inactive()->create(['title' => "O'chiq"]);
        $closed = Banner::factory()->forRestaurant(Restaurant::factory()->create(['is_open' => false]))->create();
        Livewire::actingAs($this->admin, 'admin');

        Livewire::test(ListBanners::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$live, $off, $closed])
            ->assertTableColumnExists('image_path')
            ->assertTableColumnFormattedStateSet('status', "Ko'rsatilmoqda", $live)
            ->assertTableColumnFormattedStateSet('status', "O'chirilgan", $off)
            ->assertTableColumnFormattedStateSet('status', 'Restoran yopiq', $closed)
            ->assertSee(Storage::disk('public')->url($live->image_path), false);
    }

    public function test_replacing_or_deleting_a_banner_removes_its_old_image(): void
    {
        Storage::disk('public')->put('banners/old.jpg', 'x');
        Storage::disk('public')->put('banners/new.jpg', 'x');
        $banner = Banner::factory()->create(['image_path' => 'banners/old.jpg']);

        $banner->update(['image_path' => 'banners/new.jpg']);
        Storage::disk('public')->assertMissing('banners/old.jpg');

        $banner->delete();
        Storage::disk('public')->assertMissing('banners/new.jpg');
    }

    // --- Avtorizatsiya: faqat platform_admin --------------------------------

    public function test_policy_is_platform_admin_only(): void
    {
        $policy = app(BannerPolicy::class);
        $banner = Banner::factory()->make();
        $owner = Staff::factory()->owner($this->restaurant)->make();
        $kitchen = Staff::factory()->kitchenStaff($this->restaurant)->make();

        $this->assertTrue($policy->viewAny($this->admin));
        $this->assertTrue($policy->create($this->admin));
        $this->assertTrue($policy->update($this->admin, $banner));
        $this->assertTrue($policy->delete($this->admin, $banner));

        foreach ([$owner, $kitchen] as $staff) {
            $this->assertFalse($policy->viewAny($staff));
            $this->assertFalse($policy->create($staff));
            $this->assertFalse($policy->update($staff, $banner));
            $this->assertFalse($policy->delete($staff, $banner));
        }
    }

    public function test_restaurant_owner_cannot_open_the_admin_banners_page(): void
    {
        $owner = Staff::factory()->owner($this->restaurant)->create();

        $this->actingAs($owner, 'staff')->get('/admin/banners')->assertRedirect('/admin/login');
    }

    public function test_platform_admin_opens_the_banners_page(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/admin/banners')->assertOk();
        $this->actingAs($this->admin, 'admin')->get('/admin/banners/create')->assertOk();
    }
}
