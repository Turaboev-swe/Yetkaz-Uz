<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Andijon viloyati: 2 shahar + 14 tuman.
 *
 * center_lat/lng — faqat xaritani markazlashtirish va ko'rsatish uchun.
 * Masofa / yetkazish radiusi / ETA bunga bog'liq EMAS (restoran lat/lng dan).
 *
 * Koordinatalar taxminiy (tuman markazidagi shahar/qishloq bo'yicha, ~±2 km).
 * Rasmiy SOATO ma'lumoti bilan almashtirilishi kerak — [conf] belgisi ishonch darajasi.
 */
class AndijanGeoSeeder extends Seeder
{
    /** @var array<int, array{name: string, lat: float, lng: float, conf: string}> */
    private array $districts = [
        // Shaharlar
        ['name' => 'Andijon shahri',      'lat' => 40.7821, 'lng' => 72.3442, 'conf' => 'yuqori'],
        ['name' => 'Xonobod shahri',      'lat' => 40.8144, 'lng' => 72.9606, 'conf' => "o'rta"],
        // Tumanlar
        ['name' => 'Andijon tumani',      'lat' => 40.7539, 'lng' => 72.3336, 'conf' => "o'rta"],
        ['name' => 'Asaka tumani',        'lat' => 40.6392, 'lng' => 72.2378, 'conf' => 'yuqori'],
        ['name' => 'Baliqchi tumani',     'lat' => 40.8256, 'lng' => 71.9147, 'conf' => "o'rta"],
        ['name' => "Bo'z tumani",         'lat' => 40.6208, 'lng' => 72.1544, 'conf' => 'past'],
        ['name' => 'Buloqboshi tumani',   'lat' => 40.5678, 'lng' => 72.4272, 'conf' => "o'rta"],
        ['name' => 'Izboskan tumani',     'lat' => 40.8933, 'lng' => 72.1050, 'conf' => "o'rta"],
        ['name' => 'Jalaquduq tumani',    'lat' => 40.7011, 'lng' => 72.5528, 'conf' => "o'rta"],
        ['name' => "Xo'jaobod tumani",    'lat' => 40.6636, 'lng' => 72.5628, 'conf' => "o'rta"],
        ['name' => "Qo'rg'ontepa tumani", 'lat' => 40.7317, 'lng' => 72.7514, 'conf' => "o'rta"],
        ['name' => 'Marhamat tumani',     'lat' => 40.4936, 'lng' => 72.3175, 'conf' => 'yuqori'],
        ['name' => "Oltinko'l tumani",    'lat' => 40.8333, 'lng' => 72.2833, 'conf' => 'past'],
        ['name' => 'Paxtaobod tumani',    'lat' => 40.9089, 'lng' => 72.3306, 'conf' => "o'rta"],
        ['name' => 'Shahrixon tumani',    'lat' => 40.7167, 'lng' => 72.0603, 'conf' => "o'rta"],
        ['name' => "Ulug'nor tumani",     'lat' => 40.7392, 'lng' => 72.0500, 'conf' => 'past'],
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
