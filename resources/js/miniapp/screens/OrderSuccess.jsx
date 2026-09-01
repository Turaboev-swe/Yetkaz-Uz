import { useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAsync } from '../hooks/useAsync';
import { showBackButton, setMainButton, isInsideTelegram } from '../lib/telegram';
import { som, somLabel } from '../lib/format';
import { Spinner, ErrorState } from '../components/States';

export default function OrderSuccess() {
    const { id } = useParams();
    const navigate = useNavigate();

    const home = () => navigate('/', { replace: true });

    useEffect(() => showBackButton(home), [navigate]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => setMainButton({ text: 'Restoranlarga qaytish', onClick: home }), [navigate]); // eslint-disable-line react-hooks/exhaustive-deps

    const q = useAsync(() => api.order(id), [id]);

    if (q.loading) return <Spinner />;
    if (q.error) return <ErrorState error={q.error} onRetry={q.reload} />;

    const o = q.data.data;
    const pickup = o.delivery_type === 'pickup';
    const eta = `${o.eta_minutes}–${o.eta_minutes + 15}`;

    return (
        <div className="mx-auto max-w-md px-4 pb-28 pt-10">
            <div className="text-center">
                <div className="mb-2 text-5xl">✅</div>
                <h1 className="text-[18px] font-bold" style={{ color: 'var(--tg-text)' }}>
                    Buyurtmangiz qabul qilindi
                </h1>
                <p className="mt-1 text-[15px]" style={{ color: 'var(--tg-text)' }}>
                    {eta} daqiqada {pickup ? 'tayyor bo‘ladi' : 'yetib boradi'}
                </p>
                <p className="mt-1 text-[13px]" style={{ color: 'var(--tg-hint)' }}>
                    Buyurtma № {o.order_number}
                </p>
            </div>

            <div className="mt-6 rounded-2xl p-3" style={{ background: 'var(--tg-secondary-bg)' }}>
                {o.items.map((it) => (
                    <div key={it.product_id} className="flex justify-between py-1 text-[14px]" style={{ color: 'var(--tg-text)' }}>
                        <span className="min-w-0 flex-1 truncate">
                            {it.name} <span style={{ color: 'var(--tg-hint)' }}>× {it.qty}</span>
                        </span>
                        <span className="tabular-nums">{som(it.price * it.qty)}</span>
                    </div>
                ))}
                <div className="mt-2 flex justify-between border-t pt-2 text-[13px]" style={{ borderColor: 'var(--tg-bg)', color: 'var(--tg-hint)' }}>
                    <span>Yetkazish</span>
                    <span>{o.delivery_fee === 0 ? (pickup ? '—' : 'Bepul') : somLabel(o.delivery_fee)}</span>
                </div>
                <div className="mt-1 flex justify-between text-[15px] font-bold" style={{ color: 'var(--tg-text)' }}>
                    <span>Jami</span>
                    <span>{somLabel(o.total)}</span>
                </div>
                <p className="mt-2 text-[12px]" style={{ color: 'var(--tg-hint)' }}>To‘lov: naqd pul</p>
            </div>

            {!isInsideTelegram() && (
                <button
                    onClick={home}
                    className="mt-6 h-12 w-full rounded-xl text-[15px] font-semibold"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    Restoranlarga qaytish
                </button>
            )}
        </div>
    );
}
