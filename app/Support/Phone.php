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
    /**
     * Avtomatik +998 qo'shadi, formatlash belgilarini (bo'shliq, tire, "+")
     * tozalaydi. Qabul qilinadigan kirishlar:
     *   - "+998901112233" / "998901112233" — kod allaqachon bor, faqat
     *     uzunlik tekshiriladi, hech narsa qo'shilmaydi.
     *   - "0901112233"    — mahalliy format (boshida 0) — 0 olib tashlanib,
     *     +998 qo'shiladi.
     *   - "901112233"     — kodsiz, aynan 9 xonali mobil raqam — +998 qo'shiladi.
     *   - Boshqa hamma narsa (uzunlik/format mos kelmasa) — null.
     *
     * @return string|null +998XXXXXXXXX (13 belgi) — yaroqsiz bo'lsa null.
     */
    public static function normalizeUzbek(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '998') && strlen($digits) === 12) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+998'.substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '+998'.$digits;
        }

        return null;
    }
}
