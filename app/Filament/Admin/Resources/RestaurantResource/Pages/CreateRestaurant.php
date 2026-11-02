<?php

namespace App\Filament\Admin\Resources\RestaurantResource\Pages;

use App\Filament\Admin\Resources\RestaurantResource;
use App\Filament\Admin\Resources\RestaurantResource\Concerns\HandlesBillingTariff;
use App\Filament\Support\WorkHoursForm;
use App\Models\Restaurant;
use Filament\Resources\Pages\CreateRecord;

class CreateRestaurant extends CreateRecord
{
    use HandlesBillingTariff;

    protected static string $resource = RestaurantResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['work_hours'] = WorkHoursForm::toSchedule($data['work_hours'] ?? []);

        // Tarif maydonlari restaurants jadvaliga emas, tarif tarixiga yoziladi (afterCreate).
        $data = $this->pullBillingInput($data);
        $this->validateBillingInput(new Restaurant);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->applyBillingInput($this->record);
    }
}
