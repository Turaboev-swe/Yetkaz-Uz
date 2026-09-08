<?php

namespace App\Telegram\Handlers;

use App\Services\Ordering\PendingRatingStore;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * Asosiy menyu dispetcheri (fallback) — Reply Keyboard tugmalari matnini
 * joriy tildagi lang qiymatlariga solishtirib, tegishli handlerga uzatadi.
 *
 * Menyu tugmasi bo'lmagan oddiy matn — agar foydalanuvchida "kutilayotgan
 * reyting" holati bo'lsa (PendingRatingStore) — buyurtma izohi sifatida saqlanadi.
 * Menyu tugmasi bosilса holat tugaydi.
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

    public function __construct(private readonly PendingRatingStore $pendingRating) {}

    public function __invoke(Nutgram $bot): void
    {
        $text = trim((string) $bot->message()?->text);

        foreach (self::ACTIONS as $key => $handler) {
            if ($text !== '' && $text === __("messages.main_menu.{$key}")) {
                $this->pendingRating->forget((int) $bot->userId());
                app($handler)($bot);

                return;
            }
        }

        // Menyu tugmasi emas — kutilayotgan reyting izohi bo'lishi mumkin.
        if ($text !== '' && $this->pendingRating->storeComment((int) $bot->userId(), $text) !== null) {
            $bot->sendMessage(__('messages.rating.comment_saved'));

            return;
        }

        $bot->sendMessage(__('messages.main_menu.title'), reply_markup: Keyboards::mainMenu());
    }
}
