<?php

namespace App\Domain\Commerce\Contracts;

use App\Domain\Commerce\Data\InventoryReservationData;

interface InventoryRepositoryInterface
{
    public function reserve(array $attributes): InventoryReservationData;

    public function reservedQuantity(int $applicationId, string $resourceType, string|int $resourceId): int;

    public function releaseForOrder(int $applicationId, string $orderType, string|int $orderId): int;
}
