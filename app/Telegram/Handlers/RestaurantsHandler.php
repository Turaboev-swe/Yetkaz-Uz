<?php

namespace App\Telegram\Handlers;

use App\Telegram\Support\MiniApp;
use SergiX44\Nutgram\Nutgram;

/**
 * "🏪 Restoranlar" — Mini App'ni to'g'ridan-to'g'ri restoranlar ro'yxati
 * ekranida ochadi (`?screen=restaurants`). "🍿 Buyurtma berish" dan farqli:
 * qisqa xabar, boshqa ekran parametri.
 */
class RestaurantsHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $keyboard = MiniApp::button(
            __('messages.main_menu.restaurants_button'),
            ['screen' => 'restaurants'],
        );

        if ($keyboard === null) {
            $bot->sendMessage(__('messages.mini_app_unavailable'));

            return;
        }

        $bot->sendMessage(__('messages.main_menu.restaurants_intro'), reply_markup: $keyboard);
    }
}
