<?php

namespace App\Telegram\Handlers;

use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * Asosiy menyu dispetcheri (fallback) — Reply Keyboard tugmalari matnini
 * joriy tildagi lang qiymatlariga solishtirib, tegishli handlerga uzatadi.
 *
 * Ro'yxatdan o'tish tekshiruvi — RequireRegistration middleware (routes/telegram.php).
 * Lokatsiya (onLocation) va callback (onCallbackQueryData) alohida ro'yxatdan o'tgan.
 */
class MenuHandler
{
    /** menyu kaliti => handler klass */
    private const ACTIONS = [
        'order' => OrderHandler::class,
        'restaurants' => RestaurantsHandler::class,
        'addresses' => AddressesHandler::class,
        'feedback' => FeedbackHandler::class,
        'settings' => SettingsHandler::class,
    ];

    public function __invoke(Nutgram $bot): void
    {
        $text = trim((string) $bot->message()?->text);

        foreach (self::ACTIONS as $key => $handler) {
            if ($text !== '' && $text === __("messages.main_menu.{$key}")) {
                app($handler)($bot);

                return;
            }
        }

        $bot->sendMessage(__('messages.main_menu.title'), reply_markup: Keyboards::mainMenu());
    }
}
