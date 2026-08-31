<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchGeoCoordinatesCommandTest extends TestCase
{
    private string $seederPath;

    private string $seederBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederPath = database_path('seeders/AndijanGeoSeeder.php');
        $this->seederBackup = file_get_contents($this->seederPath);
    }

    protected function tearDown(): void
    {
        // Test --write bilan seeder faylini o'zgartirishi mumkin — asl holiga qaytaramiz.
        file_put_contents($this->seederPath, $this->seederBackup);
        parent::tearDown();
    }

    private function nominatim(float $lat, float $lng, string $county, string $type = 'town'): array
    {
        return [[
            'lat' => (string) $lat,
            'lon' => (string) $lng,
            'category' => 'place',
            'type' => $type,
            'importance' => 0.3,
            'display_name' => "$county",
            'address' => ['county' => $county],
        ]];
    }

    public function test_command_reports_a_large_diff_without_writing(): void
    {
        // Har so'rovga bir xil (uzoq) nuqta qaytaramiz.
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response(
                $this->nominatim(40.9000, 72.9000, 'Asaka tumani'),
            ),
        ]);

        $before = file_get_contents(database_path('seeders/AndijanGeoSeeder.php'));

        $this->artisan('geo:fetch-coordinates')
            ->expectsOutputToContain('km dan ortiq farq')
            ->assertSuccessful();

        // --write ishlatilmagani uchun seeder o'zgarmaydi
        $this->assertSame($before, file_get_contents(database_path('seeders/AndijanGeoSeeder.php')));
    }

    public function test_zero_results_are_flagged_for_manual_review(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);

        $this->artisan('geo:fetch-coordinates')
            ->expectsOutputToContain("QO'LDA HAL QILINADIGANLAR")
            ->assertSuccessful();
    }

    public function test_out_of_bounds_result_is_flagged_not_written(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response(
                $this->nominatim(35.0, 60.0, 'Asaka tumani'), // O'zbekistondan tashqarida
            ),
        ]);

        $this->artisan('geo:fetch-coordinates', ['--write' => true])
            ->expectsOutputToContain('chegaradan tashqarida')
            ->assertSuccessful();

        // Chegaradan tashqaridagi qiymat yozilmaydi
        $this->assertStringNotContainsString(
            "'lat' => 35",
            file_get_contents(database_path('seeders/AndijanGeoSeeder.php')),
        );
    }
}
