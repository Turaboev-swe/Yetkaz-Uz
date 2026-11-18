<?php

namespace App\Filament\Admin\Resources\OrderResource\Pages;

use App\Filament\Admin\Actions\CancelOrderAction;
use App\Filament\Admin\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Platforma admini: sabab bilan bekor qilish (new | accepted | preparing) — OrderStatusService::cancel().
            CancelOrderAction::header(fn () => $this->getRecord()),
        ];
    }
}
