<?php

namespace Tests\Unit;

use App\Support\AddressText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddressTextTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function routeRefs(): array
    {
        return [
            'D137' => ['D137'],
            'D143' => ['D143'],
            'M-39' => ['M-39'],
            'A373' => ['A373'],
            'bo‘shliq bilan' => ['D 137'],
            'bir nechta' => ['D137; D143'],
            'slash' => ['A373/M39'],
            'kirill' => ['А373'],
            'kirill + tire' => ['Р-12'],
            'harf qo‘shimchasi' => ['M39A'],
        ];
    }

    #[DataProvider('routeRefs')]
    public function test_route_numbers_are_not_streets(string $value): void
    {
        $this->assertTrue(AddressText::isRouteRef($value), $value);
    }

    /** @return array<string, array{0: string}> */
    public static function realStreets(): array
    {
        return [
            'oddiy' => ["Bobur ko'chasi"],
            'uy bilan' => ["Bobur ko'chasi 12"],
            'bosh harf bilan' => ["A. Navoiy ko'chasi"],
            'raqamli ko‘cha' => ["12-ko'cha"],
            'uzun' => ["Mustaqillik shoh ko'chasi"],
            'qisqa nom' => ['Navoiy'],
            'Fidoyi' => ["Fidoyi ko'chasi"],
            'bo‘sh' => [''],
        ];
    }

    #[DataProvider('realStreets')]
    public function test_real_street_names_are_kept(string $value): void
    {
        $this->assertFalse(AddressText::isRouteRef($value), $value);
    }

    public function test_compose_joins_street_locality_and_district(): void
    {
        $this->assertSame(
            "Bobur ko'chasi 12, Yangi hayot, Qo'rg'ontepa tumani",
            AddressText::compose("Bobur ko'chasi 12", 'Yangi hayot', "Qo'rg'ontepa tumani"),
        );
    }

    public function test_compose_skips_missing_parts(): void
    {
        $this->assertSame('Yangi hayot, Xonobod shahri', AddressText::compose(null, 'Yangi hayot', 'Xonobod shahri'));
        $this->assertSame('Xonobod shahri', AddressText::compose(null, null, 'Xonobod shahri'));
        $this->assertSame("Bobur ko'chasi", AddressText::compose("Bobur ko'chasi", '  ', null));
        $this->assertSame('', AddressText::compose(null, null, null));
    }

    public function test_compose_does_not_repeat_the_district_or_locality(): void
    {
        // tuman ko'cha matnida bor (apostrof va registr farqiga qaramay)
        $this->assertSame(
            "Bobur ko'chasi, Qo‘rg‘ontepa tumani",
            AddressText::compose("Bobur ko'chasi, Qo‘rg‘ontepa tumani", null, "QO'RG'ONTEPA TUMANI"),
        );
        // qishloq tuman nomi bilan bir xil
        $this->assertSame('Xonobod shahri', AddressText::compose(null, 'Xonobod', 'Xonobod shahri'));
        // qishloq ko'cha matnida bor
        $this->assertSame("Yangi hayot ko'chasi, Xonobod shahri", AddressText::compose("Yangi hayot ko'chasi", 'Yangi hayot', 'Xonobod shahri'));
    }
}
