<?php

namespace App\Filament\Admin\Resources;

use App\Enums\BeyondFreeZoneDelivery;
use App\Enums\BillingPlan;
use App\Enums\PosType;
use App\Filament\Admin\Resources\RestaurantResource\Pages;
use App\Filament\Support\RestaurantLocationForm;
use App\Filament\Support\WorkHoursForm;
use App\Models\Restaurant;
use App\Models\RestaurantBillingPlan;
use App\Models\RestaurantInvoice;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\TariffService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RestaurantResource extends Resource
{
    /** "2026-11 dan: Foiz 5% · 2026-12 dan: Qat'iy 100 000 so'm" — tarif tarixi (forma uchun, faqat o'qish). */
    public static function billingHistoryText(?Restaurant $restaurant): string
    {
        if ($restaurant === null || ! $restaurant->exists) {
            return '—';
        }

        $lines = $restaurant->billingPlans()->get()->map(fn ($entry): string => BillingCalculator::monthLabel($entry->effective_from).' dan: '.self::tariffText($entry));

        return $lines->isEmpty() ? 'Tarif belgilanmagan' : $lines->implode(' · ');
    }

    /** «Foiz 5%» / «Qat'iy 100 000 so'm (oldindan)» / «Tarifsiz» (yozuv yo'q — «Belgilanmagan»). */
    public static function tariffText(?RestaurantBillingPlan $entry): string
    {
        return match ($entry?->plan) {
            BillingPlan::Percent => 'Foiz '.rtrim(rtrim(number_format($entry->rate_bp / 100, 2, '.', ''), '0'), '.').'%',
            BillingPlan::Fixed => "Qat'iy ".Money::soms($entry->monthly_fee).' (oldindan)',
            null => $entry === null ? 'Belgilanmagan' : 'Tarifsiz',
        };
    }

    /** «Dekabrdan: Foiz 5%» — kelajakda amalga kiradigan tarif (bo'lmasa null). */
    public static function upcomingTariffText(?Restaurant $restaurant): ?string
    {
        $entry = $restaurant?->exists ? $restaurant->upcomingBillingEntry() : null;

        return $entry === null ? null : BillingCalculator::monthName($entry->effective_from).'dan: '.self::tariffText($entry);
    }

    /** Formadagi tanlovga ko'ra bekor qilinadigan joriy oy fixed hisobi (bo'lmasa null). */
    private static function correctableInvoice(Forms\Get $get, ?Restaurant $record): ?RestaurantInvoice
    {
        if ($record === null || ! $record->exists || $get('effective_month') !== 'current') {
            return null;
        }

        return app(TariffService::class)->correctableInvoice(
            $record,
            BillingPlan::tryFrom((string) $get('billing_plan')),
            CarbonImmutable::now(BillingCalculator::timezone()),
        );
    }

    private static function correctionNotice(Forms\Get $get, ?Restaurant $record): string
    {
        $invoice = self::correctableInvoice($get, $record);

        return $invoice === null
            ? ''
            : BillingCalculator::monthLabel($invoice->period).' uchun qat‘iy hisob ('.Money::soms($invoice->amount_due).') bekor qilinadi (o‘chirilmaydi — «Bekor qilingan» holatida qoladi). Faol to‘lovi bo‘lsa, bekor qilib bo‘lmaydi.';
    }

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
                Forms\Components\FileUpload::make('logo_url')->label('Logo')
                    ->image()->imageEditor()->avatar()
                    ->disk('public')->directory('logos')->visibility('public')
                    ->imageResizeMode('cover')->imageResizeTargetWidth('400')->imageResizeTargetHeight('400')
                    ->maxSize(2048),
                Forms\Components\TextInput::make('notify_chat_id')->label('Bildirishnoma chat ID')
                    ->helperText('Yangi buyurtmalar shu Telegram chatga keladi. Egasi botга /id yozib oladi.')
                    ->maxLength(32),
                Forms\Components\Toggle::make('is_open')->label('Ochiq')->default(true),
                Forms\Components\Checkbox::make('is_test')->label('🧪 Test restoran')
                    ->helperText("Faqat TEST_TELEGRAM_IDS dagi hisoblarga ko'rinadi, hisobotlarga kirmaydi")
                    ->default(false),
            ])->columns(2),

            Forms\Components\Section::make('Joylashuv')
                ->description('Viloyat va tumanni tanlang, keyin xaritada aniq nuqtani bosing.')
                ->schema(RestaurantLocationForm::schema())
                ->columns(2),

            Forms\Components\Section::make('Yetkazish')->schema([
                Forms\Components\TextInput::make('delivery_radius_km')
                    ->label('Yetkazish radiusi — maksimal masofa (km)')
                    ->helperText('Restoran shu masofadan uzoqqa UMUMAN yetkazib bermaydi.')
                    ->numeric()->default(5),
                Forms\Components\TextInput::make('avg_prep_time_min')->label('O`rtacha tayyorlash (min)')->numeric()->default(20),
                Forms\Components\TextInput::make('min_order_amount')->label("Minimal buyurtma (so'm)")
                    ->numeric()->default(0)
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                    ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)),
                Forms\Components\TextInput::make('free_delivery_radius_km')
                    ->label('Bepul yetkazish radiusi (km)')
                    ->helperText("Shundan keyin pullik bo'ladi. Yuqoridagi \"Yetkazish radiusi\"dan BOSHQA narsa — bo'sh qoldirilsa bepul radius yo'q.")
                    ->numeric()->minValue(0)
                    // Royal Taxi rejimida "bepul zona" chegarasi shu radius — usiz taksi rejimi ma'nosiz.
                    ->required(fn (Forms\Get $get): bool => $get('beyond_free_zone_delivery') === BeyondFreeZoneDelivery::Taxi->value),
                Forms\Components\Select::make('beyond_free_zone_delivery')
                    ->label('Bepul zonadan tashqarida yetkazish')
                    ->options(collect(BeyondFreeZoneDelivery::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->default(BeyondFreeZoneDelivery::PerKm->value)
                    ->native(false)->live()->required()
                    ->helperText("Km bo'yicha narx — hozirgidek. Royal Taxi — zonadan tashqarida ilovada yetkazish narxi 0 bo'ladi, mijoz taksi haqini haydovchiga alohida to'laydi (ikki marta to'lamaydi). Bepul zonada yetkazish baribir bepul. Eski buyurtmalar o'zgarmaydi.")
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('price_per_km')
                    ->label("Km narxi (so'm)")
                    ->helperText("To'ldirilsa, masofaga qarab hisoblanadi. Bo'sh qoldirilsa — pastdagi qat'iy narx ishlatiladi.")
                    ->numeric()->minValue(0)
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? (int) round((float) $state * 100) : null),
                Forms\Components\TextInput::make('delivery_fee')
                    ->label("Qat'iy yetkazish narxi — zaxira (so'm)")
                    ->helperText("Km narxi yoki bepul radius sozlanmagan bo'lsa shu narx ishlatiladi.")
                    ->numeric()->default(0)
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                    ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)),
            ])->columns(2),

            // Obuna tarifi (Obunalar bo'limi). Mavjud restoranlarda tarif avtomat qo'yilmaydi —
            // "Belgilanmagan" bo'lsa hisob-kitob yaratilmaydi.
            Forms\Components\Section::make('Tarif')
                ->description("Platforma bilan hisob-kitob: yetkazilgan buyurtmalar taom summasidan foiz yoki qat'iy oylik to'lov.")
                ->schema([
                    Forms\Components\Select::make('billing_plan')
                        ->label('Tarif')
                        ->options(collect(BillingPlan::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                        ->placeholder('Belgilanmagan')
                        ->live()
                        ->native(false),
                    Forms\Components\TextInput::make('commission_rate_bp')
                        ->label('Stavka (%)')
                        ->helperText("«Foiz» tarifida qo'llanadi. Bo'sh qoldirilsa 5%.")
                        ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                        ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                        ->dehydrateStateUsing(fn ($state) => filled($state)
                            ? (int) round((float) $state * 100)
                            : (int) config('billing.default_rate_bp')),
                    Forms\Components\TextInput::make('monthly_fee')
                        ->label("Oylik to'lov (so'm)")
                        ->helperText("«Qat'iy oylik» tarifida qo'llanadi. Bo'sh qoldirilsa 100 000 so'm.")
                        ->numeric()->minValue(0)->step(1000)->suffix("so'm")
                        ->formatStateUsing(fn ($state) => $state === null ? null : intdiv((int) $state, 100))
                        ->dehydrateStateUsing(fn ($state) => filled($state)
                            ? (int) round((float) $state * 100)
                            : (int) config('billing.default_monthly_fee')),
                    Forms\Components\Select::make('effective_month')
                        ->label('Qaysi oydan')
                        ->helperText("Tarif o'zgarganda shu oyning 1-kunidan amal qiladi va tarif tarixiga yoziladi. O'tgan oylarga ta'sir qilmaydi.")
                        ->options(fn (): array => [
                            'current' => 'Joriy oy ('.BillingCalculator::monthLabel(CarbonImmutable::now(BillingCalculator::timezone())).')',
                            'next' => 'Keyingi oy ('.BillingCalculator::monthLabel(CarbonImmutable::now(BillingCalculator::timezone())->addMonth()).')',
                        ])
                        ->default('current')
                        ->required()
                        ->live()
                        ->native(false),
                    Forms\Components\DatePicker::make('billing_started_at')
                        ->label('Boshlangan sana')
                        ->helperText("Shu sanadan boshlab hisoblanadi (bo'sh bo'lsa 2026-11-01 dan). Joriy oydan qat'iy tarif boshlanganda MAJBURIY va shu oy ichida bo'lishi kerak: birinchi oy kunlarga proporsional hisoblanadi (masalan 16-noyabr -> 15/30) va hisob saqlash zahoti yaratiladi. Keyingi oydan boshlanadigan qat'iy tarif uchun sana kerak emas (1-kundan).")
                        ->required(fn (Forms\Get $get, ?Restaurant $record): bool => $get('billing_plan') === BillingPlan::Fixed->value
                            && $get('effective_month') === 'current'
                            && ($record === null || app(BillingCalculator::class)
                                ->tariffFor($record, CarbonImmutable::now(BillingCalculator::timezone()))->plan !== BillingPlan::Fixed))
                        ->native(false),
                    // Joriy oy fixed hisobi bor, lekin restoran foizda bo'lishi kerak: hisob BEKOR QILINADI (void).
                    // Faol to'lovi bo'lsa serverda rad etiladi (TariffService).
                    Forms\Components\Textarea::make('billing_correction_reason')
                        ->label('Tuzatish sababi')
                        ->helperText(fn (Forms\Get $get, ?Restaurant $record): string => self::correctionNotice($get, $record))
                        ->rows(2)
                        ->required()
                        ->visible(fn (Forms\Get $get, ?Restaurant $record): bool => self::correctableInvoice($get, $record) !== null)
                        ->columnSpanFull(),
                    Forms\Components\Checkbox::make('billing_correction_confirm')
                        ->label('Tasdiqlayman: joriy oy qat‘iy hisobi bekor qilinadi, restoran shu oydan foizga o‘tadi')
                        ->accepted()
                        ->visible(fn (Forms\Get $get, ?Restaurant $record): bool => self::correctableInvoice($get, $record) !== null)
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('billing_upcoming')
                        ->label('Keyingi tarif')
                        ->content(fn (?Restaurant $record): string => self::upcomingTariffText($record) ?? '—')
                        ->visible(fn (?Restaurant $record): bool => self::upcomingTariffText($record) !== null)
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('billing_history')
                        ->label('Tarif tarixi')
                        ->content(fn (?Restaurant $record): string => self::billingHistoryText($record))
                        ->visible(fn (?Restaurant $record): bool => $record !== null)
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('POS')->schema([
                Forms\Components\Select::make('pos_type')->label('POS turi')
                    ->options(collect(PosType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->default(PosType::Manual->value)->native(false)->live(),
                Forms\Components\TextInput::make('printer_host')->label('Printer IP')
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value),
                Forms\Components\TextInput::make('printer_port')->label('Printer port')->numeric()->default(9100)
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value),
                // Qog'oz kengligi (mm) tanlovdan avtomat belgilanadi: 32 -> 58 mm, 42/48 -> 80 mm.
                Forms\Components\Hidden::make('receipt_paper_mm')->default(80),
                Forms\Components\Select::make('receipt_columns')->label('Chek qog\'ozi')
                    ->options([
                        32 => '58 mm (32 belgi)',
                        42 => '80 mm (42 belgi)',
                        48 => '80 mm (48 belgi) — Xprinter XP-80T, Font A',
                    ])
                    ->helperText('48 belgi — 80 mm printerning to\'liq kengligi (576 nuqta). Sig\'masa 42 ga qaytaring. Tekshirish: print-agent:test-receipt')
                    // Eski restoranlarda bo'sh bo'lishi mumkin — qog'oz kengligidan olinadi (qiymat o'zgarmaydi).
                    ->formatStateUsing(fn ($state, ?Restaurant $record) => $state ?? $record?->receiptColumns() ?? 42)
                    ->default(42)->required()->native(false)->live()
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('receipt_paper_mm', (int) $state === 32 ? 58 : 80))
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value),
                Forms\Components\TextInput::make('receipt_codepage')->label('Kodlash jadvali (ESC t n)')
                    ->helperText('Xprinter/Epson-mos printerlar uchun 16 (WPC1252). Chekka faqat ASCII yoziladi (kirill lotinga o\'giriladi, emoji olib tashlanadi). Tekshirish: php artisan print-agent:test-receipt <restoran> --table')
                    ->numeric()->minValue(0)->maxValue(255)->default(16)->required()
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value),
                Forms\Components\TextInput::make('print_agent_token')->label('Print agent tokeni')
                    ->helperText('Oshxona kompyuteridagi agent shu token bilan ulanadi. Bo\'sh qoldirilsa chek chiqmaydi.')
                    ->visible(fn (Forms\Get $get) => $get('pos_type') === PosType::EscPos->value)
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('gen')->icon('heroicon-m-arrow-path')
                            ->action(fn (Forms\Set $set) => $set('print_agent_token', Str::random(40))),
                    ),
            ])->columns(2),

            Forms\Components\Section::make('Ish vaqti')->schema([
                WorkHoursForm::make('work_hours'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('billingPlans')) // «Tarif» ustuni: N+1 yo'q
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')->label('')->disk('public')->circular(),
                Tables\Columns\TextColumn::make('name')->label('Nomi')->searchable()->sortable()
                    ->description(fn (Restaurant $record): ?string => $record->is_test ? '🧪 Test restoran' : null),
                Tables\Columns\TextColumn::make('district.name')->label('Tuman')->sortable(),
                Tables\Columns\TextColumn::make('district.region.name')->label('Viloyat')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('billing_tariff')->label('Tarif')
                    ->state(fn (Restaurant $record): string => self::tariffText($record->currentBillingEntry()))
                    ->description(fn (Restaurant $record): ?string => self::upcomingTariffText($record))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('pos_type')->label('POS')
                    ->badge()->formatStateUsing(fn (PosType $state) => $state->label()),
                Tables\Columns\TextColumn::make('print_agent_last_seen_at')->label('Printer agenti')
                    ->badge()
                    ->state(fn (Restaurant $record): ?string => $record->usesPrintAgent() ? $record->printAgentStatus() : null)
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'online' => 'Onlayn',
                        'offline' => 'O‘chiq',
                        default => 'Ulanmagan',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('is_open')->label('Ochiq'),
                Tables\Columns\TextColumn::make('staff_count')->label('Xodimlar')->counts('staff')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('Qo`shilgan')->date()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district_id')->label('Tuman')
                    ->relationship('district', 'name')->searchable(),
                Tables\Filters\TernaryFilter::make('is_test')->label('🧪 Test restoran'),
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
