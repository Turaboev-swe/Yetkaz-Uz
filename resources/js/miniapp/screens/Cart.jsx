import { useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { showBackButton, setMainButton, hideMainButton, isInsideTelegram } from '../lib/telegram';
import { useCart, cartLines, cartCount, cartTotal } from '../store/cart';
import { som, somLabel } from '../lib/format';
import QtyControl from '../components/QtyControl';

export default function Cart() {
    const { rid: ridParam } = useParams();
    const rid = Number(ridParam);
    const navigate = useNavigate();

    useEffect(() => showBackButton(() => navigate(`/r/${rid}`)), [navigate, rid]);

    const carts = useCart((s) => s.carts);
    const add = useCart((s) => s.add);
    const remove = useCart((s) => s.remove);

    const lines = cartLines(carts, rid);
    const count = cartCount(carts, rid);
    const total = cartTotal(carts, rid);

    useEffect(() => {
        if (count === 0) {
            hideMainButton();
            return;
        }
        return setMainButton({
            text: `Buyurtma berish — ${somLabel(total)}`,
            onClick: () => navigate(`/checkout/${rid}`),
        });
    }, [count, total, rid, navigate]);

    if (count === 0) {
        return (
            <div className="mx-auto max-w-md px-4 pt-16 text-center">
                <div className="mb-2 text-3xl">🧺</div>
                <p className="mb-4 text-[15px]" style={{ color: 'var(--tg-hint)' }}>Savat bo‘sh.</p>
                <button
                    onClick={() => navigate(`/r/${rid}`)}
                    className="rounded-xl px-5 py-2 text-[14px] font-semibold"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    Menyuga qaytish
                </button>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-md px-4 pb-28 pt-2">
            <h1 className="mb-3 text-[18px] font-bold" style={{ color: 'var(--tg-text)' }}>Savat</h1>

            <div className="space-y-2">
                {lines.map((l) => (
                    <div key={l.product_id} className="flex items-center gap-3 rounded-2xl p-3" style={{ background: 'var(--tg-section-bg)' }}>
                        <div className="min-w-0 flex-1">
                            <div className="text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>{l.name}</div>
                            <div className="text-[13px]" style={{ color: 'var(--tg-hint)' }}>{somLabel(l.price)}</div>
                        </div>
                        <div className="w-[104px] shrink-0">
                            <QtyControl
                                qty={l.qty}
                                onAdd={() => add(rid, { id: l.product_id, price: l.price, name: l.name })}
                                onRemove={() => remove(rid, l.product_id)}
                            />
                        </div>
                        <div className="w-16 shrink-0 text-right text-[14px] font-bold tabular-nums" style={{ color: 'var(--tg-text)' }}>
                            {som(l.price * l.qty)}
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-4 flex items-center justify-between text-[16px] font-bold" style={{ color: 'var(--tg-text)' }}>
                <span>Jami</span>
                <span>{somLabel(total)}</span>
            </div>

            {!isInsideTelegram() && (
                <button
                    onClick={() => navigate(`/checkout/${rid}`)}
                    className="mt-4 h-12 w-full rounded-xl text-[15px] font-semibold"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    Buyurtma berish
                </button>
            )}
        </div>
    );
}
