<?php

namespace App\Telegram\Handlers;

use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * "⚙️ Sozlamalar" — hozircha til tanlash.
 */
class SettingsHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            __('messages.settings.choose_language'),
            reply_markup: Keyboards::languageChoice(),
        );
    }
}
