<?php

namespace App\Support;

/**
 * Loglar / xato xabarlaridagi sirlarni yashiradi (PROD-4).
 *
 * Asosiy holat: Telegram bot tokeni so'rov URL'ida bo'ladi
 * (`api.telegram.org/bot<token>/...`) va Guzzle cURL xato xabariga tushadi,
 * u esa `docker compose logs bot` ga chiqadi.
 */
final class SecretRedactor
{
    /** `bot<id>:<secret>` — Telegram token formati (URL ichida ham). */
    private const TOKEN_PATTERN = '#\bbot(\d{5,})[:/][A-Za-z0-9_-]{20,}#';

    public static function text(string $value): string
    {
        $value = (string) preg_replace(self::TOKEN_PATTERN, 'bot$1:[REDACTED]', $value);

        // Sozlangan aniq token bare holatda (`bot` prefiksisiz) uchrasa ham.
        $token = self::configuredToken();
        if (strlen($token) >= 12) {
            $value = str_replace($token, '[REDACTED]', $value);
        }

        return $value;
    }

    /** Laravel konteynerisiz (toza unit test) ham xavfsiz ishlaydi. */
    private static function configuredToken(): string
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return '';
        }

        return trim((string) config('nutgram.token'));
    }

    /**
     * Massiv qiymatlarини rekursiv tozalaydi (log context / extra uchun).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::text($value);
            } elseif (is_array($value)) {
                $data[$key] = self::array($value);
            }
        }

        return $data;
    }
}
