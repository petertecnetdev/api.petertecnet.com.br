<?php

namespace Tests\Unit;

use App\Services\AsaasPaymentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsaasPaymentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.asaas.base_url', 'https://api.asaas.test/v3');
        config()->set('services.asaas.api_key', '$aact_test_unit_key');
        config()->set('services.asaas.timeout', 20);
    }

    public function test_it_creates_customer_without_exposing_credentials_in_payload(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/customers')) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/customers')) {
                return Http::response([
                    'id' => 'cus_asaas_123',
                    'name' => 'Comprador Teste',
                    'cpfCnpj' => '12345678901',
                ], 201);
            }

            return Http::response([], 404);
        });

        $service = app(AsaasPaymentService::class);
        $customer = $service->findOrCreateCustomer(
            44,
            'Comprador Teste',
            '123.456.789-01',
            'buyer@example.test'
        );

        $this->assertSame('cus_asaas_123', $customer['id']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/customers')) {
                return false;
            }

            $payload = $request->data();

            return $request->hasHeader('access_token', '$aact_test_unit_key')
                && ($payload['cpfCnpj'] ?? null) === '12345678901'
                && ($payload['externalReference'] ?? null) === 'petertecnet-user-44'
                && ! array_key_exists('access_token', $payload);
        });
    }

    public function test_it_creates_pix_charge_and_fetches_qr_code(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/payments?')) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/payments')) {
                return Http::response([
                    'id' => 'pay_asaas_pix_1',
                    'status' => 'PENDING',
                    'billingType' => 'PIX',
                    'value' => 25.50,
                    'externalReference' => 'ORDER-123',
                ], 201);
            }

            if ($request->method() === 'GET' && str_ends_with($request->url(), '/payments/pay_asaas_pix_1/pixQrCode')) {
                return Http::response([
                    'encodedImage' => 'aW1hZ2U=',
                    'payload' => '000201PIXTEST',
                    'expirationDate' => '2026-09-18 23:59:59',
                ], 200);
            }

            return Http::response([], 404);
        });

        $service = app(AsaasPaymentService::class);
        $payment = $service->createOrRecoverPayment(
            'cus_asaas_123',
            'pix',
            25.50,
            '2026-09-18',
            'ORDER-123',
            'Cutinapp - Teste',
            'https://cutinapp.example/checkout/teste'
        );

        $this->assertSame('pay_asaas_pix_1', $payment['id']);
        $this->assertSame('000201PIXTEST', data_get($payment, '_pix.payload'));

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/payments')) {
                return false;
            }

            $payload = $request->data();

            return ($payload['billingType'] ?? null) === 'PIX'
                && ($payload['externalReference'] ?? null) === 'ORDER-123'
                && data_get($payload, 'callback.autoRedirect') === true;
        });
    }

    public function test_status_normalization_waits_for_pix_received_but_accepts_card_confirmed(): void
    {
        $service = app(AsaasPaymentService::class);

        $this->assertSame('pending', $service->normalizeLocalStatus([
            'status' => 'CONFIRMED',
            'billingType' => 'PIX',
        ]));

        $this->assertSame('paid', $service->normalizeLocalStatus([
            'status' => 'RECEIVED',
            'billingType' => 'PIX',
        ]));

        $this->assertSame('paid', $service->normalizeLocalStatus([
            'status' => 'CONFIRMED',
            'billingType' => 'CREDIT_CARD',
        ]));

        $this->assertSame('charged_back', $service->normalizeLocalStatus([
            'status' => 'CHARGEBACK_REQUESTED',
            'billingType' => 'CREDIT_CARD',
        ]));
    }
}
