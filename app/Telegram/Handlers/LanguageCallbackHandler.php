<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * `lang:uz` / `lang:ru` callback — interfeys tilini o'zgartiradi.
 */
class LanguageCallbackHandler
{
    public function __invoke(Nutgram $bot, string $code): void
    {
        if (! in_array($code, User::LANGUAGES, true)) {
            $bot->answerCallbackQuery();

            return;
        }

        /** @var User $user */
        $user = $bot->get('user');
        $user->update(['language' => $code]);
        app()->setLocale($code);

        $bot->answerCallbackQuery(text: __('messages.settings.language_changed'));
        $bot->sendMessage(
            __('messages.settings.language_changed'),
            reply_markup: Keyboards::mainMenu(),
        );
    }
}
