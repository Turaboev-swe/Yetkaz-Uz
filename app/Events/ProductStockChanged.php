<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mahsulot qoldig'i o'zgardi (buyurtma, bekor qilish, kunlik tiklanish, restoran tahriri).
 *
 * OCHIQ kanal `menu.{restaurantId}`: ichida faqat menyuda baribir hammaga ko'rinadigan ma'lumot (id, qoldiq, belgilar) —
 * Mini App (hozircha Reverb'ga ulanmagan: menyu ochilganda va checkout'da yangilanadi) va boshqa tinglovchilar uchun.
 * Restoran paneli (Filament) jadvalni polling bilan yangilaydi. Tranzaksiya commit bo'lgandan KEYIN yuboriladi.
 */
class ProductStockChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  list<array{id: int, stock: ?int, sold_out: bool, low_stock: bool}>  $products
     */
    public function __construct(
        public readonly int $restaurantId,
        public readonly array $products,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("menu.{$this->restaurantId}")];
    }

    public function broadcastAs(): string
    {
        return 'product.stock';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['restaurant_id' => $this->restaurantId, 'products' => $this->products];
    }
}
