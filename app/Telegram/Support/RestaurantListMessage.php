<?php

namespace App\Telegram\Support;

use App\Models\User;
use App\Services\Delivery\RestaurantFinder;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

/**
 * "Buyurtma berish" dan OLDIN chiqadigan restoranlar ro'yxati.
 *
 * Har restoran — alohida inline tugma; bosilganda Mini App o'sha restoran
 * menyusida ochiladi (URL'ga `?r={id}` qo'shiladi). Oxirida "Barcha restoranlar"
 * tugmasi — Mini App'ni ro'yxat ekranida ochadi.
 *
 * Mini App URL sozlanmagan bo'lsa (TELEGRAM_MINI_APP_URL bo'sh) — null qaytadi,
 * chaqiruvchi oddiy matn bilan cheklanadi.
 */
final class RestaurantListMessage
{
    public static function keyboard(User $user): ?InlineKeyboardMarkup
    {
        $base = trim((string) config('telegram.mini_app_url'));

        if ($base === '') {
            return null;
        }

        $address = $user->addresses()->orderByDesc('is_default')->orderBy('id')->first();

        if ($address === null) {
            return null;
        }

        $restaurants = app(RestaurantFinder::class)
            ->deliveringTo($address, includeClosed: true);

        $keyboard = InlineKeyboardMarkup::make();
        $rowsAdded = 0;

        foreach ($restaurants as $restaurant) {
            $open = $restaurant->isOpenNow();
            $label = ($open ? '🍽 ' : '🔒 ').$restaurant->name;

            if ($open) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    text: $label,
                    web_app: WebAppInfo::make(url: self::url($base, ['r' => $restaurant->id])),
                ));
                $rowsAdded++;
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make(
            text: __('messages.restaurants.open_app'),
            web_app: WebAppInfo::make(url: $base),
        ));

        return $rowsAdded > 0 || $restaurants->isNotEmpty() ? $keyboard : null;
    }

    /** Bazaviy URL'ga query parametrlarini xavfsiz qo'shadi. */
    private static function url(string $base, array $params): string
    {
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base.$sep.http_build_query($params);
    }
}
