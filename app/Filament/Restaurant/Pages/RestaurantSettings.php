<?php

namespace App\Filament\Restaurant\Pages;

use App\Filament\Support\RestaurantLocationForm;
use App\Filament\Support\WorkHoursForm;
use App\Models\Restaurant;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Restoran egasi o'z restorani sozlamalarini boshqaradi: asosiy ma'lumot,
 * yetkazish parametrlari, ish vaqti va `is_open` toggle.
 */
class RestaurantSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Restoran sozlamalari';

    protected static ?string $title = 'Restoran sozlamalari';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.restaurant.pages.restaurant-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $restaurant = $this->restaurant();

        $data = $restaurant->attributesToArray();
        $data['work_hours'] = WorkHoursForm::toRows($restaurant->work_hours);
        $data['region_id'] = $restaurant->district?->region_id;
        $data['location'] = ['lat' => (float) $restaurant->lat, 'lng' => (float) $restaurant->lng];

        $this->form->fill($data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Asosiy')
                    ->schema([
                        TextInput::make('name')->label('Nomi')->required()->maxLength(255),
                        TextInput::make('phone')->label('Telefon')->tel()->maxLength(32),
                        FileUpload::make('logo_url')->label('Logo')
                            ->image()->imageEditor()->avatar()
                            ->disk('public')->directory('logos')->visibility('public')
                            ->imageResizeMode('cover')->imageResizeTargetWidth('400')->imageResizeTargetHeight('400')
                            ->maxSize(2048)
                            ->helperText('Kvadrat rasm. 2 MB gacha.'),
                        TextInput::make('notify_chat_id')->label('Bildirishnoma chat ID')
                            ->helperText('Yangi buyurtmalar shu Telegram chatga keladi. Botга /id yozib, chiqqan raqamni bu yerga kiriting.')
                            ->maxLength(32),
                        Toggle::make('is_open')
                            ->label('Hozir ochiq')
                            ->helperText('O`chirilsa restoran mijozlarga umuman ko`rinmaydi.')
                            ->inline(false),
                    ])
                    ->columns(2),

                Section::make('Yetkazish')
                    ->schema([
                        TextInput::make('min_order_amount')
                            ->label("Minimal buyurtma (so'm)")
                            ->numeric()->minValue(0)->step(1000)->suffix("so'm")
                            ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                            ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)),
                        TextInput::make('delivery_fee')
                            ->label("Yetkazish narxi (so'm)")
                            ->numeric()->minValue(0)->step(1000)->suffix("so'm")
                            ->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 100))
                            ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)),
                        TextInput::make('delivery_radius_km')
                            ->label('Yetkazish radiusi (km)')
                            ->numeric()->minValue(0)->step(0.5)->suffix('km'),
                        TextInput::make('avg_prep_time_min')
                            ->label("O'rtacha tayyorlash (daqiqa)")
                            ->numeric()->minValue(0),
                    ])
                    ->columns(2),

                Section::make('Joylashuv')
                    ->description('Viloyat va tumanni tanlang, keyin xaritada aniq nuqtani bosing. Masofa va yetkazish radiusi shu nuqtadan hisoblanadi.')
                    ->schema(RestaurantLocationForm::schema())
                    ->columns(2),

                Section::make('Ish vaqti')
                    ->schema([
                        WorkHoursForm::make('work_hours'),
                    ]),
            ])
            ->statePath('data')
            ->model($this->restaurant());
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['work_hours'] = WorkHoursForm::toSchedule($data['work_hours'] ?? []);
        unset($data['region_id'], $data['location']); // faqat ko'rish uchun; district_id/lat/lng saqlanadi

        $this->restaurant()->update($data);

        Notification::make()->title('Saqlandi')->success()->send();
    }

    protected function restaurant(): Restaurant
    {
        return auth('staff')->user()->restaurant;
    }
}
