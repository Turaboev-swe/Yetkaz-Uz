<?php

namespace App\Filament\Admin\Resources;

use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\InvoiceResource\Pages;
use App\Filament\Admin\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Admin\Resources\InvoiceResource\Widgets\InvoiceOrdersWidget;
use App\Models\RestaurantInvoice;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\InvoiceService;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * "Hisob-kitoblar" — restoranlarning oylik obuna hisob-kitoblari. Yaratish/tahrirlash/o'chirish
 * yo'q (billing:generate-invoices); faqat amallar: to'lov qo'shish, qayta hisoblash, kechirish.
 * Faqat platform_admin (RestaurantInvoicePolicy).
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = RestaurantInvoice::class;

    protected static ?string $navigationGroup = 'Obunalar';

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationLabel = 'Hisob-kitoblar';

    protected static ?string $modelLabel = 'Hisob-kitob';

    protected static ?string $pluralModelLabel = 'Hisob-kitoblar';

    protected static ?string $slug = 'hisob-kitoblar';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('restaurant.name')->label('Restoran')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('period')->label('Oy')
                    ->formatStateUsing(fn ($state): string => BillingCalculator::monthLabel($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan')->label('Tarif')->badge()
                    ->formatStateUsing(fn (BillingPlan $state, RestaurantInvoice $record): string => self::planText($record)),
                Tables\Columns\TextColumn::make('orders_count')->label('Buyurtmalar')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('orders_total')->label('Aylanma')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state))->sortable(),
                Tables\Columns\TextColumn::make('amount_due')->label('To‘lanishi kerak')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state))->sortable(),
                Tables\Columns\TextColumn::make('amount_paid')->label('To‘langan')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state))->sortable(),
                Tables\Columns\TextColumn::make('debt')->label('Qarz')
                    ->state(fn (RestaurantInvoice $record): int => $record->debt())
                    ->formatStateUsing(fn (int $state): string => Money::soms($state))
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('due_date')->label('Muddat')->date('d.m.Y')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period')->label('Oy')
                    ->options(fn (): array => RestaurantInvoice::query()
                        ->select('period')->distinct()->orderByDesc('period')->pluck('period')
                        ->mapWithKeys(fn ($p) => [$p->format('Y-m-d') => BillingCalculator::monthLabel($p)])
                        ->all()),
                SelectFilter::make('status')->label('Holat')
                    ->options(collect(InvoiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('plan')->label('Tarif')
                    ->options(collect(BillingPlan::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all()),
                SelectFilter::make('restaurant_id')->label('Restoran')
                    ->relationship('restaurant', 'name')->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ko‘rish'),
                self::addPaymentAction(),
                self::recalculateAction(),
                self::changeTariffAction(),
                self::waiveAction(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Hali hisob-kitob yo‘q')
            ->emptyStateDescription('Hisob-kitoblar har oyning 1-kuni avtomatik yaratiladi (billing:generate-invoices).');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Hisob-kitob')->columns(3)->schema([
                Infolists\Components\TextEntry::make('restaurant.name')->label('Restoran'),
                Infolists\Components\TextEntry::make('period')->label('Oy')
                    ->formatStateUsing(fn ($state): string => BillingCalculator::monthLabel($state)),
                Infolists\Components\TextEntry::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color()),
                Infolists\Components\TextEntry::make('plan')->label('Tarif (hisob paytidagi)')
                    ->formatStateUsing(fn (BillingPlan $state, RestaurantInvoice $record): string => self::planText($record)),
                Infolists\Components\TextEntry::make('proration')->label('Faol kunlar (proratsiya)')
                    ->state(function (RestaurantInvoice $record): string {
                        [$active, $days] = BillingCalculator::activeDays($record->billing_started_at, $record->period);

                        return "{$active}/{$days}";
                    })
                    ->visible(function (RestaurantInvoice $record): bool {
                        [$active, $days] = BillingCalculator::activeDays($record->billing_started_at, $record->period);

                        return $record->plan === BillingPlan::Fixed && $active < $days;
                    }),
                Infolists\Components\TextEntry::make('orders_count')->label('Yetkazilgan buyurtmalar')->numeric(),
                Infolists\Components\TextEntry::make('orders_total')->label('Aylanma (taom summasi)')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state)),
                Infolists\Components\TextEntry::make('amount_due')->label('To‘lanishi kerak')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state)),
                Infolists\Components\TextEntry::make('amount_paid')->label('To‘langan')
                    ->formatStateUsing(fn (int $state): string => Money::soms($state)),
                Infolists\Components\TextEntry::make('debt')->label('Qarz')
                    ->state(fn (RestaurantInvoice $record): int => $record->debt())
                    ->formatStateUsing(fn (int $state): string => Money::soms($state)),
                Infolists\Components\TextEntry::make('due_date')->label('To‘lov muddati')->date('d.m.Y'),
                Infolists\Components\TextEntry::make('notes')->label('Izoh')->placeholder('—')->columnSpan(2)
                    ->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class];
    }

    public static function getWidgets(): array
    {
        return [InvoiceOrdersWidget::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }

    /** "5% (stavka)" yoki "Qat'iy 100 000 so'm" — hisob paytidagi tarif snapshot'idan. */
    public static function planText(RestaurantInvoice $invoice): string
    {
        return $invoice->plan === BillingPlan::Percent
            ? 'Foiz · '.rtrim(rtrim(number_format($invoice->rate_bp / 100, 2, '.', ''), '0'), '.').'%'
            : 'Qat‘iy · '.Money::soms($invoice->fixed_fee);
    }

    // --- Amallar (jadval va ko'rish sahifasi uchun umumiy mantiq) ---------------------------

    /** @return array<int, Forms\Components\Component> */
    public static function paymentFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('amount')
                ->label('Summa (so‘m)')
                ->required()
                ->numeric()
                ->minValue(1)
                ->suffix('so‘m'),
            Forms\Components\Select::make('method')
                ->label('Usul')
                ->options(collect(BillingPaymentMethod::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all())
                ->required()
                ->native(false),
            Forms\Components\DatePicker::make('paid_at')
                ->label('To‘lov sanasi')
                ->required()
                ->maxDate(now(BillingCalculator::timezone())->toDateString())
                ->native(false),
            Forms\Components\Textarea::make('note')->label('Izoh')->rows(2)->maxLength(500),
        ];
    }

    /** @return array<string, mixed> */
    public static function paymentDefaults(RestaurantInvoice $invoice): array
    {
        return [
            'amount' => intdiv($invoice->debt(), 100),
            'method' => BillingPaymentMethod::Cash->value,
            'paid_at' => now(BillingCalculator::timezone())->toDateString(),
        ];
    }

    /** @param array{amount: int|string, method: string, paid_at: string, note?: ?string} $data */
    public static function addPayment(RestaurantInvoice $invoice, array $data): void
    {
        // Forma so'mda, baza tiyinda.
        app(InvoiceService::class)->addPayment(
            $invoice,
            (int) round((float) $data['amount'] * 100),
            BillingPaymentMethod::from($data['method']),
            $data['paid_at'],
            $data['note'] ?? null,
            Filament::auth()->id(),
        );

        Notification::make()->title('To‘lov qo‘shildi')->success()->send();
    }

    /** Hisobdagi tarif snapshot'i bilan qayta hisoblash (restoranning joriy tarifi ishlatilmaydi). */
    public static function recalculate(RestaurantInvoice $invoice): void
    {
        app(InvoiceService::class)->recalculate($invoice);

        Notification::make()->title('Qayta hisoblandi')->success()->send();
    }

    /** @return array<int, Forms\Components\Component> */
    public static function tariffFormSchema(): array
    {
        return [
            Forms\Components\Select::make('plan')
                ->label('Tarif')
                ->options(collect(BillingPlan::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all())
                ->required()
                ->live()
                ->native(false),
            Forms\Components\TextInput::make('rate')
                ->label('Stavka (%)')
                ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                ->visible(fn (Forms\Get $get): bool => $get('plan') === BillingPlan::Percent->value)
                ->required(fn (Forms\Get $get): bool => $get('plan') === BillingPlan::Percent->value),
            Forms\Components\TextInput::make('fee')
                ->label('Oylik to‘lov (so‘m)')
                ->numeric()->minValue(0)->suffix('so‘m')
                ->visible(fn (Forms\Get $get): bool => $get('plan') === BillingPlan::Fixed->value)
                ->required(fn (Forms\Get $get): bool => $get('plan') === BillingPlan::Fixed->value),
            Forms\Components\Textarea::make('reason')
                ->label('Sabab (izoh)')
                ->helperText('Hisob-kitob izohiga yoziladi: kim, qachon, eski → yangi tarif va shu sabab.')
                ->required()
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /** @return array<string, mixed> */
    public static function tariffDefaults(RestaurantInvoice $invoice): array
    {
        return [
            'plan' => $invoice->plan->value,
            'rate' => $invoice->rate_bp / 100,
            'fee' => intdiv((int) $invoice->fixed_fee, 100),
        ];
    }

    /** @param array{plan: string, rate?: int|float|string|null, fee?: int|float|string|null, reason: string} $data */
    public static function changeTariff(RestaurantInvoice $invoice, array $data): void
    {
        $plan = BillingPlan::from($data['plan']);

        // Tanlanmagan tarifning qiymati o'zgarmaydi (snapshot'da qoladi).
        $rateBp = $plan === BillingPlan::Percent && filled($data['rate'] ?? null)
            ? (int) round((float) $data['rate'] * 100)
            : (int) $invoice->rate_bp;
        $fixedFee = $plan === BillingPlan::Fixed && filled($data['fee'] ?? null)
            ? (int) round((float) $data['fee'] * 100)
            : (int) $invoice->fixed_fee;

        app(InvoiceService::class)->changeTariff($invoice, $plan, $rateBp, $fixedFee, $data['reason'], Filament::auth()->id());

        Notification::make()->title('Tarif o‘zgartirildi')->body('Summa yangi tarif bilan qayta hisoblandi.')->success()->send();
    }

    public static function changeTariffAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('changeTariff')
            ->label('Tarifni o‘zgartirish')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->authorize('changeTariff')
            ->visible(fn (RestaurantInvoice $record): bool => ! $record->isSettled())
            ->requiresConfirmation()
            ->modalHeading(fn (RestaurantInvoice $record): string => 'Tarifni o‘zgartirish — '.$record->restaurant->name)
            ->modalDescription('Faqat shu hisob-kitobning tarifi o‘zgaradi (restoranniki emas) va summa qayta hisoblanadi. O‘zgarish izohga yoziladi.')
            ->form(self::tariffFormSchema())
            ->fillForm(fn (RestaurantInvoice $record): array => self::tariffDefaults($record))
            ->action(fn (RestaurantInvoice $record, array $data) => self::changeTariff($record, $data));
    }

    public static function waive(RestaurantInvoice $invoice): void
    {
        app(InvoiceService::class)->waive($invoice);

        Notification::make()->title('Hisob-kitob kechirildi')->success()->send();
    }

    public static function canAddPayment(RestaurantInvoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Partial], true);
    }

    public static function addPaymentAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('addPayment')
            ->label('To‘lov qo‘shish')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->authorize('addPayment')
            ->visible(fn (RestaurantInvoice $record): bool => self::canAddPayment($record))
            ->modalHeading(fn (RestaurantInvoice $record): string => 'To‘lov qo‘shish — '.$record->restaurant->name)
            ->form(self::paymentFormSchema())
            ->fillForm(fn (RestaurantInvoice $record): array => self::paymentDefaults($record))
            ->action(fn (RestaurantInvoice $record, array $data) => self::addPayment($record, $data));
    }

    public static function recalculateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('recalculate')
            ->label('Qayta hisoblash')
            ->icon('heroicon-o-arrow-path')
            ->authorize('recalculate')
            ->visible(fn (RestaurantInvoice $record): bool => ! $record->isSettled())
            ->requiresConfirmation()
            ->modalDescription('Buyurtmalar soni, aylanma va summa hisob-kitobdagi tarif (snapshot) bilan qayta hisoblanadi; tarifning o‘zi o‘zgarmaydi. To‘langan summa saqlanadi.')
            ->action(fn (RestaurantInvoice $record) => self::recalculate($record));
    }

    public static function waiveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('waive')
            ->label('Kechirish')
            ->icon('heroicon-o-hand-raised')
            ->color('gray')
            ->authorize('waive')
            ->visible(fn (RestaurantInvoice $record): bool => self::canAddPayment($record))
            ->requiresConfirmation()
            ->modalHeading('Hisob-kitobni kechirish')
            ->modalDescription('Qarz 0 bo‘ladi va hisob-kitob yopiladi. Bu amalni qaytarib bo‘lmaydi.')
            ->action(fn (RestaurantInvoice $record) => self::waive($record));
    }
}
