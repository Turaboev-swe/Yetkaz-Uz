<?php

namespace App\Telegram\Conversations\Concerns;

use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * Lokatsiyadan keyin geokoder ko'cha nomini topmagan holatda mijozdan ko'cha YOKI mo'ljalni so'rash.
 *
 * Oqim: «Ko'cha nomini yozing» -> matn = ko'cha (saqlanadi) | «Ko'cha nomini bilmayman» -> «Mo'ljalni yozing» -> matn = mo'ljal.
 * O'TKAZIB YUBORISH YO'Q: ko'cha yoki mo'ljaldan kamida bittasi majburiy (geokoder ko'cha topganda bu suhbat umuman boshlanmaydi).
 * Bekor qilish tugmasi faqat mavjud manzilga qo'shishda (canCancel); ro'yxatdan o'tishda lokatsiya majburiy.
 *
 * Suhbat holati (lat/lng) Redis'da saqlanadi. Ishlatuvchi sinf `finishAddress()` va `handleNewPoint()` ni amalga oshiradi.
 */
trait AsksForStreet
{
    public ?float $lat = null;

    public ?float $lng = null;

    /** Saqlashni yakunlaydi (ko'cha yoki mo'ljal bilan) va suhbatni tugatadi. */
    abstract protected function finishAddress(Nutgram $bot, ?string $street, ?string $landmark): void;

    /** Suhbat paytida yangi lokatsiya yuborildi — shu nuqta bilan qaytadan boshlanadi. */
    abstract protected function handleNewPoint(Nutgram $bot, float $lat, float $lng): void;

    /** Bekor qilish tugmasi bormi (mavjud manzilga qo'shishda — ha, ro'yxatdan o'tishda — yo'q). */
    protected function canCancel(): bool
    {
        return true;
    }

    protected function askStreet(Nutgram $bot): void
    {
        $bot->sendMessage(__('messages.address_flow.ask_street'), reply_markup: Keyboards::streetPrompt($this->canCancel()));
        $this->next('handleStreet');
    }

    protected function askLandmark(Nutgram $bot): void
    {
        $bot->sendMessage(
            __('messages.address_flow.ask_landmark'),
            reply_markup: $this->canCancel() ? Keyboards::cancelOnly() : Keyboards::remove(),
        );
        $this->next('handleLandmark');
    }

    public function handleStreet(Nutgram $bot): void
    {
        if ($this->interrupted($bot)) {
            return;
        }

        $text = $this->text($bot);

        if ($text === __('messages.address_flow.dont_know_street')) {
            $this->askLandmark($bot);

            return;
        }

        if (! $this->acceptable($text)) {
            $bot->sendMessage(__('messages.address_flow.too_short'), reply_markup: Keyboards::streetPrompt($this->canCancel()));
            $this->next('handleStreet');

            return;
        }

        $this->finishAddress($bot, mb_substr($text, 0, 255), null);
    }

    public function handleLandmark(Nutgram $bot): void
    {
        if ($this->interrupted($bot)) {
            return;
        }

        $text = $this->text($bot);

        if (! $this->acceptable($text)) {
            $bot->sendMessage(
                __('messages.address_flow.too_short'),
                reply_markup: $this->canCancel() ? Keyboards::cancelOnly() : Keyboards::remove(),
            );
            $this->next('handleLandmark');

            return;
        }

        $this->finishAddress($bot, null, mb_substr($text, 0, 500));
    }

    /** Yangi lokatsiya yoki bekor qilish — joriy qadamni to'xtatadi (true). */
    private function interrupted(Nutgram $bot): bool
    {
        $location = $bot->message()?->location;

        if ($location !== null) {
            $this->handleNewPoint($bot, $location->latitude, $location->longitude);

            return true;
        }

        if ($this->canCancel() && $this->text($bot) === __('messages.address_flow.cancel')) {
            $this->end();
            $bot->sendMessage(__('messages.address_flow.cancelled'), reply_markup: Keyboards::mainMenu());

            return true;
        }

        return false;
    }

    private function text(Nutgram $bot): string
    {
        return trim((string) $bot->message()?->text);
    }

    /** Kamida 3 belgi; buyruq (/start) ham, asosiy menyu tugmasi matni ham ko'cha/mo'ljal emas. */
    private function acceptable(string $text): bool
    {
        if (mb_strlen($text) < 3 || str_starts_with($text, '/')) {
            return false;
        }

        foreach (['uz', 'ru'] as $locale) {
            if (in_array($text, array_filter((array) trans('messages.main_menu', [], $locale), 'is_string'), true)) {
                return false;
            }
        }

        return true;
    }
}
