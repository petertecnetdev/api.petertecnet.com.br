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
                return Http::response(['data' => [], 'hasMore' => false], 200);
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
                return Http::response(['data' => [], 'hasMore' => false], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/payments')) {
                return Http::response([
                    'id' => 'pay_asaas_pix_1',
                    'customer' => 'cus_asaas_123',
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

    public function test_inconclusive_customer_get_never_creates_customer(): void
    {
        foreach ([Http::response(['errors' => []], 503), Http::response(['data' => []], 200)] as $reply) {
            Http::fake(['*' => $reply]);
            try {
                app(AsaasPaymentService::class)->findOrCreateCustomer(44, 'Comprador', '12345678901');
                $this->fail('GET inconclusivo não autoriza POST /customers.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        }
    }

    public function test_timeout_customer_get_never_creates_customer(): void
    {
        Http::fake(function (Request $request) {
            throw new \Illuminate\Http\Client\ConnectionException('Timeout GET /customers');
        });
        try {
            app(AsaasPaymentService::class)->findOrCreateCustomer(44, 'Comprador', '12345678901');
            $this->fail('Timeout não autoriza POST.');
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_inconclusive_payment_get_never_creates_payment(): void
    {
        foreach ([Http::response(['errors' => []], 503), Http::response('invalid-json', 200)] as $reply) {
            Http::fake(['*' => $reply]);
            try {
                app(AsaasPaymentService::class)->createOrRecoverPayment(
                    'cus_123', 'pix', 20.00, '2026-10-30', 'ORDER-FAIL', 'Teste'
                );
                $this->fail('GET inconclusivo não autoriza POST /payments.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        }
    }

    public function test_payment_has_more_is_exhausted_before_reconciliation(): void
    {
        $first = array_map(
            static fn (int $i): array => ['id' => 'pay_' . $i, 'externalReference' => 'ORDER-PAGED'],
            range(1, 20)
        );
        Http::fake(function (Request $request) use ($first) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return Http::response((int) ($query['offset'] ?? -1) === 0
                ? ['data' => $first, 'hasMore' => true]
                : ['data' => [['id' => 'pay_21', 'externalReference' => 'ORDER-PAGED']], 'hasMore' => false], 200);
        });

        $payments = app(AsaasPaymentService::class)->listPaymentsByExternalReference('ORDER-PAGED');
        $this->assertCount(21, $payments);
        $this->assertSame('pay_21', $payments[20]['id']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_duplicate_between_pages_blocks_payment_creation(): void
    {
        $first = array_map(
            static fn (int $i): array => ['id' => 'pay_' . $i, 'externalReference' => 'ORDER-DUP'],
            range(1, 20)
        );
        Http::fake(function (Request $request) use ($first) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return Http::response((int) ($query['offset'] ?? -1) === 0
                ? ['data' => $first, 'hasMore' => true]
                : ['data' => [['id' => 'pay_1', 'externalReference' => 'ORDER-DUP']], 'hasMore' => false], 200);
        });

        try {
            app(AsaasPaymentService::class)->createOrRecoverPayment(
                'cus_123', 'pix', 20.00, '2026-10-30', 'ORDER-DUP', 'Teste'
            );
            $this->fail('Duplicidade entre páginas não pode iniciar cobrança.');
        } catch (\RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_malformed_deleted_flag_blocks_duplicate_payment_creation(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [[
                'id' => 'pay_ambiguous_deleted',
                'externalReference' => 'ORDER-DELETED-AMBIGUOUS',
                'deleted' => 'false',
                'billingType' => 'PIX',
                'status' => 'PENDING',
            ]],
            'hasMore' => false,
        ], 200)]);

        try {
            app(AsaasPaymentService::class)->createOrRecoverPayment(
                'cus_123', 'pix', 20.00, '2026-10-30', 'ORDER-DELETED-AMBIGUOUS', 'Teste'
            );
            $this->fail('Indicador deleted malformado não pode permitir novo POST /payments.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('exclusão Asaas inconclusivo', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_boolean_false_deleted_flag_recovers_existing_payment_without_post(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [[
                'id' => 'pay_active_boolean',
                'externalReference' => 'ORDER-DELETED-BOOLEAN',
                'customer' => 'cus_123',
                'value' => 20.00,
                'deleted' => false,
                'billingType' => 'CREDIT_CARD',
                'status' => 'PENDING',
            ]],
            'hasMore' => false,
        ], 200)]);

        $payment = app(AsaasPaymentService::class)->createOrRecoverPayment(
            'cus_123', 'card', 20.00, '2026-10-30', 'ORDER-DELETED-BOOLEAN', 'Teste'
        );

        $this->assertSame('pay_active_boolean', $payment['id']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_existing_payment_is_recovered_without_post(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [['id' => 'pay_existing', 'externalReference' => 'ORDER-EXIST',
                'customer' => 'cus_123', 'value' => 20.00,
                'billingType' => 'CREDIT_CARD', 'status' => 'PENDING']],
            'hasMore' => false,
        ], 200)]);
        $payment = app(AsaasPaymentService::class)->createOrRecoverPayment(
            'cus_123', 'card', 20.00, '2026-10-30', 'ORDER-EXIST', 'Teste'
        );
        $this->assertSame('pay_existing', $payment['id']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_recovered_charge_with_wrong_customer_method_or_amount_is_blocked_without_post(): void
    {
        foreach ([
            ['customer' => 'cus_other'],
            ['billingType' => 'BOLETO'],
            ['value' => 29.99],
        ] as $divergence) {
            Http::fake(['*' => Http::response([
                'data' => [array_merge([
                    'id' => 'pay_mismatch',
                    'externalReference' => 'ORDER-IDENTITY',
                    'customer' => 'cus_expected',
                    'billingType' => 'PIX',
                    'value' => 20.00,
                    'status' => 'PENDING',
                ], $divergence)],
                'hasMore' => false,
            ], 200)]);

            try {
                app(AsaasPaymentService::class)->createOrRecoverPayment(
                    'cus_expected', 'pix', 20.00, '2026-10-30', 'ORDER-IDENTITY', 'Teste'
                );
                $this->fail('Cobrança remota de outro pagador, modalidade ou valor não pode ser reutilizada.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('divergente', $exception->getMessage());
            }

            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        }
    }

    public function test_successful_post_with_wrong_remote_amount_is_rejected(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => [], 'hasMore' => false], 200);
            }

            return Http::response([
                'id' => 'pay_wrong_value',
                'externalReference' => 'ORDER-POST-MISMATCH',
                'customer' => 'cus_expected',
                'billingType' => 'PIX',
                'value' => 99.99,
                'status' => 'PENDING',
            ], 201);
        });

        try {
            app(AsaasPaymentService::class)->createOrRecoverPayment(
                'cus_expected', 'pix', 20.00, '2026-10-30', 'ORDER-POST-MISMATCH', 'Teste'
            );
            $this->fail('Retorno remoto com valor divergente não pode ser aceito como cobrança do pedido.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('divergente', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_recovered_charge_with_matching_customer_method_and_amount_is_reused(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [[
                'id' => 'pay_verified',
                'externalReference' => 'ORDER-VERIFIED',
                'customer' => 'cus_expected',
                'billingType' => 'PIX',
                'value' => 20.00,
                'status' => 'PENDING',
            ]],
            'hasMore' => false,
        ], 200)]);

        $payment = app(AsaasPaymentService::class)->createOrRecoverPayment(
            'cus_expected', 'pix', 20.00, '2026-10-30', 'ORDER-VERIFIED', 'Teste'
        );

        $this->assertSame('pay_verified', $payment['id']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_missing_remote_payment_identity_blocks_get_recovery_without_post(): void
    {
        foreach (['customer', 'billingType', 'value'] as $missingField) {
            $payment = [
                'id' => 'pay_incomplete',
                'externalReference' => 'ORDER-INCOMPLETE-GET',
                'customer' => 'cus_expected',
                'billingType' => 'PIX',
                'value' => 20.00,
                'status' => 'PENDING',
            ];
            unset($payment[$missingField]);

            Http::fake(['*' => Http::response([
                'data' => [$payment],
                'hasMore' => false,
            ], 200)]);

            try {
                app(AsaasPaymentService::class)->createOrRecoverPayment(
                    'cus_expected', 'pix', 20.00, '2026-10-30', 'ORDER-INCOMPLETE-GET', 'Teste'
                );
                $this->fail('Campo remoto ausente não pode autorizar reutilização nem POST.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('ausente', $exception->getMessage());
            }

            Http::assertSentCount(1);
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        }
    }

    public function test_missing_remote_payment_identity_blocks_post_response(): void
    {
        foreach (['customer', 'billingType', 'value'] as $missingField) {
            $payment = [
                'id' => 'pay_incomplete_post',
                'externalReference' => 'ORDER-INCOMPLETE-POST',
                'customer' => 'cus_expected',
                'billingType' => 'PIX',
                'value' => 20.00,
                'status' => 'PENDING',
            ];
            unset($payment[$missingField]);

            Http::fake(function (Request $request) use ($payment) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => [], 'hasMore' => false], 200);
                }

                return Http::response($payment, 201);
            });

            try {
                app(AsaasPaymentService::class)->createOrRecoverPayment(
                    'cus_expected', 'pix', 20.00, '2026-10-30', 'ORDER-INCOMPLETE-POST', 'Teste'
                );
                $this->fail('POST com identidade remota incompleta não pode ser aceito.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('ausente', $exception->getMessage());
            }

            Http::assertSentCount(2);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && str_ends_with($request->url(), '/payments'));
        }
    }


    public function test_post_with_missing_or_divergent_remote_reference_is_rejected_without_retry(): void
    {
        foreach ([null, 'ORDER-OTHER'] as $remoteReference) {
            $payment = [
                'id' => 'pay_wrong_reference',
                'customer' => 'cus_expected',
                'billingType' => 'PIX',
                'value' => 20.00,
                'status' => 'PENDING',
            ];
            if ($remoteReference !== null) {
                $payment['externalReference'] = $remoteReference;
            }

            Http::fake(function (Request $request) use ($payment) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => [], 'hasMore' => false], 200);
                }

                return Http::response($payment, 201);
            });

            try {
                app(AsaasPaymentService::class)->createOrRecoverPayment(
                    'cus_expected', 'pix', 20.00, '2026-10-30', 'ORDER-EXPECTED', 'Teste'
                );
                $this->fail('POST com referência remota ausente ou divergente não pode ser associado ao pedido.');
            } catch (\\RuntimeException $exception) {
                $this->assertStringContainsString('Referência da cobrança Asaas', $exception->getMessage());
            }

            Http::assertSentCount(2);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && str_ends_with($request->url(), '/payments'));
        }
    }
}
