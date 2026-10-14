/**
 * Aloqa raqami (+998 + 9 raqam). Backend `App\Support\Phone::normalizeUzbek()`
 * bilan bir xil qoidalar — server har doim qayta tekshiradi, bu faqat
 * mijozga darhol xabar berish uchun.
 *   - "+998901112233" / "998901112233"      (12 raqam, 998 bilan)
 *   - "0901112233" / "8901112233"           (JAMI 10 raqam, boshida 0 yoki 8)
 *   - "901112233"                           (9 raqam; "881112233" kabi raqam buzilmaydi)
 */
export function normalizeUzPhone(raw) {
    const digits = String(raw || '').replace(/\D+/g, '');

    if (digits.startsWith('998') && digits.length === 12) return `+${digits}`;
    if ((digits.startsWith('0') || digits.startsWith('8')) && digits.length === 10) return `+998${digits.slice(1)}`;
    if (digits.length === 9) return `+998${digits}`;

    return null;
}
