import { useState, useMemo, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useAsync } from '../hooks/useAsync';
import { hideBackButton } from '../lib/telegram';
import { useSession } from '../store/session';
import AddressBar from '../components/AddressBar';
import DistrictFilter from '../components/DistrictFilter';
import RestaurantCard from '../components/RestaurantCard';
import AddressConfirmSheet from '../components/AddressConfirmSheet';
import AddressPickerSheet from '../components/AddressPickerSheet';
import { Spinner, ErrorState, EmptyState } from '../components/States';

export default function RestaurantList() {
    useEffect(hideBackButton, []);

    const base = useAsync(() => Promise.all([api.me(), api.districts()]), []);

    if (base.loading) return <Spinner />;
    if (base.error) return <ErrorState error={base.error} onRetry={base.reload} />;

    const [me, districtsRes] = base.data;
    return <Flow addresses={me.data?.addresses || []} districts={districtsRes.data || []} />;
}

function Flow({ addresses, districts }) {
    const navigate = useNavigate();
    const { addressId, mode, ready, confirmDelivery, choosePickup } = useSession();
    const [sheet, setSheet] = useState(null); // null | 'confirm' | 'pick'

    const current =
        addresses.find((a) => a.id === addressId) ||
        addresses.find((a) => a.is_default) ||
        addresses[0] ||
        null;

    // A: ilova ochilganda — tasdiq (manzil bor bo'lsa) yoki to'g'ridan-to'g'ri tanlash.
    useEffect(() => {
        if (ready) {
            setSheet(null);
        } else {
            setSheet(addresses.length === 0 ? 'pick' : 'confirm');
        }
    }, [ready, addresses.length]);

    const pickAddress = (a) => {
        confirmDelivery(a.id);
        setSheet(null);
    };
    const pickup = () => {
        if (addresses.length === 0) {
            navigate('/address/new');
            return;
        }
        choosePickup();
        setSheet(null);
    };

    return (
        <>
            {ready ? (
                <Results
                    address={current}
                    pickup={mode === 'pickup'}
                    districts={districts}
                    onChangeAddress={() => setSheet('pick')}
                />
            ) : (
                <div className="pt-24">
                    <Spinner />
                </div>
            )}

            <AddressConfirmSheet
                open={sheet === 'confirm'}
                address={current}
                onYes={() => current && pickAddress(current)}
                onNo={() => setSheet('pick')}
            />

            <AddressPickerSheet
                open={sheet === 'pick'}
                onClose={() => setSheet(null)}
                dismissible={ready}
                addresses={addresses}
                currentId={addressId}
                mode={mode}
                onPickAddress={pickAddress}
                onPickup={pickup}
            />
        </>
    );
}

function Results({ address, pickup, districts, onChangeAddress }) {
    const [districtId, setDistrictId] = useState(null);

    const list = useAsync(
        () =>
            address
                ? api.restaurants({
                      address_id: address.id,
                      include_closed: 1,
                      district_id: districtId ?? undefined,
                  })
                : Promise.resolve({ data: [] }),
        [address?.id, districtId],
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
            <AddressBar address={address} pickup={pickup} onClick={onChangeAddress} />

            <div className="sticky top-0 z-10 -mx-4 px-4 pb-2 pt-1" style={{ background: 'var(--tg-bg)' }}>
                <DistrictFilter districts={districts} value={districtId} onChange={setDistrictId} />
            </div>

            {list.loading && <Spinner />}
            {list.error && <ErrorState error={list.error} onRetry={list.reload} />}

            {!list.loading && !list.error && (
                <>
                    {open.length === 0 && closed.length === 0 && (
                        <EmptyState>Bu hududda yetkazadigan restoran topilmadi.</EmptyState>
                    )}

                    <div className="mt-2 space-y-2">
                        {open.map((r) => (
                            <RestaurantCard key={r.id} restaurant={r} />
                        ))}
                    </div>

                    {closed.length > 0 && (
                        <>
                            <p className="mb-2 mt-6 text-[12px] font-medium uppercase tracking-wide" style={{ color: 'var(--tg-hint)' }}>
                                Hozir yopiq
                            </p>
                            <div className="space-y-2">
                                {closed.map((r) => (
                                    <RestaurantCard key={r.id} restaurant={r} />
                                ))}
                            </div>
                        </>
                    )}
                </>
            )}
        </div>
    );
}
