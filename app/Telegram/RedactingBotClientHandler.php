<?php

namespace App\Telegram;

use App\Support\SecretRedactor;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Nutgram'ning Guzzle mijozi uchun handler stack. Ikki vazifa:
 *
 * 1. PROD-4 — token redaktsiyasi. Transport xatolari (cURL: hostga ulanib
 *    bo'lmadi, timeout, HTTP/2 stream reset) xabarida so'rov URL'i bo'ladi,
 *    URL ichida esa bot tokeni. Middleware xabardagi tokenni [REDACTED] ga
 *    almashtiradi, xato turini saqlab qoladi.
 *
 * 2. Tarmoq beqarorligini yumshatish (vaqtinchalik — domen tasdiqlangач bot
 *    webhook'ga o'tadi). O'zbekiston serverida `getUpdates` long-poll
 *    tez-tez `cURL error 28` bilan uziladi va ushlanmagani uchun butun
 *    `nutgram:run` process'ini o'ldiradi. HTTP klient darajasida ulanish
 *    xatosini 2 marta qayta uradi (1s, 2s backoff) — bitta uzilish
 *    konteynerni qayta ishga tushirmasin.
 *
 * Telegram API xatolari (chat not found va h.k.) Nutgram tomonidan
 * TelegramException sifatida beriladi — bu yerga yetib kelmaydi.
 */
final class RedactingBotClientHandler
{
    private const MAX_RETRIES = 2;

    public static function stack(?callable $baseHandler = null): HandlerStack
    {
        $stack = HandlerStack::create($baseHandler);

        // Ichki qatlam: xato xabaridan tokenni olib tashlash.
        $stack->push(static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                return $handler($request, $options)->otherwise(static function (mixed $reason) {
                    if ($reason instanceof Throwable) {
                        throw self::redact($reason);
                    }

                    return Create::rejectionFor($reason);
                });
            };
        }, 'redact_bot_token');

        // Tashqi qatlam: ulanish xatosida qayta urinish (faqat ConnectException —
        // Telegram'ga so'rov yetib bormagan, xavfsiz qayta uriladi).
        $stack->push(Middleware::retry(
            static function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?Throwable $e): bool {
                return $retries < self::MAX_RETRIES && $e instanceof ConnectException;
            },
            static fn (int $retries): int => 1000 * $retries, // 1s, 2s
        ), 'bot_connect_retry');

        return $stack;
    }

    private static function redact(Throwable $e): Throwable
    {
        // Transport xatolari: RequestException (5xx / bad response) yoki
        // ConnectException (cURL: ulanish, timeout, stream). Ikkalasida ham
        // xabar `... for <URL>` ko'rinishida, URL ichida token bo'ladi.
        if (! $e instanceof RequestException && ! $e instanceof ConnectException) {
            return $e;
        }

        $message = SecretRedactor::text($e->getMessage());

        if ($message === $e->getMessage()) {
            return $e;
        }

        if ($e instanceof ConnectException) {
            return new ConnectException($message, $e->getRequest(), $e->getPrevious(), $e->getHandlerContext());
        }

        return new RequestException(
            $message,
            $e->getRequest(),
            $e->getResponse(),
            $e->getPrevious(),
            $e->getHandlerContext(),
        );
    }
}
