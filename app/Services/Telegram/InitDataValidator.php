<?php

namespace App\Services\Telegram;

use App\Exceptions\InvalidInitDataException;

/**
 * Telegram Mini App `initData` imzosini tekshiradi.
 *
 * Algoritm (https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app):
 *   secret_key      = HMAC_SHA256(key="WebAppData", data=<bot_token>)
 *   data_check_hash = HMAC_SHA256(key=secret_key, data=<data_check_string>)
 *   data_check_string — `hash` va `signature` dan tashqari barcha maydonlar,
 *                        kalit bo'yicha alifbo tartibida, "key=value" ko'rinishida, \n bilan.
 *
 * initData'ga TEKSHIRUVSIZ ishonilmaydi — bu autentifikatsiyaning asosi.
 */
class InitDataValidator
{
    public function __construct(
        private readonly string $botToken,
        private readonly int $ttlSeconds = 86400,
    ) {}

    /**
     * @return array{user: array<string,mixed>, auth_date: int, fields: array<string,string>}
     *
     * @throws InvalidInitDataException
     */
    public function validate(string $initData): array
    {
        if ($this->botToken === '') {
            throw new InvalidInitDataException('Bot token sozlanmagan.');
        }

        $fields = $this->parse($initData);

        $hash = $fields['hash'] ?? '';
        if ($hash === '') {
            throw new InvalidInitDataException('`hash` maydoni yo\'q.');
        }

        // hash va signature imzo satriga kirmaydi.
        $checkFields = $fields;
        unset($checkFields['hash'], $checkFields['signature']);
        ksort($checkFields);

        $dataCheckString = implode("\n", array_map(
            static fn (string $k, string $v): string => "$k=$v",
            array_keys($checkFields),
            array_values($checkFields),
        ));

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($calculated, $hash)) {
            throw new InvalidInitDataException('initData imzosi noto\'g\'ri.');
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);
        if ($authDate <= 0) {
            throw new InvalidInitDataException('`auth_date` maydoni yo\'q.');
        }
        if ($authDate < time() - $this->ttlSeconds) {
            throw new InvalidInitDataException('initData eskirgan (auth_date 24 soatdan oldin).');
        }

        $user = json_decode($fields['user'] ?? 'null', true);
        if (! is_array($user) || ! isset($user['id'])) {
            throw new InvalidInitDataException('`user` maydoni yo\'q yoki buzuq.');
        }

        return ['user' => $user, 'auth_date' => $authDate, 'fields' => $fields];
    }

    /**
     * Query-string'ni qo'lda ajratamiz — parse_str() kalitlarni buzadi va
     * qiymatlarni qayta dekod qiladi.
     *
     * @return array<string,string>
     */
    private function parse(string $initData): array
    {
        $fields = [];

        foreach (explode('&', $initData) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $chunk, 2), 2, '');
            $fields[urldecode($key)] = urldecode($value);
        }

        return $fields;
    }
}
