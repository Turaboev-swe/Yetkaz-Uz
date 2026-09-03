<?php

namespace Tests\Unit;

use App\Support\OrderAddress;
use PHPUnit\Framework\TestCase;

class OrderAddressTest extends TestCase
{
    public function test_readable_text_is_shown_with_district(): void
    {
        $line = OrderAddress::line([
            'address_text' => 'Amir Temur ko‘chasi, 12-uy',
            'district' => 'Chilonzor tumani',
        ]);

        $this->assertSame('Amir Temur ko‘chasi, 12-uy, Chilonzor tumani', $line);
    }

    public function test_district_not_duplicated_when_already_in_text(): void
    {
        $line = OrderAddress::line([
            'address_text' => 'Bobur ko‘chasi 5, Chilonzor tumani',
            'district' => 'Chilonzor tumani',
        ]);

        $this->assertSame('Bobur ko‘chasi 5, Chilonzor tumani', $line);
    }

    public function test_raw_coordinates_fall_back_to_district(): void
    {
        $line = OrderAddress::line([
            'address_text' => '40.716863, 72.768369',
            'district' => 'Qo‘rg‘ontepa tumani',
        ]);

        $this->assertSame('Qo‘rg‘ontepa tumani', $line);
    }

    public function test_raw_coordinates_without_district_fall_back_to_label(): void
    {
        $line = OrderAddress::line([
            'address_text' => '40.716863,72.768369',
            'label' => 'Uy',
        ]);

        $this->assertSame('Uy', $line);
    }

    public function test_null_when_nothing_usable(): void
    {
        $this->assertNull(OrderAddress::line(['address_text' => '41.0, 69.0']));
        $this->assertNull(OrderAddress::line(null));
    }

    public function test_extra_lists_entrance_floor_apartment(): void
    {
        $this->assertSame(
            'kirish 2 · qavat 3 · xonadon 12',
            OrderAddress::extra(['entrance' => '2', 'floor' => '3', 'apartment' => '12']),
        );
        $this->assertNull(OrderAddress::extra([]));
    }

    public function test_map_url_uses_coordinates(): void
    {
        $url = OrderAddress::mapUrl(['lat' => 40.716863, 'lng' => 72.768369]);

        $this->assertSame('https://maps.google.com/?q=40.716863,72.768369', $url);
        $this->assertNull(OrderAddress::mapUrl(['lat' => 40.7]));
    }
}
