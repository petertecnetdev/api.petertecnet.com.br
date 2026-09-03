<?php

namespace App\Data\Payments;

final readonly class PaymentProviderResult
{
    public function __construct(
        public ?string $providerPaymentId,
        public string $status,
        public ?string $providerStatus = null,
        public float $providerFee = 0.0,
        public ?string $externalReference = null,
        public array $metadata = [],
    ) {}
}
