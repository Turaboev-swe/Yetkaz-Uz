<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * `products.photo_url` / `restaurants.logo_url` qiymatini to'liq URL'ga aylantiradi.
 *
 * Qiymat ikki xil bo'lishi mumkin:
 *  - to'liq havola (http/https) — o'zgarishsiz qaytadi
 *  - `public` diskdagi yo'l ("products/abc.jpg") — Storage::url() orqali
 */
final class Media
{
    public static function url(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
}
