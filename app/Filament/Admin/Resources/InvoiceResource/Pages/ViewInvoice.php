<?php

namespace App\Filament\Admin\Resources\InvoiceResource\Pages;

use App\Filament\Admin\Resources\InvoiceResource;
use App\Filament\Admin\Resources\InvoiceResource\Widgets\InvoiceOrdersWidget;
use App\Models\RestaurantInvoice;
use App\Support\Money;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /** To'lov bekor qilinganda (relation manager) hisob ma'lumotlarini yangilaydi. */
    #[On('invoiceChanged')]
    public function refreshInvoice(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['status', 'amount_paid']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markPaid')
                ->label('To‘landi')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->authorize('markPaid')
                ->visible(fn (RestaurantInvoice $record): bool => InvoiceResource::canAddPayment($record))
                ->modalHeading('To‘landi')
                ->modalDescription(fn (RestaurantInvoice $record): string => 'Qolgan qarz ('.Money::soms($record->debt()).') to‘liq to‘lov sifatida yoziladi.')
                ->form(InvoiceResource::markPaidFormSchema())
                ->fillForm(fn (): array => InvoiceResource::markPaidDefaults())
                ->action(function (RestaurantInvoice $record, array $data): void {
                    InvoiceResource::markPaid($record, $data);
                    $this->refreshFormData(['status', 'amount_paid']);
                    $this->dispatch('invoiceChanged');
                }),
            Actions\Action::make('addPayment')
                ->label('To‘lov qo‘shish')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->authorize('addPayment')
                ->visible(fn (RestaurantInvoice $record): bool => InvoiceResource::canAddPayment($record))
                ->form(InvoiceResource::paymentFormSchema())
                ->fillForm(fn (RestaurantInvoice $record): array => InvoiceResource::paymentDefaults($record))
                ->action(function (RestaurantInvoice $record, array $data): void {
                    InvoiceResource::addPayment($record, $data);
                    $this->refreshFormData(['status', 'amount_paid']);
                }),
            Actions\Action::make('recalculate')
                ->label('Qayta hisoblash')
                ->icon('heroicon-o-arrow-path')
                ->authorize('recalculate')
                ->visible(fn (RestaurantInvoice $record): bool => ! $record->isSettled())
                ->requiresConfirmation()
                ->action(function (RestaurantInvoice $record): void {
                    InvoiceResource::recalculate($record);
                    $record->refresh();
                    $this->refreshFormData(['orders_count', 'orders_total', 'amount_due', 'status']);
                }),
            Actions\Action::make('changeTariff')
                ->label('Tarifni o‘zgartirish')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->authorize('changeTariff')
                ->visible(fn (RestaurantInvoice $record): bool => ! $record->isSettled())
                ->requiresConfirmation()
                ->modalHeading('Tarifni o‘zgartirish')
                ->modalDescription('Faqat shu hisob-kitobning tarifi o‘zgaradi (restoranniki emas) va summa qayta hisoblanadi. O‘zgarish izohga yoziladi.')
                ->form(InvoiceResource::tariffFormSchema())
                ->fillForm(fn (RestaurantInvoice $record): array => InvoiceResource::tariffDefaults($record))
                ->action(function (RestaurantInvoice $record, array $data): void {
                    InvoiceResource::changeTariff($record, $data);
                    $record->refresh();
                    $this->refreshFormData(['orders_count', 'orders_total', 'amount_due', 'status', 'plan', 'rate_bp', 'fixed_fee', 'notes']);
                }),
            Actions\Action::make('waive')
                ->label('Kechirish')
                ->icon('heroicon-o-hand-raised')
                ->color('gray')
                ->authorize('waive')
                ->visible(fn (RestaurantInvoice $record): bool => InvoiceResource::canAddPayment($record))
                ->requiresConfirmation()
                ->modalHeading('Hisob-kitobni kechirish')
                ->modalDescription('Qarz 0 bo‘ladi va hisob-kitob yopiladi. Bu amalni qaytarib bo‘lmaydi.')
                ->action(function (RestaurantInvoice $record): void {
                    InvoiceResource::waive($record);
                    $this->refreshFormData(['status']);
                }),
        ];
    }

    /** Widgetga `record` ViewRecord::getWidgetData() orqali o'zi uzatiladi. */
    protected function getFooterWidgets(): array
    {
        return [InvoiceOrdersWidget::class];
    }
}
