<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AndijanGeoSeeder::class,
            DemoSeeder::class,
            QorgontepaSeeder::class,
            StaffSeeder::class,

            // Qo'rg'ontepa restoranlari — to'liq menyu + rasm + xodimlar.
            // StaffSeeder'dan keyin: yangi restoranlar egasi bu yerda xavfsiz
            // parol bilan yaratiladi (StaffSeeder ular mavjud bo'lmagani uchun
            // o'tkazib yuborgan).
            VanillaMenuSeeder::class,
            FreshFoodMenuSeeder::class,
            IstiqlolFoodMenuSeeder::class,
            SushiXanMenuSeeder::class,
        ]);
    }
}
