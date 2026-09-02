<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * `php artisan config:cache` production entrypoint'ida ishlaydi. Runtime'да
 * config'ga qo'yiladigan closure/obyekt (masalan PROD-4 Guzzle handler) uni
 * buzmasligi kerak — shunday qiymatlar FAQAT tegishli servis resolve qilinganда
 * qo'shilishi shart.
 */
class ConfigCacheableTest extends TestCase
{
    public function test_config_has_no_non_serializable_values(): void
    {
        foreach (Arr::dot(config()->all()) as $key => $value) {
            if (is_object($value) || $value instanceof \Closure) {
                $this->fail("config['{$key}'] obyekt/closure — config:cache buziladi.");
            }

            try {
                eval(var_export($value, true).';');
            } catch (\Throwable $e) {
                $this->fail("config['{$key}'] serializatsiya qilinmaydi: {$e->getMessage()}");
            }
        }

        $this->assertNull(config('nutgram.config.client.handler'));
    }
}
