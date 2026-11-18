<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Lang;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Oshxona botida ishlatiladigan har bir `messages.kitchen_bot.*` kaliti uz VA ru da bo'lishi shart.
 *
 * Nima uchun: kalit lang faylida bo'lmasa Laravel xatosiz KALITNING O'ZINI qaytaradi — oshxona xodimi tugmada
 * «messages.kitchen_bot.btn_reject» matnini ko'radi (jim buzilish; test odatda buni sezmaydi). Bu test kodni skanerlaydi,
 * shuning uchun yangi kalit qo'shilib lang'ga unutilsa darhol yiqiladi.
 *
 * Kalit manbalari (app/ ichida):
 *  1) to'liq literal:                 __('messages.kitchen_bot.btn_reject')
 *  2) lokal yordamchi chaqiruvlari:   $t('cb_stale')   (fayl `messages.kitchen_bot.$k` ni yig'adigan closure e'lon qilgan bo'lsa)
 *  3) `$key = match (...) { ... => 'btn_accept' }` natijalari (keyin `messages.kitchen_bot.$key` ga beriladi)
 *
 * ESLATMA: lang fayli keyin qo'shilgan bo'lsa-da, UZOQ ISHLAYDIGAN jarayonlar (bot, Horizon) eski faylni xotirada
 * ushlab turadi — deploy/lokal o'zgarishdan keyin ularni restart qiling (bu testni o'zgartirmaydi).
 */
class KitchenBotLangKeysTest extends TestCase
{
    private const LOCALES = ['uz', 'ru'];

    /** Skaner "jimgina nol topib" o'tib ketmasligi uchun: bu kalitlar har doim topilishi kerak. */
    private const KNOWN = ['btn_reject', 'btn_cancel', 'btn_accept', 'new_order', 'cb_stale', 'warn_own_free', 'courier_dispatched_taxi'];

    /** @return array<string, list<string>> kalit => ishlatilgan fayllar */
    private function usedKeys(): array
    {
        $used = [];
        $add = function (string $key, string $file) use (&$used): void {
            $used[$key][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        };

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (! str_contains($code, 'kitchen_bot')) {
                continue;
            }

            // 1) to'liq literal kalitlar
            preg_match_all('/messages\.kitchen_bot\.([A-Za-z0-9_]+)/', $code, $m);
            foreach ($m[1] as $key) {
                $add($key, $file->getPathname());
            }

            // Quyidagi ikkisi faqat dinamik `messages.kitchen_bot.$...` yig'adigan fayllarda.
            if (! preg_match('/messages\.kitchen_bot\.\$/', $code)) {
                continue;
            }

            // 2) $t('kalit') yordamchi chaqiruvlari
            preg_match_all('/\$t\(\s*\'([a-z][a-z0-9_]*)\'/', $code, $m);
            foreach ($m[1] as $key) {
                $add($key, $file->getPathname());
            }

            // 3) $key = match (...) { ... => 'kalit', ... };
            preg_match_all('/\$key\s*=\s*match\s*\([^)]*\)\s*\{(.*?)\n\s*\};/s', $code, $blocks);
            foreach ($blocks[1] as $body) {
                preg_match_all('/=>\s*\'([a-z][a-z0-9_]*)\'/', $body, $arms);
                foreach ($arms[1] as $key) {
                    $add($key, $file->getPathname());
                }
            }
        }

        ksort($used);

        return array_map(fn (array $f): array => array_values(array_unique($f)), $used);
    }

    /** @return array<string, mixed> */
    private function group(string $locale): array
    {
        $group = Lang::get('messages.kitchen_bot', [], $locale, false);

        $this->assertIsArray($group, "lang/$locale/messages.php da kitchen_bot massivi yo'q");

        return $group;
    }

    public function test_the_scanner_finds_the_keys_the_bot_uses(): void
    {
        $used = $this->usedKeys();

        $this->assertGreaterThan(30, count($used), 'skaner kalitlarni topa olmayapti (regex buzilgan bo\'lishi mumkin)');
        foreach (self::KNOWN as $key) {
            $this->assertArrayHasKey($key, $used, "skaner '$key' ni topmadi");
        }
    }

    public function test_every_key_the_kitchen_bot_uses_exists_in_both_languages(): void
    {
        $missing = [];

        foreach ($this->usedKeys() as $key => $files) {
            foreach (self::LOCALES as $locale) {
                // hasForLocale: fallback'siz — ru da yo'q kalit uz dan "yashirinmasin".
                if (! Lang::hasForLocale("messages.kitchen_bot.$key", $locale)) {
                    $missing[] = "messages.kitchen_bot.$key — lang/$locale/messages.php da YO'Q (ishlatilgan: ".implode(', ', $files).')';
                }
            }
        }

        $this->assertSame([], $missing, "Yetishmayotgan oshxona bot kalitlari:\n".implode("\n", $missing));
    }

    public function test_uz_and_ru_define_the_same_kitchen_bot_keys(): void
    {
        [$uz, $ru] = array_map(fn (string $l): array => array_keys($this->group($l)), self::LOCALES);

        $this->assertSame([], array_values(array_diff($uz, $ru)), 'uz da bor, ru da yo\'q kalitlar');
        $this->assertSame([], array_values(array_diff($ru, $uz)), 'ru da bor, uz da yo\'q kalitlar');
    }

    public function test_no_kitchen_bot_translation_is_empty(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->group($locale) as $key => $value) {
                $this->assertIsString($value, "$locale: kitchen_bot.$key matn emas");
                $this->assertNotSame('', trim($value), "$locale: kitchen_bot.$key bo'sh");
            }
        }
    }

    /** Aynan shu xato: tugma yorlig'i kalit nomi bo'lib chiqmasin (har ikki tilda, ikkala holatda). */
    public function test_the_reject_and_cancel_button_labels_resolve_to_real_text(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (['btn_reject', 'btn_cancel'] as $key) {
                $text = (string) __("messages.kitchen_bot.$key", [], $locale);

                $this->assertStringNotContainsString('messages.kitchen_bot', $text, "$locale/$key kalit nomi bo'lib chiqdi");
                $this->assertStringContainsString('❌', $text);
            }
        }
    }
}
