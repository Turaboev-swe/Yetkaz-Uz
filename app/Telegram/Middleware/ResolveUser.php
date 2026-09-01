<?php

namespace App\Telegram\Middleware;

use App\Models\User;
use App\Services\User\ProfileService;
use SergiX44\Nutgram\Nutgram;

/**
 * Har bir update oldidan: foydalanuvchini telegram_id bo'yicha topadi (bo'lsa),
 * interfeys tilini o'rnatadi, `last_seen_at` ni yangilaydi va foydalanuvchini
 * `$bot->get('user')` ga qo'yadi.
 */
class ResolveUser
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function __invoke(Nutgram $bot, callable $next): void
    {
        $from = $bot->user();

        $user = $from !== null
            ? User::byTelegramId($from->id)->first()
            : null;

        // Standart til — o'zbekcha. Faqat foydalanuvchi Sozlamalarda o'zgartirsa boshqasi.
        app()->setLocale($user?->language ?: 'uz');

        if ($user !== null) {
            $this->profiles->touch($user);

            // @username o'zgargan bo'lsa yangilab qo'yamiz (buyurtma bildirishnomasi uchun).
            if ($from->username !== null && $from->username !== $user->username) {
                $user->update(['username' => $from->username]);
            }

            $bot->set('user', $user);
        }

        $next($bot);
    }
}
