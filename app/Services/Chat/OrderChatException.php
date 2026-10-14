<?php

namespace App\Services\Chat;

use RuntimeException;

/** Chat amali rad etildi. `reason`: forbidden | closed | empty | too_long | throttled. */
class OrderChatException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__("messages.order_chat.errors.{$reason}"));
    }
}
