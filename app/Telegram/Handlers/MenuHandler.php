<?php

namespace App\Telegram\Handlers;

use App\Services\Ordering\PendingRatingStore;
use App\Telegram\Support\Keyboards;
use App\Telegram\Support\RatingMessage;
use SergiX44\Nutgram\Nutgram;

/**
 * Matnli xabarlar dispetcheri (fallback).
 *
 * Tekshiruv tartibi:
 *   1. Menyu tugmasi (Reply Keyboard) — tegishli handlerga; reyting holati tugaydi.
 *   2. Kutilayotgan reyting izohi (PendingRatingStore) — menyu fallback'idan OLDIN.
 *      Holat bo'lsa: matn izoh sifatida saqlanadi, so'rov xabari yangilanadi, TO'XTAYDI.
 *   3. Aks holda — asosiy menyu.
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
        $userId = (int) $bot->userId();

        // 1. Menyu tugmasi — reyting holatini tugatadi, tegishli handlerga.
        foreach (self::ACTIONS as $key => $handler) {
            if ($text !== '' && $text === __("messages.main_menu.{$key}")) {
                $this->pendingRating->forget($userId);
                app($handler)($bot);

                return;
            }
        }

        // 2. Kutilayotgan reyting izohi — boshqa har qanday matn logikasidan OLDIN.
        if ($text !== '') {
            $pending = $this->pendingRating->pending($userId);
            $order = $this->pendingRating->storeComment($userId, $text);

            if ($order !== null) {
                RatingMessage::refresh($bot, $order, $userId, $pending['message_id'] ?? null);

                return;
            }
        }

        // 3. Aks holda — asosiy menyu.
        $bot->sendMessage(__('messages.main_menu.title'), reply_markup: Keyboards::mainMenu());
    }
}
