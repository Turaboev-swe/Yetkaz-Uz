<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\District;
use App\Models\Region;
use App\Models\User;
use App\Services\User\AddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class AddressResolvedAddressTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();

        $region = Region::factory()->create(['name' => 'Andijon']);
        $this->district = District::factory()->for($region)->create([
            'name' => "Qo'rg'ontepa tumani",
            'center_lat' => 40.7278,
            'center_lng' => 72.7629,
        ]);

        $this->user = User::factory()->create(['telegram_id' => 333111]);
    }

    private function headers(): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));
    }

    private function nominatimReturns(array $address): void
    {
        Http::fake(['*nominatim*' => Http::response(['address' => $address], 200)]);
    }

    // --- Yaratishда geokodlash --------------------------------------

    public function test_new_address_stores_the_resolved_address_from_nominatim(): void
    {
        $this->nominatimReturns([
            'road' => "Bobur ko'chasi",
            'house_number' => '12',
            'county' => "Qo'rg'ontepa tumani",
        ]);

        $this->postJson('/api/addresses', [
            'label' => 'Uy',
            'lat' => 40.7278,
            'lng' => 72.7629,
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.resolved_address', "Bobur ko'chasi 12, Qo'rg'ontepa tumani");

        $this->assertSame(
            "Bobur ko'chasi 12, Qo'rg'ontepa tumani",
            Address::latest('id')->value('resolved_address'),
        );
    }

    public function test_resolved_address_is_computed_only_once(): void
    {
        $this->nominatimReturns(['road' => "Navoiy ko'chasi", 'county' => "Qo'rg'ontepa tumani"]);

        $address = app(AddressService::class)->create($this->user, [
            'label' => 'Uy', 'lat' => 40.7278, 'lng' => 72.7629, 'address_text' => 'x',
        ]);
        $first = $address->resolved_address;

        // Nominatim javobi o'zgarsa ham — qayta so'ralmaydi (kesh + saqlangan).
        Http::fake(['*nominatim*' => Http::response(['address' => ['road' => 'BOSHQA']], 200)]);

        $this->assertSame($first, $address->fresh()->resolved_address);
    }

    // --- Nominatim xato bo'lganda zaxira ---------------------------

    public function test_falls_back_to_label_when_nominatim_fails(): void
    {
        Http::fake(['*nominatim*' => Http::response('', 500)]);

        $this->postJson('/api/addresses', [
            'label' => 'Ish',
            'lat' => 40.7278,
            'lng' => 72.7629,
            'address_text' => 'Amir Temur 5',
            'district_id' => $this->district->id,
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.resolved_address', 'Ish');   // koordinata/matn emas — faqat label

        $this->assertNull(Address::latest('id')->value('resolved_address'));
    }

    public function test_raw_address_text_never_leaks_into_the_display(): void
    {
        Http::fake(['*nominatim*' => Http::response('', 500)]);

        $address = app(AddressService::class)->create($this->user, [
            'label' => 'Uy',
            'lat' => 40.5,
            'lng' => 72.5,
            'address_text' => '40.500000, 72.500000',
        ]);

        $this->assertNull($address->resolved_address);
        $this->assertSame('Uy', $address->fresh()->displayAddress());
    }

    public function test_resolved_address_is_never_raw_coordinates(): void
    {
        Http::fake(['*nominatim*' => Http::response('', 500)]);

        app(AddressService::class)->create($this->user, [
            'label' => 'Uy', 'lat' => 40.5, 'lng' => 72.5, 'address_text' => '40.5, 72.5',
        ]);

        $resolved = $this->getJson('/api/addresses', $this->headers())
            ->assertOk()
            ->json('data.0.resolved_address');

        $this->assertSame('Uy', $resolved);   // koordinata emas — label
        $this->assertDoesNotMatchRegularExpression('/^-?\d+\.\d+,\s*-?\d+\.\d+$/', $resolved);
    }

    // --- Backfill buyrug'i ---------------------------------------

    public function test_backfill_command_fills_empty_resolved_addresses(): void
    {
        $this->nominatimReturns(['road' => "Mustaqillik ko'chasi", 'county' => "Qo'rg'ontepa tumani"]);

        // resolved_address'siz eski manzillar (to'g'ridan-to'g'ri yaratamiz)
        $a1 = $this->user->addresses()->create(['label' => 'Uy', 'lat' => 40.72, 'lng' => 72.76, 'address_text' => 'x', 'is_default' => true]);
        $a2 = $this->user->addresses()->create(['label' => 'Ish', 'lat' => 40.73, 'lng' => 72.77, 'address_text' => 'y']);
        $this->assertNull($a1->resolved_address);

        $this->artisan('addresses:backfill-geocoding')->assertSuccessful();

        $this->assertSame("Mustaqillik ko'chasi, Qo'rg'ontepa tumani", $a1->fresh()->resolved_address);
        $this->assertSame("Mustaqillik ko'chasi, Qo'rg'ontepa tumani", $a2->fresh()->resolved_address);
    }

    public function test_backfill_skips_addresses_that_already_have_one(): void
    {
        $this->nominatimReturns(['road' => 'YANGI', 'county' => "Qo'rg'ontepa tumani"]);

        $a = $this->user->addresses()->create([
            'label' => 'Uy', 'lat' => 40.72, 'lng' => 72.76, 'address_text' => 'x',
            'resolved_address' => 'Eski qiymat', 'is_default' => true,
        ]);

        $this->artisan('addresses:backfill-geocoding')->assertSuccessful();

        $this->assertSame('Eski qiymat', $a->fresh()->resolved_address);
    }
}
