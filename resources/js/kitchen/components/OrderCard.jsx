import { useEffect, useState } from 'react';
import { som, agoLabel, nextActionLabel, isValidUzPhone } from '../lib/format';

const CANCEL_PRESETS = ['Taom tugab qoldi', 'Restoran hozir band'];

const STATUS_COLOR = {
    new: '#f59e0b',
    accepted: '#3b82f6',
    preparing: '#8b5cf6',
    on_the_way: '#06b6d4',
};

export default function OrderCard({ order, onAdvance, onCancel, busy, couriers = [] }) {
    const [, tick] = useState(0);
    useEffect(() => {
        const t = setInterval(() => tick((n) => n + 1), 15000);
        return () => clearInterval(t);
    }, []);

    const pickup = order.delivery_type === 'pickup';
    const actionLabel = nextActionLabel(order.status, order.delivery_type);
    const addr = order.address;

    // "Yo‘lga chiqdi" (yetkazish) — statusdan oldin kuryer turini so‘raymiz:
    // avval 🛵 o‘z kuryer / 🚕 Royal Taxi, keyin tafsilot ('type' -> 'own' | 'taxi').
    const asksCourier = order.status === 'preparing' && order.delivery_type === 'delivery';
    const [askOpen, setAskOpen] = useState(false);
    const [askStep, setAskStep] = useState('type');
    const [courierId, setCourierId] = useState('');
    const [taxiPhone, setTaxiPhone] = useState('');
    const taxiPhoneValid = isValidUzPhone(taxiPhone);

    // Bekor qilish modali
    const [cancelOpen, setCancelOpen] = useState(false);
    const [reason, setReason] = useState(CANCEL_PRESETS[0]);
    const [otherText, setOtherText] = useState('');
    const finalReason = reason === '__other__' ? otherText.trim() : reason;

    const confirmCancel = () => {
        if (!finalReason) return;
        setCancelOpen(false);
        onCancel(order.id, finalReason);
    };

    const handleAction = () => {
        if (asksCourier) {
            setCourierId('');
            setTaxiPhone('');
            setAskStep('type');
            setAskOpen(true);
            return;
        }
        onAdvance(order.id);
    };

    const withCourier = () => {
        setAskOpen(false);
        onAdvance(order.id, {
            courier_type: 'own_staff',
            ...(courierId ? { courier_staff_id: Number(courierId) } : {}),
        });
    };

    const withoutCourier = () => {
        setAskOpen(false);
        onAdvance(order.id, { courier_type: 'own_staff' });
    };

    const withTaxi = () => {
        if (!taxiPhoneValid) return;
        setAskOpen(false);
        onAdvance(order.id, { courier_type: 'taxi', courier_phone: taxiPhone.trim() });
    };

    return (
        <div className="flex flex-col rounded-2xl border p-4" style={{ borderColor: STATUS_COLOR[order.status] || '#374151', background: '#171a21' }}>
            <div className="flex items-start justify-between">
                <div>
                    <div className="text-[22px] font-extrabold tabular-nums">{order.order_number}</div>
                    <div className="text-[13px] text-gray-400">{agoLabel(order.created_at)} · {order.status_label}</div>
                </div>
                <span
                    className="rounded-lg px-2.5 py-1 text-[13px] font-bold"
                    style={pickup ? { background: '#7c2d12', color: '#fed7aa' } : { background: '#164e63', color: '#a5f3fc' }}
                >
                    {pickup ? '🏃 OLIB KETISH' : '🛵 YETKAZISH'}
                </span>
            </div>

            {order.dispatch_failed && (
                <div className="mt-2 rounded-lg px-3 py-1.5 text-[13px] font-bold" style={{ background: '#7f1d1d', color: '#fecaca' }}>
                    ⚠️ Chek chiqmadi — qo‘lda tekshiring
                </div>
            )}

            <div className="mt-3 text-[15px]">
                <div className="font-semibold">{order.customer.name || 'Ism yo‘q'}</div>
                {order.customer.phone && (
                    <a href={`tel:${order.customer.phone}`} className="text-[15px] font-medium" style={{ color: '#60a5fa' }}>
                        📞 {order.customer.phone}
                    </a>
                )}
            </div>

            {!pickup && addr && (
                <div className="mt-2 text-[14px] text-gray-300">
                    <div>📍 {addr.text}</div>
                    {(addr.entrance || addr.floor || addr.apartment) && (
                        <div className="text-[13px] text-gray-400">
                            {[addr.entrance && `kirish ${addr.entrance}`, addr.floor && `qavat ${addr.floor}`, addr.apartment && `xonadon ${addr.apartment}`]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                    )}
                    {addr.lat && addr.lng && (
                        <a
                            href={`https://maps.google.com/?q=${addr.lat},${addr.lng}`}
                            target="_blank"
                            rel="noreferrer"
                            className="text-[13px] font-medium"
                            style={{ color: '#60a5fa' }}
                        >
                            Xaritada ko‘rish →
                        </a>
                    )}
                </div>
            )}

            <ul className="mt-3 space-y-1 text-[15px]">
                {order.items.map((it) => (
                    <li key={it.product_id} className="flex justify-between">
                        <span><b className="tabular-nums">{it.qty}×</b> {it.name}</span>
                        <span className="text-gray-400 tabular-nums">{som(it.price * it.qty)}</span>
                    </li>
                ))}
            </ul>

            {order.note && (
                <div className="mt-2 rounded-lg px-3 py-2 text-[14px]" style={{ background: '#3f2d0a', color: '#fde68a' }}>
                    📝 {order.note}
                </div>
            )}

            <div className="mt-3 flex items-center justify-between text-[13px] text-gray-400">
                <span>Jami: <b className="text-gray-200">{som(order.total)} so‘m</b> · naqd</span>
                {order.eta_minutes ? <span>≈ {order.eta_minutes} daq</span> : null}
            </div>

            {(order.courier_name || order.courier_phone) && (
                <div className="mt-2 text-[13px] text-gray-400">
                    {order.courier_type === 'taxi' ? '🚕' : '🛵'} {[order.courier_name, order.courier_phone].filter(Boolean).join(' · ')}
                </div>
            )}

            {(actionLabel || order.can_cancel) && (
                <div className="mt-4 flex gap-2">
                    {actionLabel && (
                        <button
                            onClick={handleAction}
                            disabled={busy}
                            className="h-14 flex-1 rounded-xl text-[17px] font-bold disabled:opacity-50"
                            style={{ background: STATUS_COLOR[order.status] || '#2563eb', color: '#fff' }}
                        >
                            {busy ? '…' : actionLabel}
                        </button>
                    )}
                    {order.can_cancel && (
                        <button
                            onClick={() => { setReason(CANCEL_PRESETS[0]); setOtherText(''); setCancelOpen(true); }}
                            disabled={busy}
                            className="h-14 shrink-0 rounded-xl px-4 text-[14px] font-bold disabled:opacity-50"
                            style={{ background: '#3f1d1d', color: '#fca5a5' }}
                        >
                            ❌ Bekor
                        </button>
                    )}
                </div>
            )}

            {cancelOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                    onClick={() => setCancelOpen(false)}
                >
                    <div
                        className="w-full max-w-sm rounded-2xl border border-gray-700 bg-[#1b1f27] p-5"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="text-[17px] font-bold">Buyurtmani bekor qilish</div>
                        <div className="mt-1 text-[13px] text-gray-400">{order.order_number} · sababni tanlang</div>

                        <div className="mt-4 flex flex-col gap-2">
                            {CANCEL_PRESETS.map((p) => (
                                <label key={p} className="flex items-center gap-2 rounded-lg border border-gray-700 px-3 py-2.5 text-[15px]">
                                    <input type="radio" name={`c-${order.id}`} checked={reason === p} onChange={() => setReason(p)} />
                                    {p}
                                </label>
                            ))}
                            <label className="flex items-center gap-2 rounded-lg border border-gray-700 px-3 py-2.5 text-[15px]">
                                <input type="radio" name={`c-${order.id}`} checked={reason === '__other__'} onChange={() => setReason('__other__')} />
                                Boshqa sabab
                            </label>
                            {reason === '__other__' && (
                                <textarea
                                    value={otherText}
                                    onChange={(e) => setOtherText(e.target.value)}
                                    rows={2}
                                    maxLength={500}
                                    placeholder="Sababni yozing…"
                                    className="rounded-lg border border-gray-700 bg-[#0f1115] px-3 py-2 text-[15px] text-gray-100"
                                />
                            )}
                        </div>

                        <div className="mt-5 flex flex-col gap-2">
                            <button
                                onClick={confirmCancel}
                                disabled={!finalReason}
                                className="h-12 w-full rounded-xl text-[15px] font-bold text-white disabled:opacity-40"
                                style={{ background: '#b91c1c' }}
                            >
                                Bekor qilishni tasdiqlash
                            </button>
                            <button
                                onClick={() => setCancelOpen(false)}
                                className="h-12 w-full rounded-xl bg-gray-800 text-[15px] font-bold text-gray-300"
                            >
                                Yopish
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {askOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                    onClick={() => setAskOpen(false)}
                >
                    <div
                        className="w-full max-w-sm rounded-2xl border border-gray-700 bg-[#1b1f27] p-5"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {askStep === 'type' && (
                            <>
                                <div className="text-[17px] font-bold">Kuryer turini tanlang</div>
                                <div className="mt-1 text-[13px] text-gray-400">{order.order_number}</div>

                                <div className="mt-5 flex flex-col gap-2">
                                    <button
                                        onClick={() => setAskStep('own')}
                                        className="h-14 w-full rounded-xl text-[16px] font-bold text-white"
                                        style={{ background: STATUS_COLOR.preparing }}
                                    >
                                        🛵 O‘z kuryer bilan
                                    </button>
                                    <button
                                        onClick={() => setAskStep('taxi')}
                                        className="h-14 w-full rounded-xl text-[16px] font-bold text-white"
                                        style={{ background: '#ca8a04' }}
                                    >
                                        🚕 Royal Taxi orqali
                                    </button>
                                </div>
                            </>
                        )}

                        {askStep === 'own' && (
                            <>
                                <div className="text-[17px] font-bold">Xodimni tanlang</div>
                                <div className="mt-1 text-[13px] text-gray-400">Mijozga kuryer ismi va telefoni yuboriladi.</div>

                                <select
                                    value={courierId}
                                    onChange={(e) => setCourierId(e.target.value)}
                                    className="mt-4 h-12 w-full rounded-lg border border-gray-700 bg-[#0f1115] px-3 text-[15px] text-gray-100"
                                >
                                    <option value="">— tanlanmagan —</option>
                                    {couriers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}{c.phone ? ` (${c.phone})` : ''}
                                        </option>
                                    ))}
                                </select>
                                {couriers.length === 0 && (
                                    <div className="mt-2 text-[13px] text-amber-400">
                                        Xodimlar ro‘yxati bo‘sh — admin panelda qo‘shing.
                                    </div>
                                )}

                                <div className="mt-5 flex flex-col gap-2">
                                    <button
                                        onClick={withCourier}
                                        disabled={!courierId}
                                        className="h-12 w-full rounded-xl text-[15px] font-bold text-white disabled:opacity-40"
                                        style={{ background: STATUS_COLOR.preparing }}
                                    >
                                        Davom etish
                                    </button>
                                    <button
                                        onClick={withoutCourier}
                                        className="h-12 w-full rounded-xl bg-gray-800 text-[15px] font-bold text-gray-300"
                                    >
                                        Kuryersiz davom etish
                                    </button>
                                    <button
                                        onClick={() => setAskStep('type')}
                                        className="h-10 w-full text-[14px] font-medium text-gray-400"
                                    >
                                        ‹ Orqaga
                                    </button>
                                </div>
                            </>
                        )}

                        {askStep === 'taxi' && (
                            <>
                                <div className="text-[17px] font-bold">Royal Taxi</div>
                                <div className="mt-1 text-[13px] text-gray-400">
                                    Haydovchining telefon raqamini kiriting — +998 shart emas, o‘zi qo‘shiladi.
                                </div>

                                <input
                                    type="tel"
                                    inputMode="tel"
                                    value={taxiPhone}
                                    onChange={(e) => setTaxiPhone(e.target.value)}
                                    placeholder="901112233 yoki +998901112233"
                                    className="mt-4 h-12 w-full rounded-lg border border-gray-700 bg-[#0f1115] px-3 text-[15px] text-gray-100"
                                />
                                {taxiPhone && !taxiPhoneValid && (
                                    <div className="mt-2 text-[13px] text-red-400">
                                        Raqam noto‘g‘ri — masalan 901112233 yoki +998901112233 kabi yozing.
                                    </div>
                                )}

                                <div className="mt-5 flex flex-col gap-2">
                                    <button
                                        onClick={withTaxi}
                                        disabled={!taxiPhoneValid}
                                        className="h-12 w-full rounded-xl text-[15px] font-bold text-white disabled:opacity-40"
                                        style={{ background: '#ca8a04' }}
                                    >
                                        Tasdiqlash
                                    </button>
                                    <button
                                        onClick={() => setAskStep('type')}
                                        className="h-10 w-full text-[14px] font-medium text-gray-400"
                                    >
                                        ‹ Orqaga
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
