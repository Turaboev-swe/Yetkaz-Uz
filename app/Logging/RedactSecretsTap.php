<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Log kanaliga sir-yashirish processorini ulaydi (config/logging.php dagi `tap`).
 */
final class RedactSecretsTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new RedactSecretsProcessor());
    }
}
