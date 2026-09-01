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
