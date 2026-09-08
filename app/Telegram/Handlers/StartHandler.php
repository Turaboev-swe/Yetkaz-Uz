<?php

namespace App\Telegram\Handlers;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\User\ProfileService;
use App\Telegram\Conversations\GuestPhoneConversation;
use App\Telegram\Conversations\RegistrationConversation;
use App\Telegram\Support\Keyboards;
use App\Telegram\Support\LastRestaurantStore;
use App\Telegram\Support\MiniApp;
use SergiX44\Nutgram\Nutgram;

/**
 * /start — kirish nuqtasi.
 *
 * Chuqur havolasiz (`/start`):
 * - profil to'lgan bo'lsa: salom + asosiy menyu
 * - to'lmagan bo'lsa: to'liq ro'yxatdan o'tish suhbati (telefon -> ism -> lokatsiya)
 *
 * Chuqur havola bilan:
 * - `?start=r_{id}` — QR / havola orqali restoran menyusi.
 *     ro'yxatdan o'tgan  -> Mini App menyu (?r=id)
 *     mehmon             -> Mini App MEHMON menyu (?r=id&guest=1), to'liq ro'yxat YO'Q
 * - `?start=phone` / `?start=phone_r_{id}` — Mini App checkout'dan telefon uchun
 *     qaytgan foydalanuvchi. FAQAT telefon so'raladi (GuestPhoneConversation).
 *
 * Restoranlar ro'yxati botda EMAS — faqat Mini App ichida.
 */
class StartHandler
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly LastRestaurantStore $lastRestaurant,
    ) {}

    public function __invoke(Nutgram $bot, ?string $ref = null): void
    {
        $from = $bot->user();

        $user = $this->profiles->findOrCreateFromTelegram(
            telegramId: $from->id,
            languageCode: $from->language_code,
            username: $from->username,
        );

        app()->setLocale($user->language ?: 'uz');

        $payload = $this->parsePayload($ref);

        if ($payload['restaurant_id'] !== null) {
            $this->lastRestaurant->remember($from->id, $payload['restaurant_id']);
        }

        // 1. Mini App'dan telefon uchun qaytgan foydalanuvchi.
        if ($payload['phone']) {
            $this->handlePhoneReturn($bot, $user, $from, $payload['restaurant_id']);

            return;
        }

        // 2. Restoran chuqur havolasi: ?start=r_{id}
        if ($payload['restaurant_id'] !== null) {
            if ($user->profile_completed) {
                $this->openMenu($bot, $payload['restaurant_id'], guest: false);
            } else {
                // Mehmon: to'liq ro'yxatdan o'tkazMAYmiz — menyu ochiladi.
                $this->openMenu($bot, $payload['restaurant_id'], guest: true);
            }

            return;
        }

        // 3. Oddiy /start — o'zgarishsiz.
        if ($user->profile_completed) {
            $bot->sendMessage(
                __('messages.welcome_back', ['name' => $user->full_name ?: $from->first_name]),
                reply_markup: Keyboards::mainMenu(),
            );

            return;
        }

        $bot->sendMessage(__('messages.welcome'));
        RegistrationConversation::begin($bot);
    }

    private function handlePhoneReturn(Nutgram $bot, User $user, $from, ?int $restaurantId): void
    {
        // Allaqachon to'liq — hech qachon qayta so'ramaymiz, to'g'ridan-to'g'ri menyuga.
        if ($user->profile_completed && filled($user->phone)) {
            $this->openMenu($bot, $restaurantId ?? $this->lastRestaurant->get($from->id), guest: false);

            return;
        }

        $bot->sendMessage(__('messages.welcome'));
        GuestPhoneConversation::begin($bot, data: ['restaurantId' => $restaurantId]);
    }

    private function openMenu(Nutgram $bot, ?int $restaurantId, bool $guest): void
    {
        $restaurant = $restaurantId !== null
            ? Restaurant::query()->find($restaurantId)
            : null;

        $params = [];
        if ($restaurant !== null) {
            $params['r'] = $restaurant->id;
        }
        if ($guest) {
            $params['guest'] = 1;
        }

        $keyboard = MiniApp::button(
            $restaurant !== null
                ? __('messages.guest_phone.open_restaurant', ['name' => $restaurant->name])
                : __('messages.guest_phone.open_generic'),
            $params,
        );

        if ($keyboard === null) {
            $bot->sendMessage(__('messages.mini_app_unavailable'));

            return;
        }

        $bot->sendMessage(__('messages.main_menu.order_intro'), reply_markup: $keyboard);
    }

    /**
     * @return array{phone: bool, restaurant_id: int|null}
     */
    private function parsePayload(?string $ref): array
    {
        $ref = trim((string) $ref);
        $out = ['phone' => false, 'restaurant_id' => null];

        if ($ref === '') {
            return $out;
        }

        if ($ref === 'phone') {
            $out['phone'] = true;
        } elseif (preg_match('/^phone_r_(\d+)$/', $ref, $m)) {
            $out['phone'] = true;
            $out['restaurant_id'] = (int) $m[1];
        } elseif (preg_match('/^r_(\d+)$/', $ref, $m)) {
            $out['restaurant_id'] = (int) $m[1];
        }

        return $out;
    }
}
