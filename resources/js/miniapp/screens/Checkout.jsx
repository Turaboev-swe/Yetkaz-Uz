import { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAsync } from '../hooks/useAsync';
import { showBackButton, setMainButton, hideMainButton, notify, isInsideTelegram, botUsername, openTelegramLink } from '../lib/telegram';
import { useSession } from '../store/session';
import { useCart, cartItems, cartTotal, cartCount } from '../store/cart';
import { som, somLabel, distanceLabel } from '../lib/format';
import { resolvedAddress } from '../lib/address';
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

    const { mode, addressId, confirmDelivery, choosePickup } = useSession();
    const carts = useCart((s) => s.carts);
    const clear = useCart((s) => s.clear);

    const total = cartTotal(carts, rid);
    const count = cartCount(carts, rid);

    const [note, setNote] = useState('');
    const noteRef = useRef('');
    noteRef.current = note;
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    // Buyurtma berish uchun telefon kerak (mehmon / QR orqali kirgan) — botga qaytariladi.
    const [phoneRequired, setPhoneRequired] = useState(false);
    // submit() muvaffaqiyatli tugagach true bo'ladi — cart clear() shu zahoti
    // count'ni 0'ga tushiradi va quyidagi "savat bo'sh" guardi navigate(/order/:id)
    // bilan RAQOBATLASHADI (Zustand clear() Checkout'ni navigate() ta'sir
    // qilgunicha bir bor qayta render qiladi). Shu bayroq guardni o'chirib qo'yadi.
    const justOrderedRef = useRef(false);

    useEffect(() => showBackButton(() => navigate(`/cart/${rid}`)), [navigate, rid]);

    // Savat bo'sh bo'lsa — orqaga (lekin hozirgina buyurtma berilgan bo'lsa emas).
    useEffect(() => {
        if (count === 0 && !justOrderedRef.current) navigate(`/r/${rid}`, { replace: true });
    }, [count, rid, navigate]);

    const data = useAsync(
        () =>
            Promise.all([
                // Rejimdan qat'i nazar addressId beriladi — pickup'da ham
                // distance_km kerak (yetkazishga o'tish mumkinmi tekshirish uchun).
                api.restaurant(rid, addressId),
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
            justOrderedRef.current = true;
            clear(rid);
            navigate(`/order/${res.data.id}`, { replace: true });
        } catch (e) {
            if (e.body?.code === 'phone_required') {
                setPhoneRequired(true);
            } else {
                setError(e.message);
            }
            setSubmitting(false);
        }
    };

    const goSharePhone = () => {
        const bot = botUsername();
        if (bot) openTelegramLink(`https://t.me/${bot}?start=phone_r_${rid}`);
    };

    const restaurant = data.data?.[0]?.data;
    const addresses = data.data?.[1]?.data?.addresses || [];
    const estimate = data.data?.[2]?.data;
    const address = addresses.find((a) => a.id === addressId);
    const belowMin = restaurant && total < restaurant.min_order_amount;
    const shortfall = belowMin ? restaurant.min_order_amount - total : 0;
    // Backend hisoblaydi (masofaga qarab bo'lishi mumkin) — buyurtma yaratilganda
    // xuddi shu qiymat chiqadi, mijoz tomonidan taxmin qilinmaydi.
    const deliveryFee = estimate?.delivery_fee ?? 0;

    // Olib ketishdan yetkazishga o'tish faqat restoran manzil radiusi ichida bo'lsa
    // mumkin (backend OrderService::place() ham buni tekshiradi — bu yerda oldindan
    // aniq xabar bilan ko'rsatamiz). Yetkazishdan olib ketishga o'tish har doim mumkin.
    const canSwitchToDelivery = Boolean(
        addressId != null
            && restaurant?.distance_km != null
            && restaurant?.delivery_radius_km != null
            && restaurant.distance_km <= restaurant.delivery_radius_km,
    );
    const switchToDelivery = () => confirmDelivery(addressId);
    const switchToPickup = () => choosePickup(addressId);

    // Tasdiqlash — MainButton (min yetmasa — nofaol + "yana X qo'shing").
    useEffect(() => {
        if (data.loading || submitting || phoneRequired) {
            hideMainButton();
            return;
        }
        return setMainButton({
            text: belowMin ? `Yana ${somLabel(shortfall)} qo‘shing` : `Tasdiqlash — ${somLabel(total + deliveryFee)}`,
            active: !belowMin,
            onClick: submit,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.loading, submitting, belowMin, shortfall, total, deliveryFee, phoneRequired]);

    if (data.loading) return <Spinner />;
    if (data.error) return <ErrorState error={data.error} onRetry={data.reload} />;

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

                        {addressId != null && (
                            canSwitchToDelivery ? (
                                <button
                                    onClick={switchToDelivery}
                                    className="mt-2 text-[13px] font-medium"
                                    style={{ color: 'var(--tg-link)' }}
                                >
                                    🛵 Yetkazib berishga o‘tish
                                </button>
                            ) : (
                                <p className="mt-2 text-[12px]" style={{ color: 'var(--tg-hint)' }}>
                                    Bu restoran sizning manzilingizga yetkazib bermaydi, faqat olib ketish mavjud.
                                </p>
                            )
                        )}
                    </>
                ) : (
                    <>
                        <Row icon="📍" title={resolvedAddress(address)} subtitle={[address?.entrance && `kirish ${address.entrance}`, address?.floor && `qavat ${address.floor}`, address?.apartment && `xonadon ${address.apartment}`].filter(Boolean).join(' · ') || null} />
                        <div className="mt-1 flex items-center gap-3">
                            <button
                                onClick={() => navigate('/')}
                                className="text-[13px] font-medium"
                                style={{ color: 'var(--tg-link)' }}
                            >
                                O‘zgartirish
                            </button>
                            <button
                                onClick={switchToPickup}
                                className="text-[13px] font-medium"
                                style={{ color: 'var(--tg-link)' }}
                            >
                                🛍 Olib ketishga o‘tish
                            </button>
                        </div>
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
                    value={
                        mode === 'pickup'
                            ? '—'
                            : (deliveryFee === 0 ? 'Bepul' : somLabel(deliveryFee))
                              + (estimate?.distance_km != null ? ` (${distanceLabel(estimate.distance_km)})` : '')
                    }
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

            {phoneRequired && (
                <div className="mb-3 rounded-2xl p-4" style={{ background: 'var(--tg-secondary-bg)' }}>
                    <p className="mb-1 text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                        Telefon raqami kerak
                    </p>
                    <p className="mb-3 text-[13px]" style={{ color: 'var(--tg-hint)' }}>
                        Buyurtmani rasmiylashtirish uchun botga qaytib, «📱 Raqamni yuborish» tugmasini bosing. Keyin shu menyuga qaytasiz.
                    </p>
                    {botUsername() ? (
                        <button
                            onClick={goSharePhone}
                            className="h-11 w-full rounded-xl text-[15px] font-semibold"
                            style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                        >
                            Botga qaytish
                        </button>
                    ) : (
                        <p className="text-[13px]" style={{ color: 'var(--tg-hint)' }}>
                            Botni oching va raqamingizni ulashing.
                        </p>
                    )}
                </div>
            )}

            {!isInsideTelegram() && !phoneRequired && (
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
