<?php

namespace App\Jobs;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use Throwable;

/**
 * Admin javobini mijozga yuboradi va natijani reply_delivered ga yozadi.
 * Bot bloklangan bo'lsa — jimgina false; boshqa xatolar qayta uriniladi.
 */
class SendFeedbackReplyToCustomer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $feedbackId) {}

    public function handle(Nutgram $bot): void
    {
        $feedback = Feedback::with('user')->find($this->feedbackId);

        if ($feedback === null || blank($feedback->admin_reply)) {
            return;
        }

        if (blank($feedback->user?->telegram_id)) {
            $feedback->update(['reply_delivered' => false]);

            return;
        }

        $previous = app()->getLocale();
        app()->setLocale($feedback->user->language ?: 'uz');

        try {
            $text = __('messages.feedback.reply', [
                'excerpt' => mb_strimwidth($feedback->message, 0, 200, '…'),
                'reply' => $feedback->admin_reply,
            ]);
        } finally {
            app()->setLocale($previous);
        }

        try {
            $bot->sendMessage(text: $text, chat_id: $feedback->user->telegram_id);
            $feedback->update(['reply_delivered' => true]);
        } catch (TelegramException $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, 'chat not found') || str_contains($m, 'bot was blocked') || str_contains($m, 'deactivated')) {
                Log::info('[feedback-reply] mijozga yuborilmadi', ['feedback' => $feedback->id, 'reason' => $e->getMessage()]);
                $feedback->update(['reply_delivered' => false]);

                return;
            }
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Feedback::whereKey($this->feedbackId)->update(['reply_delivered' => false]);
    }
}
