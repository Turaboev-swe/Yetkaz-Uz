<?php

namespace App\Telegram\Support;

use App\Models\Order;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Baholash xabari va tugmalari.
 *
 *   rate:{orderId}:{1-5}   — yulduzcha bosildi
 *
 * Izoh alohida tugma emas: foydalanuvchi shunchaki matn yozib yuboradi
 * (PendingRatingStore holati orqali ushlanadi).
 */
final class RatingMessage
{
    public static function stars(int $n): string
    {
        return str_repeat('⭐️', max(0, min(5, $n)));
    }

    /** So'rov: 1–5 yulduzcha qatori. */
    public static function askKeyboard(int $orderId): InlineKeyboardMarkup
    {
        $row = [];
        foreach (range(1, 5) as $star) {
            $row[] = InlineKeyboardButton::make((string) $star, callback_data: "rate:{$orderId}:{$star}");
        }

        return InlineKeyboardMarkup::make()->addRow(...$row);
    }

    public static function askText(Order $order): string
    {
        return __('messages.rating.ask', ['n' => $order->order_number]);
    }

    public static function thanksText(Order $order): string
    {
        return __('messages.rating.thanks', ['stars' => self::stars((int) $order->rating)]);
    }
}
