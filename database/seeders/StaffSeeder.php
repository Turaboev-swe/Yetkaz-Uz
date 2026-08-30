<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Restaurant;
use App\Models\Staff;
use Illuminate\Database\Seeder;

/**
 * Panel xodimlari: bitta platform_admin + har restoranga bitta restaurant_owner.
 * Lokal test uchun parol qat'iy va chiqishda ko'rsatiladi.
 */
class StaffSeeder extends Seeder
{
    private const PASSWORD = 'yetkaz12345';

    public function run(): void
    {
        $rows = [];

        $admin = Staff::updateOrCreate(
            ['email' => 'admin@yetkaz.uz'],
            [
                'name' => 'Platforma Admini',
                'password' => self::PASSWORD,
                'role' => StaffRole::PlatformAdmin,
                'restaurant_id' => null,
                'is_active' => true,
            ],
        );
        $rows[] = ['/admin', $admin->email, self::PASSWORD, 'platform_admin', '—'];

        foreach (Restaurant::orderBy('id')->get() as $restaurant) {
            $slug = str($restaurant->name)->slug('.')->lower();
            $email = "{$slug}@yetkaz.uz";

            $owner = Staff::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $restaurant->name.' — egasi',
                    'password' => self::PASSWORD,
                    'role' => StaffRole::RestaurantOwner,
                    'restaurant_id' => $restaurant->id,
                    'is_active' => true,
                ],
            );
            $rows[] = ['/restaurant', $owner->email, self::PASSWORD, 'restaurant_owner', $restaurant->name];
        }

        $this->command->newLine();
        $this->command->info('Panel xodimlari:');
        $this->command->table(
            ['Panel', 'Email', 'Parol', 'Rol', 'Restoran'],
            $rows,
        );
    }
}
