<?php

namespace App\Filament\Restaurant\Resources\ProductResource\Pages;

use App\Enums\NutritionStatus;
use App\Filament\Restaurant\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /** Egasi kaloriyani o'zi kiritsa — tasdiqlangan, AI taxmini so'ralmaydi. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (filled($data['calories_estimate'] ?? null)) {
            $data['nutrition_status'] = NutritionStatus::Approved;
        }

        return $data;
    }
}
