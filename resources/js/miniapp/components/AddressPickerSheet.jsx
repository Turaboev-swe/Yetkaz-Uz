import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import BottomSheet from './BottomSheet';
import { resolvedAddress } from '../lib/address';
import { haptic, notify, confirmDialog } from '../lib/telegram';

/**
 * B: manzil tanlash. Yuqoridan pastga — saqlangan manzillar (joriysi
 * belgilangan) / ➕ Yangi manzil. Nomlar DOIM o'zbekcha (districts jadvalidan).
 *
 * Har qatorda ✏️ (nomni tahrirlash) va 🗑 (o'chirish) — `onUpdateAddress` /
 * `onDeleteAddress` orqali RestaurantList.jsx (Flow) ga yuboriladi, chunki
 * joriy tanlangan manzil o'chirilganda sessiya holatini shu yer boshqaradi.
 */
export default function AddressPickerSheet({ open, onClose, dismissible = true, addresses, currentId, onPickAddress, onUpdateAddress, onDeleteAddress }) {
    const navigate = useNavigate();
    const [editingId, setEditingId] = useState(null);
    const [editValue, setEditValue] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [rowError, setRowError] = useState(null);

    const row = 'flex w-full items-center gap-3 rounded-xl p-3 text-left';

    const startEdit = (a) => {
        haptic('light');
        setRowError(null);
        setEditingId(a.id);
        setEditValue(a.label || '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditValue('');
    };

    const saveEdit = async (id) => {
        const label = editValue.trim();
        setBusyId(id);
        setRowError(null);
        try {
            await onUpdateAddress(id, { label });
            notify('success');
            cancelEdit();
        } catch (e) {
            setRowError(e.message);
        } finally {
            setBusyId(null);
        }
    };

    const remove = async (a) => {
        haptic('light');
        setRowError(null);
        const ok = await confirmDialog('Bu manzilni o‘chirmoqchimisiz?');
        if (!ok) return;

        setBusyId(a.id);
        try {
            await onDeleteAddress(a.id);
            notify('success');
        } catch (e) {
            setRowError(e.message);
        } finally {
            setBusyId(null);
        }
    };

    return (
        <BottomSheet open={open} onClose={onClose} dismissible={dismissible}>
            <p className="mb-3 text-[15px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                Manzilni tanlang
            </p>

            {rowError && (
                <p className="mb-2 text-[13px]" style={{ color: 'var(--tg-destructive)' }}>{rowError}</p>
            )}

            <div className="space-y-2">
                {addresses.map((a) => {
                    // Tanlashda LABEL ("Uy"/"Ish") foydali — pastida haqiqiy manzil.
                    const label = a.label || a.district || 'Manzil';
                    const detail = resolvedAddress(a);
                    const active = a.id === currentId;
                    const editing = editingId === a.id;
                    const busy = busyId === a.id;

                    if (editing) {
                        return (
                            <div key={a.id} className={row} style={{ background: 'var(--tg-section-bg)' }}>
                                <span aria-hidden>📍</span>
                                <input
                                    autoFocus
                                    value={editValue}
                                    onChange={(e) => setEditValue(e.target.value)}
                                    maxLength={40}
                                    placeholder="Manzil nomi"
                                    className="min-w-0 flex-1 rounded-lg px-2 py-1 text-[14px] outline-none"
                                    style={{ background: 'var(--tg-secondary-bg)', color: 'var(--tg-text)' }}
                                />
                                <button
                                    onClick={() => saveEdit(a.id)}
                                    disabled={busy}
                                    className="text-[13px] font-semibold disabled:opacity-50"
                                    style={{ color: 'var(--tg-link)' }}
                                >
                                    {busy ? '…' : 'Saqlash'}
                                </button>
                                <button onClick={cancelEdit} disabled={busy} className="text-[13px] disabled:opacity-50" style={{ color: 'var(--tg-hint)' }}>
                                    Bekor
                                </button>
                            </div>
                        );
                    }

                    return (
                        <div key={a.id} className={row} style={{ background: 'var(--tg-section-bg)' }}>
                            <button
                                onClick={() => {
                                    haptic('light');
                                    onPickAddress(a);
                                }}
                                className="flex min-w-0 flex-1 items-center gap-3 text-left"
                            >
                                <span aria-hidden>📍</span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>{label}</span>
                                    {detail && detail !== label && (
                                        <span className="block truncate text-[12px]" style={{ color: 'var(--tg-hint)' }}>{detail}</span>
                                    )}
                                </span>
                                {active && <span style={{ color: 'var(--tg-link)' }}>✓</span>}
                            </button>
                            <button onClick={() => startEdit(a)} disabled={busy} aria-label="Tahrirlash" className="shrink-0 px-1 disabled:opacity-50">
                                ✏️
                            </button>
                            <button onClick={() => remove(a)} disabled={busy} aria-label="O‘chirish" className="shrink-0 px-1 disabled:opacity-50">
                                {busy ? '…' : '🗑'}
                            </button>
                        </div>
                    );
                })}

                <button
                    onClick={() => {
                        haptic('light');
                        navigate('/address/new');
                    }}
                    className={row}
                    style={{ background: 'var(--tg-section-bg)' }}
                >
                    <span aria-hidden>➕</span>
                    <span className="flex-1 text-[14px] font-semibold" style={{ color: 'var(--tg-link)' }}>
                        Yangi manzil
                    </span>
                </button>
            </div>
        </BottomSheet>
    );
}
