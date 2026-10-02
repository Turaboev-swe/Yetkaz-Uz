<?php

namespace App\Filament\Admin\Resources;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\Reporting\ReportPeriod;
use App\Support\Money;
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
        return parent::getEloquentQuery()
            ->withCount('orders')
            // "Oxirgi buyurtma" / "Jami xarid" — faqat yetkazilgan buyurtmalardan,
            // bitta so'rovda (N+1 yo'q). withSum null qaytaradi (delivered
            // buyurtma bo'lmasa) — ustunda shuni 0 so'мга aylantiramiz.
            ->withMax(['orders as last_delivered_at' => fn (Builder $q) => $q->where('status', OrderStatus::Delivered->value)], 'delivered_at')
            ->withSum(['orders as delivered_total_tiyin' => fn (Builder $q) => $q->where('status', OrderStatus::Delivered->value)], 'total');
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

                Tables\Columns\TextColumn::make('profile_completed')
                    ->label('Holati')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? '✅ To\'liq' : '⏳ Faqat /start')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Buyurtmalar')
                    ->state(fn (User $record): int => $record->orders_count ?? $record->orders()->count())
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_delivered_at')
                    ->label('Oxirgi buyurtma')
                    // orders_count'dagi kabi zaxira — aggregat yuklanmagan
                    // (masalan getEloquentQuery() dan tashqari) holatda ham to'g'ri.
                    ->state(fn (User $record) => $record->last_delivered_at
                        ?? $record->orders()->where('status', OrderStatus::Delivered->value)->max('delivered_at'))
                    ->since()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('delivered_total_tiyin')
                    ->label('Jami xarid')
                    ->state(fn (User $record) => $record->delivered_total_tiyin
                        ?? $record->orders()->where('status', OrderStatus::Delivered->value)->sum('total'))
                    ->formatStateUsing(fn (?int $state): string => Money::soms($state ?? 0))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label("Ro'yxatdan o'tgan")
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('profile_completed')
                    ->label("Ro'yxatdan o'tish holati")
                    ->options([
                        '1' => "To'liq ro'yxatdan o'tganlar",
                        '0' => 'Faqat /start bosganlar',
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] !== null && $data['value'] !== '',
                        fn (Builder $q) => $q->where('profile_completed', (bool) $data['value']),
                    )),

                SelectFilter::make('activity')
                    ->label('Faollik')
                    ->options([
                        'active_7' => 'Faol — 7 kun',
                        'active_30' => 'Faol — 30 kun',
                        'active_90' => 'Faol — 90 kun',
                        'inactive' => "Nofaol (30+ kun buyurtma yo'q)",
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active_7' => $query->activeSince(7),
                        'active_30' => $query->activeSince(30),
                        'active_90' => $query->activeSince(90),
                        'inactive' => $query->inactiveFor(30),
                        default => $query,
                    }),

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
                        // Toshkent kalendar kuni chegarasi (whereDate UTC sanani solishtirardi — REPORT-3).
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', ReportPeriod::custom($d, $d)->fromUtc()))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<=', ReportPeriod::custom($d, $d)->toUtc())))
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
