<?php

namespace App\Filament\Support;

use App\Models\District;
use App\Models\Region;
use Dotswan\MapPicker\Fields\Map;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * Restoran joylashuvi: viloyat -> tuman (bog'liq) -> xarita.
 *
 * lat/lng qo'lda kiritilmaydi — xaritada nuqta bosiladi. Xarita markazi
 * tanlangan tuman markazidan olinadi. Raqamlar formada ko'rinmaydi
 * (Hidden), lekin bazaga yoziladi. district_id ham saqlanadi.
 */
final class RestaurantLocationForm
{
    private const DEFAULT_LAT = 40.7821; // Andijon shahri

    private const DEFAULT_LNG = 72.3442;

    /** @return array<int, Component> */
    public static function schema(): array
    {
        return [
            Select::make('region_id')
                ->label('Viloyat')
                ->options(fn () => Region::query()->active()->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->live()
                ->dehydrated(false) // restaurants jadvalida yo'q
                ->afterStateHydrated(function (Select $component, $state, ?object $record) {
                    if ($state === null && $record?->district) {
                        $component->state($record->district->region_id);
                    }
                })
                ->afterStateUpdated(fn (Set $set) => $set('district_id', null)),

            Select::make('district_id')
                ->label('Tuman / shahar')
                ->options(fn (Get $get) => $get('region_id')
                    ? District::query()->where('region_id', $get('region_id'))->active()->orderBy('name')->pluck('name', 'id')
                    : [])
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) {
                    $district = $state ? District::find($state) : null;
                    if ($district) {
                        // Xaritani tuman markaziga olib boradi (boshlang'ich nuqta).
                        $set('location', ['lat' => (float) $district->center_lat, 'lng' => (float) $district->center_lng]);
                        $set('lat', (float) $district->center_lat);
                        $set('lng', (float) $district->center_lng);
                    }
                }),

            Map::make('location')
                ->label('Xaritada aniq joyni bosing')
                ->columnSpanFull()
                ->defaultLocation(self::DEFAULT_LAT, self::DEFAULT_LNG)
                ->zoom(13)
                ->clickable(true)
                ->draggable(true)
                ->showMarker(true)
                ->dehydrated(false) // 'location' ustuni yo'q — lat/lng ga ko'chiriladi
                ->afterStateHydrated(function (Map $component, ?object $record) {
                    if ($record && $record->lat !== null) {
                        $component->state(['lat' => (float) $record->lat, 'lng' => (float) $record->lng]);
                    }
                })
                ->afterStateUpdated(function (?array $state, Set $set) {
                    if (isset($state['lat'], $state['lng'])) {
                        $set('lat', round((float) $state['lat'], 7));
                        $set('lng', round((float) $state['lng'], 7));
                    }
                }),

            Hidden::make('lat')->required(),
            Hidden::make('lng')->required(),
        ];
    }
}
