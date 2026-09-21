<?php

namespace Tests\Feature\Filament;

use App\Enums\FeedbackType;
use App\Filament\Admin\Resources\FeedbackResource;
use App\Filament\Admin\Resources\FeedbackResource\Pages\ListFeedbacks;
use App\Models\Feedback;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Policies\FeedbackPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /admin — "Fikr-mulohazalar" (FeedbackResource): FAQAT platform_admin ko'radi,
 * FAQAT ko'rish (UserResource bilan bir xil naqsh).
 */
class FeedbackResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_platform_admin_sees_the_feedback_list(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $customer = User::factory()->create(['full_name' => 'Malika Yusupova']);
        $feedback = Feedback::factory()->for($customer)->suggestion()->create(['message' => 'Kartadan to‘lash imkoni bo‘lsa yaxshi bo‘lardi']);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListFeedbacks::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$feedback])
            ->assertSee('Malika Yusupova')
            ->assertSee('Taklif');
    }

    public function test_type_filter(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $customer = User::factory()->create();
        $suggestion = Feedback::factory()->for($customer)->suggestion()->create();
        $complaint = Feedback::factory()->for($customer)->complaint()->create();

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListFeedbacks::class)
            ->filterTable('type', FeedbackType::Complaint->value)
            ->assertCanSeeTableRecords([$complaint])
            ->assertCanNotSeeTableRecords([$suggestion]);
    }

    public function test_feedback_policy_view_any_is_platform_admin_only(): void
    {
        $policy = app(FeedbackPolicy::class);

        $admin = Staff::factory()->platformAdmin()->make();
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->make();
        $kitchen = Staff::factory()->kitchenStaff(Restaurant::factory()->create())->make();

        $this->assertTrue($policy->viewAny($admin));
        $this->assertFalse($policy->viewAny($owner));
        $this->assertFalse($policy->viewAny($kitchen));
    }

    public function test_platform_admin_can_open_the_feedback_page_over_http(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();

        $this->actingAs($admin, 'admin')->get('/admin/feedbacks')->assertOk();
    }

    public function test_restaurant_owner_cannot_open_the_admin_feedback_page(): void
    {
        $owner = Staff::factory()->owner(Restaurant::factory()->create())->create();

        $this->actingAs($owner, 'staff')->get('/admin/feedbacks')->assertRedirect('/admin/login');
    }

    public function test_resource_has_no_create_edit_or_delete_pages(): void
    {
        $pages = array_keys(FeedbackResource::getPages());

        $this->assertSame(['index', 'view'], $pages);
    }
}
