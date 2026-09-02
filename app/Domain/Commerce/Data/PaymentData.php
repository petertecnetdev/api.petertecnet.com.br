<?php

namespace App\Domain\Commerce\Data;

final readonly class PaymentData
{
    public function __construct(
        public string|int $id,
        public int $applicationId,
        public string|int $orderId,
        public string $provider,
        public string $method,
        public string $status,
        public float $amount,
        public string $currency = 'BRL',
        public ?string $providerPaymentId = null,
        public float $providerFee = 0.0,
        public float $platformFee = 0.0,
        public array $metadata = [],
    ) {}
}
