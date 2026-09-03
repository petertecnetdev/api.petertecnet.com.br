<?php

namespace App\Domain\Finance\Data;

final readonly class PaymentRecordData
{
    public function __construct(
        public int $applicationId,
        public string $applicationSlug,
        public string $sourceType,
        public string $sourceReference,
        public ?int $sourceId,
        public ?int $userId,
        public string $currency,
        public string $method,
        public string $status,
        public float $grossAmount,
        public float $platformFee = 0.0,
        public float $providerFee = 0.0,
        public ?float $sellerNet = null,
        public ?string $provider = null,
        public ?string $providerPaymentId = null,
        public ?int $productionId = null,
        public ?int $establishmentId = null,
        public array $metadata = [],
    ) {
    }
}
