<?php

namespace App\Filament\Admin\Resources;

use App\Enums\PosType;
use App\Filament\Admin\Resources\RestaurantResource\Pages;
use App\Filament\Support\RestaurantLocationForm;
use App\Filament\Support\WorkHoursForm;
use App\Models\Restaurant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Restoranlar';

    protected static ?string $modelLabel = 'Restoran';

    protected static ?string $pluralModelLabel = 'Restoranlar';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Asosiy')->schema([
                Forms\Components\TextInput::make('name')->label('Nomi')->required()->maxLength(255),
                Forms\Components\TextInput::make('phone')->label('Telefon')->tel()->maxLength(32),
                Forms\Components\FileUpload::make('logo_url')->label('Logo')
                    ->image()->imageEditor()->avatar()
                    ->disk('public')->directory('logos')->visibility('public')
                    ->imageResizeMode('cover')->imageResizeTargetWidth('400')->imageResizeTargetHeight('400')
                    ->maxSize(2048),
                Forms\Components\TextInput::make('notify_chat_id')->label('Bildirishnoma chat ID')
                    ->helperText('Yangi buyurtmalar shu Telegram chatga keladi. Egasi botга /id yozib oladi.')
                    ->maxLength(32),
                Forms\Components\Toggle::make('is_open')->label('Ochiq')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Joylashuv')
                ->description('Viloyat va tumanni tanlang, keyin xaritada aniq nuqtani bosing.')
                ->schema(RestaurantLocationForm::schema())
                ->columns(2),

            Forms\Components\Section::make('Yetkazish')->schema([
                Forms\Components\TextInput::make('delivery_radius_km')
                    ->label('Yetkazish radiusi — maksimal masofa (km)')
                    ->helperText('Restoran shu masofadan uzoqqa UMUMAN yetkazib bermaydi.')
                    ->numeric()->default(5),
                Forms\Components\TextInput::make('avg_prep_time_min')->label('O`rtacha tayyorlash (min)')->numeric()->default(20),
                Forms\Components\TextInput::make('min_order_amount')->label("Minimal buyurtma (so'm)")
                    ->numeric()->default(0)
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                    ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)),
                Forms\Components\TextInput::make('free_delivery_radius_km')
                    ->label('Bepul yetkazish radiusi (km)')
                    ->helperText("Shundan keyin pullik bo'ladi. Yuqoridagi \"Yetkazish radiusi\"dan BOSHQA narsa — bo'sh qoldirilsa bepul radius yo'q.")
                    ->numeric()->minValue(0),
                Forms\Components\TextInput::make('price_per_km')
                    ->label("Km narxi (so'm)")
                    ->helperText("To'ldirilsa, masofaga qarab hisoblanadi. Bo'sh qoldirilsa — pastdagi qat'iy narx ishlatiladi.")
                    ->numeric()->minValue(0)
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? (int) round((float) $state * 100) : null),
                Forms\Components\TextInput::make('delivery_fee')
                    ->label("Qat'iy yetkazish narxi — zaxira (so'm)")
                    ->helperText("Km narxi yoki bepul radius sozlanmagan bo'lsa shu narx ishlatiladi.")
                    ->numeric()->default(0)
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                    ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)),
            ])->columns(2),

            Forms\Components\Section::make('POS')->schema([
                Forms\Components\Select::make('pos_type')->label('POS turi')
                    ->options(collect(PosType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->default(PosType::Manual->value)->native(false)->live(),
                Forms\Components\TextInput::make('printer_host')->label('Printer IP')
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value),
                Forms\Components\TextInput::make('printer_port')->label('Printer port')->numeric()->default(9100)
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value),
                Forms\Components\TextInput::make('print_agent_token')->label('Print agent tokeni')
                    ->helperText('Oshxona kompyuteridagi agent shu token bilan ulanadi. Bo\'sh qoldirilsa chek chiqmaydi.')
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value)
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('gen')->icon('heroicon-m-arrow-path')
                            ->action(fn (Forms\Set $set) => $set('print_agent_token', Str::random(40))),
                    ),
            ])->columns(2),

            Forms\Components\Section::make('Ish vaqti')->schema([
                WorkHoursForm::make('work_hours'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')->label('')->disk('public')->circular(),
                Tables\Columns\TextColumn::make('name')->label('Nomi')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('district.name')->label('Tuman')->sortable(),
                Tables\Columns\TextColumn::make('district.region.name')->label('Viloyat')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pos_type')->label('POS')
                    ->badge()->formatStateUsing(fn (PosType $state) => $state->label()),
                Tables\Columns\ToggleColumn::make('is_open')->label('Ochiq'),
                Tables\Columns\TextColumn::make('staff_count')->label('Xodimlar')->counts('staff')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('Qo`shilgan')->date()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district_id')->label('Tuman')
                    ->relationship('district', 'name')->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRestaurants::route('/'),
            'create' => Pages\CreateRestaurant::route('/create'),
            'edit' => Pages\EditRestaurant::route('/{record}/edit'),
        ];
    }
}
