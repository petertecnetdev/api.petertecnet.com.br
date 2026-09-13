<?php

namespace Tests\Unit;

use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MercadoPagoServiceTest extends TestCase
{
    public function test_it_retries_transient_payment_creation_with_the_same_idempotency_key(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'temporarily unavailable'], 503)
            ->push(['id' => 987654321, 'status' => 'pending'], 201);

        $service = app(MercadoPagoService::class);
        $result = $service->createPayment('seller-token', [
            'transaction_amount' => 42.50,
            'payment_method_id' => 'pix',
        ], 'commerce-payment-stable-key');

        $this->assertSame(987654321, $result['id']);
        $this->assertSame('pending', $result['status']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Idempotency-Key', 'commerce-payment-stable-key'));
    }

    public function test_it_does_not_retry_non_transient_payment_rejection(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response(['message' => 'invalid payment data'], 400),
        ]);

        $service = app(MercadoPagoService::class);

        try {
            $service->createPayment('seller-token', [
                'transaction_amount' => 42.50,
                'payment_method_id' => 'pix',
            ], 'commerce-payment-rejected-key');
            $this->fail('Expected provider rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Mercado Pago recusou', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

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
