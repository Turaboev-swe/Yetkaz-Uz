import { haptic } from '../lib/telegram';

/**
 * "+ Qo'shish" tugmasi -> qo'shilgach (− N +) boshqaruvi.
 * `max` — qoldiq (null/yo'q = cheklanmagan): qty unga yetganda "+" o'chadi.
 */
export default function QtyControl({ qty, onAdd, onRemove, addLabel = '+ Qo‘shish', max = null }) {
    const atMax = max !== null && max !== undefined && qty >= max;

    if (!qty) {
        return (
            <button
                onClick={() => {
                    haptic('light');
                    onAdd();
                }}
                className="h-9 w-full rounded-xl text-[13px] font-semibold"
                style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
            >
                {addLabel}
            </button>
        );
    }

    return (
        <div
            className="flex h-9 w-full items-center justify-between rounded-xl px-1"
            style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
        >
            <button
                onClick={() => {
                    haptic('light');
                    onRemove();
                }}
                className="flex h-9 w-9 items-center justify-center text-lg font-bold"
                aria-label="Kamaytirish"
            >
                −
            </button>
            <span className="min-w-[1.5rem] text-center text-[14px] font-semibold tabular-nums">{qty}</span>
            <button
                onClick={() => {
                    haptic('light');
                    onAdd();
                }}
                disabled={atMax}
                className="flex h-9 w-9 items-center justify-center text-lg font-bold disabled:opacity-30"
                aria-label="Ko‘paytirish"
            >
                +
            </button>
        </div>
    );
}
