<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Buyurtmadagi mahsulot(lar) uchun qoldiq yetmaydi. 422: xabar mijozga ko'rsatiladi, `shortages` esa Mini App
 * savatni mavjud miqdorga moslashi uchun.
 */
class InsufficientStockException extends Exception
{
    /** @param  list<array{product_id: int, name: string, available: int}>  $shortages */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct(self::messageFor($shortages));
    }

    /** @param  list<array{product_id: int, name: string, available: int}>  $shortages */
    public static function messageFor(array $shortages): string
    {
        return implode(' ', array_map(
            fn (array $s): string => $s['available'] > 0
                ? __('messages.stock.only_left', ['name' => $s['name'], 'count' => $s['available']])
                : __('messages.stock.sold_out', ['name' => $s['name']]),
            $shortages,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => self::messageFor($this->shortages),
            'code' => 'insufficient_stock',
            'errors' => ['items' => [self::messageFor($this->shortages)]],
            'shortages' => $this->shortages,
        ], 422);
    }
}
