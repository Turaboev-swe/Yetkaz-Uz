<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\Broadcasts;
use App\Jobs\SendBroadcastMessage;
use App\Models\Address;
use App\Models\Broadcast;
use App\Models\District;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /admin/broadcasts — faqat platform_admin, forma orqali xabarnoma yuborish
 * + tarix jadvali.
 */
class BroadcastsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // --- Avtorizatsiya: faqat platform_admin ---------------------------

    public function test_platform_admin_can_open_the_broadcasts_page(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'admin')->get('/admin/broadcasts')->assertOk();
    }

    public function test_restaurant_owner_cannot_open_the_broadcasts_page(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        $this->actingAs($owner, 'staff')->get('/admin/broadcasts')->assertRedirect('/admin/login');
    }

    public function test_kitchen_staff_cannot_open_the_broadcasts_page(): void
    {
        $kitchen = Staff::factory()->kitchenStaff(Restaurant::factory()->create())->create();

        $this->actingAs($kitchen, 'staff')->get('/admin/broadcasts')->assertRedirect('/admin/login');
    }

    public function test_page_can_access_check_rejects_non_platform_admin(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();
        auth('admin')->setUser($owner);

        $this->assertFalse(Broadcasts::canAccess());
    }

    // --- Yuborish: "barcha" auditoriya ----------------------------------

    public function test_sending_to_all_queues_a_job_for_every_registered_user(): void
    {
        Queue::fake();
        $admin = Staff::factory()->platformAdmin()->create();
        $a = User::factory()->create(['telegram_id' => 111, 'profile_completed' => true]);
        $b = User::factory()->create(['telegram_id' => 222, 'profile_completed' => true]);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(Broadcasts::class)
            ->fillForm([
                'message' => 'Yangi menyu qo\'shildi!',
                'audience_type' => 'all',
            ])
            ->call('send')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('broadcasts', ['message' => 'Yangi menyu qo\'shildi!', 'audience_type' => 'all']);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $a->id);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $b->id);
    }

    // --- Yuborish: "tuman" auditoriya ------------------------------------

    public function test_sending_to_a_district_only_queues_jobs_for_that_districts_users(): void
    {
        Queue::fake();
        $admin = Staff::factory()->platformAdmin()->create();

        $districtA = District::factory()->create();
        $districtB = District::factory()->create();

        $inA = User::factory()->create(['telegram_id' => 111]);
        Address::factory()->for($inA)->create(['district_id' => $districtA->id]);
        $inB = User::factory()->create(['telegram_id' => 222]);
        Address::factory()->for($inB)->create(['district_id' => $districtB->id]);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(Broadcasts::class)
            ->fillForm([
                'message' => 'A tumanidagilarga aksiya!',
                'audience_type' => 'district',
                'district_ids' => [$districtA->id],
            ])
            ->call('send')
            ->assertHasNoFormErrors();

        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $inA->id);
        Queue::assertNotPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $inB->id);
    }

    public function test_district_audience_requires_at_least_one_district(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        Livewire::actingAs($admin, 'admin');

        Livewire::test(Broadcasts::class)
            ->fillForm([
                'message' => 'Xabar',
                'audience_type' => 'district',
                'district_ids' => [],
            ])
            ->call('send')
            ->assertHasFormErrors(['district_ids']);
    }

    public function test_message_is_required(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        Livewire::actingAs($admin, 'admin');

        Livewire::test(Broadcasts::class)
            ->fillForm(['message' => ''])
            ->call('send')
            ->assertHasFormErrors(['message']);
    }

    // --- Tarix jadvali ---------------------------------------------------

    public function test_history_table_shows_past_broadcasts(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $broadcast = Broadcast::factory()->create(['message' => 'Eski xabarnoma', 'sent_count' => 156, 'failed_count' => 3]);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(Broadcasts::class)
            ->assertCanSeeTableRecords([$broadcast])
            ->assertSee('Eski xabarnoma')
            ->assertSee('156')
            ->assertSee('3');
    }
}
