/**
 * Mijozga ko'rsatiladigan manzil (backend `resolved_address` — Nominatim reverse).
 * Bar / tasdiqlash / checkout / tanlash ro'yxatida label ("Uy") o'rniga shu.
 *
 * Xom koordinata HECH QACHON ko'rsatilmaydi.
 *   1. resolved_address (Nominatim ko'cha/tuman bergan bo'lsa)
 *   2. label ("Uy", "Ish")
 */
export function resolvedAddress(a) {
    if (!a) return '';
    return a.resolved_address || a.label || 'Manzil';
}
