<?php

namespace App\Filament\Admin\Resources\InvoiceResource\Widgets;

use App\Models\Order;
use App\Models\RestaurantInvoice;
use App\Services\Billing\BillingCalculator;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hisob-kitobga kirgan buyurtmalar (ko'rish sahifasi). Faqat InvoiceResource sahifasida —
 * `Filament/Admin/Widgets` ichida emas, shuning uchun dashboard'ga avtomat qo'shilmaydi.
 */
class InvoiceOrdersWidget extends TableWidget
{
    public ?RestaurantInvoice $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Hisobga kirgan buyurtmalar';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->record === null
                ? Order::withoutGlobalScopes()->whereRaw('1 = 0')
                : app(BillingCalculator::class)->ordersQuery($this->record->restaurant_id, $this->record->period, $this->record->billing_started_at))
            ->defaultSort('delivered_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->label('Raqam')->searchable(),
                Tables\Columns\TextColumn::make('delivered_at')->label('Yetkazilgan')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('subtotal')->label('Taomlar')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state)),
                Tables\Columns\TextColumn::make('discount_restaurant_amount')->label('Chegirma (restoran ulushi)')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '−'.Money::soms($state) : '—'),
                Tables\Columns\TextColumn::make('base')->label('Baza')
                    ->state(fn (Order $record): int => $record->subtotal - $record->discount_restaurant_amount)
                    ->formatStateUsing(fn (int $state): string => Money::soms($state)),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Bu oyda yetkazilgan buyurtma yo‘q');
    }
}
