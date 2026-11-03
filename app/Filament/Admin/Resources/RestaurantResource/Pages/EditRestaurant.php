<?php

namespace App\Filament\Admin\Resources\RestaurantResource\Pages;

use App\Filament\Admin\Resources\RestaurantResource;
use App\Filament\Admin\Resources\RestaurantResource\Concerns\HandlesBillingTariff;
use App\Filament\Support\WorkHoursForm;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRestaurant extends EditRecord
{
    use HandlesBillingTariff;

    protected static string $resource = RestaurantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['work_hours'] = WorkHoursForm::toRows($data['work_hours'] ?? []);
        // Forma JORIY oy tarifini ko'rsatadi (restaurants.billing_*), kelajak tarifi alohida («Keyingi tarif»)
        // — shuning uchun "Qaysi oydan" standarti doim joriy oy: boshqa maydonni saqlash tarifga tegmaydi.
        $data['effective_month'] = 'current';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['work_hours'] = WorkHoursForm::toSchedule($data['work_hours'] ?? []);

        // Tarif maydonlari restaurants jadvaliga emas, tarif tarixiga yoziladi (afterSave).
        // Tekshiruv shu yerda (record yangilanishidan OLDIN): xato bo'lsa hech narsa saqlanmaydi.
        // (Filament'da beforeSave hook'i mutateFormDataBeforeSave'dan oldin ishlaydi.)
        $data = $this->pullBillingInput($data);
        $this->validateBillingInput($this->record);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->applyBillingInput($this->record);
    }
}
