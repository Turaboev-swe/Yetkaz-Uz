<?php

namespace App\Telegram\Support;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

/**
 * Reply-klaviaturalar. request_contact / request_location faqat reply
 * klaviaturada ishlaydi (inline'da emas). Barcha matnlar lang fayllaridan.
 */
final class Keyboards
{
    public static function requestPhone(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make(
                __('messages.registration.ask_phone_button'),
                request_contact: true,
            ));
    }

    public static function requestLocation(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make(
                __('messages.registration.ask_location_button'),
                request_location: true,
            ));
    }

    /**
     * Asosiy menyu — 3 qator, 2 ustun.
     *
     *   🍿 Buyurtma berish    |  📍 Yangi manzil   (request_location)
     *   🏪 Restoranlar        |  📌 Manzillarim
     *   💬 Taklif va shikoyat |  ⚙️ Sozlamalar
     *
     * "📍 Yangi manzil" — YANGI manzil qo'shish uchun (har buyurtmada emas;
     * saqlangan manzillar baribir ishlatiladi).
     */
    public static function mainMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(
                KeyboardButton::make(__('messages.main_menu.order')),
                KeyboardButton::make(__('messages.main_menu.new_address'), request_location: true),
            )
            ->addRow(
                KeyboardButton::make(__('messages.main_menu.restaurants')),
                KeyboardButton::make(__('messages.main_menu.addresses')),
            )
            ->addRow(
                KeyboardButton::make(__('messages.main_menu.feedback')),
                KeyboardButton::make(__('messages.main_menu.settings')),
            );
    }

    /** Til tanlash — Sozlamalar bo'limida (inline). */
    public static function languageChoice(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(__('messages.settings.lang_uz'), callback_data: 'lang:uz'),
            InlineKeyboardButton::make(__('messages.settings.lang_ru'), callback_data: 'lang:ru'),
        );
    }

    public static function remove(): ReplyKeyboardRemove
    {
        return ReplyKeyboardRemove::make(remove_keyboard: true);
    }
}
