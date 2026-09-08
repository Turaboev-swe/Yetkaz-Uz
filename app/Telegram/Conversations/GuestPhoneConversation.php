<?php

namespace App\Telegram\Conversations;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\User\ProfileService;
use App\Telegram\Support\Keyboards;
use App\Telegram\Support\LastRestaurantStore;
use App\Telegram\Support\MiniApp;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Mehmon (QR / deep-link) telefon so'rovi.
 *
 * To'liq ro'yxatdan o'tishdan farqi:
 * - FAQAT telefon so'raladi (request_contact) — ism va lokatsiya emas
 * - ism Telegram profilidan avtomat olinadi (first_name + last_name)
 * - lokatsiya YO'Q — profile_completed manzilsiz tugatiladi
 * - oxirida foydalanuvchini o'zi ko'rayotgan restoran menyusiga qaytaruvchi
 *   WebApp tugmasi yuboriladi
 */
class GuestPhoneConversation extends Conversation
{
    protected ?string $step = 'askPhone';

    /** Qaysi restoran menyusidan kelgan (telefondan keyin o'sha yerga qaytariladi). */
    public ?int $restaurantId = null;

    public function askPhone(Nutgram $bot, ?int $restaurantId = null): void
    {
        $this->restaurantId = $restaurantId;

        // Ism Telegram profilidan — so'ralmaydi.
        $this->autofillName($bot);

        $bot->sendMessage(
            __('messages.guest_phone.ask'),
            reply_markup: Keyboards::requestPhone(),
        );

        $this->next('handlePhone');
    }

    public function handlePhone(Nutgram $bot): void
    {
        $contact = $bot->message()?->contact;

        if ($contact === null) {
            $bot->sendMessage(
                __('messages.registration.phone_must_use_button'),
                reply_markup: Keyboards::requestPhone(),
            );
            $this->next('handlePhone');

            return;
        }

        // request_contact doim o'z raqamini yuboradi, lekin attach-menu orqali
        // boshqasini ulashish mumkin — tekshiramiz.
        if ($contact->user_id !== null && $contact->user_id !== $bot->userId()) {
            $bot->sendMessage(
                __('messages.registration.phone_must_be_own'),
                reply_markup: Keyboards::requestPhone(),
            );
            $this->next('handlePhone');

            return;
        }

        $user = $this->user($bot);
        $profiles = $this->profiles();

        $profiles->saveContact($user, $contact->phone_number);
        $this->autofillName($bot);           // askPhone'da ism bo'lmagan bo'lsa — hozir
        $profiles->completeWithoutAddress($user);

        $bot->sendMessage(
            __('messages.guest_phone.done'),
            reply_markup: Keyboards::remove(),
        );

        $this->sendReturnButton($bot);

        $this->end();
    }

    /** Ismni Telegram profilidan bir marta to'ldiradi (bo'sh bo'lsa). */
    private function autofillName(Nutgram $bot): void
    {
        $user = $this->user($bot);

        if (filled($user->full_name)) {
            return;
        }

        $from = $bot->user();
        $name = trim(($from?->first_name ?? '').' '.($from?->last_name ?? ''));

        if ($name !== '') {
            $this->profiles()->saveName($user, mb_substr($name, 0, 60));
        }
    }

    /** Telefondan keyin: foydalanuvchini restoran menyusiga qaytaruvchi WebApp tugmasi. */
    private function sendReturnButton(Nutgram $bot): void
    {
        $restaurantId = $this->restaurantId
            ?? app(LastRestaurantStore::class)->get((int) $bot->userId());

        $restaurant = $restaurantId !== null
            ? Restaurant::query()->find($restaurantId)
            : null;

        $keyboard = MiniApp::button(
            $restaurant !== null
                ? __('messages.guest_phone.open_restaurant', ['name' => $restaurant->name])
                : __('messages.guest_phone.open_generic'),
            $restaurant !== null ? ['r' => $restaurant->id] : [],
        );

        if ($keyboard === null) {
            $bot->sendMessage(__('messages.main_menu.title'), reply_markup: Keyboards::mainMenu());

            return;
        }

        $bot->sendMessage(__('messages.main_menu.order_intro'), reply_markup: $keyboard);
    }

    private function profiles(): ProfileService
    {
        return app(ProfileService::class);
    }

    private function user(Nutgram $bot): User
    {
        return $bot->get('user')
            ?? $this->profiles()->findOrCreateFromTelegram(
                telegramId: $bot->userId(),
                languageCode: $bot->user()?->language_code,
                username: $bot->user()?->username,
            );
    }
}
