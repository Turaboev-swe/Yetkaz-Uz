<?php

namespace Tests\Feature;

use App\Models\Region;
use Database\Seeders\AndijanGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tuman markaz koordinatalari o'z viloyati chegarasi ichida ekanini tekshiradi.
 * Chegaradan chiqqan qiymat testni tushiradi — yangi viloyat qo'shilganda ham.
 * Chegaralar: config/geo.php -> region_bounds.<CODE>.
 */
class GeoBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_region_has_a_bounding_box_configured(): void
    {
        $this->seed(AndijanGeoSeeder::class);

        foreach (Region::all() as $region) {
            $this->assertIsArray(
                config("geo.region_bounds.{$region->code}"),
                "«{$region->name}» ({$region->code}) uchun config/geo.php da region_bounds yo'q.",
            );
        }
    }

    public function test_all_seeded_district_centres_are_inside_their_region(): void
    {
        $this->seed(AndijanGeoSeeder::class);

        $regions = Region::with('districts')->get();
        $this->assertNotEmpty($regions);

        foreach ($regions as $region) {
            $bounds = config("geo.region_bounds.{$region->code}");
            $this->assertIsArray($bounds);

            foreach ($region->districts as $district) {
                $this->assertGreaterThanOrEqual($bounds['lat'][0], $district->center_lat,
                    "{$district->name}: center_lat {$district->center_lat} < {$bounds['lat'][0]}");
                $this->assertLessThanOrEqual($bounds['lat'][1], $district->center_lat,
                    "{$district->name}: center_lat {$district->center_lat} > {$bounds['lat'][1]}");
                $this->assertGreaterThanOrEqual($bounds['lng'][0], $district->center_lng,
                    "{$district->name}: center_lng {$district->center_lng} < {$bounds['lng'][0]}");
                $this->assertLessThanOrEqual($bounds['lng'][1], $district->center_lng,
                    "{$district->name}: center_lng {$district->center_lng} > {$bounds['lng'][1]}");
            }
        }
    }

    public function test_andijan_seeder_array_is_within_bounds(): void
    {
        $bounds = config('geo.region_bounds.AN');
        $rows = (new AndijanGeoSeeder)->districts;

        $this->assertCount(16, $rows);

        foreach ($rows as $d) {
            $this->assertGreaterThanOrEqual($bounds['lat'][0], $d['lat'], "{$d['name']} lat past");
            $this->assertLessThanOrEqual($bounds['lat'][1], $d['lat'], "{$d['name']} lat baland");
            $this->assertGreaterThanOrEqual($bounds['lng'][0], $d['lng'], "{$d['name']} lng past");
            $this->assertLessThanOrEqual($bounds['lng'][1], $d['lng'], "{$d['name']} lng baland");
            $this->assertNotSame('', trim($d['center']), "{$d['name']} uchun center nomi yo'q");
        }
    }
}
