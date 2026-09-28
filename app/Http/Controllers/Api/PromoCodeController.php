<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\Ordering\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoCodeController extends Controller
{
    public function __construct(private readonly PromoCodeService $promoCodes) {}

    /**
     * POST /api/promo-codes/validate — checkout'dagi "Qo'llash".
     *
     * Muvaffaqiyat: chegirma summasi (tiyinда). Xato: 422, aniq sabab bilan
     * (PromoCodeException::render — `promo_error`: not_found | inactive |
     * not_started | expired | wrong_restaurant | usage_limit_reached |
     * user_limit_reached). Faqat oldindan ko'rsatish — yakuniy chegirma
     * buyurtma yaratishda bazadagi narxlardan qayta hisoblanadi.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'promo_code' => ['required', 'string', 'max:32'],
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'subtotal' => ['required', 'integer', 'min:0'], // savat summasi, tiyinда
        ]);

        $restaurant = Restaurant::query()->findOrFail($data['restaurant_id']);
        $result = $this->promoCodes->quote($data['promo_code'], $restaurant, (int) $data['subtotal'], $request->user());

        return response()->json(['data' => [
            'code' => mb_strtoupper(trim($data['promo_code'])),
            'discount_amount' => $result->discountAmount,
        ]]);
    }
}
