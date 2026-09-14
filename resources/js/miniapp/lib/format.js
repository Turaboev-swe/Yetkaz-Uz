/** Tiyin -> "30 000" (so'm belgisisiz). 1 so'm = 100 tiyin. */
export function som(tiyin) {
    const value = Math.round((Number(tiyin) || 0) / 100);
    return value.toLocaleString('ru-RU').replace(/[  ]/g, ' ');
}

/** Tiyin -> "30 000 so'm". */
export function somLabel(tiyin) {
    return `${som(tiyin)} so‘m`;
}

export function distanceLabel(km) {
    if (km === null || km === undefined) return null;
    return km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(1)} km`;
}

/**
 * ETA oralig'i, masalan "35–50 daq".
 *
 * VAQTINCHALIK heuristика (restoranlar ro'yxati uchun — haqiqiy ETA backend'da,
 * EtaEstimator). prep_time + (masofa / ~18 km/soat) va +15 daq oraliq.
 *
 * `pickup=true` bo'lsa yo'l vaqti hisoblanmaydi — mijoz o'zi boradi, faqat
 * pishirish vaqti (backend EtaEstimator/DeliveryFeeCalculator pickup uchun
 * xuddi shunday: kuryer_kutish=0, yo'l_vaqti=0).
 */
export function etaLabel(km, prepMin, pickup = false) {
    const prep = Number(prepMin) || 20;
    const travel = pickup ? 0 : Math.round(((Number(km) || 0) / 18) * 60);
    const low = Math.max(15, Math.round((prep + travel) / 5) * 5);
    return `${low}–${low + 15} daq`;
}
