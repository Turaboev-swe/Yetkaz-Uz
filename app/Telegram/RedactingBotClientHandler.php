<?php

namespace App\Telegram;

use App\Support\SecretRedactor;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Nutgram'ning Guzzle mijozi uchun handler stack (PROD-4).
 *
 * Transport xatolari (cURL: hostga ulanib bo'lmadi, timeout, HTTP/2 stream
 * reset) xabarida so'rov URL'i bo'ladi, URL ichida esa bot tokeni —
 * `https://api.telegram.org/bot<token>/getUpdates`. Bu xato `nutgram:run`
 * loopidan `docker compose logs bot` ga chiqadi. Middleware xabardagi
 * tokenni [REDACTED] ga almashtiradi, xato turini saqlab qoladi.
 *
 * Telegram API xatolari (chat not found va h.k.) Nutgram tomonidan
 * TelegramException sifatida beriladi va tokenni o'z ichiga olmaydi.
 */
final class RedactingBotClientHandler
{
    public static function stack(?callable $baseHandler = null): HandlerStack
    {
        $stack = HandlerStack::create($baseHandler);

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
