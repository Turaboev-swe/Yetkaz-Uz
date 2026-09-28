<?php

namespace App\Filament\Admin\Resources;

use App\Enums\DiscountType;
use App\Filament\Admin\Resources\PromoCodeResource\Pages;
use App\Models\PromoCode;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Promokodlar — platforma admini yaratadi/tahrirlaydi. Xarajat platforma va
 * restoran o'rtasida `restaurant_share_percent` bo'yicha bo'linadi
 * (`PromoCodeService::split()`), buyurtmaga snapshot qilinadi. Hisob-kitob: Hisobotlar → Platforma chegirmalari.
 */
class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Promokodlar';

    protected static ?string $modelLabel = 'Promokod';

    protected static ?string $pluralModelLabel = 'Promokodlar';

    protected static ?int $navigationSort = 8;

    public static function getEloquentQuery(): Builder
    {
        // Ishlatilish — bekor qilinmagan buyurtmalar (limit bilan bir xil ta'rif).
        return parent::getEloquentQuery()->withCount('usages');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Kod')
                ->required()
                ->maxLength(32)
                // Qiymatni DARHOL katta harfga o'giradi — shunda pastdagi unique()
                // tekshiruvi ham (u joriy holatni solishtiradi) bazadagi bilan bir
                // xil registrда bo'ladi. dehydrateStateUsing shart emas — model
                // mutator (setCodeAttribute) baribir shu ishni saqlashda takrorlaydi.
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $set('code', mb_strtoupper(trim((string) $state))))
                ->unique(ignoreRecord: true)
                ->helperText('Mijoz kiritganda katta/kichik harf farqi yo\'q — baribir katta harfga o\'giriladi.'),

            Forms\Components\Select::make('discount_type')
                ->label('Chegirma turi')
                ->options(collect(DiscountType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required()
                ->native(false)
                ->live()
                ->default(DiscountType::Percent->value),

            Forms\Components\TextInput::make('discount_value')
                ->label(fn (Forms\Get $get): string => $get('discount_type') === DiscountType::Fixed->value
                    ? "Chegirma summasi (so'm)"
                    : 'Chegirma foizi')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(fn (Forms\Get $get): int => $get('discount_type') === DiscountType::Fixed->value ? 999_999_999 : 100)
                ->suffix(fn (Forms\Get $get): string => $get('discount_type') === DiscountType::Fixed->value ? "so'm" : '%')
                // Fixed — panelда so'mда kiritiladi, bazaga tiyinда yoziladi (Product narxi bilan bir xil naqsh).
                ->formatStateUsing(fn (?int $state, Forms\Get $get): ?int => $state !== null && $get('discount_type') === DiscountType::Fixed->value
                    ? intdiv($state, 100) : $state)
                ->dehydrateStateUsing(fn ($state, Forms\Get $get): int => $get('discount_type') === DiscountType::Fixed->value
                    ? (int) round((float) $state * 100) : (int) $state),

            Forms\Components\TextInput::make('restaurant_share_percent')
                ->label('Restoran qoplaydigan ulush (%)')
                ->helperText("0 — hammasini platforma qoplaydi. Qolgan qismini platforma qoplaydi; toq so'm ham platformaga. "
                    ."Buyurtmaga shu paytdagi ulush yoziladi — keyin o'zgartirsangiz eski buyurtmalar o'zgarmaydi.")
                ->required()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->default(0),

            Forms\Components\TextInput::make('per_user_limit')
                ->label('Bir mijozga limit')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->suffix('marta')
                ->helperText("Bo'sh — cheklovsiz. Bekor qilingan buyurtma hisoblanmaydi."),

            Forms\Components\TextInput::make('total_usage_limit')
                ->label('Umumiy limit')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->suffix('marta')
                ->helperText("Bo'sh — cheklovsiz. Bekor qilingan buyurtma hisoblanmaydi."),

            Forms\Components\Select::make('restaurant_id')
                ->label('Restoran')
                ->relationship('restaurant', 'name')
                ->searchable()
                ->native(false)
                ->helperText("Bo'sh — kod barcha restoranlarda ishlaydi."),

            Forms\Components\Toggle::make('is_active')->label('Faol')->default(true),

            Forms\Components\DateTimePicker::make('starts_at')->label('Boshlanish vaqti')->native(false)
                ->helperText("Bo'sh — darhol boshlanadi."),
            Forms\Components\DateTimePicker::make('ends_at')->label('Tugash vaqti')->native(false)
                ->helperText("Bo'sh — muddatsiz."),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kod')->searchable()->sortable(),

                Tables\Columns\TextColumn::make('discount_type')
                    ->label('Turi')
                    ->badge()
                    ->formatStateUsing(fn (DiscountType $state): string => $state->label()),

                Tables\Columns\TextColumn::make('discount_value')
                    ->label('Qiymati')
                    ->formatStateUsing(fn (PromoCode $record): string => $record->discount_type === DiscountType::Fixed
                        ? Money::soms($record->discount_value)
                        : $record->discount_value.'%'),

                Tables\Columns\TextColumn::make('restaurant_share_percent')
                    ->label('Restoran qoplaydi')
                    ->suffix('%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Restoran')
                    ->placeholder('Barchasi')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'info'),

                Tables\Columns\ToggleColumn::make('is_active')->label('Faol'),

                Tables\Columns\TextColumn::make('usages_count')
                    ->label('Ishlatilgan')
                    ->tooltip('Bekor qilinmagan buyurtmalar / umumiy limit')
                    ->state(function (PromoCode $record): string {
                        $used = $record->usages_count ?? $record->usages()->count();

                        return $record->total_usage_limit !== null ? "{$used} / {$record->total_usage_limit}" : (string) $used;
                    }),

                Tables\Columns\TextColumn::make('per_user_limit')
                    ->label('Mijozga')
                    ->placeholder('∞')
                    ->suffix(' marta')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Tugaydi')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Muddatsiz')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Faollik'),
                Tables\Filters\SelectFilter::make('restaurant_id')
                    ->label('Restoran')
                    ->relationship('restaurant', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading("Hali promokod yo'q");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }
}
