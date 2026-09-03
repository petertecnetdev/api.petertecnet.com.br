<?php

namespace Tests\Feature\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentIntent;
use App\Data\Payments\PaymentProviderResult;
use App\Services\Payments\PaymentGatewayManager;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    public function test_it_resolves_the_default_gateway_from_configuration(): void
    {
        config()->set('commerce.payments.default', 'fake');
        config()->set('commerce.payments.providers.fake', FakePaymentGateway::class);

        $gateway = app(PaymentGatewayManager::class)->default();

        $this->assertInstanceOf(FakePaymentGateway::class, $gateway);
        $this->assertSame('fake', $gateway->name());
        $this->assertSame(['pix'], $gateway->supportedMethods());
    }

    public function test_it_rejects_an_unknown_provider(): void
    {
        config()->set('commerce.payments.providers', []);

        $this->expectException(InvalidArgumentException::class);

        app(PaymentGatewayManager::class)->for('unknown');
    }
}

class FakePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportedMethods(): array
    {
        return ['pix'];
    }

    public function initiate(PaymentIntent $intent): PaymentProviderResult
    {
        return new PaymentProviderResult(
            providerPaymentId: 'fake-payment',
            status: 'pending',
            externalReference: $intent->externalReference,
        );
    }

    public function retrieve(?string $providerPaymentId, string $externalReference): ?PaymentProviderResult
    {
        return new PaymentProviderResult(
            providerPaymentId: $providerPaymentId,
            status: 'pending',
            externalReference: $externalReference,
        );
    }

    public function retrieveById(string $providerPaymentId): PaymentProviderResult
    {
        return new PaymentProviderResult(
            providerPaymentId: $providerPaymentId,
            status: 'paid',
        );
    }

    public function validateWebhook(array $headers, string $resourceId): bool
    {
        return true;
    }
}
