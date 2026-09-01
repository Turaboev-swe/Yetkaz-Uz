/** Manzil kartasi uchun sarlavha (label) + tag (ko'cha/tuman, o'zbekcha). */
export function addressLines(a) {
    if (!a) return { title: '', subtitle: '' };

    const text = (a.address_text || '').trim();
    const isCoords = /^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$/.test(text);
    const subtitle = isCoords ? a.district || text : text || a.district || '';

    return {
        title: a.label || a.district || 'Manzil',
        subtitle,
    };
}
