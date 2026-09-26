<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastMessage;
use App\Models\Broadcast;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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

    // --- Rasm file_id qayta ishlatish va tarmoq xatosida qayta urinish (2026-09-26) ---

    /** Telegram sendPhoto javobi: bir nechta o'lcham, oxirgisi eng kattasi. */
    private function photoMessage(string $largestFileId): array
    {
        return [
            'message_id' => 1,
            'date' => 1_700_000_000,
            'chat' => ['id' => 1, 'type' => 'private'],
            'photo' => [
                ['file_id' => 'small-size-id', 'file_unique_id' => 'u1', 'width' => 90, 'height' => 90],
                ['file_id' => $largestFileId, 'file_unique_id' => 'u2', 'width' => 1280, 'height' => 1280],
            ],
        ];
    }

    /** Keyingi Telegram so'rovi javob o'rniga shu tarmoq xatosini tashlaydi (cURL timeout). */
    private function failNextRequestWithNetworkError(Nutgram $bot): void
    {
        $this->mockHandler($bot)->append(new ConnectException(
            'cURL error 28: Operation timed out after 5001 milliseconds',
            new GuzzleRequest('POST', 'sendPhoto'),
        ));
    }

    private function mockHandler(Nutgram $bot): MockHandler
    {
        return (fn () => $this->mockHandler)->call($bot);
    }

    private function imageBroadcast(array $attributes = []): Broadcast
    {
        Storage::fake('public');
        Storage::disk('public')->put('broadcasts/promo.jpg', 'fake-image-bytes');

        return Broadcast::factory()->create(array_merge([
            'message' => 'Aksiya boshlandi!',
            'image_path' => 'broadcasts/promo.jpg',
        ], $attributes));
    }

    public function test_first_successful_photo_upload_stores_the_largest_telegram_file_id(): void
    {
        $broadcast = $this->imageBroadcast();
        $user = User::factory()->create(['telegram_id' => 555101]);
        $bot = $this->bot();

        $bot->willReceive($this->photoMessage('BIG-FILE-ID'));

        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $broadcast->refresh();
        $this->assertSame('BIG-FILE-ID', $broadcast->telegram_file_id);
        $this->assertSame(1, $broadcast->sent_count);
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'fake-image-bytes'));
    }

    public function test_following_recipients_get_the_photo_by_file_id_without_re_uploading(): void
    {
        $broadcast = $this->imageBroadcast();
        [$first, $second, $third] = User::factory()->count(3)->create()->all();
        $bot = $this->bot();

        $bot->willReceive($this->photoMessage('BIG-FILE-ID'));
        (new SendBroadcastMessage($broadcast->id, $first->id))->handle($bot);
        (new SendBroadcastMessage($broadcast->id, $second->id))->handle($bot);
        (new SendBroadcastMessage($broadcast->id, $third->id))->handle($bot);

        $bodies = array_map(
            fn (array $entry) => (string) $entry['request']->getBody(),
            $bot->getRequestHistory(),
        );

        $this->assertCount(3, $bodies);
        // Faqat birinchi so'rov rasm baytlarini yuklaydi...
        $this->assertStringContainsString('fake-image-bytes', $bodies[0]);
        // ...qolganlari saqlangan file_id (oddiy string) bilan, qayta yuklashsiz.
        foreach ([1, 2] as $i) {
            $this->assertStringNotContainsString('fake-image-bytes', $bodies[$i]);
            $this->assertStringContainsString('BIG-FILE-ID', $bodies[$i]);
            $this->assertStringContainsString('Aksiya boshlandi!', $bodies[$i]);
        }

        $this->assertSame(3, $broadcast->fresh()->sent_count);
    }

    public function test_if_the_first_recipient_fails_the_next_one_uploads_and_stores_the_file_id(): void
    {
        $broadcast = $this->imageBroadcast();
        $blocked = User::factory()->create();
        $ok = User::factory()->create();
        $bot = $this->bot();

        $bot->willReceive(['description' => 'Forbidden: bot was blocked by the user'], false);
        (new SendBroadcastMessage($broadcast->id, $blocked->id))->handle($bot);
        $this->assertNull($broadcast->fresh()->telegram_file_id);

        $bot->willReceive($this->photoMessage('SECOND-TRY-ID'));
        (new SendBroadcastMessage($broadcast->id, $ok->id))->handle($bot);

        $broadcast->refresh();
        $this->assertSame('SECOND-TRY-ID', $broadcast->telegram_file_id);
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
    }

    public function test_an_already_stored_file_id_is_never_overwritten(): void
    {
        $broadcast = $this->imageBroadcast(['telegram_file_id' => 'FIRST-ID']);
        $user = User::factory()->create();
        $bot = $this->bot();

        $bot->willReceive($this->photoMessage('OTHER-ID'));
        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $this->assertSame('FIRST-ID', $broadcast->fresh()->telegram_file_id);
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'FIRST-ID'));
    }

    public function test_a_network_error_is_retried_exactly_once_and_then_succeeds(): void
    {
        Sleep::fake();
        $broadcast = $this->imageBroadcast();
        $user = User::factory()->create();
        $bot = $this->bot();

        $this->failNextRequestWithNetworkError($bot);
        $bot->willReceive($this->photoMessage('AFTER-RETRY-ID'));

        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $broadcast->refresh();
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->failed_count);
        $this->assertSame('AFTER-RETRY-ID', $broadcast->telegram_file_id);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_second_network_error_is_not_retried_again_and_counts_as_failed(): void
    {
        Sleep::fake();
        $broadcast = Broadcast::factory()->create();
        $user = User::factory()->create();
        $bot = $this->bot();

        $this->failNextRequestWithNetworkError($bot);
        $this->failNextRequestWithNetworkError($bot);
        $bot->willReceive(true); // uchinchi urinish bo'lsa shu javob olinardi

        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $broadcast->refresh();
        $this->assertSame(0, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
        Sleep::assertSleptTimes(1);
        // Uchinchi javob navbatda qolgan — ya'ni uchinchi urinish bo'lmagan.
        $this->assertSame(1, $this->mockHandler($bot)->count());
    }

    public function test_telegram_errors_like_bot_blocked_are_not_retried(): void
    {
        Sleep::fake();
        $broadcast = Broadcast::factory()->create();
        $user = User::factory()->create();
        $bot = $this->bot();

        $bot->willReceive(['error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], false);

        (new SendBroadcastMessage($broadcast->id, $user->id))->handle($bot);

        $this->assertSame(1, $broadcast->fresh()->failed_count);
        Sleep::assertNeverSlept();
        $bot->assertCalled('sendMessage', 1);
    }
}
