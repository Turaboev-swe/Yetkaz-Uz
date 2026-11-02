<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BillingPlan;
use App\Enums\PaymentBadge;
use App\Filament\Admin\Resources\InvoiceResource;
use App\Filament\Admin\Widgets\BillingStats;
use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentState;
use App\Services\Billing\PaymentStateResolver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Joriy oy": barcha restoranlar bo'yicha shu oydagi (Asia/Tashkent) yetkazilgan buyurtmalar,
 * aylanma, shu kungacha hisoblangan to'lov (jonli, BillingCalculator) va HOZIR to'lanishi kerak
 * hisobning holati (fixed — joriy oy, percent — o'tgan oy).
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

    /** Shu restoranning HOZIR to'lanishi kerak hisobi (fixed — joriy oy, percent — o'tgan oy). */
    private function stateOf(Restaurant $record): PaymentState
    {
        return app(PaymentStateResolver::class)->resolve($record, self::month());
    }

    public function table(Table $table): Table
    {
        $calc = app(BillingCalculator::class);
        $month = self::month();

        return $table
            // aggregated() — davr tarifi (tarix), buyurtmalar agregati va joriy/o'tgan oy hisoblari ustunlari bilan;
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
                // Hozir to'lanishi kerak hisob holati. Bosilganda: qarz bor -> «To'landi» modali (InvoiceResource'dagi
                // o'sha amal), to'langan/kechirilgan -> hisob sahifasi (to'lovni bekor qilish o'sha yerda),
                // hisob yo'q -> «Hisob yaratish».
                Tables\Columns\TextColumn::make('payment_state')->label('To‘lov holati')->badge()
                    ->state(function (Restaurant $record): string {
                        $state = $this->stateOf($record);

                        return $state->badge === PaymentBadge::Partial
                            ? $state->badge->label().' · '.Money::soms($state->debt)
                            : $state->badge->label();
                    })
                    ->color(fn (Restaurant $record): string|array => $this->stateOf($record)->badge === PaymentBadge::Overdue
                        ? Color::hex('#991b1b')
                        : $this->stateOf($record)->badge->color())
                    // Percent keyin to'lanadi — qaysi oy hisobi ekanini kichik yozuv ko'rsatadi («Oktabr»).
                    ->description(function (Restaurant $record): ?string {
                        $state = $this->stateOf($record);

                        return $state->period !== null && BillingPlan::tryFrom((string) $record->period_plan) === BillingPlan::Percent
                            ? BillingCalculator::monthName($state->period)
                            : null;
                    })
                    ->action(function (Restaurant $record): void {
                        $state = $this->stateOf($record);

                        if ($state->badge->hasDebt()) {
                            $this->mountTableAction('markPaid', (string) $record->getKey());
                        } elseif ($state->badge->opensInvoice()) {
                            $this->redirect(InvoiceResource::getUrl('view', ['record' => $state->invoiceId]));
                        } elseif ($state->badge === PaymentBadge::NoInvoice) {
                            $this->mountTableAction('createInvoice', (string) $record->getKey());
                        }
                    }),
                Tables\Columns\TextColumn::make('billing_orders_count')->label('Yetkazilgan buyurtmalar')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_orders_total')->label('Aylanma')
                    ->formatStateUsing(fn ($state): string => Money::soms((int) $state))
                    ->sortable(),
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
                SelectFilter::make('payment_state')->label('To‘lov holati')
                    ->options([
                        'debt' => 'Qarzi bor (to‘lanmagan, qisman, muddati o‘tgan)',
                        PaymentBadge::Unpaid->value => PaymentBadge::Unpaid->label(),
                        PaymentBadge::Overdue->value => PaymentBadge::Overdue->label(),
                        PaymentBadge::Partial->value => PaymentBadge::Partial->label(),
                        PaymentBadge::Paid->value => PaymentBadge::Paid->label(),
                        PaymentBadge::Waived->value => PaymentBadge::Waived->label(),
                        PaymentBadge::NoInvoice->value => PaymentBadge::NoInvoice->label(),
                        PaymentBadge::AtMonthEnd->value => PaymentBadge::AtMonthEnd->label(),
                    ])
                    ->query(function (Builder $query, array $data) use ($calc, $month): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        // Holat hisob-kitob qoidalaridan (PHP) aniqlanadi; restoranlar soni kam — bitta so'rov.
                        $ids = $calc->aggregated($month)->where('is_test', false)->get()
                            ->filter(function (Restaurant $row) use ($value): bool {
                                $badge = $this->stateOf($row)->badge;

                                return $value === 'debt' ? $badge->hasDebt() : $badge->value === $value;
                            })
                            ->pluck('id')->all();

                        return $query->whereIn('restaurants.id', $ids);
                    }),
            ])
            // Badge bosilganda ochiladigan amallar; qatorda ham faqat tegishli holatda ko'rinadi (qarz bor / hisob yo'q).
            ->actions([
                InvoiceResource::configureMarkPaid(
                    Tables\Actions\Action::make('markPaid'),
                    fn (Restaurant $record): RestaurantInvoice => RestaurantInvoice::query()->with('restaurant')->find($this->stateOf($record)->invoiceId) ?? new RestaurantInvoice,
                )->visible(fn (Restaurant $record): bool => $this->stateOf($record)->badge->hasDebt()),
                Tables\Actions\Action::make('createInvoice')
                    ->label('Hisob yaratish')
                    ->icon('heroicon-o-document-plus')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Restaurant $record): string => 'Hisob yaratish — '.$record->name)
                    ->modalDescription(fn (Restaurant $record): string => BillingCalculator::monthLabel($this->stateOf($record)->period ?? $month).' uchun hisob-kitob yaratiladi.')
                    ->action(function (Restaurant $record) use ($month): void {
                        $period = $this->stateOf($record)->period ?? $month;
                        // Toza model: agregat qatori joriy oy tarifini «ko'tarib» yuradi, o'tgan oy uchun noto'g'ri bo'lardi.
                        $invoice = app(InvoiceService::class)->generate(Restaurant::query()->findOrFail($record->getKey()), $period);

                        $invoice === null
                            ? Notification::make()->title('Hisob yaratib bo‘lmadi')->body('Tarif belgilanmagan yoki bu davr hisob-kitobga kirmaydi.')->warning()->send()
                            : Notification::make()->title('Hisob yaratildi')->body(BillingCalculator::monthLabel($period))->success()->send();
                    })
                    ->visible(fn (Restaurant $record): bool => $this->stateOf($record)->badge === PaymentBadge::NoInvoice),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Restoran yo‘q');
    }
}
