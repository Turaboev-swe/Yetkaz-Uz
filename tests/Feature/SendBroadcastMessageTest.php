<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastMessage;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * SendBroadcastMessage — bitta foydalanuvchiga xabarnoma yetkazish.
 * Xato boshqa job'larga (foydalanuvchilarga) ta'sir qilmasligi — bu yerda
 * ushlanadi va faqat shu job uchun `failed_count` ga yoziladi.
 */
class SendBroadcastMessageTest extends TestCase
{
    use RefreshDatabase;

    private function bot(): Nutgram
    {
        return app(Nutgram::class);
    }

    public function test_sends_a_plain_text_message_and_increments_sent_count(): void
    {
        $broadcast = Broadcast::factory()->create(['message' => 'Salom, mijoz!']);
        $user = User::factory()->create(['telegram_id' => 555001]);
        $bot = $this->bot();

        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'Salom, mijoz!')
            && str_contains((string) $request->getBody(), '555001'));

        $this->assertSame(1, $broadcast->fresh()->sent_count);
        $this->assertSame(0, $broadcast->fresh()->failed_count);
    }

    public function test_sends_a_photo_with_the_message_as_caption_when_an_image_is_attached(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('broadcasts/promo.jpg', 'fake-image-bytes');

        $broadcast = Broadcast::factory()->create([
            'message' => 'Aksiya boshlandi!',
            'image_path' => 'broadcasts/promo.jpg',
        ]);
        $user = User::factory()->create(['telegram_id' => 555002]);
        $bot = $this->bot();

        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $bot->assertCalled('sendPhoto');
        $bot->assertCalled('sendMessage', 0);
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'Aksiya boshlandi!'));

        $this->assertSame(1, $broadcast->fresh()->sent_count);
    }

    public function test_a_failed_send_increments_failed_count_and_does_not_throw(): void
    {
        $broadcast = Broadcast::factory()->create();
        $user = User::factory()->create(['telegram_id' => 555003]);
        $bot = $this->bot();

        $bot->willReceive(['error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], false);

        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $broadcast->refresh();
        $this->assertSame(0, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
    }

    public function test_one_recipients_failure_does_not_affect_the_next_job(): void
    {
        $broadcast = Broadcast::factory()->create();
        $blocked = User::factory()->create(['telegram_id' => 555004]);
        $ok = User::factory()->create(['telegram_id' => 555005]);
        $bot = $this->bot();

        $bot->willReceive(['description' => 'Forbidden: bot was blocked by the user'], false);
        (new SendBroadcastMessage($broadcast->id, $blocked->id))->handle($bot);

        // Yangi so'rov — mock navbati bo'sh, standart muvaffaqiyatli javobga qaytadi.
        (new SendBroadcastMessage($broadcast->id, $ok->id))->handle($bot);

        $broadcast->refresh();
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
    }

    public function test_sent_and_failed_counts_accumulate_correctly_across_recipients(): void
    {
        $broadcast = Broadcast::factory()->create();
        $users = User::factory()->count(3)->create();
        $blockedUser = User::factory()->create();
        $bot = $this->bot();

        foreach ($users as $u) {
            (new SendBroadcastMessage($broadcast->id, $u->id))->handle($bot);
        }

        $bot->willReceive(['description' => 'bot was blocked by the user'], false);
        (new SendBroadcastMessage($broadcast->id, $blockedUser->id))->handle($bot);

        $broadcast->refresh();
        $this->assertSame(3, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
    }

    public function test_missing_broadcast_or_user_is_skipped_silently(): void
    {
        $bot = $this->bot();

        (new SendBroadcastMessage(999_999, 888_888))->handle($bot);

        $bot->assertCalled('sendMessage', 0);
        $bot->assertCalled('sendPhoto', 0);
    }
}
