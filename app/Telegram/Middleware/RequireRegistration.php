<?php

namespace App\Telegram\Middleware;

use App\Telegram\Conversations\RegistrationConversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Menyu amallari (buyurtma, manzillar, sozlamalar) faqat ro'yxatdan o'tgan
 * foydalanuvchi uchun. Aks holda — ro'yxatdan o'tish suhbatini boshlaydi.
 *
 * `ResolveUser` dan keyin ishlaydi: `$bot->get('user')` ni o'qiydi.
 */
class RequireRegistration
{
    public function __invoke(Nutgram $bot, callable $next): void
    {
        $user = $bot->get('user');

        if ($user === null || ! $user->profile_completed) {
            $bot->sendMessage(__('messages.welcome'));
            RegistrationConversation::begin($bot);

            return;
        }

        $next($bot);
    }
}
