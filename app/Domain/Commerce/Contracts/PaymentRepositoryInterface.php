<?php

namespace App\Domain\Commerce\Contracts;

use App\Domain\Commerce\Data\PaymentData;

interface PaymentRepositoryInterface
{
    public function latestForOrder(int $applicationId, string|int $orderId): ?PaymentData;

    public function findByProviderReference(int $applicationId, string $provider, string $reference): ?PaymentData;

    public function markStatus(string|int $paymentId, string $status, array $metadata = []): PaymentData;
}
