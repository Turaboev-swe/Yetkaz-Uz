<?php

namespace App\Filament\Restaurant\Resources\ProductResource\Pages;

use App\Enums\NutritionStatus;
use App\Filament\Restaurant\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Egasi kaloriya yoki "yengil taom" ni o'zi o'zgartirib saqlasa — bu
     * uning qarori, avtomatik tasdiqlanadi. (Toggle null'ni false deb
     * ko'rsatadi — null -> false o'zgarish sifatida hisoblanmaydi.)
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $calories = filled($data['calories_estimate'] ?? null) ? (int) $data['calories_estimate'] : null;
        $record = $this->getRecord();

        $caloriesChanged = $calories !== $record->calories_estimate;
        $lightChanged = (bool) ($data['is_light'] ?? false) !== (bool) $record->is_light;

        if ($calories !== null && ($caloriesChanged || $lightChanged)) {
            $data['nutrition_status'] = NutritionStatus::Approved;
        }

        return $data;
    }
}
