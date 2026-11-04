<?php

namespace App\Telegram\Conversations;

use App\Exceptions\StreetRequiredException;
use App\Models\User;
use App\Services\Geo\AddressGeocoder;
use App\Services\User\ProfileService;
use App\Telegram\Conversations\Concerns\AsksForStreet;
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
 * - geokoder ko'cha nomini topmasa ko'cha YOKI mo'ljal so'raladi (AsksForStreet; o'tkazib yuborib bo'lmaydi)
 */
class RegistrationConversation extends Conversation
{
    use AsksForStreet;

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

        $this->handleNewPoint($bot, $location->latitude, $location->longitude);
    }

    /** Lokatsiya (yoki suhbat paytida qayta yuborilgan lokatsiya): ko'cha topilsa savolsiz, topilmasa so'rab saqlanadi. */
    protected function handleNewPoint(Nutgram $bot, float $lat, float $lng): void
    {
        $this->lat = $lat;
        $this->lng = $lng;

        if (app(AddressGeocoder::class)->describe($lat, $lng)['street'] !== null) {
            $this->finishAddress($bot, null, null);

            return;
        }

        $this->askStreet($bot);
    }

    /** Ro'yxatdan o'tishda lokatsiya majburiy — bekor qilish tugmasi yo'q. */
    protected function canCancel(): bool
    {
        return false;
    }

    protected function finishAddress(Nutgram $bot, ?string $street, ?string $landmark): void
    {
        try {
            $this->profiles()->completeWithHomeAddress(
                user: $this->user($bot),
                lat: (float) $this->lat,
                lng: (float) $this->lng,
                street: $street,
                landmark: $landmark,
            );
        } catch (StreetRequiredException) {
            $this->askStreet($bot);

            return;
        }

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
