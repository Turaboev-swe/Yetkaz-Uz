<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lat' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'address_text' => ['sometimes', 'required', 'string', 'max:500'],
            'entrance' => ['sometimes', 'nullable', 'string', 'max:32'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:32'],
            'apartment' => ['sometimes', 'nullable', 'string', 'max:32'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
