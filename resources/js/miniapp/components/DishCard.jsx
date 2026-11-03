import ProductImage from './ProductImage';
import QtyControl from './QtyControl';
import { som } from '../lib/format';

/**
 * Rasm yoki nom bosilsa `onOpen` (tafsilot oynasi); qo'shish tugmasi darhol savatga qo'shadi.
 * Qoldiq: `sold_out` — kulrang «Tugadi» (savatga qo'shib bo'lmaydi); `low_stock` — «Faqat N ta qoldi!»;
 * savatdagi miqdor `stock` dan oshmaydi.
 */
export default function DishCard({ product, restaurantLogoUrl, qty, onAdd, onRemove, onOpen }) {
    const soldOut = Boolean(product.sold_out);

    return (
        <div className="flex flex-col rounded-2xl p-2.5" style={{ background: 'var(--tg-section-bg)' }}>
            <button type="button" onClick={onOpen} className="relative mb-2 flex justify-center" aria-label={product.name}>
                <span className={soldOut ? 'opacity-50 grayscale' : ''}>
                    <ProductImage
                        photoUrl={product.photo_url}
                        restaurantLogoUrl={restaurantLogoUrl}
                        name={product.name}
                        className="h-24 w-24"
                    />
                </span>
                {soldOut && (
                    <span
                        className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full px-2.5 py-1 text-[12px] font-bold"
                        style={{ background: 'var(--tg-secondary-bg)', color: 'var(--tg-hint)' }}
                    >
                        Tugadi
                    </span>
                )}
            </button>

            <div className="mb-1 flex items-baseline gap-1.5 tabular-nums">
                <span className="text-[14px] font-bold" style={{ color: product.old_price ? 'var(--tg-destructive)' : 'var(--tg-text)' }}>
                    {som(product.price)}
                </span>
                {product.old_price && (
                    <span className="text-[12px] line-through" style={{ color: 'var(--tg-hint)' }}>
                        {som(product.old_price)}
                    </span>
                )}
                <span className="text-[12px] font-medium" style={{ color: 'var(--tg-hint)' }}>so‘m</span>
            </div>

            <button
                type="button"
                onClick={onOpen}
                className="mb-2 line-clamp-2 text-left text-[13px] leading-tight"
                style={{ color: soldOut ? 'var(--tg-hint)' : 'var(--tg-text)' }}
            >
                {product.name}
            </button>

            {product.low_stock && !soldOut && (
                <p className="-mt-1 mb-2 text-[12px] font-semibold" style={{ color: 'var(--tg-destructive)' }}>
                    Faqat {product.stock} ta qoldi!
                </p>
            )}

            <div className="mt-auto">
                {soldOut ? (
                    <button
                        type="button"
                        disabled
                        className="h-9 w-full rounded-xl text-[13px] font-semibold"
                        style={{ background: 'var(--tg-secondary-bg)', color: 'var(--tg-hint)' }}
                    >
                        Tugadi
                    </button>
                ) : (
                    <QtyControl qty={qty} onAdd={onAdd} onRemove={onRemove} max={product.stock} />
                )}
            </div>
        </div>
    );
}
