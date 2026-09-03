import { useCallback, useEffect, useRef, useState } from 'react';
import { echo } from './lib/echo';
import { api } from './lib/api';
import { enableSound, soundReady, newOrderChime } from './lib/sound';
import OrderCard from './components/OrderCard';

const { restaurantId, restaurantName, staffName, csrf } = window.__KITCHEN__;
const DONE = new Set(['delivered', 'cancelled']);

export default function App() {
    const [orders, setOrders] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [connected, setConnected] = useState(false);
    const [soundOn, setSoundOn] = useState(soundReady());
    const [busyId, setBusyId] = useState(null);
    const seen = useRef(new Set());

    const sortInsert = useCallback((list) => [...list].sort((a, b) => a.created_at.localeCompare(b.created_at)), []);

    const load = useCallback(() => {
        setLoading(true);
        api.orders()
            .then((r) => {
                const rows = r.data || [];
                rows.forEach((o) => seen.current.add(o.id));
                setOrders(sortInsert(rows));
                setError(null);
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [sortInsert]);

    useEffect(load, [load]);

    // Reverb — mavjud bo'lmasa (VITE_REVERB_* yo'q) 15s polling bilan ishlaymiz.
    useEffect(() => {
        if (!echo) {
            setConnected(false);
            const t = setInterval(load, 15000);
            return () => clearInterval(t);
        }

        const ch = echo.private(`kitchen.${restaurantId}`);

        ch.listen('.order.placed', (o) => {
            setOrders((cur) => {
                if (seen.current.has(o.id)) return cur;
                seen.current.add(o.id);
                newOrderChime();
                return sortInsert([...cur, o]);
            });
        });

        ch.listen('.order.status', (e) => {
            setOrders((cur) =>
                DONE.has(e.status)
                    ? cur.filter((o) => o.id !== e.id)
                    : cur.map((o) => (o.id === e.id ? { ...o, status: e.status, status_label: e.status_label } : o)),
            );
        });

        ch.listen('.order.dispatch_failed', (e) => {
            setOrders((cur) => cur.map((o) => (o.id === e.id ? { ...o, dispatch_failed: true } : o)));
        });

        const pusher = echo.connector.pusher;
        pusher.connection.bind('connected', () => setConnected(true));
        pusher.connection.bind('unavailable', () => setConnected(false));
        pusher.connection.bind('disconnected', () => setConnected(false));
        setConnected(pusher.connection.state === 'connected');

        return () => echo.leave(`kitchen.${restaurantId}`);
    }, [sortInsert, load]);

    const advance = async (id) => {
        setBusyId(id);
        try {
            const r = await api.advance(id);
            const updated = r.data;
            setOrders((cur) =>
                DONE.has(updated.status) ? cur.filter((o) => o.id !== id) : cur.map((o) => (o.id === id ? updated : o)),
            );
        } catch (e) {
            setError(e.message);
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="min-h-screen">
            <header className="sticky top-0 z-10 flex items-center justify-between border-b border-gray-800 bg-[#0f1115] px-5 py-3">
                <div>
                    <h1 className="text-[18px] font-extrabold">{restaurantName} — Oshxona</h1>
                    <p className="text-[12px] text-gray-500">{staffName}</p>
                </div>
                <div className="flex items-center gap-3">
                    {!soundOn && (
                        <button
                            onClick={() => setSoundOn(enableSound())}
                            className="rounded-lg bg-amber-600 px-3 py-2 text-[13px] font-bold text-white"
                        >
                            🔔 Ovozni yoqish
                        </button>
                    )}
                    <span className="flex items-center gap-1.5 text-[12px] text-gray-400">
                        <span
                            className="inline-block h-2.5 w-2.5 rounded-full"
                            style={{ background: connected ? '#22c55e' : '#ef4444' }}
                        />
                        {connected ? 'ulangan' : 'uzilgan'}
                    </span>
                    <span className="rounded-lg bg-gray-800 px-2.5 py-1 text-[14px] font-bold tabular-nums">{orders.length}</span>
                    <form method="POST" action="/kitchen/logout">
                        <input type="hidden" name="_token" value={csrf} />
                        <button type="submit" className="rounded-lg bg-gray-800 px-3 py-2 text-[13px] text-gray-300 hover:bg-gray-700">
                            Chiqish
                        </button>
                    </form>
                </div>
            </header>

            <main className="p-4">
                {loading && <p className="p-8 text-center text-gray-500">Yuklanmoqda…</p>}
                {error && (
                    <div className="mb-4 rounded-xl bg-red-950 p-3 text-[14px] text-red-300">
                        {error} <button onClick={load} className="ml-2 underline">qayta</button>
                    </div>
                )}
                {!loading && orders.length === 0 && (
                    <p className="p-12 text-center text-[16px] text-gray-500">Faol buyurtma yo‘q</p>
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {orders.map((o) => (
                        <OrderCard key={o.id} order={o} onAdvance={advance} busy={busyId === o.id} />
                    ))}
                </div>
            </main>
        </div>
    );
}
