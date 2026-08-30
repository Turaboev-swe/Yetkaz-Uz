<?php

namespace App\Filament\Restaurant\Resources\CategoryResource\Pages;

use App\Filament\Restaurant\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /** Kategoriya avtomat ravishda egasining restoraniga biriktiriladi. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['restaurant_id'] = auth('staff')->user()->restaurant_id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
