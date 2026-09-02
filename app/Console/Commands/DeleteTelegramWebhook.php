<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

/**
 * Telegram webhook ni o'chirish — long polling rejimiga qaytish uchun
 * (webhook ishlamay qolsa yoki domen o'zgarsa).
 *
 *   php artisan telegram:webhook:delete [--drop-pending]
 */
class DeleteTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook:delete {--drop-pending : Kutayotgan update larni tashlab yubor}';

    protected $description = 'Telegram webhook ni o\'chirish (polling rejimiga qaytish)';

    public function handle(Nutgram $bot): int
    {
        $ok = $bot->deleteWebhook(drop_pending_updates: (bool) $this->option('drop-pending'));

        if ($ok !== true) {
            $this->error('deleteWebhook muvaffaqiyatsiz.');

            return self::FAILURE;
        }

        $this->info('Webhook o\'chirildi. `bot` konteynerini (polling) qayta ishga tushiring.');

        return self::SUCCESS;
    }
}
