<?php

namespace App\Services\Telegram;

use App\Exceptions\InvalidInitDataException;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Mini App `initData` imzosini tekshiradi (bot tokeni bilan, HMAC).
 *
 * Algoritm (https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app):
 *   secret_key      = HMAC_SHA256(key="WebAppData", data=<bot_token>)
 *   data_check_hash = HMAC_SHA256(key=secret_key, data=<data_check_string>)
 *   data_check_string — FAQAT `hash` dan tashqari barcha maydonlar, kalit bo'yicha
 *                        alifbo tartibida, "key=value" ko'rinishida, \n bilan ajratilgan.
 *
 * DIQQAT: `signature` (Ed25519) maydoni HMAC `hash` tekshiruvining data_check_string
 * IGA KIRADI. `signature` faqat uchinchi tomon Ed25519 tekshiruvida chiqariladi —
 * bu yerda emas. (Ilgari xato bo'lgan: `signature` ham chiqarib tashlanardi, natijada
 * haqiqiy Telegram initData'si "imzo noto'g'ri" bilan rad etilardi.)
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
            $this->debug('hash maydoni yo\'q', [
                'init_data_len' => strlen($initData),
                'keys' => array_keys($fields),
            ]);

            throw new InvalidInitDataException('`hash` maydoni yo\'q.');
        }

        // FAQAT `hash` chiqariladi. `signature` data_check_string ga kiradi.
        $checkFields = $fields;
        unset($checkFields['hash']);
        ksort($checkFields);

        $dataCheckString = implode("\n", array_map(
            static fn (string $k, string $v): string => "$k=$v",
            array_keys($checkFields),
            array_values($checkFields),
        ));

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $dataCheckString, $secretKey);

        $authDate = (int) ($fields['auth_date'] ?? 0);

        if (! hash_equals($calculated, $hash)) {
            $this->debug('imzo mos kelmadi', [
                'init_data_len' => strlen($initData),
                'keys' => array_keys($fields),
                'check_keys' => array_keys($checkFields),
                'auth_date_age_sec' => $authDate > 0 ? time() - $authDate : null,
                'token_prefix' => substr($this->botToken, 0, 6),
                'hash_received_prefix' => substr($hash, 0, 8),
                'hash_calculated_prefix' => substr($calculated, 0, 8),
            ]);

            throw new InvalidInitDataException('initData imzosi noto\'g\'ri.');
        }

        if ($authDate <= 0) {
            throw new InvalidInitDataException('`auth_date` maydoni yo\'q.');
        }
        if ($authDate < time() - $this->ttlSeconds) {
            $this->debug('initData eskirgan', ['auth_date_age_sec' => time() - $authDate, 'ttl' => $this->ttlSeconds]);

            throw new InvalidInitDataException('initData eskirgan (auth_date 24 soatdan oldin).');
        }

        $user = json_decode($fields['user'] ?? 'null', true);
        if (! is_array($user) || ! isset($user['id'])) {
            throw new InvalidInitDataException('`user` maydoni yo\'q yoki buzuq.');
        }

        return ['user' => $user, 'auth_date' => $authDate, 'fields' => $fields];
    }

    /**
     * Query-string'ni qo'lda ajratamiz — parse_str() kalitlarni buzadi
     * (`.` va ` ` -> `_`). Qiymatlar bir marta URL-dekod qilinadi (`+` -> bo'sh joy,
     * URLSearchParams / parse_str bilan bir xil).
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

    /**
     * Diagnostika — token va to'liq hash logga TUSHMAYDI (faqat qisqa prefiks).
     *
     * @param  array<string,mixed>  $context
     */
    private function debug(string $reason, array $context = []): void
    {
        Log::warning("[initdata] {$reason}", $context);
    }
}
