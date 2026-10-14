<?php

namespace App\Services\Chat;

use RuntimeException;

/** Chat amali rad etildi. `reason`: forbidden | closed | empty | too_long | throttled. */
class OrderChatException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::text($reason));
    }

    /** Foydalanuvchiga ko'rsatiladigan matn (joriy locale'da). "closed" — "Taklif va shikoyat" bo'limiga yo'naltiradi. */
    public static function text(string $reason): string
    {
        return (string) __("messages.order_chat.errors.{$reason}", [
            'section' => __('messages.main_menu.feedback'),
        ]);
    }
}
