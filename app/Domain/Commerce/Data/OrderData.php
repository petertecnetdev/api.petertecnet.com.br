<?php

namespace App\Domain\Commerce\Data;

final readonly class OrderData
{
    public function __construct(
        public string|int $id,
        public int $applicationId,
        public ?string $publicId,
        public string $status,
        public string $currency,
        public float $subtotal,
        public float $total,
        public ?int $actorId = null,
        public ?string $tenantType = null,
        public int|string|null $tenantId = null,
        public ?string $contextType = null,
        public int|string|null $contextId = null,
        public array $metadata = [],
    ) {}
}
