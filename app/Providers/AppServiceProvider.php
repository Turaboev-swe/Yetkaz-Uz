<?php

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Listeners\NotifyKitchenStaffOfNewOrder;
use App\Services\Telegram\InitDataValidator;
use App\Telegram\RedactingBotClientHandler;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InitDataValidator::class, fn () => new InitDataValidator(
            botToken: (string) config('nutgram.token'),
            ttlSeconds: (int) config('telegram.init_data_ttl', 86400),
        ));
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureTelegramClient();

        // Yangi buyurtma -> restoran xodimlariga bot orqali (status tugmasi bilan).
        // Aniq ro'yxatdan o'tkazamiz — `event:cache` (entrypoint) ga bog'liq emas.
        Event::listen(OrderPlaced::class, NotifyKitchenStaffOfNewOrder::class);
    }

    /**
     * Nutgram Guzzle mijoziga bot tokenini yashiradigan handler stack (PROD-4).
     *
     * Handler `beforeResolving` da qo'shiladi — ya'ni FAQAT Nutgram haqiqatan
     * resolve qilinganда. `config:cache` Nutgram'ni resolve qilmaydi, shuning
     * uchun `nutgram.config.client.handler` (serializatsiya qilib bo'lmaydigan
     * closure/obyekt) keshга tushmaydi.
     */
    private function configureTelegramClient(): void
    {
        $this->app->beforeResolving(Nutgram::class, function (): void {
            config()->set('nutgram.config.client.handler', RedactingBotClientHandler::stack());
        });
    }

    /**
     * Mini App API rate limitlari (PROD-1). Hisob Redis'da (RateLimiter facade
     * standart cache store'dan foydalanadi — CACHE_STORE=redis).
     *
     * Kalit foydalanuvchi bo'yicha (initData'dan aniqlangan user_id), imzosiz
     * holatda IP bo'yicha. Limitdan oshilganda 429 + o'zbekcha/ruscha xabar,
     * shuningdek warning log — kelajakda shubhali user/IP ni aniqlash uchun.
     */
    private function configureRateLimiting(): void
    {
        // O'qish endpointlari (restoranlar, menyu, qidiruv, ETA taxmini) — 60/daqiqa.
        RateLimiter::for('api-read', fn (Request $request) => Limit::perMinute(60)
            ->by($this->limiterKey('read', $request))
            ->response($this->tooManyRequests('api-read', 'messages.rate_limited')));

        // Manzil yaratish / tahrirlash / o'chirish — 20/daqiqa.
        RateLimiter::for('addresses', fn (Request $request) => Limit::perMinute(20)
            ->by($this->limiterKey('addr', $request))
            ->response($this->tooManyRequests('addresses', 'messages.rate_limited')));

        // Buyurtma yaratish — 5/daqiqa (foydalanuvchi bo'yicha).
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(5)
            ->by($this->limiterKey('order', $request))
            ->response($this->tooManyRequests('orders', 'messages.rate_limited_orders')));
    }

    private function limiterKey(string $bucket, Request $request): string
    {
        return $bucket.':'.($request->user()?->getAuthIdentifier() ?? $request->ip());
    }

    /**
     * Limit oshib ketganda: warning log + 429 JSON (rate-limit sarlavhalari bilan).
     *
     * @return Closure(Request, array<string, mixed>): Response
     */
    private function tooManyRequests(string $limiter, string $messageKey): Closure
    {
        return function (Request $request, array $headers) use ($limiter, $messageKey): Response {
            Log::warning('[ratelimit] limit oshib ketdi', [
                'limiter' => $limiter,
                'user_id' => $request->user()?->getAuthIdentifier(),
                'ip' => $request->ip(),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(
                ['message' => __($messageKey)],
                Response::HTTP_TOO_MANY_REQUESTS,
                $headers,
            );
        };
    }
}
