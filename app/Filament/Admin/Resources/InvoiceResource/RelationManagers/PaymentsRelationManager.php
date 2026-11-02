<?php

namespace App\Filament\Admin\Resources\InvoiceResource\RelationManagers;

use App\Enums\BillingPaymentMethod;
use App\Enums\InvoiceStatus;
use App\Models\RestaurantPayment;
use App\Services\Billing\InvoiceService;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Livewire\Attributes\On;

/**
 * Hisob-kitob bo'yicha to'lovlar tarixi. To'lov o'chirilmaydi — sabab bilan bekor qilinadi
 * (bekor qilingan to'lovlar tarixda qoladi, hisobga kirmaydi).
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'To‘lovlar tarixi';

    protected static ?string $modelLabel = 'To‘lov';

    protected static ?string $pluralModelLabel = 'To‘lovlar';

    /** Ko'rish sahifasida ham amallar (bekor qilish) ishlasin. */
    public function isReadOnly(): bool
    {
        return false;
    }

    /** Sahifadagi "To'landi" amalidan keyin jadval qayta chiziladi. */
    #[On('invoiceChanged')]
    public function refreshPayments(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->recordClasses(fn (RestaurantPayment $record): ?string => $record->isCancelled() ? 'opacity-60' : null)
            ->columns([
                Tables\Columns\TextColumn::make('paid_at')->label('Sana')->date('d.m.Y'),
                Tables\Columns\TextColumn::make('amount')->label('Summa')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state))
                    ->extraAttributes(fn (RestaurantPayment $record): array => $record->isCancelled() ? ['style' => 'text-decoration: line-through'] : []),
                Tables\Columns\TextColumn::make('method')->label('Usul')->badge()
                    ->formatStateUsing(fn (BillingPaymentMethod $state): string => $state->label()),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->state(fn (RestaurantPayment $record): string => $record->isCancelled() ? 'Bekor qilingan' : 'Amalda')
                    ->color(fn (string $state): string => $state === 'Amalda' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('note')->label('Izoh')->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Kiritgan')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Kiritilgan vaqt')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('cancel_reason')->label('Bekor qilish sababi')->placeholder('—')->wrap()
                    ->description(fn (RestaurantPayment $record): ?string => $record->isCancelled()
                        ? ($record->cancelledBy?->name ?? '—').' · '.$record->cancelled_at->format('d.m.Y H:i')
                        : null),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('cancelPayment')
                    ->label('Bekor qilish')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize('cancel')
                    ->visible(fn (RestaurantPayment $record): bool => ! $record->isCancelled()
                        && $this->getOwnerRecord()->status !== InvoiceStatus::Waived)
                    ->requiresConfirmation()
                    ->modalHeading('To‘lovni bekor qilish')
                    ->modalDescription(fn (RestaurantPayment $record): string => Money::soms($record->amount).' to‘lov bekor qilinadi: hisobning to‘langan summasi va holati qayta hisoblanadi. Yozuv tarixda qoladi.')
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Sabab')->required()->rows(2)->maxLength(500),
                    ])
                    ->action(function (RestaurantPayment $record, array $data): void {
                        app(InvoiceService::class)->cancelPayment($record, $data['reason'], Filament::auth()->id());

                        Notification::make()->title('To‘lov bekor qilindi')->success()->send();
                        $this->dispatch('invoiceChanged');
                    }),
            ])
            ->bulkActions([])
            ->paginated(false)
            ->emptyStateHeading('Hali to‘lov yo‘q');
    }
}
