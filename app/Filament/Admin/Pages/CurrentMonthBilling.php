<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BillingPlan;
use App\Filament\Admin\Widgets\BillingStats;
use App\Models\Restaurant;
use App\Services\Billing\BillingCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Joriy oy": barcha restoranlar bo'yicha shu oydagi (Asia/Tashkent) yetkazilgan buyurtmalar,
 * aylanma va shu kungacha hisoblangan to'lov (jonli, BillingCalculator).
 */
class CurrentMonthBilling extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Obunalar';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Joriy oy';

    protected static ?string $slug = 'joriy-oy';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.admin.pages.current-month-billing';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isPlatformAdmin();
    }

    public function getTitle(): string
    {
        return 'Joriy oy — '.BillingCalculator::monthLabel(self::month());
    }

    protected function getHeaderWidgets(): array
    {
        return [BillingStats::class];
    }

    private static function month(): CarbonImmutable
    {
        return CarbonImmutable::now(BillingCalculator::timezone())->startOfMonth();
    }

    public function table(Table $table): Table
    {
        $calc = app(BillingCalculator::class);
        $month = self::month();

        return $table
            ->query(fn (): Builder => $calc->aggregated($month)->where('restaurants.is_test', false))
            ->defaultSort('billing_orders_total', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Restoran')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('billing_plan')->label('Tarif')->badge()
                    ->placeholder('Belgilanmagan')
                    ->color(fn ($state): string => $state === null ? 'gray' : 'primary')
                    ->formatStateUsing(fn (?BillingPlan $state, Restaurant $record): string => match ($state) {
                        BillingPlan::Percent => 'Foiz · '.rtrim(rtrim(number_format($record->commission_rate_bp / 100, 2, '.', ''), '0'), '.').'%',
                        BillingPlan::Fixed => 'Qat‘iy · '.Money::soms($record->monthly_fee),
                        default => 'Belgilanmagan',
                    }),
                Tables\Columns\TextColumn::make('billing_orders_count')->label('Yetkazilgan buyurtmalar')
                    ->numeric()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('billing_orders_count', $direction)),
                Tables\Columns\TextColumn::make('billing_orders_total')->label('Aylanma')
                    ->formatStateUsing(fn ($state): string => Money::soms((int) $state))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('billing_orders_total', $direction)),
                Tables\Columns\TextColumn::make('accrued')->label('Hisoblangan to‘lov')
                    ->state(fn (Restaurant $record): string => $record->billing_plan === null
                        ? '—'
                        : Money::soms($calc->resultFor($record, $month)->amountDue))
                    ->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw(
                        "CASE billing_plan WHEN 'fixed' THEN monthly_fee WHEN 'percent' THEN billing_orders_total * commission_rate_bp ELSE -1 END {$direction}",
                    )),
            ])
            ->filters([
                SelectFilter::make('billing_plan')->label('Tarif')
                    ->options([
                        BillingPlan::Percent->value => BillingPlan::Percent->label(),
                        BillingPlan::Fixed->value => BillingPlan::Fixed->label(),
                        'none' => 'Belgilanmagan',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('restaurants.billing_plan'),
                        default => $query->where('restaurants.billing_plan', $data['value']),
                    }),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Restoran yo‘q');
    }
}
