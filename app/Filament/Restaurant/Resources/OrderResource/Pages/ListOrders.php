<?php

namespace App\Filament\Restaurant\Resources\OrderResource\Pages;

use App\Filament\Restaurant\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
