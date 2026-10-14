import { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { chatBus } from '../lib/chatBus';

const MAX = 500;

const QUICK = [
    { key: 'accepted', label: 'Buyurtma qabul qilindi' },
    { key: 'clarify_address', label: 'Manzilni aniqlashtiring' },
    { key: 'unreachable', label: 'Raqamingizga ulanib bo‘lmadi' },
];

function time(iso) {
    return iso ? new Date(iso).toLocaleTimeString('uz-UZ', { hour: '2-digit', minute: '2-digit' }) : '';
}

/**
 * Buyurtma chati (oshxona tomoni). Matn React orqali chiqariladi (avtomatik
 * escape) — hech qachon innerHTML emas. Mijozning Telegram profili ko'rsatilmaydi:
 * faqat "Mijoz" va restoran nomi.
 */
export default function ChatPanel({ order, onRead }) {
    const [messages, setMessages] = useState([]);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [open, setOpen] = useState(order.chat?.open ?? true);
    const endRef = useRef(null);

    const add = (msg) => setMessages((cur) => (cur.some((m) => m.id === msg.id) ? cur : [...cur, msg]));

    // Ochilganda tarix yuklanadi (server mijoz xabarlarini o'qilgan deb belgilaydi).
    useEffect(() => {
        let alive = true;
        api.chatMessages(order.id)
            .then((r) => {
                if (!alive) return;
                setMessages(r.data || []);
                setOpen(r.meta?.open ?? true);
                onRead(order.id);
            })
            .catch((e) => alive && setError(e.message));

        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order.id]);

    // Real-time (Reverb → chatBus). Panel ochiq turganda kelgan mijoz xabari darhol o'qilgan.
    useEffect(
        () =>
            chatBus.subscribe(order.id, (msg) => {
                add(msg);
                if (msg.sender === 'customer') {
                    api.chatRead(order.id).catch(() => {});
                    onRead(order.id);
                }
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [order.id],
    );

    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);

    const run = async (fn) => {
        setBusy(true);
        setError(null);
        try {
            const r = await fn();
            add(r.data);
            return true;
        } catch (e) {
            setError(e.message);
            return false;
        } finally {
            setBusy(false);
        }
    };

    const send = async () => {
        const body = text.trim();
        if (!body || busy) return;
        if (await run(() => api.chatSend(order.id, { body }))) setText('');
    };

    const quick = (key) => !busy && run(() => api.chatSend(order.id, { quick: key }));

    return (
        <div className="mt-3 rounded-xl border border-gray-700 bg-[#0f1115] p-3">
            <div className="max-h-56 space-y-2 overflow-y-auto pr-1">
                {messages.length === 0 && <p className="py-2 text-center text-[13px] text-gray-500">Xabarlar yo‘q</p>}
                {messages.map((m) => (
                    <div key={m.id} className={m.sender === 'restaurant' ? 'text-right' : 'text-left'}>
                        <div
                            className="inline-block max-w-[85%] whitespace-pre-wrap break-words rounded-xl px-3 py-1.5 text-left text-[14px]"
                            style={m.sender === 'restaurant' ? { background: '#1e3a8a', color: '#dbeafe' } : { background: '#262b36', color: '#e5e7eb' }}
                        >
                            <div className="text-[11px] font-bold opacity-70">{m.sender_label} · {time(m.created_at)}</div>
                            {m.body}
                        </div>
                    </div>
                ))}
                <div ref={endRef} />
            </div>

            {order.chat?.awaiting_phone && (
                <p className="mt-2 text-[12px] text-amber-400">📵 Mijozning yangi raqami kutilmoqda</p>
            )}
            {error && <p className="mt-2 text-[12px] text-red-400">{error}</p>}

            {open ? (
                <>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {QUICK.map((q) => (
                            <button
                                key={q.key}
                                onClick={() => quick(q.key)}
                                disabled={busy}
                                className="rounded-lg bg-gray-800 px-2.5 py-1.5 text-[12px] font-semibold text-gray-200 hover:bg-gray-700 disabled:opacity-50"
                            >
                                {q.label}
                            </button>
                        ))}
                    </div>
                    <div className="mt-2 flex gap-2">
                        <textarea
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    send();
                                }
                            }}
                            rows={2}
                            maxLength={MAX}
                            placeholder="Mijozga xabar…"
                            className="min-w-0 flex-1 rounded-lg border border-gray-700 bg-[#171a21] px-3 py-2 text-[14px] text-gray-100"
                        />
                        <button
                            onClick={send}
                            disabled={busy || !text.trim()}
                            className="shrink-0 rounded-lg bg-blue-600 px-4 text-[14px] font-bold text-white disabled:opacity-40"
                        >
                            Yuborish
                        </button>
                    </div>
                    <div className="mt-1 text-right text-[11px] text-gray-500">{text.length}/{MAX}</div>
                </>
            ) : (
                <p className="mt-2 text-[12px] text-gray-500">Bu buyurtma bo‘yicha chat yopilgan.</p>
            )}
        </div>
    );
}
