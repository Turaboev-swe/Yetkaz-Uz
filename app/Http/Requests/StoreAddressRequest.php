<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:40'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address_text' => ['required', 'string', 'max:500'],
            'entrance' => ['nullable', 'string', 'max:32'],
            'floor' => ['nullable', 'string', 'max:32'],
            'apartment' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
