<?php

namespace Tests\Feature;

use App\Enums\FeedbackStatus;
use App\Filament\Admin\Resources\FeedbackResource\Pages\ListFeedbacks;
use App\Jobs\SendFeedbackReplyToCustomer;
use App\Models\Feedback;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Feedback\FeedbackReplyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use Tests\TestCase;

class FeedbackReplyTest extends TestCase
{
    use RefreshDatabase;

    private function feedback(string $lang = 'uz'): Feedback
    {
        $user = User::factory()->create(['telegram_id' => random_int(7000000, 7999999), 'language' => $lang]);

        return Feedback::factory()->for($user)->suggestion()->create(['message' => 'Kartadan to‘lash bo‘lsa yaxshi']);
    }

    public function test_reply_marks_answered_and_queues_delivery(): void
    {
        Queue::fake();
        $admin = Staff::factory()->platformAdmin()->create();
        $feedback = $this->feedback();

        $this->assertSame(FeedbackStatus::New, $feedback->fresh()->status);

        app(FeedbackReplyService::class)->reply($feedback, $admin, '  Rahmat, ko‘rib chiqamiz  ');

        $feedback->refresh();
        $this->assertSame(FeedbackStatus::Answered, $feedback->status);
        $this->assertSame('Rahmat, ko‘rib chiqamiz', $feedback->admin_reply);
        $this->assertSame($admin->id, $feedback->replied_by);
        $this->assertNotNull($feedback->replied_at);
        $this->assertNull($feedback->reply_delivered);
        Queue::assertPushed(SendFeedbackReplyToCustomer::class, fn ($j) => $j->feedbackId === $feedback->id);
    }

    public function test_job_sends_localized_reply_to_customer(): void
    {
        $admin = Staff::factory()->platformAdmin()->create();
        $feedback = $this->feedback('ru');
        $feedback->update(['admin_reply' => 'Спасибо!', 'status' => FeedbackStatus::Answered, 'replied_by' => $admin->id]);

        $bot = app(Nutgram::class);
        (new SendFeedbackReplyToCustomer($feedback->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertReplyText(__('messages.feedback.reply', [
            'excerpt' => $feedback->message,
            'reply' => 'Спасибо!',
        ], 'ru'));
        $this->assertTrue($feedback->fresh()->reply_delivered);
    }

    public function test_blocked_bot_is_swallowed_and_marked_undelivered(): void
    {
        $feedback = $this->feedback();
        $feedback->update(['admin_reply' => 'Javob', 'status' => FeedbackStatus::Answered]);

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('sendMessage')->andThrow(new TelegramException('Forbidden: bot was blocked by the user', 403));

        (new SendFeedbackReplyToCustomer($feedback->id))->handle($bot);

        $this->assertFalse($feedback->fresh()->reply_delivered);
    }

    public function test_empty_reply_is_rejected(): void
    {
        Queue::fake();
        $admin = Staff::factory()->platformAdmin()->create();
        $feedback = $this->feedback();

        try {
            app(FeedbackReplyService::class)->reply($feedback, $admin, "   \n ");
            $this->fail('ValidationException kutilgan edi');
        } catch (ValidationException) {
        }

        $this->assertSame(FeedbackStatus::New, $feedback->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_second_reply_replaces_the_first(): void
    {
        Queue::fake();
        $admin = Staff::factory()->platformAdmin()->create();
        $feedback = $this->feedback();
        $service = app(FeedbackReplyService::class);

        $service->reply($feedback, $admin, 'Birinchi');
        $feedback->update(['reply_delivered' => false]);
        $service->reply($feedback->fresh(), $admin, 'Ikkinchi');

        $feedback->refresh();
        $this->assertSame('Ikkinchi', $feedback->admin_reply);
        $this->assertNull($feedback->reply_delivered);
        Queue::assertPushed(SendFeedbackReplyToCustomer::class, 2);
    }

    public function test_only_platform_admin_can_reply(): void
    {
        Queue::fake();
        $restaurant = Restaurant::factory()->create();
        $owner = Staff::factory()->owner($restaurant)->create();
        $feedback = $this->feedback();

        $this->assertTrue(Staff::factory()->platformAdmin()->make()->can('reply', $feedback));
        $this->assertFalse($owner->can('reply', $feedback));

        try {
            app(FeedbackReplyService::class)->reply($feedback, $owner, 'Salom');
            $this->fail('403 kutilgan edi');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(FeedbackStatus::New, $feedback->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_reply_action_in_panel_and_status_filter(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->platformAdmin()->create();
        $feedback = $this->feedback();
        $other = $this->feedback();
        $other->update(['status' => FeedbackStatus::Answered]);

        Livewire::actingAs($admin, 'admin');

        Livewire::test(ListFeedbacks::class)
            ->filterTable('status', 'new')
            ->assertCanSeeTableRecords([$feedback])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListFeedbacks::class)
            ->callTableAction('reply', $feedback, ['reply' => '   '])
            ->assertHasTableActionErrors(['reply']);

        Livewire::test(ListFeedbacks::class)
            ->callTableAction('reply', $feedback, ['reply' => 'Rahmat'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(FeedbackStatus::Answered, $feedback->fresh()->status);
        Queue::assertPushed(SendFeedbackReplyToCustomer::class);
    }
}
