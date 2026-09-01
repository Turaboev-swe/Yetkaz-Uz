<?php

namespace App\Telegram\Support;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

/**
 * Mini App (Telegram Web App) — URL va WebApp inline tugmasi.
 *
 * Bazaviy URL: config('telegram.mini_app_url') (TELEGRAM_MINI_APP_URL).
 * Sozlanmagan bo'lsa null qaytadi — chaqiruvchi matn bilan cheklanadi.
 *
 * Ekran/kontekst URL query orqali uzatiladi (`?screen=restaurants`, `?r=12`) —
 * Mini App uni window.location dan o'qiydi.
 */
final class MiniApp
{
    public static function isConfigured(): bool
    {
        return trim((string) config('telegram.mini_app_url')) !== '';
    }

    /** @param array<string, string|int> $params */
    public static function url(array $params = []): ?string
    {
        $base = trim((string) config('telegram.mini_app_url'));

        if ($base === '') {
            return null;
        }

        if ($params === []) {
            return $base;
        }

        $sep = str_contains($base, '?') ? '&' : '?';

        return $base.$sep.http_build_query($params);
    }

    /**
     * Bitta WebApp tugmali inline klaviatura.
     *
     * @param  array<string, string|int>  $params
     */
    public static function button(string $text, array $params = []): ?InlineKeyboardMarkup
    {
        $url = self::url($params);

        if ($url === null) {
            return null;
        }

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($text, web_app: WebAppInfo::make(url: $url)),
        );
    }
}
