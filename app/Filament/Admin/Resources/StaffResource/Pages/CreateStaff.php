<?php

namespace App\Filament\Admin\Resources\StaffResource\Pages;

use App\Filament\Admin\Resources\StaffResource;
use App\Models\Staff;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /** Asosiy restaurant_id — birinchi tanlangan restoran; qolganlari pivot'ga. */
    protected function handleRecordCreation(array $data): Model
    {
        [$data, $restaurantIds] = StaffResource::extractRestaurants($data);

        if ($restaurantIds !== null && $restaurantIds !== []) {
            $data['restaurant_id'] = $restaurantIds[0];
        }

        /** @var Staff $staff */
        $staff = parent::handleRecordCreation($data);

        if ($restaurantIds !== null) {
            $staff->assignRestaurants($restaurantIds);
        }

        return $staff;
    }
}
