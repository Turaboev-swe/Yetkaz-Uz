<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Andijon viloyati: 2 shahar + 14 tuman.
 *
 * center_lat/lng — FAQAT xaritani markazlashtirish uchun. Masofa / yetkazish
 * radiusi / ETA bunga bog'liq EMAS (restoran va manzil lat/lng dan).
 *
 * Koordinatalar OpenStreetMap (Nominatim) dan olingan:
 *   php artisan geo:fetch-coordinates          — hozirgi qiymatlar bilan solishtiradi
 *   php artisan geo:fetch-coordinates --write   — shu faylni yangilaydi
 * `center` — Nominatim so'rovi uchun tuman markazidagi shahar/shaharcha nomi.
 *
 * DistrictBoundsTest barcha koordinatalar Andijon viloyati chegarasida ekanini
 * tekshiradi (config/geo.php: region_bounds.AN).
 */
class AndijanGeoSeeder extends Seeder
{
    /** @var array<int, array{name: string, center: string, lat: float, lng: float}> */
    public array $districts = [
        // Shaharlar
        ['name' => 'Andijon shahri',      'center' => 'Andijon',      'lat' => 40.7833471, 'lng' => 72.3506746],
        ['name' => 'Xonobod shahri',      'center' => 'Xonobod',      'lat' => 40.8065759, 'lng' => 72.9672304],
        // Tumanlar (markazidagi shahar/shaharcha nomi bo'yicha)
        ['name' => 'Andijon tumani',      'center' => 'Kuyganyor',    'lat' => 40.856771, 'lng' => 72.313531],
        ['name' => 'Asaka tumani',        'center' => 'Asaka',        'lat' => 40.6462095, 'lng' => 72.2489005],
        ['name' => 'Baliqchi tumani',     'center' => 'Baliqchi',     'lat' => 40.9002001, 'lng' => 71.8413903],
        ['name' => "Bo'z tumani",         'center' => "Bo'z",         'lat' => 40.6890327, 'lng' => 71.9254198],
        ['name' => 'Buloqboshi tumani',   'center' => 'Buloqboshi',   'lat' => 40.6291119, 'lng' => 72.50152],
        ['name' => 'Izboskan tumani',     'center' => 'Poytug',       'lat' => 40.8986353, 'lng' => 72.2475029],
        ['name' => 'Jalaquduq tumani',    'center' => 'Jalaquduq',    'lat' => 40.7186364, 'lng' => 72.6423006],
        ['name' => "Xo'jaobod tumani",    'center' => "Xo'jaobod",    'lat' => 40.665998, 'lng' => 72.5681925],
        ['name' => "Qo'rg'ontepa tumani", 'center' => "Qo'rg'ontepa", 'lat' => 40.7278, 'lng' => 72.7629],
        ['name' => 'Marhamat tumani',     'center' => 'Marhamat',     'lat' => 40.5038893, 'lng' => 72.3326001],
        ['name' => "Oltinko'l tumani",    'center' => "Oltinko'l",    'lat' => 40.8011469, 'lng' => 72.1634159],
        ['name' => 'Paxtaobod tumani',    'center' => 'Paxtaobod',    'lat' => 40.9275291, 'lng' => 72.498114],
        ['name' => 'Shahrixon tumani',    'center' => 'Shahrixon',    'lat' => 40.7090399, 'lng' => 72.0577601],
        ['name' => "Ulug'nor tumani",     'center' => 'Oqoltin',      'lat' => 40.7458558, 'lng' => 71.7029989],
    ];

    public function run(): void
    {
        $region = Region::updateOrCreate(
            ['code' => 'AN'],
            ['name' => 'Andijon viloyati', 'is_active' => true],
        );

        foreach ($this->districts as $d) {
            District::updateOrCreate(
                ['region_id' => $region->id, 'name' => $d['name']],
                [
                    'center_lat' => $d['lat'],
                    'center_lng' => $d['lng'],
                    'is_active' => true,
                ],
            );
        }

        $this->command->info("Andijon viloyati: {$region->districts()->count()} ta tuman/shahar.");
    }
}
