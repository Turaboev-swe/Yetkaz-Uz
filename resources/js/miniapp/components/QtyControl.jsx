import { haptic } from '../lib/telegram';

/** "+ Qo'shish" tugmasi -> qo'shilgach (− N +) boshqaruvi. */
export default function QtyControl({ qty, onAdd, onRemove, addLabel = '+ Qo‘shish' }) {
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
            <span className="min-w-6 text-center text-[14px] font-semibold tabular-nums">{qty}</span>
            <button
                onClick={() => {
                    haptic('light');
                    onAdd();
                }}
                className="flex h-9 w-9 items-center justify-center text-lg font-bold"
                aria-label="Ko‘paytirish"
            >
                +
            </button>
        </div>
    );
}
