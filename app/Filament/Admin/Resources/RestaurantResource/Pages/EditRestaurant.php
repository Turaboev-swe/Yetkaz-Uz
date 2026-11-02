<?php

namespace App\Filament\Admin\Resources\RestaurantResource\Pages;

use App\Filament\Admin\Resources\RestaurantResource;
use App\Filament\Admin\Resources\RestaurantResource\Concerns\HandlesBillingTariff;
use App\Filament\Support\WorkHoursForm;
use App\Services\Billing\BillingCalculator;
use Carbon\CarbonImmutable;
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
        // Forma eng oxirgi (ehtimol kelgusi oydan boshlanadigan) tarifni ko'rsatadi: "Qaysi oydan"
        // standarti shu tarif oyi, aks holda boshqa maydonni saqlash tarifni oldinga surib yuborardi.
        $latest = $this->record->billingPlans()->reorder('effective_from', 'desc')->first();
        $currentMonth = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()));
        $data['effective_month'] = $latest !== null && $latest->effective_from->greaterThan($currentMonth) ? 'next' : 'current';

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
