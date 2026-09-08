<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Mehmon buyurtma bermoqchi, lekin telefon raqami yo'q. Mini App bu kodни tanib,
 * foydalanuvchini botга (telefon ulashish uchun) yo'naltiradi.
 */
class PhoneRequiredException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __('messages.phone_required'),
            'code' => 'phone_required',
        ], 422);
    }
}
