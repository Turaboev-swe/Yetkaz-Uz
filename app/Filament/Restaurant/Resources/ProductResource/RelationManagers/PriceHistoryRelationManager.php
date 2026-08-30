<?php

namespace App\Filament\Restaurant\Resources\ProductResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PriceHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'priceHistory';

    protected static ?string $title = 'Narx tarixi';

    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('changed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Sana')
                    ->dateTime('d.m.Y H:i'),

                Tables\Columns\TextColumn::make('old_price')
                    ->label('Eski narx')
                    ->formatStateUsing(fn (int $state): string => number_format(intdiv($state, 100), 0, '.', ' ')." so'm"),

                Tables\Columns\TextColumn::make('new_price')
                    ->label('Yangi narx')
                    ->formatStateUsing(fn (int $state): string => number_format(intdiv($state, 100), 0, '.', ' ')." so'm")
                    ->color('primary'),

                Tables\Columns\TextColumn::make('staff.name')
                    ->label('O`zgartirdi')
                    ->placeholder('—'),
            ])
            ->paginated([10, 25])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    /** Faqat ko'rish uchun — bu yerda yozib bo'lmaydi. */
    public function isReadOnly(): bool
    {
        return true;
    }
}
