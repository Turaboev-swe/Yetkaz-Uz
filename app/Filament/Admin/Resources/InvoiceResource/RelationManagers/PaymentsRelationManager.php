<?php

namespace App\Filament\Admin\Resources\InvoiceResource\RelationManagers;

use App\Enums\BillingPaymentMethod;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Hisob-kitob bo'yicha to'lovlar tarixi (faqat ko'rish). */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'To‘lovlar tarixi';

    protected static ?string $modelLabel = 'To‘lov';

    protected static ?string $pluralModelLabel = 'To‘lovlar';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('paid_at')->label('Sana')->date('d.m.Y'),
                Tables\Columns\TextColumn::make('amount')->label('Summa')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state)),
                Tables\Columns\TextColumn::make('method')->label('Usul')->badge()
                    ->formatStateUsing(fn (BillingPaymentMethod $state): string => $state->label()),
                Tables\Columns\TextColumn::make('note')->label('Izoh')->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Kiritgan')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Kiritilgan vaqt')->dateTime('d.m.Y H:i'),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->paginated(false)
            ->emptyStateHeading('Hali to‘lov yo‘q');
    }
}
