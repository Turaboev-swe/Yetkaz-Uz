<?php

namespace App\Exceptions;

use App\Enums\PromoCodeError;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Promokod qabul qilinmadi. PhoneRequiredException kabi o'zi 422 JSON
 * qaytaradi: `code` — Mini App promokod xatosini tanishi (maydon ostida
 * ko'rsatish) uchun, `promo_error` — aniq sabab, `errors.promo_code` —
 * Laravel validatsiya formati bilan mos.
 *
 * Diqqat: `reason` kaliti ishlatilmaydi — Mini App api.js uni xabar oxiriga
 * "(...)" qilib qo'shadi (initData xatolari uchun).
 */
class PromoCodeException extends Exception
{
    public function __construct(public readonly PromoCodeError $error)
    {
        parent::__construct($error->message());
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'promo_code_invalid',
            'promo_error' => $this->error->value,
            'errors' => ['promo_code' => [$this->getMessage()]],
        ], 422);
    }
}
