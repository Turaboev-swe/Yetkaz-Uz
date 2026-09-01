<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Services\User\ProfileService;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Ro'yxatdan o'tish suhbati: telefon -> ism -> lokatsiya.
 *
 * Holat Redis'da saqlanadi (Nutgram conversation cache = Laravel cache = redis).
 * Har qadam ma'lumoti darhol bazaga yoziladi, shuning uchun suhbat obyektida
 * faqat `step` ko'rsatkichi saqlanadi.
 *
 * Biznes qoidalari (Claude.md):
 * - telefon faqat request_contact tugmasi orqali
 * - ism/telefon/lokatsiya bir marta so'raladi
 * - lokatsiya "Uy" yorlig'i, is_default = true bilan saqlanadi
 */
class RegistrationConversation extends Conversation
{
    protected ?string $step = 'askPhone';

    public function askPhone(Nutgram $bot): void
    {
        $bot->sendMessage(
            __('messages.registration.ask_phone'),
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

        // request_contact tugmasi doim foydalanuvchining o'z raqamini yuboradi,
        // lekin attach-menu orqali boshqasini ulashish mumkin — tekshiramiz.
        if ($contact->user_id !== null && $contact->user_id !== $bot->userId()) {
            $bot->sendMessage(
                __('messages.registration.phone_must_be_own'),
                reply_markup: Keyboards::requestPhone(),
            );
            $this->next('handlePhone');

            return;
        }

        $this->profiles()->saveContact($this->user($bot), $contact->phone_number);

        $bot->sendMessage(
            __('messages.registration.ask_name'),
            reply_markup: Keyboards::remove(),
        );
        $this->next('handleName');
    }

    public function handleName(Nutgram $bot): void
    {
        $name = trim((string) $bot->message()?->text);

        if (mb_strlen($name) < 2 || str_starts_with($name, '/')) {
            $bot->sendMessage(__('messages.registration.name_too_short'));
            $this->next('handleName');

            return;
        }

        $this->profiles()->saveName($this->user($bot), mb_substr($name, 0, 60));

        $bot->sendMessage(
            __('messages.registration.ask_location'),
            reply_markup: Keyboards::requestLocation(),
        );
        $this->next('handleLocation');
    }

    public function handleLocation(Nutgram $bot): void
    {
        $location = $bot->message()?->location;

        if ($location === null) {
            $bot->sendMessage(
                __('messages.registration.location_must_use_button'),
                reply_markup: Keyboards::requestLocation(),
            );
            $this->next('handleLocation');

            return;
        }

        $this->profiles()->completeWithHomeAddress(
            user: $this->user($bot),
            lat: $location->latitude,
            lng: $location->longitude,
        );

        $bot->sendMessage(
            __('messages.registration.done'),
            reply_markup: Keyboards::remove(),
        );
        $bot->sendMessage(
            __('messages.main_menu.title'),
            reply_markup: Keyboards::mainMenu(),
        );

        $this->end();
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
