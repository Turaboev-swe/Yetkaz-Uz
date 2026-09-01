<?php

namespace App\Services\Dispatch;

final class DispatchResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $driver,
        public readonly ?string $message = null,
    ) {}

    public static function ok(string $driver, ?string $message = null): self
    {
        return new self(true, $driver, $message);
    }

    public static function skipped(string $driver, string $message): self
    {
        return new self(true, $driver, $message);
    }
}
