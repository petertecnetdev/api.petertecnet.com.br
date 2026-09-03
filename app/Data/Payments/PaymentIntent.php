<?php

namespace App\Data\Payments;

final readonly class PaymentIntent
{
    public function __construct(
        public string $method,
        public float $amount,
        public string $currency,
        public string $description,
        public string $externalReference,
        public string $notificationUrl,
        public string $returnUrl,
        public string $idempotencyKey,
        public string $payerEmail,
        public string $payerFirstName = '',
        public string $payerLastName = '',
        public array $items = [],
        public array $metadata = [],
    ) {}
}
