<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
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
            // aggregated() — davr tarifi (tarix), buyurtmalar agregati va shu oy hisobi ustunlari bilan;
            // fromSub bilan ular oddiy ustun sifatida saralanadi/filtrlanadi.
            ->query(fn (): Builder => Restaurant::query()
                ->fromSub($calc->aggregated($month), 'restaurants')
                ->where('restaurants.is_test', false))
            ->defaultSort('billing_orders_total', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Restoran')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('period_plan')->label('Tarif')->badge()
                    ->placeholder('Belgilanmagan')
                    ->color(fn ($state): string => $state === null ? 'gray' : 'primary')
                    ->formatStateUsing(fn (?string $state, Restaurant $record): string => match (BillingPlan::tryFrom((string) $state)) {
                        BillingPlan::Percent => 'Foiz · '.rtrim(rtrim(number_format($record->period_rate_bp / 100, 2, '.', ''), '0'), '.').'%',
                        BillingPlan::Fixed => 'Qat‘iy · '.Money::soms($record->period_monthly_fee),
                        default => 'Belgilanmagan',
                    }),
                Tables\Columns\TextColumn::make('billing_orders_count')->label('Yetkazilgan buyurtmalar')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_orders_total')->label('Aylanma')
                    ->formatStateUsing(fn ($state): string => Money::soms((int) $state))
                    ->sortable(),
                // Fixed oldindan to'lanadi — shu oy hisobining holati; percent oy oxirida — jonli hisob.
                Tables\Columns\TextColumn::make('billing_state')->label('Shu oy hisobi')->badge()
                    ->state(fn (Restaurant $record): string => self::stateText($record))
                    ->color(fn (string $state): string => match ($state) {
                        InvoiceStatus::Paid->label() => 'success',
                        InvoiceStatus::Partial->label() => 'warning',
                        InvoiceStatus::Unpaid->label() => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('accrued')->label('Hisoblangan to‘lov')
                    ->state(fn (Restaurant $record): string => BillingPlan::tryFrom((string) $record->period_plan) === null
                        ? '—'
                        : Money::soms($calc->expectedAmount($record, $month)))
                    ->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw(
                        "CASE period_plan WHEN 'fixed' THEN period_monthly_fee WHEN 'percent' THEN billing_orders_total * period_rate_bp / 10000 ELSE -1 END {$direction}",
                    )),
            ])
            ->filters([
                SelectFilter::make('period_plan')->label('Tarif')
                    ->options([
                        BillingPlan::Percent->value => BillingPlan::Percent->label(),
                        BillingPlan::Fixed->value => BillingPlan::Fixed->label(),
                        'none' => 'Belgilanmagan',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('restaurants.period_plan'),
                        default => $query->where('restaurants.period_plan', $data['value']),
                    }),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Restoran yo‘q');
    }

    /** Fixed: shu oy hisobining holati; percent: oy oxirida hisoblanishi; tarifsiz: «—». */
    private static function stateText(Restaurant $record): string
    {
        return match (BillingPlan::tryFrom((string) $record->period_plan)) {
            BillingPlan::Fixed => $record->billing_invoice_status === null
                ? 'Hisob yaratilmagan'
                : InvoiceStatus::from($record->billing_invoice_status)->label(),
            BillingPlan::Percent => 'Oy oxirida',
            default => '—',
        };
    }
}
