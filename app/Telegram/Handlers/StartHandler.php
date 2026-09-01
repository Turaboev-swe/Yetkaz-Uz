<?php

namespace App\Telegram\Handlers;

use App\Services\User\ProfileService;
use App\Telegram\Conversations\RegistrationConversation;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * /start — kirish nuqtasi.
 *
 * - profil to'lgan bo'lsa: salom + asosiy menyu (Reply Keyboard)
 * - to'lmagan bo'lsa: ro'yxatdan o'tish suhbati (telefon -> ism -> lokatsiya)
 *
 * Restoranlar ro'yxati botda EMAS — faqat Mini App ichida ("🍿 Buyurtma berish").
 */
class StartHandler
{
    public function __invoke(Nutgram $bot, ProfileService $profiles): void
    {
        $from = $bot->user();

        $user = $profiles->findOrCreateFromTelegram(
            telegramId: $from->id,
            languageCode: $from->language_code,
            username: $from->username,
        );

        app()->setLocale($user->language ?: 'uz');

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
