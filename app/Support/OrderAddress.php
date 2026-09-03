<?php

namespace App\Support;

/**
 * `orders.address_snapshot` dan o'qiladigan manzil satri.
 *
 * `address_text` xom koordinata bo'lishi mumkin (ro'yxatdan o'tishда geokodlash
 * ishlamasa `ProfileService::coordsAsText` shunday saqlaydi). Bunday holда
 * xabarларда koordinata emas — tuman nomi, keyin manzil yorlig'i ("Uy") ko'rsatiladi.
 * Koordinata faqat xarita havolasi uchun.
 */
final class OrderAddress
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function line(?array $snapshot): ?string
    {
        $snap = $snapshot ?? [];
        $text = trim((string) ($snap['address_text'] ?? ''));
        $district = trim((string) ($snap['district'] ?? ''));

        if ($text !== '' && ! self::looksLikeCoordinates($text)) {
            return $district !== '' && ! str_contains($text, $district)
                ? "{$text}, {$district}"
                : $text;
        }

        // Xom koordinata yoki bo'sh — tuman, keyin yorliq.
        foreach ([$district, trim((string) ($snap['label'] ?? ''))] as $fallback) {
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * Kirish / qavat / xonadon — bo'lsa "kirish 2 · qavat 3 · xonadon 12".
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function extra(?array $snapshot): ?string
    {
        $snap = $snapshot ?? [];

        $parts = array_filter([
            filled($snap['entrance'] ?? null) ? 'kirish '.$snap['entrance'] : null,
            filled($snap['floor'] ?? null) ? 'qavat '.$snap['floor'] : null,
            filled($snap['apartment'] ?? null) ? 'xonadon '.$snap['apartment'] : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Xaritada ko'rish havolasi (lat/lng bo'lsa) — telefon Google Maps ilovasini ochadi.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function mapUrl(?array $snapshot): ?string
    {
        $snap = $snapshot ?? [];

        if (! isset($snap['lat'], $snap['lng']) || ! is_numeric($snap['lat']) || ! is_numeric($snap['lng'])) {
            return null;
        }

        return sprintf('https://maps.google.com/?q=%.6f,%.6f', (float) $snap['lat'], (float) $snap['lng']);
    }

    private static function looksLikeCoordinates(string $text): bool
    {
        return (bool) preg_match('/^\s*-?\d{1,3}(?:\.\d+)?\s*,\s*-?\d{1,3}(?:\.\d+)?\s*$/', $text);
    }
}
