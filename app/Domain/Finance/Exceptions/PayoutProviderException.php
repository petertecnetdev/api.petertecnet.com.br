<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;
use Throwable;

final class PayoutProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $outcomeUnknown,
        public readonly ?int $providerStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
