<?php

namespace App\Support;

/**
 * O'zbekiston telefon raqamini tekshirish/normallashtirish — OPERATSION
 * ma'lumot uchun: Royal Taxi haydovchisi va mijozning aloqa raqami
 * (`contact_phone`). Mijozning Telegram raqami (`users.phone`) esa faqat
 * `request_contact` orqali olinadi (CLAUDE.md) — bu sinf uni o'zgartirmaydi.
 */
final class Phone
{
    /**
     * Avtomatik +998 qo'shadi, formatlash belgilarini (bo'shliq, tire, "+")
     * tozalaydi. Qabul qilinadigan kirishlar:
     *   - "+998901112233" / "998901112233" — kod allaqachon bor, faqat
     *     uzunlik tekshiriladi, hech narsa qo'shilmaydi.
     *   - "0901112233" / "8901112233" — mahalliy format: JAMI 10 xonali va
     *     boshida 0 yoki 8 — o'sha raqam olib tashlanib, +998 qo'shiladi.
     *   - "901112233"     — kodsiz, aynan 9 xonali mobil raqam — +998 qo'shiladi
     *     ("881112233" kabi 9 xonali raqamdagi 8 prefiks EMAS, o'zgarmaydi).
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

        if ((str_starts_with($digits, '0') || str_starts_with($digits, '8')) && strlen($digits) === 10) {
            return '+998'.substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '+998'.$digits;
        }

        return null;
    }
}
