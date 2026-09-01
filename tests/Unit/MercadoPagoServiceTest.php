<?php

namespace Tests\Unit;

use App\Services\MercadoPagoService;
use Tests\TestCase;

class MercadoPagoServiceTest extends TestCase
{
    public function test_it_validates_a_valid_webhook_signature(): void
    {
        config(['services.mercadopago.webhook_secret' => 'test-webhook-secret']);

        $dataId = '123456789';
        $requestId = 'request-abc';
        $timestamp = '1720000000';
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$timestamp};";
        $hash = hash_hmac('sha256', $manifest, 'test-webhook-secret');

        $service = app(MercadoPagoService::class);

        $this->assertTrue($service->validateWebhookSignature(
            "ts={$timestamp},v1={$hash}",
            $requestId,
            $dataId
        ));
    }

    public function test_it_rejects_a_tampered_webhook_signature(): void
    {
        config(['services.mercadopago.webhook_secret' => 'test-webhook-secret']);

        $service = app(MercadoPagoService::class);

        $this->assertFalse($service->validateWebhookSignature(
            'ts=1720000000,v1=invalid',
            'request-abc',
            '123456789'
        ));
    }

    public function test_it_rejects_webhook_when_secret_is_not_configured(): void
    {
        config(['services.mercadopago.webhook_secret' => null]);

        $service = app(MercadoPagoService::class);

        $this->assertFalse($service->validateWebhookSignature(
            'ts=1720000000,v1=anything',
            'request-abc',
            '123456789'
        ));
    }
}
