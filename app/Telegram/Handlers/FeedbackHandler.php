<?php

namespace App\Telegram\Handlers;

use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * "💬 Taklif va shikoyat" — tur tanlashni so'raydi (inline). Matnning o'zi
 * FeedbackTypeHandler orqali tanlangan tur bo'yicha keyingi matn xabaridan
 * (MenuHandler + PendingFeedbackStore) olinadi.
 */
class FeedbackHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            __('messages.feedback.choose_type'),
            reply_markup: Keyboards::feedbackType(),
        );
    }
}
