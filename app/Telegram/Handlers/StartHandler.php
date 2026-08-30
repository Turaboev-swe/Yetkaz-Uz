<?php

namespace App\Telegram\Handlers;

use App\Services\User\ProfileService;
use App\Telegram\Conversations\RegistrationConversation;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * /start — kirish nuqtasi.
 *
 * - users jadvalidan telegram_id bo'yicha qidiradi, bo'lmasa yaratadi
 * - profil to'lgan bo'lsa: asosiy menyu
 * - to'lmagan bo'lsa: ro'yxatdan o'tish suhbati (telefon -> ism -> lokatsiya)
 */
class StartHandler
{
    public function __invoke(Nutgram $bot, ProfileService $profiles): void
    {
        $from = $bot->user();

        $user = $profiles->findOrCreateFromTelegram(
            telegramId: $from->id,
            languageCode: $from->language_code,
        );
        $profiles->touch($user);

        app()->setLocale($user->language);

        if ($user->profile_completed) {
            $bot->sendMessage(
                __('messages.welcome_back', ['name' => $user->full_name ?: $from->first_name]),
                reply_markup: Keyboards::mainMenu(),
            );

            return;
        }

        $bot->sendMessage(__('messages.welcome'));
        RegistrationConversation::begin($bot);
    }
}
