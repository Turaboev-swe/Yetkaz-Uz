<?php

namespace App\Filament\Restaurant\Pages;

use App\Models\Order;
use App\Models\Restaurant;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restoran egasi uchun "Baholar va sharhlar" — FAQAT o'z restorani.
 *
 * IZOLYATSIYA (eng muhim xavfsizlik nuqtasi — bu yerda mijoz izohlari bor):
 *  - so'rov `restaurant_id` bo'yicha ANIQ cheklanadi (raw where)
 *  - Order modelidagi RestaurantScope global scope ham qo'llanadi (restaurant_owner)
 *  - sahifaga faqat restaurant_owner kiradi (canAccess + panel gate)
 * Boshqa restoran yozuvi hech qanday holatда ko'rinmaydi.
 */
class Ratings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Baholar';

    protected static ?string $title = 'Baholar va sharhlar';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.restaurant.pages.ratings';

    public static function canAccess(): bool
    {
        $staff = auth('staff')->user();

        return $staff !== null && $staff->isRestaurantOwner() && $staff->restaurant_id !== null;
    }

    protected function restaurant(): Restaurant
    {
        return auth('staff')->user()->restaurant;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $r = $this->restaurant();

        return [
            'average' => $r->cached_average_rating,
            'count' => (int) $r->cached_ratings_count,
            'isPublic' => $r->hasPublicRating(),
            'minCount' => Restaurant::MIN_PUBLIC_RATINGS_COUNT,
            'minAvg' => Restaurant::MIN_PUBLIC_RATING,
        ];
    }

    public function table(Table $table): Table
    {
        $restaurantId = (int) auth('staff')->user()->restaurant_id;

        return $table
            ->query(
                Order::query()
                    ->whereNotNull('rating')
                    ->where('restaurant_id', $restaurantId),
            )
            ->defaultSort('rated_at', 'desc')
            ->columns([
                TextColumn::make('rated_at')
                    ->label('Sana')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('order_number')
                    ->label('Buyurtma')
                    ->searchable(),

                TextColumn::make('rating')
                    ->label('Baho')
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state))
                    ->sortable(),

                TextColumn::make('rating_comment')
                    ->label('Izoh')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(140),

                TextColumn::make('user.full_name')
                    ->label('Mijoz')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->label('Yulduzcha')
                    ->options([
                        5 => '★★★★★',
                        4 => '★★★★',
                        3 => '★★★',
                        2 => '★★',
                        1 => '★',
                    ]),

                TernaryFilter::make('has_comment')
                    ->label('Izoh')
                    ->placeholder('Hammasi')
                    ->trueLabel('Faqat izohli')
                    ->falseLabel('Faqat izohsiz')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('rating_comment')->where('rating_comment', '!=', ''),
                        false: fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('rating_comment')->orWhere('rating_comment', '')),
                        blank: fn (Builder $q) => $q,
                    ),

                Filter::make('rated_between')
                    ->form([
                        DatePicker::make('from')->label('Dan')->native(false),
                        DatePicker::make('until')->label('Gacha')->native(false),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('rated_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('rated_at', '<=', $d)))
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
            ->emptyStateHeading('Hali baho yo\'q')
            ->paginated([25, 50, 100]);
    }
}
