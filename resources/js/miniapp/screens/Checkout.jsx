import { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAsync } from '../hooks/useAsync';
import { showBackButton, setMainButton, hideMainButton, notify, isInsideTelegram } from '../lib/telegram';
import { useSession } from '../store/session';
import { useCart, cartItems, cartTotal, cartCount } from '../store/cart';
import { som, somLabel } from '../lib/format';
import { addressLines } from '../lib/address';
import { Spinner, ErrorState } from '../components/States';

/** Bugungi ish vaqti "09:00–23:00" ko'rinishida (ixtiyoriy). */
function todayHours(workHours) {
    if (!workHours) return null;
    const key = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][new Date().getDay()];
    const slots = workHours[key];
    if (!slots?.length) return null;
    return slots.map(([a, b]) => `${a}–${b}`).join(', ');
}

export default function Checkout() {
    const { rid: ridParam } = useParams();
    const rid = Number(ridParam);
    const navigate = useNavigate();

    const { mode, addressId } = useSession();
    const carts = useCart((s) => s.carts);
    const clear = useCart((s) => s.clear);

    const total = cartTotal(carts, rid);
    const count = cartCount(carts, rid);

    const [note, setNote] = useState('');
    const noteRef = useRef('');
    noteRef.current = note;
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => showBackButton(() => navigate(`/cart/${rid}`)), [navigate, rid]);

    // Savat bo'sh bo'lsa — orqaga.
    useEffect(() => {
        if (count === 0) navigate(`/r/${rid}`, { replace: true });
    }, [count, rid, navigate]);

    const data = useAsync(
        () =>
            Promise.all([
                api.restaurant(rid, mode === 'delivery' ? addressId : null),
                api.me(),
                api.estimateOrder({
                    restaurant_id: rid,
                    delivery_type: mode,
                    address_id: mode === 'delivery' ? addressId : null,
                    items: cartItems(carts, rid),
                }),
            ]),
        [rid, mode, addressId],
    );

    const submit = async () => {
        if (submitting) return;
        setSubmitting(true);
        setError(null);
        try {
            const res = await api.createOrder({
                restaurant_id: rid,
                delivery_type: mode,
                address_id: mode === 'delivery' ? addressId : null,
                payment_method: 'cash',
                note: noteRef.current.trim() || null,
                items: cartItems(carts, rid),
            });
            notify('success');
            clear(rid);
            navigate(`/order/${res.data.id}`, { replace: true });
        } catch (e) {
            setError(e.message);
            setSubmitting(false);
        }
    };

    const restaurant = data.data?.[0]?.data;
    const addresses = data.data?.[1]?.data?.addresses || [];
    const address = addresses.find((a) => a.id === addressId);
    const belowMin = restaurant && total < restaurant.min_order_amount;
    const shortfall = belowMin ? restaurant.min_order_amount - total : 0;
    const deliveryFee = mode === 'delivery' && restaurant ? restaurant.delivery_fee : 0;

    // Tasdiqlash — MainButton (min yetmasa — nofaol + "yana X qo'shing").
    useEffect(() => {
        if (data.loading || submitting) {
            hideMainButton();
            return;
        }
        return setMainButton({
            text: belowMin ? `Yana ${somLabel(shortfall)} qo‘shing` : `Tasdiqlash — ${somLabel(total + deliveryFee)}`,
            active: !belowMin,
            onClick: submit,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.loading, submitting, belowMin, shortfall, total, deliveryFee]);

    if (data.loading) return <Spinner />;
    if (data.error) return <ErrorState error={data.error} onRetry={data.reload} />;

    const estimate = data.data?.[2]?.data;
    const eta = estimate ? `${estimate.eta_low}–${estimate.eta_high} daq` : '…';
    const hours = todayHours(restaurant.work_hours);

    return (
        <div className="mx-auto max-w-md px-4 pb-28 pt-2">
            <h1 className="mb-3 text-[18px] font-bold" style={{ color: 'var(--tg-text)' }}>Rasmiylashtirish</h1>

            {/* Manzil / Olib ketish */}
            <Section title={mode === 'pickup' ? 'Olib ketish' : 'Yetkazish manzili'}>
                {mode === 'pickup' ? (
                    <>
                        <Row icon="🏪" title={restaurant.name} subtitle={restaurant.district?.name} />
                        {hours && <p className="mt-1 text-[12px]" style={{ color: 'var(--tg-hint)' }}>Ish vaqti: {hours}</p>}
                        <p className="text-[12px]" style={{ color: 'var(--tg-hint)' }}>{restaurant.phone}</p>
                    </>
                ) : (
                    <>
                        <Row icon="📍" title={addressLines(address).title} subtitle={addressLines(address).subtitle} />
                        <button
                            onClick={() => navigate('/')}
                            className="mt-1 text-[13px] font-medium"
                            style={{ color: 'var(--tg-link)' }}
                        >
                            O‘zgartirish
                        </button>
                    </>
                )}
            </Section>

            {/* To'lov */}
            <Section title="To‘lov usuli">
                <Row icon="💵" title="Naqd pul" subtitle="Kuryerga / kassaga to‘lanadi" trailing="✓" />
            </Section>

            {/* Izoh */}
            <Section title="Izoh (ixtiyoriy)">
                <textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    maxLength={500}
                    rows={2}
                    placeholder="Masalan: qo‘ng‘iroqsiz, eshik oldiga qo‘ying"
                    className="w-full rounded-xl px-3 py-2 text-[14px] outline-none"
                    style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-text)' }}
                />
            </Section>

            {/* Hisob */}
            <Section title={`Taxminan ${eta}`}>
                <Line label={`Taomlar (${count})`} value={somLabel(total)} />
                <Line
                    label="Yetkazish"
                    value={mode === 'pickup' ? '—' : deliveryFee === 0 ? 'Bepul' : somLabel(deliveryFee)}
                />
                <div className="mt-2 flex justify-between border-t pt-2 text-[15px] font-bold" style={{ borderColor: 'var(--tg-bg)', color: 'var(--tg-text)' }}>
                    <span>Jami</span>
                    <span>{somLabel(total + deliveryFee)}</span>
                </div>
            </Section>

            {belowMin && (
                <p className="mb-2 text-[13px]" style={{ color: 'var(--tg-destructive)' }}>
                    Minimal buyurtma: {somLabel(restaurant.min_order_amount)}. Yana {somLabel(restaurant.min_order_amount - total)} qo‘shing.
                </p>
            )}
            {error && <p className="mb-2 text-[13px]" style={{ color: 'var(--tg-destructive)' }}>{error}</p>}

            {!isInsideTelegram() && (
                <button
                    onClick={submit}
                    disabled={belowMin || submitting}
                    className="h-12 w-full rounded-xl text-[15px] font-semibold disabled:opacity-50"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    {submitting ? 'Yuborilmoqda…' : `Tasdiqlash — ${som(total + deliveryFee)} so‘m`}
                </button>
            )}
        </div>
    );
}

function Section({ title, children }) {
    return (
        <div className="mb-4">
            <p className="mb-1.5 text-[12px] font-medium uppercase tracking-wide" style={{ color: 'var(--tg-hint)' }}>{title}</p>
            <div className="rounded-2xl p-3" style={{ background: 'var(--tg-secondary-bg)' }}>{children}</div>
        </div>
    );
}

function Row({ icon, title, subtitle, trailing }) {
    return (
        <div className="flex items-center gap-2.5">
            <span aria-hidden>{icon}</span>
            <span className="min-w-0 flex-1">
                <span className="block text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>{title}</span>
                {subtitle && <span className="block text-[12px]" style={{ color: 'var(--tg-hint)' }}>{subtitle}</span>}
            </span>
            {trailing && <span style={{ color: 'var(--tg-link)' }}>{trailing}</span>}
        </div>
    );
}

function Line({ label, value }) {
    return (
        <div className="flex justify-between py-0.5 text-[14px]" style={{ color: 'var(--tg-text)' }}>
            <span style={{ color: 'var(--tg-hint)' }}>{label}</span>
            <span>{value}</span>
        </div>
    );
}
