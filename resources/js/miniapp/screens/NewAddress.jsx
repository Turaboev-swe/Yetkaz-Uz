import { useEffect, useRef, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { api } from '../lib/api';
import { showBackButton, haptic, notify } from '../lib/telegram';
import { useSession } from '../store/session';

const ANDIJAN = { lat: 40.7825, lng: 72.35 };
const LABELS = ['Uy', 'Ish', 'Boshqa'];

/** B: "Yangi manzil" — xaritada nuqta tanlanadi, label so'raladi, saqlanadi. */
export default function NewAddress() {
    const navigate = useNavigate();
    const mapEl = useRef(null);
    const mapRef = useRef(null);
    const revTimer = useRef(null);

    const [point, setPoint] = useState(ANDIJAN);
    const [geo, setGeo] = useState(null); // { district_name, address_text }
    const [loadingGeo, setLoadingGeo] = useState(false);
    const [label, setLabel] = useState('Uy');
    const [customLabel, setCustomLabel] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const confirmDelivery = useSession((s) => s.confirmDelivery);

    useEffect(() => showBackButton(() => navigate(-1)), [navigate]);

    const reverse = useCallback((lat, lng) => {
        clearTimeout(revTimer.current);
        setLoadingGeo(true);
        revTimer.current = setTimeout(() => {
            api.reverse(lat, lng)
                .then((r) => setGeo(r.data))
                .catch(() => setGeo(null))
                .finally(() => setLoadingGeo(false));
        }, 400);
    }, []);

    useEffect(() => {
        const map = L.map(mapEl.current, { zoomControl: false, attributionControl: false })
            .setView([ANDIJAN.lat, ANDIJAN.lng], 14);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
        L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);

        const onMove = () => {
            const c = map.getCenter();
            setPoint({ lat: c.lat, lng: c.lng });
            reverse(c.lat, c.lng);
        };
        map.on('moveend', onMove);
        mapRef.current = map;
        setTimeout(() => map.invalidateSize(), 0);
        reverse(ANDIJAN.lat, ANDIJAN.lng);

        return () => {
            map.off('moveend', onMove);
            map.remove();
        };
    }, [reverse]);

    const save = async () => {
        const finalLabel = label === 'Boshqa' ? customLabel.trim() || 'Manzil' : label;
        setSaving(true);
        setError(null);
        try {
            const res = await api.createAddress({
                label: finalLabel,
                lat: point.lat,
                lng: point.lng,
                address_text: geo?.address_text,
                is_default: true,
            });
            notify('success');
            confirmDelivery(res.data.id);
            navigate('/', { replace: true });
        } catch (e) {
            setError(e.message);
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 flex flex-col" style={{ background: 'var(--tg-bg)' }}>
            <div className="relative flex-1">
                <div ref={mapEl} className="absolute inset-0" />
                {/* markazdagi qo'zg'almas nuqta */}
                <div className="pointer-events-none absolute left-1/2 top-1/2 z-[500] -translate-x-1/2 -translate-y-full text-3xl drop-shadow">
                    📍
                </div>
            </div>

            <div
                className="rounded-t-2xl px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3"
                style={{ background: 'var(--tg-secondary-bg)' }}
            >
                <div className="mb-3 min-h-[2.5rem]">
                    <p className="text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                        {loadingGeo ? 'Aniqlanmoqda…' : geo?.address_text || 'Xaritani suring'}
                    </p>
                    {geo?.district_name && !loadingGeo && (
                        <p className="text-[12px]" style={{ color: 'var(--tg-hint)' }}>{geo.district_name}</p>
                    )}
                </div>

                <div className="mb-3 flex gap-2">
                    {LABELS.map((l) => (
                        <button
                            key={l}
                            onClick={() => {
                                haptic('light');
                                setLabel(l);
                            }}
                            className="rounded-full px-3.5 py-1.5 text-[13px] font-medium"
                            style={{
                                background: label === l ? 'var(--tg-button)' : 'var(--tg-section-bg)',
                                color: label === l ? 'var(--tg-button-text)' : 'var(--tg-text)',
                            }}
                        >
                            {l}
                        </button>
                    ))}
                </div>

                {label === 'Boshqa' && (
                    <input
                        value={customLabel}
                        onChange={(e) => setCustomLabel(e.target.value)}
                        placeholder="Masalan: Onamning uyi"
                        maxLength={40}
                        className="mb-3 w-full rounded-xl px-3 py-2 text-[14px] outline-none"
                        style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-text)' }}
                    />
                )}

                {error && <p className="mb-2 text-[13px]" style={{ color: 'var(--tg-destructive)' }}>{error}</p>}

                <button
                    onClick={save}
                    disabled={saving}
                    className="h-11 w-full rounded-xl text-[15px] font-semibold disabled:opacity-60"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    {saving ? 'Saqlanmoqda…' : 'Saqlash'}
                </button>
            </div>
        </div>
    );
}
