<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Restaurant;
use App\Models\Staff;
use Illuminate\Database\Seeder;

/**
 * Panel xodimlari: bitta platform_admin + har restoranga bitta restaurant_owner
 * va bitta kitchen_staff (/kitchen sinovи uchun). Lokal test uchun parol qat'iy
 * va chiqishda ko'rsatiladi.
 *
 * telegram_chat_id:
 *   - platform_admin — TELEGRAM_DEV_NOTIFY_CHAT_ID (.env), FAQAT shu yozuvda.
 *   - har restoran (egasi + oshxona) — NOYOB, haqiqiy bo'lmagan test raqami
 *     (9000000000 + restaurant_id). Bir xil Telegram hisobini hammaga ulash
 *     `Staff::where('telegram_chat_id', ...)->orderBy('id')->first()` ni
 *     qaysi restoran ekanidan qat'i nazar birinchi yozuvga (platform_admin,
 *     id=1) qaytarib, "ruxsat yo'q" xatosiga olib kelgan edi (2026-09-24).
 *     Bitta restorandagi egasi+oshxona bitta xil raqamni ulashadi — ikkalasi
 *     ham canManageKitchen()=true, shuning uchun bu muammo emas.
 *
 * Bitta restoran sifatida sinash uchun: o'z shaxsiy Telegram hisobingiz uchun
 * tinker orqali `Staff::where('restaurant_id', X)->update(['telegram_chat_id' => YOUR_ID])`.
 */
class StaffSeeder extends Seeder
{
    private const PASSWORD = 'yetkaz12345';

    public function run(): void
    {
        $rows = [];

        // Faqat platform_admin uchun (.env da bo'lsa) — botdan "keyingi bosqich"
        // tugmalari shu hisobga keladi.
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
        $rows[] = ['/admin', $admin->email, self::PASSWORD, 'platform_admin', '—', $devChatId ?? '—'];

        foreach (Restaurant::orderBy('id')->get() as $restaurant) {
            $slug = str($restaurant->name)->slug('.')->lower();
            $email = "{$slug}@yetkaz.uz";
            $restaurantChatId = 9_000_000_000 + $restaurant->id;

            $owner = Staff::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $restaurant->name.' — egasi',
                    'password' => self::PASSWORD,
                    'role' => StaffRole::RestaurantOwner,
                    'restaurant_id' => $restaurant->id,
                    'telegram_chat_id' => $restaurantChatId,
                    'is_active' => true,
                ],
            );
            $rows[] = ['/restaurant', $owner->email, self::PASSWORD, 'restaurant_owner', $restaurant->name, $restaurantChatId];

            $kitchen = Staff::updateOrCreate(
                ['email' => "oshxona.{$slug}@yetkaz.uz"],
                [
                    'name' => $restaurant->name.' — oshxona',
                    'password' => self::PASSWORD,
                    'role' => StaffRole::KitchenStaff,
                    'restaurant_id' => $restaurant->id,
                    'telegram_chat_id' => $restaurantChatId,
                    'is_active' => true,
                ],
            );
            $rows[] = ['/kitchen', $kitchen->email, self::PASSWORD, 'kitchen_staff', $restaurant->name, $restaurantChatId];
        }

        $this->command->newLine();
        $this->command->info('Panel xodimlari:');
        $this->command->table(
            ['Panel', 'Email', 'Parol', 'Rol', 'Restoran', 'telegram_chat_id (test)'],
            $rows,
        );
    }
}
