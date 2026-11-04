<?php

namespace App\Filament\Restaurant\Resources;

use App\Enums\NutritionStatus;
use App\Filament\Restaurant\Resources\ProductResource\Pages;
use App\Filament\Restaurant\Resources\ProductResource\RelationManagers\PriceHistoryRelationManager;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

            self::stockSection(),

            self::nutritionSection(),
        ]);
    }

    /**
     * Qoldiq (stock). Bo'sh = cheklanmagan (mavjud mahsulotlar shunday qoladi). 0 = «Tugadi» (mijozga kulrang),
     * soni oshirilsa mahsulot o'zi qaytadi — `is_available` ga tegilmaydi. Kunlik qoldiq: har kuni belgilangan
     * vaqtda (Asia/Tashkent) shu songa tiklanadi (`stock:reset-daily`, har daqiqa).
     */
    private static function stockSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Qoldiq')
            ->description('Buyurtma berilganda kamayadi, bekor qilinganda qaytadi. 0 bo\'lganda mijozga «Tugadi» ko\'rinadi.')
            ->columns(3)
            ->schema([
                Forms\Components\TextInput::make('stock_quantity')
                    ->label('Qoldiq (dona)')
                    ->numeric()->integer()->minValue(0)->maxValue(1_000_000)
                    ->placeholder('∞ cheklanmagan')
                    ->helperText('Bo\'sh qoldirilsa — cheklanmagan.'),

                Forms\Components\TextInput::make('daily_stock')
                    ->label('Kunlik qoldiq')
                    ->numeric()->integer()->minValue(0)->maxValue(1_000_000)
                    ->live(onBlur: true)
                    ->helperText('Ixtiyoriy: har kuni shu songa tiklanadi.'),

                Forms\Components\TimePicker::make('daily_reset_time')
                    ->label('Qayta tiklanish vaqti')
                    ->seconds(false)
                    ->required(fn (Forms\Get $get): bool => filled($get('daily_stock')))
                    ->helperText('Toshkent vaqti bilan, masalan 06:00.'),
            ]);
    }

    /**
     * Kaloriya (AI taxmini). Mijozga faqat egasi tasdiqlagach chiqadi.
     * Egasi qiymatni o'zi o'zgartirib saqlasa — avtomatik tasdiqlanadi
     * (EditProduct / CreateProduct::mutateFormDataBefore*).
     */
    private static function nutritionSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Kaloriya')
            ->description('AI taxmini — iltimos haqiqiy porsiyangizga qarab tekshiring. Mijozga faqat tasdiqlangandan keyin "taxminiy" belgisi bilan ko\'rsatiladi.')
            ->columns(2)
            ->schema([
                Forms\Components\Placeholder::make('nutrition_status_label')
                    ->label('Holat')
                    ->content(fn (?Product $record): string => $record?->nutrition_status
                        ? $record->nutrition_status->emoji().' '.$record->nutrition_status->label()
                        : ($record ? 'AI taxmini hali yo\'q' : 'Saqlangach AI taxmin qiladi'))
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('calories_estimate')
                    ->label('Kaloriya (1 porsiya)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(5000)
                    ->suffix('kkal'),

                Forms\Components\Toggle::make('is_light')
                    ->label('🥗 Yengil taom')
                    ->inline(false),

                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('approveNutrition')
                        ->label('Tasdiqlash')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(function (Product $record, Forms\Get $get, Forms\Set $set): void {
                            if (blank($get('calories_estimate'))) {
                                Notification::make()->title('Avval kaloriyani kiriting')->danger()->send();

                                return;
                            }

                            $record->update([
                                'calories_estimate' => (int) $get('calories_estimate'),
                                'is_light' => (bool) $get('is_light'),
                                'nutrition_status' => NutritionStatus::Approved,
                            ]);
                            $set('calories_estimate', $record->calories_estimate);

                            Notification::make()->title('Kaloriya tasdiqlandi — mijozga ko\'rinadi')->success()->send();
                        }),

                    Forms\Components\Actions\Action::make('hideNutrition')
                        ->label('Ko\'rsatmaslik')
                        ->icon('heroicon-o-eye-slash')
                        ->color('gray')
                        ->action(function (Product $record): void {
                            $record->update(['nutrition_status' => NutritionStatus::Hidden]);

                            Notification::make()->title('Kaloriya mijozga ko\'rsatilmaydi')->success()->send();
                        }),
                ])
                    ->visible(fn (?Product $record): bool => $record !== null)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            // Buyurtmalar qoldiqni kamaytiradi — jadval o'zi yangilanib turadi (panelda Echo yo'q, shuning uchun polling).
            ->poll('15s')
            ->columns([
                // Eng ko'p ishlatiladigan amal — bir bosishli "mavjud/tugadi" toggle.
                Tables\Columns\ToggleColumn::make('is_available')
                    ->label('Mavjud')
                    ->sortable(),

                Tables\Columns\ImageColumn::make('photo_url')
                    ->label('')
                    ->disk('public')
                    ->circular(),

                // Rasm yo'q bo'lsa bo'sh joy o'rniga aniq belgi (Mini App'dagi tarelka/logo mantiqi
                // panelga ko'chirilmaydi — panelda rasm yo'qligi ochiq ko'rinishi kerak).
                Tables\Columns\TextColumn::make('photo_missing')
                    ->label('')
                    ->state(fn (Product $record): ?string => blank(trim((string) $record->photo_url)) ? "Rasm yo'q" : null)
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-photo'),

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

                // Qoldiqni tezkor tahrirlash: bo'sh = cheklanmagan (∞), 0 = «Tugadi», raqam = shuncha dona.
                Tables\Columns\TextInputColumn::make('stock_quantity')
                    ->label('Qoldiq (dona)')
                    ->type('number')
                    ->inputMode('numeric')
                    ->step(1)
                    ->placeholder('∞')
                    ->tooltip('Bo\'sh — cheklanmagan, 0 — Tugadi')
                    ->rules(['nullable', 'integer', 'min:0', 'max:1000000'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_state')
                    ->label('')
                    ->state(fn (Product $record): ?string => $record->isSoldOut() ? 'Tugadi' : null)
                    ->badge()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('prep_time_min')
                    ->label('Daqiqa')
                    ->suffix(' min')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('calories_estimate')
                    ->label('Kaloriya')
                    ->state(fn (Product $record): string => $record->nutrition_status
                        ? $record->nutrition_status->emoji().' '.($record->calories_estimate !== null ? $record->calories_estimate.' kkal' : '')
                        : '—')
                    ->tooltip(fn (Product $record): ?string => $record->nutrition_status?->label())
                    ->sortable(),

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
                Tables\Filters\Filter::make('sold_out')
                    ->label('Tugagan (qoldiq 0)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('stock_quantity', '<=', 0)),
                Tables\Filters\Filter::make('no_photo')
                    ->label('Rasmsiz mahsulotlar')
                    ->toggle()
                    // null, bo'sh yoki faqat bo'shliqdan iborat qiymat.
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $q) => $q->whereNull('photo_url')->orWhereRaw("TRIM(photo_url) = ''"),
                    )),
                Tables\Filters\Filter::make('nutrition_pending')
                    ->label('Tasdiq kutayotganlar')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('nutrition_status', NutritionStatus::Pending->value)),
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
