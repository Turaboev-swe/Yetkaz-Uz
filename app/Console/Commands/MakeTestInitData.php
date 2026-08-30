<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Lokal test uchun: haqiqiy imzo bilan Telegram initData yasaydi, shunda
 * Mini App API'sini brauzersiz (curl bilan) sinash mumkin.
 *
 * Token chiqmaydi — faqat imzolangan initData (user JSON + hash).
 *   php artisan telegram:test-init-data 1746546661 --name="Abdulqayum"
 */
class MakeTestInitData extends Command
{
    protected $signature = 'telegram:test-init-data
        {telegram_id=111222333 : Telegram foydalanuvchi ID}
        {--name=Test User : first_name}
        {--lang=uz : language_code}
        {--curl : to`liq curl misolini chiqar}';

    protected $description = 'Lokal API testi uchun imzolangan Telegram initData yasaydi';

    public function handle(): int
    {
        $token = (string) config('nutgram.token');

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN .env da sozlanmagan.');

            return self::FAILURE;
        }

        if (app()->environment('production')) {
            $this->error('Bu buyruq faqat lokal/dev muhitida.');

            return self::FAILURE;
        }

        $user = json_encode([
            'id' => (int) $this->argument('telegram_id'),
            'first_name' => $this->option('name'),
            'language_code' => $this->option('lang'),
        ], JSON_UNESCAPED_UNICODE);

        $fields = [
            'auth_date' => (string) time(),
            'query_id' => 'AA'.substr(md5((string) mt_rand()), 0, 16),
            'user' => $user,
        ];

        ksort($fields);
        $dcs = implode("\n", array_map(
            static fn ($k, $v) => "$k=$v",
            array_keys($fields),
            array_values($fields),
        ));

        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $dcs, $secret);

        $initData = http_build_query($fields);

        if ($this->option('curl')) {
            $this->line('curl -s -H '.escapeshellarg('Authorization: tma '.$initData).
                ' http://localhost:8010/api/me | jq');

            return self::SUCCESS;
        }

        $this->line($initData);

        return self::SUCCESS;
    }
}
