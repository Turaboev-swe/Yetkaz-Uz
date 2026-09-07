<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Restaurant;
use App\Models\Staff;
use Illuminate\Database\Seeder;

/**
 * Panel xodimlari: bitta platform_admin + har restoranga bitta restaurant_owner
 * va bitta kitchen_staff (/kitchen sinovи uchun). Lokal test uchun parol qat'iy
 * va chiqishda ko'rsatiladi. TELEGRAM_DEV_NOTIFY_CHAT_ID bo'lsa — hammasiga
 * telegram_chat_id sifatida yoziladi (botdan status tugmalari keladi).
 */
class StaffSeeder extends Seeder
{
    private const PASSWORD = 'yetkaz12345';

    public function run(): void
    {
        $rows = [];

        // Lokal test: shu Telegram chat botdan "keyingi bosqich" tugmalarini oladi
        // (admin + har restoran egasi + oshxona xodimi). Faqat .env da bo'lsa.
        $devChatId = env('TELEGRAM_DEV_NOTIFY_CHAT_ID') ?: null;

        $admin = Staff::updateOrCreate(
            ['email' => 'admin@yetkaz.uz'],
            [
                'name' => 'Platforma Admini',
                'password' => self::PASSWORD,
                'role' => StaffRole::PlatformAdmin,
                'restaurant_id' => null,
                'telegram_chat_id' => $devChatId,
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
                    'telegram_chat_id' => $devChatId,
                    'is_active' => true,
                ],
            );
            $rows[] = ['/restaurant', $owner->email, self::PASSWORD, 'restaurant_owner', $restaurant->name];

            $kitchen = Staff::updateOrCreate(
                ['email' => "oshxona.{$slug}@yetkaz.uz"],
                [
                    'name' => $restaurant->name.' — oshxona',
                    'password' => self::PASSWORD,
                    'role' => StaffRole::KitchenStaff,
                    'restaurant_id' => $restaurant->id,
                    'telegram_chat_id' => $devChatId,
                    'is_active' => true,
                ],
            );
            $rows[] = ['/kitchen', $kitchen->email, self::PASSWORD, 'kitchen_staff', $restaurant->name];
        }

        $this->command->newLine();
        $this->command->info('Panel xodimlari:');
        $this->command->table(
            ['Panel', 'Email', 'Parol', 'Rol', 'Restoran'],
            $rows,
        );
    }
}
