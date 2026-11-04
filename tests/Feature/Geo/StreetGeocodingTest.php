<?php

namespace Tests\Feature\Geo;

use App\Models\District;
use App\Models\Region;
use App\Services\Geo\AddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Geokoder: ko'cha / qishloq-mahalla / tuman. Yo'l raqami (ref) ko'cha emas; village/hamlet nomi olinadi;
 * xato qisqa keshlanadi (muvaffaqiyatli javob — uzoq).
 */
class StreetGeocodingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $region = Region::factory()->create(['code' => 'AN']);
        District::factory()->for($region)->create(['name' => "Qo'rg'ontepa tumani", 'center_lat' => 40.7278, 'center_lng' => 72.7629]);
        District::factory()->for($region)->create(['name' => 'Xonobod shahri', 'center_lat' => 40.8066, 'center_lng' => 72.9672]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function osm(array $address): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => $address])]);
    }

    private function describe(float $lat = 40.7278, float $lng = 72.7629): array
    {
        return app(AddressGeocoder::class)->describe($lat, $lng);
    }

    // --- Yo'l raqami ko'cha emas ---------------------------------------------------------------

    public function test_a_route_number_is_not_returned_as_the_street(): void
    {
        $this->osm(['road' => 'D137', 'county' => "Qo'rg'ontepa tumani"]);

        $geo = $this->describe();

        $this->assertNull($geo['street']);
        $this->assertSame("Qo'rg'ontepa tumani", $geo['address_text'], 'D137 matnga tushmaydi');
    }

    public function test_route_numbers_with_a_house_number_are_still_not_a_street(): void
    {
        $this->osm(['road' => 'D143', 'house_number' => '5', 'county' => "Qo'rg'ontepa tumani"]);

        $geo = $this->describe();

        $this->assertNull($geo['street']);
        $this->assertStringNotContainsString('D143', $geo['address_text']);
        $this->assertStringNotContainsString('5', $geo['address_text']);
    }

    public function test_a_real_street_name_is_the_street_with_the_house_number(): void
    {
        $this->osm(['road' => "Bobur ko'chasi", 'house_number' => '12', 'county' => "Qo'rg'ontepa tumani"]);

        $geo = $this->describe();

        $this->assertSame("Bobur ko'chasi 12", $geo['street']);
        $this->assertSame("Bobur ko'chasi 12, Qo'rg'ontepa tumani", $geo['address_text']);
    }

    public function test_a_house_number_without_a_road_is_not_a_street(): void
    {
        $this->osm(['house_number' => '7', 'county' => "Qo'rg'ontepa tumani"]);

        $this->assertNull($this->describe()['street']);
    }

    // --- Qishloq / mahalla -------------------------------------------------------------------------

    public function test_a_village_name_is_used_when_there_is_no_street(): void
    {
        $this->osm(['village' => 'Yangi hayot', 'county' => 'Xonobod shahri', 'town' => 'Xonobod']);

        $geo = $this->describe(40.8066, 72.9672);

        $this->assertNull($geo['street']);
        $this->assertSame('Yangi hayot', $geo['locality']);
        $this->assertSame('Yangi hayot, Xonobod shahri', $geo['address_text']);
    }

    public function test_a_hamlet_name_is_used_too(): void
    {
        $this->osm(['hamlet' => 'Gulobod', 'county' => 'Xonobod shahri']);

        $geo = $this->describe(40.8066, 72.9672);

        $this->assertSame('Gulobod', $geo['locality']);
        $this->assertSame('Gulobod, Xonobod shahri', $geo['address_text']);
    }

    public function test_locality_priority_goes_from_the_smallest_unit(): void
    {
        $this->osm(['village' => 'Katta qishloq', 'hamlet' => 'Kichik', 'suburb' => 'Markaz', 'neighbourhood' => 'Mahalla 1', 'county' => 'Xonobod shahri']);

        $this->assertSame('Mahalla 1', $this->describe(40.8066, 72.9672)['locality']);
    }

    public function test_a_hamlet_wins_over_a_village(): void
    {
        $this->osm(['village' => 'Katta qishloq', 'hamlet' => 'Kichik', 'county' => 'Xonobod shahri']);

        $this->assertSame('Kichik', $this->describe(40.8066, 72.9672)['locality']);
    }

    public function test_street_and_locality_are_both_in_the_text(): void
    {
        $this->osm(['road' => "Bobur ko'chasi", 'village' => 'Yangi hayot', 'county' => "Qo'rg'ontepa tumani"]);

        $this->assertSame("Bobur ko'chasi, Yangi hayot, Qo'rg'ontepa tumani", $this->describe()['address_text']);
    }

    public function test_a_locality_equal_to_the_district_is_not_repeated(): void
    {
        $this->osm(['village' => 'Xonobod', 'county' => 'Xonobod shahri']);

        $geo = $this->describe(40.8066, 72.9672);

        $this->assertNull($geo['locality']);
        $this->assertSame('Xonobod shahri', $geo['address_text']);
    }

    public function test_only_town_and_county_means_district_only(): void
    {
        $this->osm(['town' => "Qo'rg'ontepa", 'county' => "Qo'rg'ontepa tumani"]);

        $geo = $this->describe();

        $this->assertNull($geo['street']);
        $this->assertNull($geo['locality']);
        $this->assertSame("Qo'rg'ontepa tumani", $geo['address_text']);
    }

    public function test_resolve_uses_the_same_composition(): void
    {
        $this->osm(['road' => 'D137', 'hamlet' => 'Gulobod', 'county' => 'Xonobod shahri']);

        $this->assertSame('Gulobod, Xonobod shahri', app(AddressGeocoder::class)->resolve(40.8066, 72.9672));
    }

    // --- Kesh: xato qisqa, muvaffaqiyat uzoq -----------------------------------------------------------

    private function nominatimCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'nominatim.openstreetmap.org'))->count();
    }

    public function test_a_server_error_is_cached_only_briefly(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');
        Http::fakeSequence('nominatim.openstreetmap.org/*')
            ->push('', 500)
            ->push(['address' => ['road' => "Bobur ko'chasi", 'county' => "Qo'rg'ontepa tumani"]]);

        $this->assertNull($this->describe()['street'], 'xato -> ko‘chasiz');
        $this->assertNull($this->describe()['street'], 'qisqa kesh ichida qayta so‘ralmaydi');
        $this->assertSame(1, $this->nominatimCalls());

        Carbon::setTestNow('2026-11-04 10:01:30');   // 60 s dan keyin
        $this->assertSame("Bobur ko'chasi", $this->describe()['street'], 'qayta urinib, topdi');
        $this->assertSame(2, $this->nominatimCalls());
    }

    public function test_a_connection_failure_is_cached_only_briefly_too(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $attempts = 0;
        Http::fake(['nominatim.openstreetmap.org/*' => function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['address' => ['road' => "Fidoyi ko'chasi", 'county' => "Qo'rg'ontepa tumani"]]);
        }]);

        $this->assertNull($this->describe()['street']);
        $this->assertNull($this->describe()['street']);
        $this->assertSame(1, $attempts);

        Carbon::setTestNow('2026-11-04 10:02:00');
        $this->assertSame("Fidoyi ko'chasi", $this->describe()['street']);
        $this->assertSame(2, $attempts);
    }

    public function test_a_successful_answer_is_cached_for_a_day(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->osm(['road' => "Bobur ko'chasi", 'county' => "Qo'rg'ontepa tumani"]);

        $this->describe();
        Carbon::setTestNow('2026-11-04 20:00:00');   // 10 soat keyin
        $this->describe();
        $this->assertSame(1, $this->nominatimCalls(), 'muvaffaqiyatli javob uzoq keshlanadi');

        Carbon::setTestNow('2026-11-05 10:30:00');   // 1 kundan keyin
        $this->describe();
        $this->assertSame(2, $this->nominatimCalls());
    }

    public function test_the_error_cache_ttl_is_configurable(): void
    {
        config(['geo.nominatim.error_cache_seconds' => 5]);
        Carbon::setTestNow('2026-11-04 10:00:00');
        Http::fakeSequence('nominatim.openstreetmap.org/*')->push('', 503)->push(['address' => ['road' => 'Navoiy', 'county' => "Qo'rg'ontepa tumani"]]);

        $this->describe();
        Carbon::setTestNow('2026-11-04 10:00:06');

        $this->assertSame('Navoiy', $this->describe()['street']);
    }

    public function test_a_legitimately_empty_answer_is_cached_long(): void
    {
        // 200 OK, address yo'q ("Unable to geocode") — bu xato emas, ma'lumot yo'q.
        Carbon::setTestNow('2026-11-04 10:00:00');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['error' => 'Unable to geocode'])]);

        $this->describe();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->describe();

        $this->assertSame(1, $this->nominatimCalls());
    }
}
