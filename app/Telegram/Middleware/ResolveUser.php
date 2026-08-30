<?php

namespace App\Telegram\Middleware;

use App\Models\User;
use SergiX44\Nutgram\Nutgram;

/**
 * Har bir update oldidan: foydalanuvchini telegram_id bo'yicha topadi (bo'lsa),
 * interfeys tilini o'rnatadi va foydalanuvchini `$bot->get('user')` ga qo'yadi.
 */
class ResolveUser
{
    public function __invoke(Nutgram $bot, callable $next): void
    {
        $from = $bot->user();

        $user = $from !== null
            ? User::byTelegramId($from->id)->first()
            : null;

        $locale = $user?->language
            ?? (str_starts_with(strtolower($from?->language_code ?? ''), 'ru') ? 'ru' : 'uz');

        app()->setLocale($locale);

        if ($user !== null) {
            $bot->set('user', $user);
        }

        $next($bot);
    }
}
