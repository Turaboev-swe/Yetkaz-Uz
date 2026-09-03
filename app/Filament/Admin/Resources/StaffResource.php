<?php

namespace App\Filament\Admin\Resources;

use App\Enums\StaffRole;
use App\Filament\Admin\Resources\StaffResource\Pages;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Xodimlar';

    protected static ?string $modelLabel = 'Xodim';

    protected static ?string $pluralModelLabel = 'Xodimlar';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Ism')->required()->maxLength(255),

            Forms\Components\TextInput::make('email')->label('Email')
                ->email()->required()->unique(ignoreRecord: true)->maxLength(255),

            Forms\Components\TextInput::make('telegram_chat_id')
                ->label('Bildirishnoma uchun Telegram chat ID')
                ->helperText("Botga /id buyrug'ini yuborib olingan raqamni kiriting.")
                ->numeric()
                ->rule('integer'),

            Forms\Components\Select::make('role')->label('Rol')
                ->options(collect(StaffRole::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required()->native(false)->live(),

            Forms\Components\Select::make('restaurant_id')->label('Restoran')
                ->relationship('restaurant', 'name')
                ->native(false)
                ->required(fn (Forms\Get $get) => $get('role') !== StaffRole::PlatformAdmin->value)
                ->visible(fn (Forms\Get $get) => $get('role') !== StaffRole::PlatformAdmin->value)
                ->helperText('platform_admin uchun bo`sh qoldiring.'),

            // Model `password` cast'i (hashed) hash qiladi.
            Forms\Components\TextInput::make('password')->label('Parol')
                ->password()
                ->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Tahrirlashda bo`sh qoldirilsa parol o`zgarmaydi.'),

            Forms\Components\Toggle::make('is_active')->label('Faol')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Ism')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('Rol')
                    ->badge()->formatStateUsing(fn (StaffRole $state) => $state->label()),
                Tables\Columns\TextColumn::make('restaurant.name')->label('Restoran')->placeholder('—'),
                Tables\Columns\IconColumn::make('telegram_chat_id')->label('Telegram')
                    ->boolean()->tooltip('Bildirishnoma chat ID kiritilganmi')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('is_active')->label('Faol'),
                Tables\Columns\TextColumn::make('last_login_at')->label('Oxirgi kirish')->since()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options(collect(StaffRole::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
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
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }
}
