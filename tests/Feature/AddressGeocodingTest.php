<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Region;
use App\Models\User;
use App\Services\Geo\AddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * Nuqta -> tuman (DOIM o'zbekcha, districts jadvalidan) + manzil matni.
 */
class AddressGeocodingTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private function districts(): array
    {
        $region = Region::factory()->create(['code' => 'AN']);

        return [
            'shahar' => District::factory()->for($region)->create([
                'name' => 'Andijon shahri', 'center_lat' => 40.7833, 'center_lng' => 72.3506,
            ]),
            'qorgontepa' => District::factory()->for($region)->create([
                'name' => "Qo'rg'ontepa tumani", 'center_lat' => 40.7339, 'center_lng' => 72.7628,
            ]),
        ];
    }

    public function test_reverse_returns_uzbek_district_name_from_our_table(): void
    {
        $d = $this->districts();

        // Nominatim rus tilida county qaytarsa ham — bizniki o'zbekcha bo'ladi.
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'address' => ['road' => 'Bobur shoh koʻchasi', 'house_number' => '12', 'county' => 'Курган-Тепинский район'],
        ])]);

        $geo = app(AddressGeocoder::class)->describe(40.7339, 72.7628);

        $this->assertSame($d['qorgontepa']->id, $geo['district_id']);
        $this->assertSame("Qo'rg'ontepa tumani", $geo['district_name']);
        $this->assertStringContainsString('Bobur shoh', $geo['address_text']);
        $this->assertStringContainsString("Qo'rg'ontepa tumani", $geo['address_text']);
    }

    public function test_falls_back_to_nearest_district_when_nominatim_fails(): void
    {
        $d = $this->districts();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

        $geo = app(AddressGeocoder::class)->describe(40.7830, 72.3500); // Andijon shahri yonida

        $this->assertSame($d['shahar']->id, $geo['district_id']);
        $this->assertSame('Andijon shahri', $geo['district_name']);
    }

    public function test_reverse_endpoint_requires_auth_and_coords(): void
    {
        $this->bindInitDataValidator();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $this->districts();

        $user = User::factory()->create(['telegram_id' => 424242]);
        $headers = $this->initDataHeaders($this->signedInitData(['id' => 424242]));

        $this->getJson('/api/geo/reverse?lat=40.7833&lng=72.3506', $headers)
            ->assertOk()
            ->assertJsonPath('data.district_name', 'Andijon shahri');

        $this->getJson('/api/geo/reverse?lat=40.78', $headers)->assertStatus(422);
        $this->getJson('/api/geo/reverse?lat=40.78&lng=72.35')->assertUnauthorized();
    }

    public function test_address_create_without_district_resolves_it(): void
    {
        $this->bindInitDataValidator();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $d = $this->districts();

        $user = User::factory()->create(['telegram_id' => 333111]);
        $headers = $this->initDataHeaders($this->signedInitData(['id' => 333111]));

        $this->postJson('/api/addresses', [
            'label' => 'Uy',
            'lat' => 40.7335,
            'lng' => 72.7620,
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.district', "Qo'rg'ontepa tumani");

        $this->assertSame($d['qorgontepa']->id, $user->addresses()->sole()->district_id);
    }
}
