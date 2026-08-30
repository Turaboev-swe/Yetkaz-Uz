<?php

namespace Tests\Concerns;

use App\Services\Telegram\InitDataValidator;

trait InteractsWithTelegramInitData
{
    protected string $botToken = '123456:TEST-BOT-TOKEN';

    /** Test uchun InitDataValidator'ni belgilangan token bilan bog'laydi. */
    protected function bindInitDataValidator(int $ttl = 86400): void
    {
        config()->set('nutgram.token', $this->botToken);

        $this->app->singleton(
            InitDataValidator::class,
            fn () => new InitDataValidator($this->botToken, $ttl),
        );
    }

    /**
     * Haqiqiy Telegram imzosi bilan initData query-string yasaydi.
     *
     * @param  array<string,mixed>  $user
     * @param  array<string,string>  $extra
     */
    protected function signedInitData(array $user = [], ?int $authDate = null, array $extra = []): string
    {
        $user = array_replace([
            'id' => 111222333,
            'first_name' => 'Ali',
            'last_name' => 'Valiyev',
            'username' => 'ali',
            'language_code' => 'uz',
        ], $user);

        $fields = array_replace([
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AAF'.substr(md5((string) mt_rand()), 0, 20),
            'user' => json_encode($user, JSON_UNESCAPED_UNICODE),
        ], $extra);

        // Telegram `hash` ni `hash` va `signature` dan tashqari maydonlar ustidan hisoblaydi.
        $checkFields = $fields;
        unset($checkFields['hash'], $checkFields['signature']);
        ksort($checkFields);

        $dataCheckString = implode("\n", array_map(
            static fn ($k, $v) => "$k=$v",
            array_keys($checkFields),
            array_values($checkFields),
        ));

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($fields);
    }

    /** @return array<string,string> */
    protected function initDataHeaders(string $initData): array
    {
        return ['Authorization' => 'tma '.$initData];
    }
}
