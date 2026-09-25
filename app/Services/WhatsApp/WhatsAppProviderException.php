<?php

namespace App\Services\WhatsApp;

use RuntimeException;

class WhatsAppProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
