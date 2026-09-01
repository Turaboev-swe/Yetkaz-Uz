import { useEffect, useState } from 'react';
import { som, agoLabel, nextActionLabel } from '../lib/format';

const STATUS_COLOR = {
    new: '#f59e0b',
    accepted: '#3b82f6',
    preparing: '#8b5cf6',
    on_the_way: '#06b6d4',
};

export default function OrderCard({ order, onAdvance, busy }) {
    const [, tick] = useState(0);
    useEffect(() => {
        const t = setInterval(() => tick((n) => n + 1), 15000);
        return () => clearInterval(t);
    }, []);

    const pickup = order.delivery_type === 'pickup';
    const actionLabel = nextActionLabel(order.status, order.delivery_type);
    const addr = order.address;

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

            {actionLabel && (
                <button
                    onClick={() => onAdvance(order.id)}
                    disabled={busy}
                    className="mt-4 h-14 w-full rounded-xl text-[17px] font-bold disabled:opacity-50"
                    style={{ background: STATUS_COLOR[order.status] || '#2563eb', color: '#fff' }}
                >
                    {busy ? '…' : actionLabel}
                </button>
            )}
        </div>
    );
}
