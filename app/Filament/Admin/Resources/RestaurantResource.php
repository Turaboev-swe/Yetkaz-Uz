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
                Forms\Components\TextInput::make('logo_url')->label('Logo URL')->url()->maxLength(500),
                Forms\Components\Toggle::make('is_open')->label('Ochiq')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Joylashuv')
                ->description('Viloyat va tumanni tanlang, keyin xaritada aniq nuqtani bosing.')
                ->schema(RestaurantLocationForm::schema())
                ->columns(2),

            Forms\Components\Section::make('Yetkazish')->schema([
                Forms\Components\TextInput::make('delivery_radius_km')->label('Radius (km)')->numeric()->default(5),
                Forms\Components\TextInput::make('avg_prep_time_min')->label('O`rtacha tayyorlash (min)')->numeric()->default(20),
                Forms\Components\TextInput::make('min_order_amount')->label("Minimal buyurtma (so'm)")
                    ->numeric()->default(0)
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                    ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)),
                Forms\Components\TextInput::make('delivery_fee')->label("Yetkazish narxi (so'm)")
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
