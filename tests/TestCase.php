<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Testlar tashqi HTTP chaqirmasin. Nominatim'ga tegadigan test o'zi
        // Http::fake() qiladi (AddressGeocoder fake bo'sh javobda eng yaqin
        // tuman markaziga qaytadi).
        Http::preventStrayRequests();
    }
}
