<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nutgram\Laravel\RunningMode\LaravelWebhook;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Telegram webhook kirish nuqtasi (PROD-2 / PROD-6).
 *
 * Xavfsizlik — ikki qatlam, ikkalasi ham `config('telegram.webhook_secret')`:
 *   1. URL path segmenti  (`/telegram/webhook/{token}`)
 *   2. `X-Telegram-Bot-Api-Secret-Token` sarlavhasi (Telegram `setWebhook` da
 *      berilgan `secret_token` ni har so'rovda qaytaradi)
 *
 * Mos kelmasa — 404 (403 emas: endpoint mavjudligini ham oshkor qilmaymiz).
 * Secret bo'sh bo'lsa (webhook o'rnatilmagan) — har doim 404.
 *
 * Handler ichidagi xatolar HECH QACHON 5xx ga aylanmasligi kerak: Telegram 5xx
 * da update'ni qayta-qayta yuboradi va oxir-oqibat webhook'ni o'chirib qo'yadi.
 * Xato loglanadi (Nutgram + report), Telegram'ga esa 204 qaytadi.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, Nutgram $bot): Response
    {
        $secret = trim((string) config('telegram.webhook_secret'));

        abort_if($secret === '', Response::HTTP_NOT_FOUND);
        abort_unless(hash_equals($secret, $token), Response::HTTP_NOT_FOUND);
        abort_unless(
            hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '')),
            Response::HTTP_NOT_FOUND,
        );

        // Secret allaqachon tekshirildi — Nutgram'ning o'z safeMode tekshiruvi shart emas.
        $bot->setRunningMode(new LaravelWebhook);

        try {
            $bot->run();
        } catch (Throwable $e) {
            // Nutgram "Update failed" ni loglagan; to'liq izini ham yozamiz.
            report($e);
        }

        // Telegram javob tanasiga qaramaydi — 204 kifoya (5xx EMAS).
        return response()->noContent();
    }
}
