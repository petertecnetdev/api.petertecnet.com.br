<?php

namespace App\Domain\Commerce\Contracts;

interface PaymentProviderInterface
{
    public function name(): string;

    public function create(array $payload, string $accessToken, string $idempotencyKey): array;

    public function find(string $providerPaymentId, string $accessToken): array;

    public function validateWebhook(?string $signature, ?string $requestId, ?string $dataId): bool;
}
