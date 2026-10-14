<?php

namespace App\Http\Requests;

use App\Enums\DeliveryType;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Aloqa raqami bitta joyda (App\Support\Phone) normallashtiriladi:
     * yaroqli bo'lsa +998XXXXXXXXX ga o'giriladi, yaroqsiz bo'lsa xom qiymat
     * qoladi va quyidagi regex uni rad etadi.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('contact_phone');

        if (is_string($raw) && trim($raw) !== '') {
            $this->merge(['contact_phone' => Phone::normalizeUzbek($raw) ?? $raw]);
        } elseif ($raw !== null) {
            $this->merge(['contact_phone' => null]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['contact_phone.regex' => __('messages.contact_phone.invalid')];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Ixtiyoriy: berilmasa saqlangan aloqa raqami (yoki Telegram raqami) ishlatiladi.
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^\+998\d{9}$/'],
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'delivery_type' => ['required', Rule::enum(DeliveryType::class)],
            'address_id' => ['nullable', 'integer', 'required_if:delivery_type,delivery'],
            // Hozircha faqat naqd.
            'payment_method' => ['nullable', Rule::in(['cash'])],
            'note' => ['nullable', 'string', 'max:500'],
            'promo_code' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
