<?php

namespace App\Telegram\Handlers;

use App\Services\User\ProfileService;
use App\Telegram\Conversations\RegistrationConversation;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * Buyruq bo'lmagan xabarlar uchun zaxira handler.
 *
 * - profil to'lmagan bo'lsa: ro'yxatdan o'tishga qaytaradi
 * - to'lgan bo'lsa: asosiy menyu tugmalariga javob beradi (hozircha "tez orada")
 *
 * 3-bosqichdan boshlab bu handler taom qidiruvi va restoran ro'yxatiga ulanadi.
 */
class MenuHandler
{
    public function __invoke(Nutgram $bot, ProfileService $profiles): void
    {
        $from = $bot->user();

        if ($from === null) {
            return;
        }

        $user = $profiles->findOrCreateFromTelegram($from->id, $from->language_code);
        $profiles->touch($user);
        app()->setLocale($user->language ?: 'uz');

        if (! $user->profile_completed) {
            $bot->sendMessage(__('messages.welcome'));
            RegistrationConversation::begin($bot);

            return;
        }

        $text = trim((string) $bot->message()?->text);

        $known = [
            __('messages.main_menu.search'),
            __('messages.main_menu.restaurants'),
            __('messages.main_menu.orders'),
            __('messages.main_menu.settings'),
        ];

        if (in_array($text, $known, true)) {
            $bot->sendMessage(__('messages.main_menu.coming_soon'));

            return;
        }

        $bot->sendMessage(
            __('messages.main_menu.title'),
            reply_markup: Keyboards::mainMenu(),
        );
    }
}
