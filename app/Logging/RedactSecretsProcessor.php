<?php

namespace App\Logging;

use App\Support\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Har bir log yozuvida (message + context + extra) sirlarni yashiradi (PROD-4).
 * Kanallarga `tap` orqali ulanadi — qarang RedactSecretsTap.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretRedactor::text($record->message),
            context: SecretRedactor::array($record->context),
            extra: SecretRedactor::array($record->extra),
        );
    }
}
