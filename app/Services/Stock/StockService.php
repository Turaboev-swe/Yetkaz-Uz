<?php

namespace App\Services\Stock;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Mahsulot qoldig'i (products.stock_quantity; NULL = cheklanmagan).
 *
 * Yagona manba: buyurtma yaratish ham, bekor qilish ham, qisman qaytarish ham shu yerdan o'tadi.
 *
 *  - reserve()  — buyurtma tranzaksiyasi ICHIDA, atomik: `UPDATE ... SET stock = stock - :qty WHERE stock >= :qty`.
 *                 Yetmasa InsufficientStockException (butun tranzaksiya rollback). NULL qatorlarga tegilmaydi.
 *  - release()  — band qilingan qoldiqni qaytaradi. Faqat `orders.stock_reserved` dagi (HAQIQATAN kamaytirilgan)
 *                 miqdor qaytariladi va xaritadan ayiriladi: ikkinchi chaqiruvda qaytariladigan narsa qolmaydi.
 */
class StockService
{
    /**
     * @param  array<int, array{product_id: int, name: string, qty: int}>  $lines  bir mahsulot — bitta qator
     * @return array<int, int> haqiqatan kamaytirilganlar {product_id: qty} (cheksiz mahsulotlar kirmaydi)
     *
     * @throws InsufficientStockException
     */
    public function reserve(array $lines): array
    {
        // Doim bir xil tartibda (id o'sish bo'yicha): ikki buyurtma bir-birini kutib qotib qolmasin (deadlock).
        usort($lines, fn (array $a, array $b): int => $a['product_id'] <=> $b['product_id']);

        $reserved = [];
        $shortages = [];

        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $qty = (int) $line['qty'];

            $affected = DB::table('products')
                ->where('id', $id)
                ->whereNotNull('stock_quantity')
                ->where('stock_quantity', '>=', $qty)
                ->update(['stock_quantity' => DB::raw('stock_quantity - '.$qty), 'updated_at' => now()]);

            if ($affected === 1) {
                $reserved[$id] = $qty;

                continue;
            }

            // 0 qator: yoki cheklanmagan (NULL — muammo yo'q), yoki yetmaydi.
            $current = DB::table('products')->where('id', $id)->value('stock_quantity');
            if ($current !== null) {
                $shortages[] = ['product_id' => $id, 'name' => (string) $line['name'], 'available' => max(0, (int) $current)];
            }
        }

        if ($shortages !== []) {
            throw new InsufficientStockException($shortages);
        }

        return $reserved;
    }

    /**
     * Buyurtma band qilgan qoldiqni qaytaradi (bekor qilish yoki qisman buyurtmada «yo'q» qator).
     * Idempotent: qaytarilgan miqdor `stock_reserved` dan olib tashlanadi.
     *
     * @param  array<int, int>|null  $only  {product_id: qty} — faqat shu miqdorlar (qisman); null — hammasi
     * @return array<int, int> mahsulotga HAQIQATAN qo'shilganlar {product_id: qty}
     */
    public function release(Order $order, ?array $only = null): array
    {
        return DB::transaction(function () use ($order, $only): array {
            // Bir buyurtmani ikki oqim bir vaqtda qaytarmasin: buyurtma qatori qulflanadi.
            $locked = Order::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->first();
            $reserved = array_map('intval', (array) ($locked?->stock_reserved ?? []));

            if ($locked === null || $reserved === []) {
                return [];
            }

            $wanted = $only === null ? $reserved : $only;
            $restored = [];

            foreach ($wanted as $productId => $qty) {
                $productId = (int) $productId;
                $take = min((int) $qty, $reserved[$productId] ?? 0);

                if ($take <= 0) {
                    continue;
                }

                // Qoldiq cheklanmagan bo'lib qolgan bo'lsa (NULL) — tegilmaydi. Buyurtma oxirgi kunlik tiklanishdan OLDIN
                // berilgan bo'lsa ham tegilmaydi: kechagi buyurtma bugungi qoldiqni oshirmasin.
                $added = DB::table('products')
                    ->where('id', $productId)
                    ->whereNotNull('stock_quantity')
                    ->where(fn ($q) => $q->whereNull('stock_reset_at')->orWhere('stock_reset_at', '<=', $locked->created_at))
                    ->update(['stock_quantity' => DB::raw('stock_quantity + '.$take), 'updated_at' => now()]);

                if ($added === 1) {
                    $restored[$productId] = $take;
                }

                // Band qilish bekor qilingan hisoblanadi (qo'shilgan bo'lsa ham, qo'shilmagan bo'lsa ham): qayta urinilmaydi.
                $reserved[$productId] -= $take;
                if ($reserved[$productId] <= 0) {
                    unset($reserved[$productId]);
                }
            }

            $releasedAt = $reserved === [] ? now() : null;
            DB::table('orders')->where('id', $locked->id)->update([
                'stock_reserved' => json_encode((object) $reserved),
                'stock_released_at' => $releasedAt,
            ]);

            $order->forceFill(['stock_reserved' => $reserved, 'stock_released_at' => $releasedAt])->syncOriginalAttributes(['stock_reserved', 'stock_released_at']);

            return $restored;
        });
    }
}
