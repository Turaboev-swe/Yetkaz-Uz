<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TranslationSanityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function langFiles(): array
    {
        return [
            'uz' => [__DIR__.'/../../lang/uz/messages.php'],
            'ru' => [__DIR__.'/../../lang/ru/messages.php'],
        ];
    }

    #[DataProvider('langFiles')]
    public function test_no_literal_backslash_n_in_translation_values(string $path): void
    {
        $messages = require $path;
        $flat = [];
        array_walk_recursive($messages, function ($value, $key) use (&$flat) {
            $flat[$key] = $value;
        });

        foreach ($flat as $key => $value) {
            $this->assertStringNotContainsString(
                '\n',
                $value,
                "«{$key}» qiymatida harfma-harf \\n bor — qo'sh tirnoq ishlating.",
            );
        }
    }

    #[DataProvider('langFiles')]
    public function test_required_bot_keys_exist(string $path): void
    {
        $messages = require $path;

        foreach (['welcome', 'welcome_back', 'registration', 'main_menu'] as $key) {
            $this->assertArrayHasKey($key, $messages);
        }
        foreach (['ask_phone', 'ask_name', 'ask_location', 'done'] as $key) {
            $this->assertArrayHasKey($key, $messages['registration']);
        }
    }
}
