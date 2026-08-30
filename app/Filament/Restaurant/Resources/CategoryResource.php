<?php

namespace App\Filament\Restaurant\Resources;

use App\Filament\Restaurant\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Kategoriyalar';

    protected static ?string $modelLabel = 'Kategoriya';

    protected static ?string $pluralModelLabel = 'Kategoriyalar';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        // restaurant_id ko'rsatilmaydi — CreateCategory sahifasi egasining
        // restoraniga o'zi biriktiradi.
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nomi')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('sort_order')
                ->label('Tartib')
                ->numeric()
                ->default(0),

            Forms\Components\Toggle::make('is_active')
                ->label('Faol')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nomi')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Taomlar')
                    ->counts('products')
                    ->badge(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Faol'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
