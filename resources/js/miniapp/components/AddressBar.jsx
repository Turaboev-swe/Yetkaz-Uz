/**
 * Joriy yetkazish manzili (hozircha faqat ko'rsatish).
 * Manzil almashtirish (bottom sheet) — 3-ekranda.
 */
export default function AddressBar({ address }) {
    if (!address) return null;
    const text = address.address_text || `${address.label}`;

    return (
        <div className="flex items-center gap-1.5 py-1 text-[13px]" style={{ color: 'var(--tg-hint)' }}>
            <span aria-hidden>📍</span>
            <span className="truncate" style={{ color: 'var(--tg-text)' }}>
                {address.label}
                {address.address_text ? ` · ${text}` : ''}
            </span>
        </div>
    );
}
