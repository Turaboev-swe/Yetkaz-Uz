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
        ]);
    }
}
