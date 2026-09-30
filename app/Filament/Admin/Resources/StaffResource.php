<?php

namespace App\Filament\Admin\Resources;

use App\Enums\StaffRole;
use App\Filament\Admin\Resources\StaffResource\Pages;
use App\Models\Restaurant;
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

            Forms\Components\TextInput::make('phone')->label('Telefon raqami')
                ->tel()->maxLength(32)
                ->helperText('Kuryer sifatida tanlanganда mijozga shu raqam yuboriladi.'),

            Forms\Components\TextInput::make('telegram_chat_id')
                ->label('Bildirishnoma uchun Telegram chat ID')
                ->helperText("Botga /id buyrug'ini yuborib olingan raqamni kiriting.")
                ->numeric()
                ->rule('integer'),

            Forms\Components\Select::make('role')->label('Rol')
                ->options(collect(StaffRole::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required()->native(false)->live(),

            // Pivot (restaurant_staff) — saqlash Create/EditStaff'da, Staff::assignRestaurants() orqali.
            Forms\Components\Select::make('restaurant_ids')->label('Restoranlar')
                ->multiple()
                ->options(fn () => Restaurant::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->native(false)
                ->required(fn (Forms\Get $get) => $get('role') !== StaffRole::PlatformAdmin->value)
                ->visible(fn (Forms\Get $get) => $get('role') !== StaffRole::PlatformAdmin->value)
                ->helperText('Oshxona xodimi tanlangan barcha restoranlar buyurtmalarini bitta /kitchen panelida boshqaradi. '
                    .'Asosiy restoran (egasining /restaurant paneli) — avvalgisi; u olib tashlansa, birinchi tanlangani.'),

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
                Tables\Columns\TextColumn::make('phone')->label('Telefon')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('role')->label('Rol')
                    ->badge()->formatStateUsing(fn (StaffRole $state) => $state->label()),
                Tables\Columns\TextColumn::make('restaurants.name')->label('Restoranlar')
                    ->badge()->placeholder('—'),
                Tables\Columns\IconColumn::make('telegram_chat_id')->label('Telegram')
                    ->boolean()->tooltip('Bildirishnoma chat ID kiritilganmi')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('is_active')->label('Faol'),
                Tables\Columns\TextColumn::make('last_login_at')->label('Oxirgi kirish')->since()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options(collect(StaffRole::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                Tables\Filters\SelectFilter::make('restaurants')->label('Restoran')
                    ->relationship('restaurants', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * Formadagi `restaurant_ids` ni modeldan ajratadi: [qolgan ma'lumot, ids].
     * ids = null — maydon formada yo'q edi (o'zgartirilmaydi). platform_admin
     * restoransiz (staff CHECK): restaurant_id null, pivot bo'sh.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<int>|null}
     */
    public static function extractRestaurants(array $data): array
    {
        $ids = array_key_exists('restaurant_ids', $data)
            ? array_values(array_map('intval', (array) $data['restaurant_ids']))
            : null;
        unset($data['restaurant_ids']);

        $role = $data['role'] ?? null;
        $role = $role instanceof StaffRole ? $role : StaffRole::tryFrom((string) $role);

        if ($role === StaffRole::PlatformAdmin) {
            $data['restaurant_id'] = null;
            $ids = [];
        }

        return [$data, $ids];
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
