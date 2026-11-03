import { useCart } from '../store/cart';

/** Qoldiq o'zgargani uchun savat yangilangani haqida xabar (menyu / savat / rasmiylashtirish ekranlarida). */
export default function StockNotice() {
    const notice = useCart((s) => s.notice);
    const clearNotice = useCart((s) => s.clearNotice);

    if (!notice) return null;

    return (
        <div
            role="alert"
            className="mb-3 flex items-start gap-2 rounded-xl px-3 py-2 text-[13px]"
            style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-text)' }}
        >
            <span aria-hidden="true">⚠️</span>
            <span className="min-w-0 flex-1">{notice}</span>
            <button onClick={clearNotice} aria-label="Yopish" className="px-1 text-[16px] leading-none" style={{ color: 'var(--tg-hint)' }}>
                ×
            </button>
        </div>
    );
}
