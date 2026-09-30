<?php

namespace App\Filament\Admin\Resources\StaffResource\Pages;

use App\Filament\Admin\Resources\StaffResource;
use App\Models\Staff;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** Asosiy restoran ro'yxatda birinchi turadi. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Staff $staff */
        $staff = $this->record;
        $ids = $staff->restaurantIds();

        $data['restaurant_ids'] = $staff->restaurant_id !== null
            ? array_values(array_unique([(int) $staff->restaurant_id, ...$ids]))
            : $ids;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$data, $restaurantIds] = StaffResource::extractRestaurants($data);

        /** @var Staff $staff */
        $staff = parent::handleRecordUpdate($record, $data);

        if ($restaurantIds !== null) {
            $staff->assignRestaurants($restaurantIds);
        }

        return $staff;
    }
}
