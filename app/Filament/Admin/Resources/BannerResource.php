<?php

namespace App\Filament\Admin\Resources;

use App\Enums\BannerTarget;
use App\Filament\Admin\Resources\BannerResource\Pages;
use App\Models\Banner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mini App bosh sahifasidagi reklama bannerlari (karusel) — faqat platforma
 * admini. Rasm serverda qayta ishlanmaydi (GD/Imagick yo'q): o'lcham faqat
 * tavsiya, tur va hajm esa qat'iy tekshiriladi. Muddat Toshkent vaqtida
 * kiritiladi va ko'rsatiladi, bazada UTC (promo-kod bilan bir xil).
 */
class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Bannerlar';

    protected static ?string $modelLabel = 'Banner';

    protected static ?string $pluralModelLabel = 'Bannerlar';

    protected static ?int $navigationSort = 9;

    /** Rasm cheklovlari — forma matni va validatsiya bitta joydan. */
    public const MAX_IMAGE_KB = 500;

    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function getEloquentQuery(): Builder
    {
        // Ro'yxatda restoran nomi va holati ko'rsatiladi — N+1 bo'lmasin.
        return parent::getEloquentQuery()->with('restaurant:id,name,is_open');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\FileUpload::make('image_path')
                ->label('Rasm')
                ->required()
                ->disk('public')
                ->directory('banners')
                ->visibility('public')
                ->acceptedFileTypes(self::IMAGE_TYPES)
                ->maxSize(self::MAX_IMAGE_KB)
                ->validationMessages([
                    'mimetypes' => 'Faqat JPG, PNG yoki WebP rasm yuklang.',
                    'max' => "Rasm hajmi ko'pi bilan ".self::MAX_IMAGE_KB." KB bo'lishi kerak.",
                ])
                ->helperText("Tavsiya etilgan o'lcham: 1200×600 px (2:1). JPG, PNG yoki WebP, ko'pi bilan "
                    .self::MAX_IMAGE_KB." KB. Rasm serverda kesilmaydi — nisbati 2:1 dan farq qilsa, chetlari qirqilib ko'rinadi.")
                ->columnSpanFull(),

            Forms\Components\TextInput::make('title')
                ->label('Nomi (ichki)')
                ->required()
                ->maxLength(120)
                ->helperText("Faqat panel uchun — mijozga ko'rinmaydi."),

            Forms\Components\TextInput::make('sort_order')
                ->label('Tartib')
                ->numeric()
                ->integer()
                ->default(0)
                ->helperText('Kichigi oldinda.'),

            Forms\Components\Select::make('target_type')
                ->label('Bosilganda')
                ->options(collect(BannerTarget::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->default(BannerTarget::None->value)
                ->required()
                ->native(false)
                ->live(),

            Forms\Components\Select::make('restaurant_id')
                ->label('Restoran')
                ->relationship('restaurant', 'name')
                ->searchable()
                ->preload()
                ->visible(fn (Forms\Get $get): bool => $get('target_type') === BannerTarget::Restaurant->value)
                ->required(fn (Forms\Get $get): bool => $get('target_type') === BannerTarget::Restaurant->value)
                ->helperText("Restoran yopiq (\"Ochiq\" o'chirilgan) bo'lsa, banner mijozga ko'rsatilmaydi."),

            Forms\Components\Toggle::make('is_active')->label('Faol')->default(true)->columnSpanFull(),

            // Admin Toshkent vaqtini kiritadi va ko'radi, bazaga UTC yoziladi
            // (zonasiz picker promo-kodlarda muddatni 5 soat surib yuborgan edi).
            Forms\Components\DateTimePicker::make('starts_at')->label('Boshlanish vaqti')->native(false)
                ->timezone(config('app.display_timezone'))
                ->required()
                ->default(fn () => now())
                ->helperText('Toshkent vaqti.'),
            Forms\Components\DateTimePicker::make('ends_at')->label('Tugash vaqti')->native(false)
                ->timezone(config('app.display_timezone'))
                ->after('starts_at')
                ->helperText("Toshkent vaqti. Bo'sh — muddatsiz."),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('')
                    ->disk('public')
                    ->width(96)
                    ->height(48)
                    ->extraImgAttributes(['class' => 'rounded-md object-cover', 'loading' => 'lazy']),

                Tables\Columns\TextColumn::make('title')->label('Nomi')->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Holati')
                    ->badge()
                    ->state(fn (Banner $record): string => $record->statusAt(now()))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'live' => "Ko'rsatilmoqda",
                        'scheduled' => 'Kutilmoqda',
                        'expired' => 'Muddati tugagan',
                        'restaurant_closed' => 'Restoran yopiq',
                        default => "O'chirilgan",
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'live' => 'success',
                        'scheduled' => 'info',
                        'restaurant_closed' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('target_type')
                    ->label('Bosilganda')
                    ->formatStateUsing(fn (Banner $record): string => $record->target_type === BannerTarget::Restaurant
                        ? (string) $record->restaurant?->name
                        : $record->target_type->label()),

                Tables\Columns\TextColumn::make('sort_order')->label('Tartib')->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')->label('Faol'),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Boshlanadi')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(config('app.display_timezone'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Tugaydi')
                    ->dateTime('d.m.Y H:i')
                    ->timezone(config('app.display_timezone'))
                    ->placeholder('Muddatsiz')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Faollik'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading("Hali banner yo'q");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit' => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
