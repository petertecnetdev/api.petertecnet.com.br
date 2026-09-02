<?php

namespace App\Domain\Commerce\Data;

final readonly class InventoryReservationData
{
    public function __construct(
        public string|int $id,
        public int $applicationId,
        public string $resourceType,
        public string|int $resourceId,
        public int $quantity,
        public string $status,
        public \DateTimeInterface $expiresAt,
        public ?string $tenantType = null,
        public string|int|null $tenantId = null,
        public ?string $orderType = null,
        public string|int|null $orderId = null,
    ) {}
}
