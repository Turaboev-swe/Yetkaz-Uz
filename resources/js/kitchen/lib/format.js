export function som(tiyin) {
    return Math.round((Number(tiyin) || 0) / 100).toLocaleString('ru-RU').replace(/[  ]/g, ' ');
}

export function agoMinutes(iso) {
    if (!iso) return 0;
    return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));
}

export function agoLabel(iso) {
    const m = agoMinutes(iso);
    if (m < 1) return 'hozirgina';
    return `${m} daq oldin`;
}

const NEXT_LABEL = {
    new: 'Qabul qilish',
    accepted: 'Tayyorlashni boshlash',
    preparing_delivery: 'Yo‘lga chiqdi',
    preparing_pickup: 'Mijoz oldi',
    on_the_way: 'Yetkazildi',
};

export function nextActionLabel(status, deliveryType) {
    if (status === 'preparing') return NEXT_LABEL[`preparing_${deliveryType}`];
    return NEXT_LABEL[status] || null;
}

/**
 * Backend `App\Support\Phone::normalizeUzbek()` QABUL QILADIGAN formatlar
 * bilan bir xil — bu yerda faqat TEKSHIRUV (tugmani yoqish/o'chirish uchun),
 * "+998" qo'shish mantig'i takrorlanmaydi: haqiqiy normallashtirish
 * serverda bo'ladi, javobdagi courier_phone allaqachon to'liq formatda keladi.
 *   - "+998901112233" / "998901112233" — kod bilan (12 raqam)
 *   - "0901112233"    — mahalliy format, boshida 0 (10 raqam)
 *   - "901112233"     — kodsiz, 9 ta raqam
 */
export function isValidUzPhone(raw) {
    const digits = String(raw || '').replace(/\D/g, '');

    if (digits.startsWith('998') && digits.length === 12) return true;
    if (digits.startsWith('0') && digits.length === 10) return true;
    if (digits.length === 9) return true;

    return false;
}
