<?php

namespace App\Services\User;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Foydalanuvchi profili va ro'yxatdan o'tish mantiqi.
 *
 * Biznes qoidalari (Claude.md):
 * - Telefon faqat request_contact orqali (bu servis normallashtiradi, xolos)
 * - Ism, telefon, lokatsiya bir marta so'raladi; profile_completed = true bo'lgach hech qachon
 * - Lokatsiya "Uy" yorlig'i bilan, is_default = true qilib saqlanadi
 */
class ProfileService
{
    public function findOrCreateFromTelegram(
        int $telegramId,
        ?string $languageCode = null,
    ): User {
        $user = User::byTelegramId($telegramId)->first();

        if ($user === null) {
            $user = User::create([
                'telegram_id' => $telegramId,
                'language' => $this->normalizeLanguage($languageCode),
                'profile_completed' => false,
            ]);
        }

        return $user;
    }

    public function touch(User $user): void
    {
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function saveContact(User $user, string $rawPhone): void
    {
        $user->update(['phone' => $this->normalizePhone($rawPhone)]);
    }

    public function saveName(User $user, string $name): void
    {
        $user->update(['full_name' => $this->cleanName($name)]);
    }

    /**
     * Ro'yxatdan o'tishning oxirgi qadami: uy manzilini saqlaydi va profilni tugatadi.
     * Bitta tranzaksiyada — yarim holat qolmaydi.
     */
    public function completeWithHomeAddress(
        User $user,
        float $lat,
        float $lng,
        ?string $addressText = null,
    ): Address {
        return DB::transaction(function () use ($user, $lat, $lng, $addressText) {
            $address = $user->addresses()->create([
                'label' => Address::LABEL_HOME,
                'lat' => $lat,
                'lng' => $lng,
                'address_text' => $addressText ?: $this->coordsAsText($lat, $lng),
                'is_default' => true,
            ]);

            $user->update(['profile_completed' => true]);

            return $address;
        });
    }

    public function isRegistered(User $user): bool
    {
        return $user->profile_completed
            && filled($user->phone)
            && filled($user->full_name)
            && $user->addresses()->exists();
    }

    /**
     * Yangi foydalanuvchi doim o'zbekcha boshlaydi — Telegram profilidagi
     * language_code ga qarab avtomat almashmaydi. Tilni faqat "Sozlamalar"da
     * foydalanuvchi o'zi o'zgartiradi.
     */
    private function normalizeLanguage(?string $code): string
    {
        return 'uz';
    }

    /** `998901234567` / `+998 90 123 45 67` -> `+998901234567` */
    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return '+'.ltrim($digits, '+');
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function coordsAsText(float $lat, float $lng): string
    {
        return sprintf('%.6f, %.6f', $lat, $lng);
    }
}
