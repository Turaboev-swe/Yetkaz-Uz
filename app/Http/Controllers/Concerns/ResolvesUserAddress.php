<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesUserAddress
{
    /** `address_id` so'rov parametri -> foydalanuvchining manzili (aks holda 422). */
    protected function resolveUserAddress(Request $request): Address
    {
        $validated = $request->validate([
            'address_id' => ['required', 'integer'],
        ]);

        $address = $request->user()->addresses()->find($validated['address_id']);

        if ($address === null) {
            throw ValidationException::withMessages([
                'address_id' => 'Manzil topilmadi yoki sizga tegishli emas.',
            ]);
        }

        return $address;
    }
}
