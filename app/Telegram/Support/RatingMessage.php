<?php

namespace App\Telegram\Support;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Baholash xabari va tugmalari.
 *
 *   rate:{orderId}:{1-5}   — yulduzcha bosildi
 *
 * Izoh alohida tugma emas: foydalanuvchi shunchaki matn yozib yuboradi
 * (PendingRatingStore holati orqali ushlanadi). So'rov xabari yulduzcha yoki izoh
 * kelganda joriy holatga yangilanadi.
 */
final class RatingMessage
{
    public static function stars(int $n): string
    {
        return str_repeat('⭐️', max(0, min(5, $n)));
    }

    /** So'rov matni: 1–5 yulduzchali tugmalar. */
    public static function askText(Order $order): string
    {
        return __('messages.rating.ask', ['n' => $order->order_number]);
    }

    /**
     * Yulduzcha tugmalari — har biri o'z bahosicha yulduzcha ko'rsatadi,
     * har tugma ALOHIDA qatorda (vertikal).
     */
    public static function askKeyboard(int $orderId): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach (range(1, 5) as $star) {
            $keyboard->addRow(InlineKeyboardButton::make(self::stars($star), callback_data: "rate:{$orderId}:{$star}"));
        }

        return $keyboard;
    }

    /** So'rov xabarining joriy holati (yulduzcha va/yoki izoh). */
    public static function resultText(Order $order): string
    {
        $rating = $order->rating;
        $comment = trim((string) $order->rating_comment);

        if ($rating !== null && $comment !== '') {
            return __('messages.rating.thanks_both', ['stars' => self::stars($rating), 'comment' => $comment]);
        }

        if ($rating !== null) {
            return __('messages.rating.thanks_stars', ['stars' => self::stars($rating)]);
        }

        return __('messages.rating.thanks_comment');
    }

    /** Yulduzcha hali qo'yilmagan bo'lsa tugmalar qoladi; qo'yilgach — olib tashlanadi. */
    public static function resultKeyboard(Order $order): ?InlineKeyboardMarkup
    {
        return $order->rating === null ? self::askKeyboard($order->id) : null;
    }

    /**
     * So'rov xabarini joriy holatga yangilaydi. Callback kontekstida
     * ($chatId/$messageId null) Nutgram xabarni o'zi aniqlaydi.
     */
    public static function refresh(Nutgram $bot, Order $order, ?int $chatId = null, ?int $messageId = null): void
    {
        try {
            $bot->editMessageText(
                text: self::resultText($order),
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: self::resultKeyboard($order),
            );
        } catch (TelegramException $e) {
            Log::info('[rating] so\'rov xabari yangilanmadi', [
                'order' => $order->order_number,
                'reason' => $e->getMessage(),
            ]);

            // Xabarni topib bo'lmadi (juda eski) — yangi tasdiqni yuboramiz.
            if ($chatId !== null) {
                try {
                    $bot->sendMessage(text: self::resultText($order), chat_id: $chatId, reply_markup: self::resultKeyboard($order));
                } catch (TelegramException) {
                    // e'tiborsiz
                }
            }
        }
    }
}
