import { useState, useMemo, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { api } from '../lib/api';
import { useAsync } from '../hooks/useAsync';
import { hideBackButton } from '../lib/telegram';
import { useSession } from '../store/session';
import AddressBar from '../components/AddressBar';
import DistrictFilter from '../components/DistrictFilter';
import RestaurantCard from '../components/RestaurantCard';
import AddressConfirmSheet from '../components/AddressConfirmSheet';
import AddressPickerSheet from '../components/AddressPickerSheet';
import DeliveryModeSheet from '../components/DeliveryModeSheet';
import { Spinner, ErrorState, EmptyState } from '../components/States';

export default function RestaurantList() {
    useEffect(hideBackButton, []);
    const location = useLocation();
    const navigate = useNavigate();

    // Bir martalik: NewAddress.jsx dan hozirgina qo'shilgan manzil id'si
    // qaysi — mount'da o'qib olamiz va tarix holatidan darhol tozalaymiz,
    // aks holda keyinroq shu sahifaga "Orqaga" bilan qaytilsa qayta ishga tushib
    // ketardi (rejim tanlash qaytadan ochilib qolardi).
    const [justAddedAddressId] = useState(() => location.state?.justAddedAddressId ?? null);
    useEffect(() => {
        if (location.state?.justAddedAddressId) {
            navigate(location.pathname, { replace: true, state: {} });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const base = useAsync(() => Promise.all([api.me(), api.districts()]), []);

    if (base.loading) return <Spinner />;
    if (base.error) return <ErrorState error={base.error} onRetry={base.reload} />;

    const [me, districtsRes] = base.data;
    return (
        <Flow
            addresses={me.data?.addresses || []}
            districts={districtsRes.data || []}
            justAddedAddressId={justAddedAddressId}
        />
    );
}

/**
 * Oqim: manzil tasdiqlash/tanlash -> yetkazish/olib ketish rejimi -> ro'yxat.
 * Rejim har doim manzildan KEYIN, ro'yxatdan OLDIN so'raladi.
 */
function Flow({ addresses: initialAddresses, districts, justAddedAddressId }) {
    const navigate = useNavigate();
    const { addressId, mode, ready, setAddress, confirmDelivery, choosePickup, reset } = useSession();
    const [step, setStep] = useState(null); // null | 'confirm' | 'pick' | 'mode'
    // Server ro'yxatidan boshlab olinadi, tahrirlash/o'chirishdan keyin lokal
    // yangilanadi — har safar butun ekranni (me + districts) qayta yuklamaslik
    // uchun (sheet ochiq turgan holda spinner miltillamasin).
    const [addresses, setAddresses] = useState(initialAddresses);

    const current =
        addresses.find((a) => a.id === addressId) ||
        addresses.find((a) => a.is_default) ||
        addresses[0] ||
        null;

    const updateAddress = async (id, patch) => {
        const res = await api.updateAddress(id, patch);
        setAddresses((list) => list.map((a) => (a.id === id ? res.data : a)));
    };

    const removeAddress = async (id) => {
        await api.deleteAddress(id);
        // Backend default'ni qayta tayinlagan bo'lishi mumkin (o'chirilgan
        // manzil default edi) — haqiqiy holatni qayta so'raymiz.
        const fresh = await api.addresses();
        const nextList = fresh.data || [];
        setAddresses(nextList);

        if (addressId === id) {
            const fallback = nextList.find((a) => a.is_default) || nextList[0] || null;
            if (fallback) {
                setAddress(fallback.id);
            } else {
                reset();
                navigate('/address/new');
            }
        }
    };

    useEffect(() => {
        // Yangi manzil hozirgina qo'shildi (NewAddress.jsx) — to'g'ridan-to'g'ri
        // rejim tanlashga, qayta tasdiqlash so'ralmaydi.
        if (justAddedAddressId) {
            setAddress(justAddedAddressId);
            setStep('mode');
            return;
        }

        if (ready) {
            setStep(null);
        } else {
            setStep(addresses.length === 0 ? 'pick' : 'confirm');
        }
        // `addresses.length` emas, `initialAddresses.length` — sheet ichida
        // tahrirlash/o'chirish paytida `addresses` lokal o'zgaradi, bu effekt
        // shu tufayli qayta ishga tushib step'ni tasodifan yopib qo'ymasin.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, initialAddresses.length, justAddedAddressId]);

    const goToMode = (addrId) => {
        setAddress(addrId);
        setStep('mode');
    };

    const pickAddress = (a) => goToMode(a.id);

    return (
        <>
            {ready ? (
                <Results
                    address={current}
                    pickup={mode === 'pickup'}
                    districts={districts}
                    onChangeAddress={() => setStep('pick')}
                    onChangeMode={() => setStep('mode')}
                />
            ) : (
                <div className="pt-24">
                    <Spinner />
                </div>
            )}

            <AddressConfirmSheet
                open={step === 'confirm'}
                address={current}
                onYes={() => current && goToMode(current.id)}
                onNo={() => setStep('pick')}
            />

            <AddressPickerSheet
                open={step === 'pick'}
                onClose={() => setStep(ready ? null : 'confirm')}
                dismissible={ready}
                addresses={addresses}
                currentId={addressId}
                onPickAddress={pickAddress}
                onUpdateAddress={updateAddress}
                onDeleteAddress={removeAddress}
            />

            <DeliveryModeSheet
                open={step === 'mode'}
                onClose={() => setStep(ready ? null : 'pick')}
                dismissible={ready}
                address={current}
                onDelivery={() => {
                    confirmDelivery(addressId);
                    setStep(null);
                }}
                onPickup={() => {
                    choosePickup(addressId);
                    setStep(null);
                }}
            />
        </>
    );
}

function Results({ address, pickup, districts, onChangeAddress, onChangeMode }) {
    const [districtId, setDistrictId] = useState(null);

    const list = useAsync(
        () =>
            address
                ? api.restaurants({
                      address_id: address.id,
                      include_closed: 1,
                      district_id: districtId ?? undefined,
                      delivery_type: pickup ? 'pickup' : 'delivery',
                  })
                : Promise.resolve({ data: [] }),
        [address?.id, districtId, pickup],
    );

    const { open, closed } = useMemo(() => {
        const rows = list.data?.data || [];
        return {
            open: rows.filter((r) => r.is_open_now),
            closed: rows.filter((r) => !r.is_open_now),
        };
    }, [list.data]);

    return (
        <div className="mx-auto max-w-md px-4 pb-10 pt-1">
            <div className="flex items-center justify-between gap-2">
                <AddressBar address={address} pickup={pickup} onClick={onChangeAddress} />
                <button
                    onClick={onChangeMode}
                    className="shrink-0 rounded-full px-2.5 py-1 text-[12px] font-medium"
                    style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-hint)' }}
                >
                    {pickup ? '🛍 Olib ketaman' : '🛵 Yetkazish'}
                </button>
            </div>

            <div className="sticky top-0 z-10 -mx-4 px-4 pb-2 pt-1" style={{ background: 'var(--tg-bg)' }}>
                <DistrictFilter districts={districts} value={districtId} onChange={setDistrictId} />
            </div>

            {list.loading && <Spinner />}
            {list.error && <ErrorState error={list.error} onRetry={list.reload} />}

            {!list.loading && !list.error && (
                <>
                    {open.length === 0 && closed.length === 0 && (
                        <EmptyState>
                            {pickup ? 'Yaqin atrofda restoran topilmadi.' : 'Bu hududda yetkazadigan restoran topilmadi.'}
                        </EmptyState>
                    )}

                    <div className="mt-2 space-y-2">
                        {open.map((r) => (
                            <RestaurantCard key={r.id} restaurant={r} pickup={pickup} />
                        ))}
                    </div>

                    {closed.length > 0 && (
                        <>
                            <p className="mb-2 mt-6 text-[12px] font-medium uppercase tracking-wide" style={{ color: 'var(--tg-hint)' }}>
                                Hozir yopiq
                            </p>
                            <div className="space-y-2">
                                {closed.map((r) => (
                                    <RestaurantCard key={r.id} restaurant={r} pickup={pickup} />
                                ))}
                            </div>
                        </>
                    )}
                </>
            )}
        </div>
    );
}
