// "Yangi manzil" formasi mantig'i: matn yig'ish va majburiylik. Sof funksiyalar (node:test bilan tekshiriladi).
// Haqiqiy tekshiruv va yakuniy matn serverda (AddressService::resolvePlace, AddressText::compose) — bu faqat ko'rinish va qulaylik.

const norm = (s) =>
    String(s || '')
        .toLowerCase()
        .replace(/[ʻʼʹ‘’`´]/g, "'")
        .replace(/\s+/g, ' ')
        .replace(/\s+(tumani|shahri|tuman|shahar|district|rayoni)$/u, '')
        .trim();

/** "Bobur ko'chasi 12, Yangi hayot, Qo'rg'ontepa tumani" — takrorsiz (server AddressText::compose bilan bir xil qoida). */
export function composeAddress(street, locality, district) {
    const s = String(street || '').trim();
    let l = String(locality || '').trim();
    let d = String(district || '').trim();

    if (l && ((d && norm(l) === norm(d)) || (s && norm(s).includes(norm(l))))) l = '';
    if (d && s && norm(s).includes(norm(d))) d = '';

    return [s, l, d].filter(Boolean).join(', ');
}

/** Ko'cha ham, mo'ljal ham bo'sh (bo'shliqlar hisobga olinmaydi) — saqlab bo'lmaydi. */
export function streetOrLandmarkMissing(street, landmark) {
    return !String(street || '').trim() && !String(landmark || '').trim();
}
