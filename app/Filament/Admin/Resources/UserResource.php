<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Telegram mijozlari — platforma admini uchun FAQAT KO'RISH.
 *
 * Profil ma'lumoti (ism, telefon, til, manzil) faqat bot orqali
 * (ProfileService / RegistrationConversation) o'zgaradi — bu operatsion
 * ma'lumot, admin panel orqali tahrirlanmaydi/o'chirilmaydi (UserPolicy
 * create/update/delete har doim false qaytaradi, forma yo'q).
 *
 * MAXFIYLIK: telefon raqami shu yerda ko'rinadi — bu platformaning
 * operatsion ma'lumoti (buyurtma/yetkazish uchun shart). Agar kelajakda
 * boshqa xodim turi (masalan restoran egasi) ham mijozlar ro'yxatiga
 * kirish huquqi olishi kerak bo'lsa, buni ALOHIDA qaror sifatida ko'rib
 * chiqish kerak — hozircha bu resurs va UserPolicy faqat platform_admin
 * bilan cheklangan.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Mijozlar';

    protected static ?string $modelLabel = 'Mijoz';

    protected static ?string $pluralModelLabel = 'Mijozlar';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('orders');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('telegram_id')
                    ->label('Telegram ID')
                    ->searchable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Ism')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('language')
                    ->label('Til')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? __("messages.settings.lang_{$state}") : '—'),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Buyurtmalar')
                    ->state(fn (User $record): int => $record->orders_count ?? $record->orders()->count())
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label("Ro'yxatdan o'tgan")
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->label('Til')
                    ->options(collect(User::LANGUAGES)->mapWithKeys(
                        fn (string $l) => [$l => __("messages.settings.lang_{$l}")],
                    )),

                Filter::make('registered_between')
                    ->label("Ro'yxatdan o'tgan sana")
                    ->form([
                        DatePicker::make('from')->label('Dan')->native(false),
                        DatePicker::make('until')->label('Gacha')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if ($data['from'] ?? null) {
                            $out[] = 'Dan: '.$data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $out[] = 'Gacha: '.$data['until'];
                        }

                        return $out;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading("Hali mijoz yo'q");
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('telegram_id')->label('Telegram ID'),
            Infolists\Components\TextEntry::make('full_name')->label('Ism')->placeholder('—'),
            Infolists\Components\TextEntry::make('phone')->label('Telefon')->placeholder('—'),
            Infolists\Components\TextEntry::make('language')->label('Til')
                ->formatStateUsing(fn (?string $state): string => $state ? __("messages.settings.lang_{$state}") : '—'),
            Infolists\Components\TextEntry::make('orders_count')->label('Jami buyurtmalar')
                ->state(fn (User $record): int => $record->orders()->count()),
            Infolists\Components\TextEntry::make('created_at')->label("Ro'yxatdan o'tgan")->dateTime('d.m.Y H:i'),
            Infolists\Components\RepeatableEntry::make('addresses')->label('Manzillar')
                ->schema([
                    Infolists\Components\TextEntry::make('label')->label('Nomi'),
                    Infolists\Components\TextEntry::make('resolved_address')->label('Manzil')->placeholder('—'),
                ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
        ];
    }
}
