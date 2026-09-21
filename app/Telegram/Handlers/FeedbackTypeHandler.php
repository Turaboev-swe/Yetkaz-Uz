<?php

namespace App\Telegram\Handlers;

use App\Enums\FeedbackType;
use App\Services\Feedback\PendingFeedbackStore;
use SergiX44\Nutgram\Nutgram;

/**
 * `feedback:{type}` — tur tugmasi bosildi. Holatni eslab qoladi
 * (PendingFeedbackStore) va matn so'raydi; javob keyingi matn xabarida
 * MenuHandler orqali ushlanadi (PendingRatingStore bilan bir xil naqsh).
 */
class FeedbackTypeHandler
{
    public function __construct(private readonly PendingFeedbackStore $pending) {}

    public function __invoke(Nutgram $bot, string $type): void
    {
        $feedbackType = FeedbackType::tryFrom($type);

        if ($feedbackType === null) {
            $bot->answerCallbackQuery();

            return;
        }

        $this->pending->remember($bot->userId(), $feedbackType);

        $bot->answerCallbackQuery();
        $bot->editMessageText(
            text: $feedbackType->icon().' '.$feedbackType->label()."\n\n".__('messages.feedback.ask_message'),
        );
    }
}
