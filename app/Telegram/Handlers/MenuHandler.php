<?php

namespace App\Telegram\Handlers;

use App\Services\Feedback\FeedbackService;
use App\Services\Feedback\PendingFeedbackStore;
use App\Services\Ordering\PendingRatingStore;
use App\Telegram\Support\Keyboards;
use App\Telegram\Support\RatingMessage;
use SergiX44\Nutgram\Nutgram;

/**
 * Matnli xabarlar dispetcheri (fallback).
 *
 * Tekshiruv tartibi:
 *   1. Menyu tugmasi (Reply Keyboard) — tegishli handlerga; reyting va
 *      fikr-mulohaza holatlari tugaydi.
 *   2. Kutilayotgan reyting izohi (PendingRatingStore) — menyu fallback'idan OLDIN.
 *      Holat bo'lsa: matn izoh sifatida saqlanadi, so'rov xabari yangilanadi, TO'XTAYDI.
 *   3. Kutilayotgan fikr-mulohaza matni (PendingFeedbackStore) — xuddi shu tartibda.
 *      Holat bo'lsa: matn feedbacks'ga saqlanadi, turi bo'yicha javob ketadi, TO'XTAYDI.
 *   4. Aks holda — asosiy menyu.
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

    public function __construct(
        private readonly PendingRatingStore $pendingRating,
        private readonly PendingFeedbackStore $pendingFeedback,
        private readonly FeedbackService $feedbacks,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $text = trim((string) $bot->message()?->text);
        $userId = (int) $bot->userId();

        // 1. Menyu tugmasi — reyting va fikr-mulohaza holatini tugatadi, tegishli handlerga.
        foreach (self::ACTIONS as $key => $handler) {
            if ($text !== '' && $text === __("messages.main_menu.{$key}")) {
                $this->pendingRating->forget($userId);
                $this->pendingFeedback->forget($userId);
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

        // 3. Kutilayotgan fikr-mulohaza matni.
        if ($text !== '') {
            $type = $this->pendingFeedback->pending($userId);

            if ($type !== null) {
                $this->pendingFeedback->forget($userId);
                $this->feedbacks->submit($bot->get('user'), $type, $text);
                $bot->sendMessage($type->thanksMessage());

                return;
            }
        }

        // 4. Aks holda — asosiy menyu.
        $bot->sendMessage(__('messages.main_menu.title'), reply_markup: Keyboards::mainMenu());
    }
}
