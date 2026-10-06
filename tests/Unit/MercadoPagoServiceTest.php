<?php

namespace Tests\Unit;

use App\Services\MercadoPagoService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MercadoPagoServiceTest extends TestCase
{
    public function test_it_retries_transient_payment_creation_with_the_same_idempotency_key(): void
    {
        Http::fakeSequence()->push(['message' => 'temporarily unavailable'], 503)->push(['id' => 987654321, 'status' => 'pending'], 201);
        $result = app(MercadoPagoService::class)->createPayment('seller-token', ['transaction_amount' => 42.50, 'payment_method_id' => 'pix'], 'commerce-payment-stable-key');
        $this->assertSame(987654321, $result['id']);
        $this->assertSame('pending', $result['status']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Idempotency-Key', 'commerce-payment-stable-key'));
    }

    public function test_it_retries_request_timeout_payment_creation_with_the_same_idempotency_key(): void
    {
        Http::fakeSequence()->push(['message' => 'request timeout'], 408)->push(['id' => 987654322, 'status' => 'pending'], 201);
        $result = app(MercadoPagoService::class)->createPayment('seller-token', ['transaction_amount' => 31.90, 'payment_method_id' => 'pix'], 'commerce-payment-timeout-key');
        $this->assertSame(987654322, $result['id']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Idempotency-Key', 'commerce-payment-timeout-key'));
    }

    public function test_it_retries_too_early_payment_creation_with_the_same_idempotency_key(): void
    {
        Http::fakeSequence()->push(['message' => 'too early'], 425)->push(['id' => 987654323, 'status' => 'pending'], 201);
        $result = app(MercadoPagoService::class)->createPayment('seller-token', ['transaction_amount' => 28.00, 'payment_method_id' => 'pix'], 'commerce-payment-too-early-key');
        $this->assertSame(987654323, $result['id']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Idempotency-Key', 'commerce-payment-too-early-key'));
    }

    public function test_retry_delay_respects_numeric_retry_after_with_a_safe_cap(): void
    {
        $service = app(MercadoPagoService::class);
        $method = new \ReflectionMethod($service, 'paymentRetryDelayMs');
        $method->setAccessible(true);
        $this->assertSame(5000, $method->invoke($service, 1, $this->clientResponse(429, ['Retry-After' => '9'])));
    }

    public function test_retry_delay_understands_http_date_retry_after(): void
    {
        $service = app(MercadoPagoService::class);
        $method = new \ReflectionMethod($service, 'paymentRetryDelayMs');
        $method->setAccessible(true);
        $delay = $method->invoke($service, 1, $this->clientResponse(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3)]));
        $this->assertGreaterThanOrEqual(1000, $delay);
        $this->assertLessThanOrEqual(5000, $delay);
    }

    public function test_retry_delay_falls_back_when_retry_after_is_zero_or_invalid(): void
    {
        $service = app(MercadoPagoService::class);
        $method = new \ReflectionMethod($service, 'paymentRetryDelayMs');
        $method->setAccessible(true);
        $this->assertSame(250, $method->invoke($service, 1, $this->clientResponse(429, ['Retry-After' => '0'])));
        $this->assertSame(750, $method->invoke($service, 2, $this->clientResponse(503, ['Retry-After' => 'not-a-date'])));
    }

    public function test_it_does_not_retry_non_transient_payment_rejection(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['message' => 'invalid payment data'], 400)]);
        try {
            app(MercadoPagoService::class)->createPayment('seller-token', ['transaction_amount' => 42.50, 'payment_method_id' => 'pix'], 'commerce-payment-rejected-key');
            $this->fail('Expected provider rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Mercado Pago recusou', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_it_validates_a_valid_webhook_signature(): void
    {
        config(['services.mercadopago.webhook_secret' => 'test-webhook-secret']);
        $dataId = '123456789'; $requestId = 'request-abc'; $timestamp = '1720000000';
        $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$timestamp};", 'test-webhook-secret');
        $this->assertTrue(app(MercadoPagoService::class)->validateWebhookSignature("ts={$timestamp},v1={$hash}", $requestId, $dataId));
    }

    public function test_it_accepts_any_matching_signature_from_repeated_v1_headers(): void
    {
        config(['services.mercadopago.webhook_secret' => 'test-webhook-secret']);
        $dataId = '123456789'; $requestId = 'request-abc'; $timestamp = '1720000000';
        $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$timestamp};", 'test-webhook-secret');
        $this->assertTrue(app(MercadoPagoService::class)->validateWebhookSignature("ts={$timestamp},v1=invalid,v1={$hash}", $requestId, $dataId));
    }

    public function test_it_rejects_ambiguous_repeated_timestamps(): void
    {
        config(['services.mercadopago.webhook_secret' => 'test-webhook-secret']);
        $this->assertFalse(app(MercadoPagoService::class)->validateWebhookSignature('ts=1720000000,ts=1720000001,v1=anything', 'request-abc', '123456789'));
    }

    public function test_it_rejects_a_tampered_webhook_signature(): void
    {
        config(['services.mercadopago.webhook_secret' => 'test-webhook-secret']);
        $this->assertFalse(app(MercadoPagoService::class)->validateWebhookSignature('ts=1720000000,v1=invalid', 'request-abc', '123456789'));
    }

    public function test_it_rejects_webhook_when_secret_is_not_configured(): void
    {
        config(['services.mercadopago.webhook_secret' => null]);
        $this->assertFalse(app(MercadoPagoService::class)->validateWebhookSignature('ts=1720000000,v1=anything', 'request-abc', '123456789'));
    }

    private function clientResponse(int $status, array $headers = []): Response
    {
        return new Response(new Psr7Response($status, $headers));
    }
}
