import BottomSheet from './BottomSheet';
import Thumb from './Thumb';
import QtyControl from './QtyControl';
import { som } from '../lib/format';

/**
 * Taom tafsiloti: katta rasm, nom, narx, to'liq tavsif, tayyorlash vaqti va
 * savatga qo'shish. Kartochkaning rasmi yoki nomi bosilganda ochiladi.
 * Yopish: fon, pastga surish, Telegram BackButton (Menu ekranida ulanadi).
 */
export default function DishDetailSheet({ product, qty, onAdd, onRemove, onClose }) {
    const description = product?.description?.trim();

    return (
        <BottomSheet open={Boolean(product)} onClose={onClose} swipeToClose>
            {product && (
                <>
                    {product.photo_url ? (
                        // Rasmlar kvadrat (600/800px) — ekran kengligida sifat buzilmaydi.
                        <img
                            src={product.photo_url}
                            alt={product.name}
                            className="-mx-4 mb-3 aspect-square w-[calc(100%+2rem)] max-w-none object-cover"
                        />
                    ) : (
                        <div className="mb-3 flex justify-center">
                            <Thumb name={product.name} className="h-24 w-24" />
                        </div>
                    )}

                    <h2 className="text-[20px] font-bold leading-snug" style={{ color: 'var(--tg-text)' }}>
                        {product.name}
                    </h2>

                    <div className="mt-1 flex items-baseline gap-2 tabular-nums">
                        <span
                            className="text-[18px] font-bold"
                            style={{ color: product.old_price ? 'var(--tg-destructive)' : 'var(--tg-text)' }}
                        >
                            {som(product.price)} so‘m
                        </span>
                        {product.old_price && (
                            <span className="text-[14px] line-through" style={{ color: 'var(--tg-hint)' }}>
                                {som(product.old_price)}
                            </span>
                        )}
                    </div>

                    {product.prep_time_min > 0 && (
                        <p className="mt-1 text-[13px]" style={{ color: 'var(--tg-hint)' }}>
                            Tayyorlash: ~{product.prep_time_min} daqiqa
                        </p>
                    )}

                    {description && (
                        <p className="mt-3 whitespace-pre-line text-[14px] leading-relaxed" style={{ color: 'var(--tg-text)' }}>
                            {description}
                        </p>
                    )}

                    <div className="sticky bottom-0 -mx-4 mt-4 px-4 pt-2" style={{ background: 'var(--tg-secondary-bg)' }}>
                        <QtyControl qty={qty} onAdd={onAdd} onRemove={onRemove} addLabel="+ Savatga qo‘shish" />
                    </div>
                </>
            )}
        </BottomSheet>
    );
}
