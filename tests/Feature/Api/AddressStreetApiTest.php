<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\District;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * Manzil yaratish/tahrirlash: ko'cha (street) va mo'ljal (note). Geokoder ko'cha topmasa — ko'cha YOKI mo'ljal MAJBURIY
 * (API darajasida ham). address_text = ko'cha + qishloq/mahalla + tuman, server tomonda yig'iladi.
 */
class AddressStreetApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Cache::flush();

        $region = Region::factory()->create(['code' => 'AN']);
        $this->district = District::factory()->for($region)->create(['name' => "Qo'rg'ontepa tumani", 'center_lat' => 40.7278, 'center_lng' => 72.7629]);
        $this->user = User::factory()->create(['telegram_id' => 950001, 'language' => 'uz']);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));
    }

    private function osm(array $address): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => $address + ['county' => "Qo'rg'ontepa tumani"]])]);
    }

    private function createAddress(array $extra = []): TestResponse
    {
        return $this->postJson('/api/addresses', array_replace(['label' => 'Uy', 'lat' => 40.7278, 'lng' => 72.7629], $extra), $this->headers());
    }

    // --- Geokoder ko'cha topdi ------------------------------------------------------------------------

    public function test_the_geocoded_street_is_used_when_the_customer_sends_none(): void
    {
        $this->osm(['road' => "Bobur ko'chasi", 'house_number' => '12']);

        $this->createAddress()->assertCreated()
            ->assertJsonPath('data.street', "Bobur ko'chasi 12")
            ->assertJsonPath('data.address_text', "Bobur ko'chasi 12, Qo'rg'ontepa tumani")
            ->assertJsonPath('data.resolved_address', "Bobur ko'chasi 12, Qo'rg'ontepa tumani")
            ->assertJsonPath('data.note', null);
    }

    public function test_the_landmark_is_optional_when_a_street_is_found(): void
    {
        $this->osm(['road' => "Bobur ko'chasi"]);

        $this->createAddress()->assertCreated();                                  // mo'ljalsiz
        $this->createAddress(['note' => 'Hokimiyat orqasida'])->assertCreated()    // mo'ljal bilan
            ->assertJsonPath('data.note', 'Hokimiyat orqasida');
    }

    public function test_the_customers_street_wins_over_the_geocoded_one(): void
    {
        $this->osm(['road' => "Bobur ko'chasi", 'village' => 'Yangi hayot']);

        $this->createAddress(['street' => "Navoiy ko'chasi 5"])->assertCreated()
            ->assertJsonPath('data.street', "Navoiy ko'chasi 5")
            ->assertJsonPath('data.address_text', "Navoiy ko'chasi 5, Yangi hayot, Qo'rg'ontepa tumani");
    }

    public function test_the_text_includes_the_village_found_by_the_geocoder(): void
    {
        $this->osm(['road' => "Bobur ko'chasi", 'hamlet' => 'Gulobod']);

        $this->createAddress()->assertCreated()->assertJsonPath('data.address_text', "Bobur ko'chasi, Gulobod, Qo'rg'ontepa tumani");
    }

    public function test_a_client_supplied_address_text_is_ignored(): void
    {
        $this->osm(['road' => "Bobur ko'chasi"]);

        $this->createAddress(['address_text' => 'Qo‘lda yozilgan matn'])->assertCreated()
            ->assertJsonPath('data.address_text', "Bobur ko'chasi, Qo'rg'ontepa tumani");
    }

    // --- Geokoder ko'cha TOPMADI: ko'cha yoki mo'ljal majburiy -------------------------------------------------

    public function test_without_a_street_or_landmark_it_is_refused_in_uzbek_and_nothing_is_saved(): void
    {
        $this->osm(['town' => "Qo'rg'ontepa"]);

        $this->createAddress()->assertStatus(422)
            ->assertJsonValidationErrors(['street'])
            ->assertJsonPath('errors.street.0', 'Xaritada ko‘cha nomi topilmadi. Ko‘cha nomini yoki mo‘ljalni kiriting.');

        $this->assertSame(0, Address::query()->count());
    }

    public function test_the_refusal_follows_the_customers_language(): void
    {
        $this->user->update(['language' => 'ru']);
        $this->osm([]);

        $this->createAddress()->assertStatus(422)
            ->assertJsonPath('errors.street.0', 'На карте не найдено название улицы. Укажите улицу или ориентир.');
    }

    public function test_a_route_number_from_the_geocoder_does_not_count_as_a_street(): void
    {
        $this->osm(['road' => 'D137']);

        $this->createAddress()->assertStatus(422)->assertJsonValidationErrors(['street']);
    }

    public function test_a_landmark_alone_is_enough(): void
    {
        $this->osm(['village' => 'Yangi hayot']);

        $this->createAddress(['note' => 'Maktab yonida'])->assertCreated()
            ->assertJsonPath('data.street', null)
            ->assertJsonPath('data.note', 'Maktab yonida')
            ->assertJsonPath('data.address_text', "Yangi hayot, Qo'rg'ontepa tumani");
    }

    public function test_a_street_alone_is_enough(): void
    {
        $this->osm(['town' => "Qo'rg'ontepa"]);

        $this->createAddress(['street' => "Fidoyi ko'chasi"])->assertCreated()
            ->assertJsonPath('data.street', "Fidoyi ko'chasi")
            ->assertJsonPath('data.address_text', "Fidoyi ko'chasi, Qo'rg'ontepa tumani");
    }

    public function test_blank_street_and_landmark_count_as_missing(): void
    {
        $this->osm([]);

        $this->createAddress(['street' => '   ', 'note' => "\t "])->assertStatus(422)->assertJsonValidationErrors(['street']);
    }

    public function test_when_nominatim_is_down_a_street_or_landmark_is_still_required(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

        $this->createAddress()->assertStatus(422)->assertJsonValidationErrors(['street']);
        $this->createAddress(['street' => 'Navoiy 3'])->assertCreated();
    }

    public function test_street_length_is_limited(): void
    {
        $this->osm([]);

        $this->createAddress(['street' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors(['street']);
    }

    public function test_extra_spaces_in_the_street_are_collapsed(): void
    {
        $this->osm([]);

        $this->createAddress(['street' => "  Navoiy    ko'chasi   7 "])->assertCreated()->assertJsonPath('data.street', "Navoiy ko'chasi 7");
    }

    // --- Tahrirlash (PATCH) ----------------------------------------------------------------------------------

    private function legacyAddress(array $extra = []): Address
    {
        // Eski manzil: ko'cha ustuni yo'q, matn tayyor.
        return Address::factory()->for($this->user)->create(array_replace([
            'label' => 'Uy', 'lat' => 40.7278, 'lng' => 72.7629, 'district_id' => $this->district->id,
            'address_text' => "Eski ko'cha, Qo'rg'ontepa tumani", 'resolved_address' => "Eski ko'cha, Qo'rg'ontepa tumani", 'street' => null, 'note' => null,
        ], $extra));
    }

    public function test_patching_other_fields_leaves_a_legacy_address_untouched(): void
    {
        $this->osm([]);
        $address = $this->legacyAddress();

        $this->patchJson("/api/addresses/{$address->id}", ['label' => 'Ish', 'entrance' => '3'], $this->headers())->assertOk();

        $fresh = $address->fresh();
        $this->assertNull($fresh->street);
        $this->assertSame("Eski ko'cha, Qo'rg'ontepa tumani", $fresh->address_text);
        $this->assertSame("Eski ko'cha, Qo'rg'ontepa tumani", $fresh->resolved_address);
    }

    public function test_patching_the_street_rebuilds_the_text(): void
    {
        $this->osm(['village' => 'Yangi hayot']);
        $address = $this->legacyAddress();

        $this->patchJson("/api/addresses/{$address->id}", ['street' => "Navoiy ko'chasi 9"], $this->headers())->assertOk()
            ->assertJsonPath('data.street', "Navoiy ko'chasi 9")
            ->assertJsonPath('data.address_text', "Navoiy ko'chasi 9, Yangi hayot, Qo'rg'ontepa tumani")
            ->assertJsonPath('data.resolved_address', "Navoiy ko'chasi 9, Yangi hayot, Qo'rg'ontepa tumani");
    }

    public function test_clearing_the_street_without_a_landmark_is_refused_when_the_geocoder_has_none(): void
    {
        $this->osm([]);
        $address = $this->legacyAddress(['street' => 'Eski ko‘cha']);

        $this->patchJson("/api/addresses/{$address->id}", ['street' => null], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors(['street']);

        $this->assertSame('Eski ko‘cha', $address->fresh()->street, 'o‘zgarmagan');
    }

    public function test_clearing_the_street_is_fine_when_a_landmark_exists(): void
    {
        $this->osm([]);
        $address = $this->legacyAddress(['street' => 'Eski ko‘cha', 'note' => 'Bozor yonida']);

        $this->patchJson("/api/addresses/{$address->id}", ['street' => null], $this->headers())->assertOk()
            ->assertJsonPath('data.street', null)
            ->assertJsonPath('data.address_text', "Qo'rg'ontepa tumani");
    }

    public function test_clearing_the_street_falls_back_to_the_geocoded_one(): void
    {
        $this->osm(['road' => "Bobur ko'chasi"]);
        $address = $this->legacyAddress(['street' => "Navoiy ko'chasi"]);

        $this->patchJson("/api/addresses/{$address->id}", ['street' => ''], $this->headers())->assertOk()
            ->assertJsonPath('data.street', "Bobur ko'chasi");
    }

    public function test_moving_the_point_rebuilds_the_text_and_keeps_the_customers_street(): void
    {
        $this->osm(['village' => 'Boshqa qishloq']);
        $address = $this->legacyAddress(['street' => "Navoiy ko'chasi"]);

        $this->patchJson("/api/addresses/{$address->id}", ['lat' => 40.73, 'lng' => 72.77], $this->headers())->assertOk()
            ->assertJsonPath('data.street', "Navoiy ko'chasi")
            ->assertJsonPath('data.address_text', "Navoiy ko'chasi, Boshqa qishloq, Qo'rg'ontepa tumani");
    }

    // --- Ro'yxat va reverse ----------------------------------------------------------------------------------

    public function test_the_list_exposes_the_street_and_legacy_addresses_have_none(): void
    {
        $this->legacyAddress(['label' => 'Eski']);
        Address::factory()->for($this->user)->create(['label' => 'Yangi', 'street' => "Bobur ko'chasi", 'address_text' => "Bobur ko'chasi, Qo'rg'ontepa tumani"]);

        $rows = collect($this->getJson('/api/addresses', $this->headers())->assertOk()->json('data'))->keyBy('label');

        $this->assertNull($rows['Eski']['street']);
        $this->assertSame("Bobur ko'chasi", $rows['Yangi']['street']);
    }

    public function test_reverse_returns_the_street_and_locality_for_the_form(): void
    {
        $this->osm(['road' => "Bobur ko'chasi", 'hamlet' => 'Gulobod']);

        $this->getJson('/api/geo/reverse?lat=40.7278&lng=72.7629', $this->headers())->assertOk()
            ->assertJsonPath('data.street', "Bobur ko'chasi")
            ->assertJsonPath('data.locality', 'Gulobod')
            ->assertJsonPath('data.district_name', "Qo'rg'ontepa tumani")
            ->assertJsonPath('data.address_text', "Bobur ko'chasi, Gulobod, Qo'rg'ontepa tumani");
    }

    public function test_reverse_reports_no_street_when_there_is_none(): void
    {
        $this->osm(['road' => 'D137']);

        $this->getJson('/api/geo/reverse?lat=40.7278&lng=72.7629', $this->headers())->assertOk()
            ->assertJsonPath('data.street', null);
    }
}
