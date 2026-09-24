<?php

namespace App\Support;

/**
 * O'zbekiston telefon raqamini tekshirish/normallashtirish — OPERATSION
 * ma'lumot uchun (masalan, Royal Taxi haydovchisi). Mijozning o'z raqami
 * faqat `request_contact` orqali olinadi (Claude.md) — bu qoida BUNGA
 * tegishli emas, chunki bu yerda xodim boshqa birovning raqamini yozadi.
 */
final class Phone
{
    /** @return string|null +998XXXXXXXXX (12 raqam) — yaroqsiz bo'lsa null. */
    public static function normalizeUzbek(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (! str_starts_with($digits, '998') || strlen($digits) !== 12) {
            return null;
        }

        return '+'.$digits;
    }
}
