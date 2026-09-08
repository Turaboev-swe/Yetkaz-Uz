import { resolvedAddress } from '../lib/address';

/** Joriy yetkazish manzili / "Olib ketaman". Bosilганда — manzil tanlash sheet'i. */
export default function AddressBar({ address, pickup, onClick }) {
    const text = pickup ? 'Olib ketaman' : resolvedAddress(address) || 'Manzil tanlang';

    return (
        <button
            onClick={onClick}
            className="-mx-1 flex w-full items-center gap-1.5 rounded-lg px-1 py-1.5 text-left"
        >
            <span aria-hidden>{pickup ? '🛍' : '📍'}</span>
            <span className="min-w-0 flex-1 truncate text-[13px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                {text}
            </span>
            <span aria-hidden style={{ color: 'var(--tg-hint)' }}>▾</span>
        </button>
    );
}
