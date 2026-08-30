<?php

namespace App\Telegram\Support;

use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

/**
 * Reply-klaviaturalar. request_contact / request_location faqat reply
 * klaviaturada ishlaydi (inline'da emas).
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

    public static function mainMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(
                KeyboardButton::make(__('messages.main_menu.search')),
            )
            ->addRow(
                KeyboardButton::make(__('messages.main_menu.restaurants')),
                KeyboardButton::make(__('messages.main_menu.orders')),
            )
            ->addRow(
                KeyboardButton::make(__('messages.main_menu.settings')),
            );
    }

    public static function remove(): ReplyKeyboardRemove
    {
        return ReplyKeyboardRemove::make(remove_keyboard: true);
    }
}
