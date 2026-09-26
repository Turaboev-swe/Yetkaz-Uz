import Thumb from './Thumb';
import QtyControl from './QtyControl';
import { som } from '../lib/format';

/** Rasm yoki nom bosilsa `onOpen` (tafsilot oynasi); qo'shish tugmasi darhol savatga qo'shadi. */
export default function DishCard({ product, qty, onAdd, onRemove, onOpen }) {
    return (
        <div className="flex flex-col rounded-2xl p-2.5" style={{ background: 'var(--tg-section-bg)' }}>
            <button type="button" onClick={onOpen} className="mb-2 flex justify-center" aria-label={product.name}>
                <Thumb url={product.photo_url} name={product.name} className="h-24 w-24" />
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
                style={{ color: 'var(--tg-text)' }}
            >
                {product.name}
            </button>

            <div className="mt-auto">
                <QtyControl qty={qty} onAdd={onAdd} onRemove={onRemove} />
            </div>
        </div>
    );
}
