import { useEffect, useRef, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { api } from '../lib/api';
import { showBackButton, haptic, notify } from '../lib/telegram';
import { composeAddress, streetOrLandmarkMissing } from '../lib/addressForm';

const ANDIJAN = { lat: 40.7825, lng: 72.35 };
const LABELS = ['Uy', 'Ish', 'Boshqa'];

/**
 * B: "Yangi manzil" — xaritada nuqta tanlanadi, ko'cha (tahrirlanadi) va mo'ljal kiritiladi, label so'raladi, saqlanadi.
 *
 * Ko'cha geokoderdan avtomatik to'ldiriladi (OSM'da ko'cha nomi ko'pincha yo'q — shunda maydon bo'sh qoladi).
 * Geokoder ko'cha topmasa ko'cha YOKI mo'ljaldan kamida bittasi majburiy (server ham tekshiradi).
 * Tuman va qishloq/mahalla nomi avtomatik qo'shiladi — mijoz faqat ko'chani yozadi.
 */
export default function NewAddress() {
    const navigate = useNavigate();
    const mapEl = useRef(null);
    const mapRef = useRef(null);
    const revTimer = useRef(null);
    // Oxirgi AVTOMAT to'ldirilgan ko'cha: maydon unga teng bo'lsa (mijoz tegmagan) xarita siljiganda yangilanadi.
    const autoStreet = useRef('');

    const [point, setPoint] = useState(ANDIJAN);
    const [geo, setGeo] = useState(null); // { district_name, locality, street, address_text }
    const [loadingGeo, setLoadingGeo] = useState(false);
    const [street, setStreet] = useState('');
    const [landmark, setLandmark] = useState('');
    const [label, setLabel] = useState('Uy');
    const [customLabel, setCustomLabel] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => showBackButton(() => navigate(-1)), [navigate]);

    const reverse = useCallback((lat, lng) => {
        clearTimeout(revTimer.current);
        setLoadingGeo(true);
        revTimer.current = setTimeout(() => {
            api.reverse(lat, lng)
                .then((r) => {
                    const found = r.data?.street || '';
                    setGeo(r.data);
                    setStreet((cur) => (cur === autoStreet.current ? found : cur));
                    autoStreet.current = found;
                })
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

    const streetFound = Boolean(geo?.street);
    const missing = streetOrLandmarkMissing(street, landmark);
    // Geokoder ko'cha topmagan joyda majburiylik haqida tushuntirish ko'rinadi (yozishni boshlagach yo'qoladi).
    const showRequiredHint = !loadingGeo && !streetFound && missing;
    const preview = composeAddress(street, geo?.locality, geo?.district_name);

    const save = async () => {
        if (missing) {
            notify('error');
            setError('Ko‘cha nomini yoki mo‘ljalni kiriting.');
            return;
        }

        const finalLabel = label === 'Boshqa' ? customLabel.trim() || 'Manzil' : label;
        setSaving(true);
        setError(null);
        try {
            // Manzil matni (ko'cha + qishloq + tuman) serverda yig'iladi — mijozdan faqat ko'cha va mo'ljal.
            const res = await api.createAddress({
                label: finalLabel,
                lat: point.lat,
                lng: point.lng,
                street: street.trim() || null,
                note: landmark.trim() || null,
                is_default: true,
            });
            notify('success');
            // Rejim (yetkazish/olib ketish) hali so'ralmagan — RestaurantList'da
            // shu manzil uchun to'g'ridan-to'g'ri so'raladi (qayta tasdiqlashsiz).
            navigate('/', { replace: true, state: { justAddedAddressId: res.data.id } });
        } catch (e) {
            // 422: server "ko'cha yoki mo'ljal kerak" desa — aynan shu xabar.
            setError(e.body?.errors?.street?.[0] || e.message);
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
                className="max-h-[70vh] overflow-y-auto rounded-t-2xl px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3"
                style={{ background: 'var(--tg-secondary-bg)' }}
            >
                <div className="mb-3 min-h-[2.5rem]">
                    <p className="text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                        {loadingGeo ? 'Aniqlanmoqda…' : preview || 'Xaritani suring'}
                    </p>
                </div>

                <label className="mb-1 block text-[12px] font-medium" style={{ color: 'var(--tg-hint)' }} htmlFor="addr-street">
                    Ko‘cha
                </label>
                <input
                    id="addr-street"
                    value={street}
                    onChange={(e) => setStreet(e.target.value)}
                    placeholder={streetFound ? 'Ko‘cha nomi' : 'Ko‘cha nomini yozing'}
                    maxLength={255}
                    autoComplete="off"
                    className="mb-2 w-full rounded-xl px-3 py-2 text-[14px] outline-none"
                    style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-text)' }}
                />

                <label className="mb-1 block text-[12px] font-medium" style={{ color: 'var(--tg-hint)' }} htmlFor="addr-landmark">
                    {streetFound || street.trim() ? 'Mo‘ljal (ixtiyoriy)' : 'Mo‘ljal'}
                </label>
                <input
                    id="addr-landmark"
                    value={landmark}
                    onChange={(e) => setLandmark(e.target.value)}
                    placeholder="Masalan: Hokimiyat orqasida"
                    maxLength={500}
                    autoComplete="off"
                    className="mb-2 w-full rounded-xl px-3 py-2 text-[14px] outline-none"
                    style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-text)' }}
                />

                {showRequiredHint && (
                    <p className="mb-3 text-[12px]" style={{ color: 'var(--tg-destructive)' }}>
                        Xaritada ko‘cha nomi topilmadi. Ko‘cha nomini yoki mo‘ljalni kiriting.
                    </p>
                )}

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
                    disabled={saving || loadingGeo || missing}
                    className="h-11 w-full rounded-xl text-[15px] font-semibold disabled:opacity-60"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    {saving ? 'Saqlanmoqda…' : 'Saqlash'}
                </button>
            </div>
        </div>
    );
}
