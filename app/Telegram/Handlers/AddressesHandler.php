<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * "📌 Manzillarim" — saqlangan manzillar ro'yxati (matn).
 * Yangi manzil qo'shish — "📍 Yangi manzil" tugmasi orqali.
 * To'liq boshqaruv (tahrir/o'chirish) — Mini App'da (keyingi bosqich).
 */
class AddressesHandler
{
    public function __invoke(Nutgram $bot): void
    {
        /** @var User $user */
        $user = $bot->get('user');

        $addresses = $user->addresses()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        if ($addresses->isEmpty()) {
            $bot->sendMessage(__('messages.addresses.empty'));

            return;
        }

        $lines = $addresses->map(function ($a) {
            $marker = $a->is_default ? __('messages.addresses.default_marker') : '';

            return '• '.trim($a->label.' — '.$a->address_text).$marker;
        })->implode("\n");

        $bot->sendMessage(
            __('messages.addresses.title')."\n\n".$lines."\n\n".__('messages.addresses.hint'),
        );
    }
}
