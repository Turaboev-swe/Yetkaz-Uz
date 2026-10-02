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
 * Promokodlar — platforma admini yaratadi/tahrirlaydi. Kod faqat tanlangan
 * restoranlarda ishlaydi (kamida bitta, "hammasi" varianti yo'q). Xarajat
 * platforma va restoran o'rtasida `restaurant_share_percent` bo'yicha bo'linadi
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
        // Restoranlar ro'yxatda ko'rsatiladi — N+1 bo'lmasin.
        return parent::getEloquentQuery()->withCount('usages')->with('restaurants:id,name');
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
                ->helperText('Standart 50% — yarmini restoran, yarmini platforma qoplaydi')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->default(50),

            // Taomlar summasi bilan solishtiriladi (yetkazishsiz). Panelda so'mда, bazada tiyinда.
            Forms\Components\TextInput::make('min_order_amount')
                ->label("Minimal buyurtma summasi (so'm)")
                ->helperText("Faqat TAOMLAR summasi hisobga olinadi — yetkazish narxi qo'shilmaydi. Bo'sh — cheklovsiz.")
                ->numeric()
                ->integer()
                ->minValue(0)
                ->suffix("so'm")
                ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : intdiv($state, 100))
                ->dehydrateStateUsing(fn ($state): ?int => filled($state) ? (int) $state * 100 : null),

            Forms\Components\TextInput::make('per_user_limit')
                ->label('Bir mijozga limit')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->suffix('marta')
                ->default(1)
                ->helperText("Standart 1 — har mijoz bir marta. Bo'sh — cheklovsiz. Bekor qilingan buyurtma hisoblanmaydi."),

            Forms\Components\TextInput::make('total_usage_limit')
                ->label('Umumiy limit')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->suffix('marta')
                ->helperText("Bo'sh — cheklovsiz. Bekor qilingan buyurtma hisoblanmaydi."),

            // "Barcha restoranlar" varianti ATAYLAB yo'q — rozi bo'lmagan restoranga
            // tasodifan tushib qolmasligi uchun kamida bittasini tanlash majburiy.
            Forms\Components\Select::make('restaurants')
                ->label('Restoranlar')
                ->relationship('restaurants', 'name')
                ->multiple()
                ->required()
                ->searchable()
                ->preload()
                ->helperText('Kod faqat tanlangan restoranlarda ishlaydi. Kamida bittasini tanlang.')
                ->columnSpanFull(),

            Forms\Components\Toggle::make('is_active')->label('Faol')->default(true),

            // Admin Toshkent vaqtini kiritadi va ko'radi, bazaga UTC yoziladi
            // (zonasiz picker kiritilgan vaqtni UTC deb saqlardi — muddat 5 soat kech edi).
            Forms\Components\DateTimePicker::make('starts_at')->label('Boshlanish vaqti')->native(false)
                ->timezone(config('app.display_timezone'))
                ->helperText("Toshkent vaqti. Bo'sh — darhol boshlanadi."),
            Forms\Components\DateTimePicker::make('ends_at')->label('Tugash vaqti')->native(false)
                ->timezone(config('app.display_timezone'))
                ->helperText("Toshkent vaqti. Bo'sh — muddatsiz."),
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

                Tables\Columns\TextColumn::make('restaurants.name')
                    ->label('Restoranlar')
                    ->badge()
                    ->color('info')
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('Tanlanmagan — ishlamaydi'),

                Tables\Columns\TextColumn::make('min_order_amount')
                    ->label('Min. summa')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::soms($state))
                    ->placeholder('—')
                    ->toggleable(),

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

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Boshlanadi')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(config('app.display_timezone'))
                    ->placeholder('Darhol')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Tugaydi')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(config('app.display_timezone'))
                    ->placeholder('Muddatsiz')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Faollik'),
                Tables\Filters\SelectFilter::make('restaurants')
                    ->label('Restoran')
                    ->relationship('restaurants', 'name'),
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
