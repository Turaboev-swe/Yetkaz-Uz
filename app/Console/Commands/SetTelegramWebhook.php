<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

/**
 * Telegram webhook o'rnatish (PROD-2). Domen + SSL tayyor bo'lgandagina.
 *
 *   php artisan telegram:webhook:set [--drop-pending]
 *
 * Webhook URL `TELEGRAM_MINI_APP_URL` domenidan quriladi:
 *   https://<domen>/api/telegram/webhook/<TELEGRAM_WEBHOOK_SECRET>
 * `secret_token` sarlavhasi ham shu bilan o'rnatiladi.
 */
class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook:set {--drop-pending : Kutayotgan update larni tashlab yubor}';

    protected $description = 'Telegram webhook ni o\'rnatish (production + https)';

    public function handle(Nutgram $bot): int
    {
        if (! $this->laravel->isProduction()) {
            $this->error('Faqat production muhitida ishlaydi (APP_ENV=production).');

            return self::FAILURE;
        }

        $secret = trim((string) config('telegram.webhook_secret'));
        if (mb_strlen($secret) < 16) {
            $this->error('TELEGRAM_WEBHOOK_SECRET yo\'q yoki juda qisqa (kamida 16 belgi kerak).');

            return self::FAILURE;
        }

        $miniApp = (string) config('telegram.mini_app_url');
        $parts = parse_url($miniApp) ?: [];
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            $this->error("TELEGRAM_MINI_APP_URL https bo'lishi shart. Hozir: '{$miniApp}'");

            return self::FAILURE;
        }

        $path = route('telegram.webhook', ['token' => $secret], absolute: false);
        $url = 'https://'.$parts['host'].$path;
        $masked = 'https://'.$parts['host'].preg_replace('#/webhook/.*$#', '/webhook/***', $path);

        $this->line("Webhook URL: <info>{$masked}</info>");

        $ok = $bot->setWebhook(
            url: $url,
            max_connections: 40,
            drop_pending_updates: (bool) $this->option('drop-pending'),
            secret_token: $secret,
        );

        if ($ok !== true) {
            $this->error('setWebhook muvaffaqiyatsiz tugadi.');

            return self::FAILURE;
        }

        $info = $bot->getWebhookInfo();
        $this->table(['Maydon', 'Qiymat'], [
            ['url', preg_replace('#/webhook/[^/?]+#', '/webhook/***', (string) ($info->url ?? ''))],
            ['has_custom_certificate', var_export($info->has_custom_certificate ?? false, true)],
            ['pending_update_count', (string) ($info->pending_update_count ?? 0)],
            ['max_connections', (string) ($info->max_connections ?? '—')],
            ['last_error_message', $info->last_error_message ?: '—'],
        ]);

        $this->newLine();
        $this->info('Webhook o\'rnatildi. Endi `bot` konteynerini to\'xtatib qo\'ying —');
        $this->info('update lar `app` (nginx -> Octane) orqali keladi. Qarang docs/deploy.md.');

        return self::SUCCESS;
    }
}
