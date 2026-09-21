<?php

namespace Tests\Feature;

use App\Enums\FeedbackType;
use App\Jobs\NotifyPlatformAdminsOfFeedback;
use App\Models\Feedback;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Feedback\PendingFeedbackStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

/**
 * "💬 Taklif va shikoyat": tur tanlash (inline) -> matn so'raladi ->
 * keyingi matn xabari feedbacks'ga saqlanadi (MenuHandler + PendingFeedbackStore,
 * PendingRatingStore bilan bir xil naqsh).
 */
class FeedbackFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TG_ID = 700001;

    private const PROMPT_MSG = 999;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'telegram_id' => self::TG_ID,
            'profile_completed' => true,
            'language' => 'uz',
        ]);
    }

    private function pressMenuButton(): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'X'],
            'text' => '💬 Taklif va shikoyat',
        ])->reply();

        return $bot;
    }

    private function chooseType(string $type): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => self::TG_ID, 'first_name' => 'X'],
            'data' => "feedback:{$type}",
            'message' => [
                'message_id' => self::PROMPT_MSG,
                'date' => 1703892479,
                'chat' => ['id' => self::TG_ID, 'type' => 'private'],
            ],
        ])->reply();

        return $bot;
    }

    private function sendText(string $text): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'X'],
            'text' => $text,
        ])->reply();

        return $bot;
    }

    public function test_menu_button_shows_type_choice(): void
    {
        $bot = $this->pressMenuButton();

        $bot->assertCalled('sendMessage');
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'feedback:suggestion')
            && str_contains((string) $request->getBody(), 'feedback:complaint'));
    }

    public function test_choosing_type_sets_pending_state_and_asks_for_text(): void
    {
        $bot = $this->chooseType('suggestion');

        $bot->assertCalled('editMessageText');
        // index 0 = answerCallbackQuery, index 1 = editMessageText (OrderRatingTest bilan bir xil naqsh).
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'Fikringizni batafsil yozing'), 1);

        $this->assertSame(FeedbackType::Suggestion, app(PendingFeedbackStore::class)->pending(self::TG_ID));
    }

    public function test_text_after_choosing_suggestion_is_saved_and_thanked(): void
    {
        app(PendingFeedbackStore::class)->remember(self::TG_ID, FeedbackType::Suggestion);

        $bot = $this->sendText('Mini App sekin ishlayapti, tezlashtirsangiz zo\'r bo\'lardi');

        $feedback = Feedback::first();
        $this->assertNotNull($feedback);
        $this->assertSame($this->user->id, $feedback->user_id);
        $this->assertSame(FeedbackType::Suggestion, $feedback->type);
        $this->assertSame("Mini App sekin ishlayapti, tezlashtirsangiz zo'r bo'lardi", $feedback->message);

        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'Rahmat! Taklifingiz'));

        // Holat tozalangan — keyingi matn endi feedback sifatida qabul qilinmaydi.
        $this->assertNull(app(PendingFeedbackStore::class)->pending(self::TG_ID));
    }

    public function test_text_after_choosing_complaint_is_saved_and_gets_a_different_reply(): void
    {
        app(PendingFeedbackStore::class)->remember(self::TG_ID, FeedbackType::Complaint);

        $bot = $this->sendText('Kuryer 2 soat kechikdi');

        $feedback = Feedback::first();
        $this->assertSame(FeedbackType::Complaint, $feedback->type);

        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'Kechirasiz')
            && str_contains((string) $request->getBody(), 'aloqaga chiqamiz'));
    }

    public function test_without_pending_state_plain_text_falls_back_to_main_menu(): void
    {
        $bot = $this->sendText('salom');

        $this->assertSame(0, Feedback::count());
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'Asosiy menyu'));
    }

    public function test_switching_menu_action_cancels_pending_feedback(): void
    {
        app(PendingFeedbackStore::class)->remember(self::TG_ID, FeedbackType::Suggestion);

        $this->pressMenuButton(); // qaytadan "Taklif va shikoyat" bosildi — eski holat tozalanadi va yangisi so'raladi

        $this->assertNull(app(PendingFeedbackStore::class)->pending(self::TG_ID));
        $this->assertSame(0, Feedback::count());
    }

    // --- Admin DM ------------------------------------------------------

    public function test_saving_feedback_dispatches_the_admin_notify_job(): void
    {
        Queue::fake();
        app(PendingFeedbackStore::class)->remember(self::TG_ID, FeedbackType::Complaint);

        $this->sendText('Taom sovuq keldi');

        $feedback = Feedback::first();
        Queue::assertPushed(NotifyPlatformAdminsOfFeedback::class, fn ($job) => $job->feedbackId === $feedback->id);
    }

    public function test_admin_notify_job_sends_dm_to_platform_admins_with_chat_id(): void
    {
        $admin = Staff::factory()->platformAdmin()->withTelegramChatId(600600)->create();
        Staff::factory()->platformAdmin()->create(); // telegram_chat_id yo'q — o'tkazib yuboriladi
        Staff::factory()->owner(Restaurant::factory()->create())->withTelegramChatId(600601)->create(); // platform_admin emas

        $feedback = Feedback::factory()->for($this->user)->complaint()->create(['message' => 'Kuryer kechikdi']);

        (new NotifyPlatformAdminsOfFeedback($feedback->id))->handle(app(Nutgram::class));

        $bot = app(Nutgram::class);
        $bot->assertCalled('sendMessage', 1);
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), (string) $admin->telegram_chat_id)
            && str_contains((string) $request->getBody(), 'Shikoyat')
            && str_contains((string) $request->getBody(), 'Kuryer kechikdi'));
    }

    public function test_admin_notify_job_is_noop_without_recipients(): void
    {
        $feedback = Feedback::factory()->for($this->user)->create();

        (new NotifyPlatformAdminsOfFeedback($feedback->id))->handle(app(Nutgram::class));

        app(Nutgram::class)->assertCalled('sendMessage', 0);
    }
}
