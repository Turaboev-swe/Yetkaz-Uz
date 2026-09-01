<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;

/**
 * "💬 Taklif va shikoyat" — hali tayyor emas. Aniq matn qoldiriladi
 * (soxta "tez orada ishlaydi" javob emas).
 */
class FeedbackHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(__('messages.feedback.not_ready'));
    }
}
