<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentIntent;
use App\Data\Payments\PaymentProviderResult;

interface PaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    public function supportedMethods(): array;

    public function initiate(PaymentIntent $intent): PaymentProviderResult;

    public function retrieve(?string $providerPaymentId, string $externalReference): ?PaymentProviderResult;

    public function retrieveById(string $providerPaymentId): PaymentProviderResult;

    public function validateWebhook(array $headers, string $resourceId): bool;
}
