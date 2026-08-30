<?php

namespace App\Filament\Admin\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Admin\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Platforma admini barcha restoranlarning buyurtmalarini ko'radi (faqat ko'rish).
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Buyurtmalar';

    protected static ?string $modelLabel = 'Buyurtma';

    protected static ?string $pluralModelLabel = 'Buyurtmalar';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->label('Raqam')->searchable(),
                Tables\Columns\TextColumn::make('restaurant.name')->label('Restoran')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.full_name')->label('Mijoz')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('payment_status')->label('To`lov')->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('total')->label('Summa')
                    ->formatStateUsing(fn (int $state): string => number_format(intdiv($state, 100), 0, '.', ' ')." so'm")
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Vaqti')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('restaurant_id')->label('Restoran')->relationship('restaurant', 'name'),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('order_number')->label('Raqam'),
            Infolists\Components\TextEntry::make('restaurant.name')->label('Restoran'),
            Infolists\Components\TextEntry::make('user.full_name')->label('Mijoz'),
            Infolists\Components\TextEntry::make('user.phone')->label('Telefon'),
            Infolists\Components\TextEntry::make('status')->label('Holat')->badge()
                ->formatStateUsing(fn (OrderStatus $state) => $state->label()),
            Infolists\Components\TextEntry::make('total')->label('Summa')
                ->formatStateUsing(fn (int $state): string => number_format(intdiv($state, 100), 0, '.', ' ')." so'm"),
            Infolists\Components\KeyValueEntry::make('address_snapshot')->label('Manzil')->columnSpanFull(),
            Infolists\Components\RepeatableEntry::make('items')->label('Taomlar')
                ->schema([
                    Infolists\Components\TextEntry::make('name')->label('Nomi'),
                    Infolists\Components\TextEntry::make('qty')->label('Soni'),
                    Infolists\Components\TextEntry::make('price')->label('Narxi')
                        ->formatStateUsing(fn ($state): string => number_format(intdiv((int) $state, 100), 0, '.', ' ')." so'm"),
                ])->columns(3)->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
