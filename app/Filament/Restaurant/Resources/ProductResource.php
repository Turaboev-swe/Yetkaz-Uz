<?php

namespace App\Filament\Restaurant\Resources;

use App\Filament\Restaurant\Resources\ProductResource\Pages;
use App\Filament\Restaurant\Resources\ProductResource\RelationManagers\PriceHistoryRelationManager;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cake';

    protected static ?string $navigationLabel = 'Taomlar';

    protected static ?string $modelLabel = 'Taom';

    protected static ?string $pluralModelLabel = 'Taomlar';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('category_id')
                ->label('Kategoriya')
                ->relationship('category', 'name')
                ->required()
                ->native(false),

            Forms\Components\TextInput::make('name')
                ->label('Nomi')
                ->required()
                ->maxLength(255),

            Forms\Components\Textarea::make('description')
                ->label('Tavsif')
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),

            // Xodim so'mda kiritadi, bazaga tiyinda yoziladi.
            Forms\Components\TextInput::make('price')
                ->label("Narxi (so'm)")
                ->required()
                ->numeric()
                ->minValue(0)
                ->step(100)
                ->suffix("so'm")
                ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : (string) intdiv($state, 100))
                ->dehydrateStateUsing(fn ($state): int => (int) round((float) $state * 100)),

            // Chegirma: to'ldirilsa va joriy narxdan katta bo'lsa — taom aksiyada.
            Forms\Components\TextInput::make('old_price')
                ->label("Eski narx (so'm) — chegirma uchun")
                ->helperText('Bo\'sh qoldirilsa — chegirma yo\'q.')
                ->numeric()
                ->minValue(0)
                ->step(100)
                ->suffix("so'm")
                ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : (string) intdiv($state, 100))
                ->dehydrateStateUsing(fn ($state): ?int => filled($state) ? (int) round((float) $state * 100) : null),

            Forms\Components\TextInput::make('prep_time_min')
                ->label('Tayyorlash vaqti (daqiqa)')
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(15),

            Forms\Components\FileUpload::make('photo_url')
                ->label('Taom rasmi')
                ->image()
                ->imageEditor()
                ->disk('public')
                ->directory('products')
                ->visibility('public')
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth('800')
                ->imageResizeTargetHeight('800')
                ->maxSize(4096)
                ->helperText('Kvadrat rasm tavsiya etiladi. 4 MB gacha.'),

            Forms\Components\TextInput::make('sort_order')
                ->label('Tartib')
                ->numeric()
                ->default(0),

            Forms\Components\Toggle::make('is_available')
                ->label('Mavjud')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                // Eng ko'p ishlatiladigan amal — bir bosishli "mavjud/tugadi" toggle.
                Tables\Columns\ToggleColumn::make('is_available')
                    ->label('Mavjud')
                    ->sortable(),

                Tables\Columns\ImageColumn::make('photo_url')
                    ->label('')
                    ->disk('public')
                    ->circular(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nomi')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Kategoriya')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('Narxi')
                    ->formatStateUsing(fn (int $state): string => number_format(intdiv($state, 100), 0, '.', ' ')." so'm")
                    ->sortable(),

                Tables\Columns\TextColumn::make('prep_time_min')
                    ->label('Daqiqa')
                    ->suffix(' min')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Yangilangan')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Kategoriya')
                    ->relationship('category', 'name'),
                Tables\Filters\TernaryFilter::make('is_available')
                    ->label('Mavjudlik'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PriceHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
