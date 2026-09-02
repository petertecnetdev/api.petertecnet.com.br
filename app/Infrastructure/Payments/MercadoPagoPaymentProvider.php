<?php

namespace App\Infrastructure\Payments;

use App\Domain\Commerce\Contracts\PaymentProviderInterface;
use App\Services\MercadoPagoService;

final class MercadoPagoPaymentProvider implements PaymentProviderInterface
{
    public function __construct(private readonly MercadoPagoService $service) {}

    public function name(): string
    {
        return 'mercadopago';
    }

    public function create(array $payload, string $accessToken, string $idempotencyKey): array
    {
        return $this->service->createPayment($accessToken, $payload, $idempotencyKey);
    }

    public function find(string $providerPaymentId, string $accessToken): array
    {
        return $this->service->getPayment($accessToken, $providerPaymentId);
    }

    public function validateWebhook(?string $signature, ?string $requestId, ?string $dataId): bool
    {
        return $this->service->validateWebhookSignature($signature, $requestId, $dataId);
    }
}
