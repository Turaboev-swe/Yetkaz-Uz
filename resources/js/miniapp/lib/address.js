/**
 * Koordinatadan aniqlangan haqiqiy manzil matni (backend `resolved_address`).
 * Bar / tasdiqlash / menyu tepasi / checkout'da label ("Uy") o'rniga shu ko'rsatiladi.
 * Backend hech qachon bo'sh/koordinata qaytarmaydi, lekin zaxira ham bor.
 */
export function resolvedAddress(a) {
    if (!a) return '';
    if (a.resolved_address) return a.resolved_address;

    const text = (a.address_text || '').trim();
    const isCoords = /^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$/.test(text);

    return (
        [a.district, isCoords ? '' : text].filter(Boolean).join(', ') ||
        a.label ||
        'Manzil'
    );
}
