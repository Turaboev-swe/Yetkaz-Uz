<?php

namespace App\Telegram\Handlers;

use App\Telegram\Support\MiniApp;
use SergiX44\Nutgram\Nutgram;

/**
 * "🍿 Buyurtma berish" — Mini App'ni ochadigan inline WebApp tugmasi bilan
 * yo'riqnoma xabari. Mini App kirish ekrani — restoranlar ro'yxati.
 */
class OrderHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $keyboard = MiniApp::button(__('messages.main_menu.order_button'));

        if ($keyboard === null) {
            $bot->sendMessage(__('messages.mini_app_unavailable'));

            return;
        }

        $bot->sendMessage(__('messages.main_menu.order_intro'), reply_markup: $keyboard);
    }
}
