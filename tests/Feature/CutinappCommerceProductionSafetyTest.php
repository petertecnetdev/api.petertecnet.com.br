<?php

namespace Tests\Feature;

use App\Domain\Commerce\Services\CommerceOrderPaymentRetryService;
use App\Models\Application;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappCommerceProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_treats_legacy_null_privacy_as_public(): void
    {
        [, $event, $ticket] = $this->paidEventFixture('legacy-null-privacy');

        // Bases novas usam NOT NULL, enquanto fichas antigas podiam ter NULL.
        // O modelo deve interpretar NULL como público sem tentar violar o schema.
        $legacy = \App\Models\Event::query()->findOrFail($event['id']);
        $legacy->setRawAttributes([...$legacy->getAttributes(), 'is_private' => null]);
        $this->assertFalse($legacy->salesClosed());

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('event.id', $event['id'])
            ->assertJsonPath('sales_closed', false)
            ->assertJsonPath('tickets.0.id', $ticket['id'])
            ->assertJsonPath('tickets.0.available', true);
    }

    public function test_paid_sales_use_platform_collection_before_producer_completes_payout_setup(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.finance.payment_primary_provider', 'mercadopago');
        config()->set('services.mercadopago.access_token', 'test-payment-token');
        config()->set('services.mercadopago.public_key', '');

        [, $event, $ticket] = $this->paidEventFixture('sales-before-payout');

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('payment_config.available', true)
            ->assertJsonPath('payment_config.producer_connected', false)
            ->assertJsonPath('payment_config.settlement_mode', 'platform_collection')
            ->assertJsonPath('payment_config.methods', ['pix', 'boleto'])
            ->assertJsonPath('payment_config.payout_ready', false)
            ->assertJsonPath('payment_config.payout_setup_required', true);

        $mercadoPago = Mockery::mock(MercadoPagoService::class);
        $mercadoPago->shouldReceive('createPayment')
            ->once()
            ->withArgs(function (string $token, array $payload): bool {
                $this->assertSame('test-payment-token', $token);
                $this->assertSame('pix', $payload['payment_method_id']);
                $this->assertSame('platform_collection', $payload['metadata']['settlement_mode']);
                $this->assertArrayNotHasKey('application_fee', $payload);
                return true;
            })
            ->andReturn([
                'id' => 24681012,
                'status' => 'pending',
                'fee_details' => [],
                'point_of_interaction' => [
                    'transaction_data' => [
                        'transaction_id' => 'pix-before-payout-setup',
                        'qr_code' => '000201-before-payout',
                        'qr_code_base64' => 'dGVzdA==',
                    ],
                ],
            ]);
        $this->app->instance(MercadoPagoService::class, $mercadoPago);

        $buyer = $this->user('Comprador', 'buyer-before-payout@cutinapp.test');
        $this->withHeaders($this->headersFor($buyer))
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertCreated()
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('payment.status', 'pending');

        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseMissing('financial_payout_destinations', ['source_id' => $event['production_id']]);
    }

    public function test_asaas_platform_collection_does_not_block_checkout_when_payout_destination_is_not_ready(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.finance.payment_primary_provider', 'asaas');
        config()->set('services.asaas.api_key', 'test-asaas-key');

        [, $event, ] = $this->paidEventFixture('asaas-sales-before-payout');

        $catalog = $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk();

        $catalog
            ->assertJsonPath('payment_config.available', true)
            ->assertJsonPath('payment_config.provider', 'asaas')
            ->assertJsonPath('payment_config.settlement_mode', 'platform_collection')
            ->assertJsonPath('payment_config.payout_ready', false)
            ->assertJsonPath('payment_config.payout_setup_required', true)
            ->assertJsonPath('payment_config.sales_readiness.ready', true)
            ->assertJsonPath('payment_config.sales_readiness.payout', false);
    }

    public function test_unconfigured_primary_asaas_never_silently_switches_to_mercadopago(): void
    {
        config()->set('services.finance.payment_primary_provider', 'asaas');
        config()->set('services.finance.payment_fallback_provider', '');
        config()->set('services.asaas.api_key', '');
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.mercadopago.access_token', 'configured-but-not-selected');

        [, $event] = $this->paidEventFixture('asaas-fail-closed');

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('payment_config.available', false)
            ->assertJsonPath('payment_config.provider', null)
            ->assertJsonPath('payment_config.methods', []);
    }

    public function test_pix_expiration_matches_inventory_reservation_and_uses_platform_collection_even_with_legacy_mercado_pago_account(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('platform.applications.cutinapp.commerce.order_expiration_minutes', 30);
        config()->set('platform.applications.cutinapp.commerce.platform_fee_percent', 8);
        config()->set('services.mercadopago.access_token', 'platform-access-token');

        [$producer, $event, $ticket, $productionId] = $this->paidEventFixture('pix-expiration');
        $this->verifyFinancialRecipient($producer, $productionId);
        $applicationId = Application::query()->where('slug', 'cutinapp')->value('id');

        // Regression guard: an old OAuth account must never reactivate seller split.
        DB::table('merchant_payment_accounts')->insert([
            'app_id' => $applicationId,
            'production_id' => $productionId,
            'provider' => 'mercadopago',
            'status' => 'connected',
            'provider_recipient_id' => 'legacy-seller-test',
            'access_token' => Crypt::encryptString('legacy-seller-access-token'),
            'refresh_token' => null,
            'token_expires_at' => null,
            'metadata' => json_encode(['public_key' => 'APP_USR-legacy-public-key'], JSON_THROW_ON_ERROR),
            'connected_at' => now(),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mercadoPago = Mockery::mock(MercadoPagoService::class);
        $mercadoPago->shouldReceive('createPayment')
            ->once()
            ->withArgs(function (string $token, array $payload, string $idempotencyKey): bool {
                $this->assertSame('platform-access-token', $token);
                $this->assertNotSame('', $idempotencyKey);
                $this->assertSame('pix', $payload['payment_method_id']);
                $this->assertSame('platform_collection', $payload['metadata']['settlement_mode']);
                $this->assertArrayNotHasKey('application_fee', $payload);
                $this->assertArrayHasKey('date_of_expiration', $payload);
                $expiration = \Carbon\Carbon::parse($payload['date_of_expiration']);
                $this->assertGreaterThan(now()->addMinutes(29), $expiration);
                $this->assertLessThanOrEqual(now()->addMinutes(31), $expiration);
                return true;
            })
            ->andReturn([
                'id' => 123456789,
                'status' => 'pending',
                'transaction_amount' => 20.0,
                'external_reference' => 'remote-placeholder',
                'fee_details' => [],
                'point_of_interaction' => [
                    'transaction_data' => [
                        'transaction_id' => 'pix-tx-production-safety',
                        'qr_code' => '000201-test-pix',
                        'qr_code_base64' => 'dGVzdA==',
                    ],
                ],
            ]);
        $this->app->instance(MercadoPagoService::class, $mercadoPago);

        $buyer = $this->user('Comprador PIX', 'buyer-pix@cutinapp.test');
        $response = $this->withHeaders($this->headersFor($buyer))
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ]);

        $this->assertSame(201, $response->status(), $response->getContent());
        $response
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('payment.status', 'pending');

        $orderId = $response->json('order.id');
        $orderExpiration = DB::table('commerce_orders')->where('id', $orderId)->value('expires_at');
        $reservationExpiration = DB::table('inventory_reservations')->where('order_id', $orderId)->value('expires_at');
        $orderMetadata = json_decode((string) DB::table('commerce_orders')->where('id', $orderId)->value('metadata'), true);

        $this->assertSame((string) $orderExpiration, (string) $reservationExpiration);
        $this->assertSame('platform_collection', $orderMetadata['settlement_mode'] ?? null);
        $this->assertDatabaseHas('merchant_payment_accounts', [
            'app_id' => $applicationId,
            'production_id' => $productionId,
            'status' => 'legacy_disabled',
        ]);
    }

    public function test_catalog_uses_platform_public_key_only_after_verified_pix_recipient(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.mercadopago.access_token', 'platform-access-token');
        config()->set('services.mercadopago.public_key', '');

        [$producer, $event, , $productionId] = $this->paidEventFixture('payment-methods');
        $this->verifyFinancialRecipient($producer, $productionId);

        $catalog = $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk();
        $this->assertTrue(
            (bool) $catalog->json('payment_config.available'),
            json_encode($catalog->json('payment_config'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $catalog
            ->assertJsonPath('payment_config.producer_connected', false)
            ->assertJsonPath('payment_config.settlement_mode', 'platform_collection')
            ->assertJsonPath('payment_config.methods', ['pix', 'boleto'])
            ->assertJsonPath('payment_config.public_key', '');

        config()->set('services.mercadopago.public_key', 'APP_USR-platform-public-key');

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('payment_config.available', true)
            ->assertJsonPath('payment_config.methods', ['pix', 'boleto', 'card'])
            ->assertJsonPath('payment_config.public_key', 'APP_USR-platform-public-key');
    }

    public function test_verified_recipient_is_not_enough_when_platform_collection_is_disabled(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', false);
        config()->set('services.mercadopago.access_token', 'platform-access-token');

        [$producer, $event, $ticket, $productionId] = $this->paidEventFixture('platform-disabled');
        $this->verifyFinancialRecipient($producer, $productionId);

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('payment_config.available', false)
            ->assertJsonPath('payment_config.methods', []);

        $buyer = $this->user('Comprador Plataforma Off', 'buyer-platform-off@cutinapp.test');
        $this->withHeaders($this->headersFor($buyer))
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A plataforma de pagamentos ainda não está habilitada para novas vendas.');
    }

    public function test_pix_provider_failure_preserves_order_and_retry_resumes_same_payment_intent(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('platform.applications.cutinapp.commerce.platform_fee_percent', 8);
        config()->set('services.mercadopago.access_token', 'platform-access-token');

        [$producer, $event, $ticket, $productionId] = $this->paidEventFixture('pix-resume');
        $this->verifyFinancialRecipient($producer, $productionId);

        $idempotencyKeys = [];
        $first = Mockery::mock(MercadoPagoService::class);
        $first->shouldReceive('createPayment')
            ->once()
            ->withArgs(function (string $token, array $payload, string $idempotencyKey) use (&$idempotencyKeys): bool {
                $idempotencyKeys[] = $idempotencyKey;
                $this->assertSame('platform-access-token', $token);
                $this->assertSame('pix', $payload['payment_method_id']);
                return true;
            })
            ->andThrow(new \RuntimeException('provider temporarily unavailable'));
        $this->app->instance(MercadoPagoService::class, $first);

        $buyer = $this->user('Comprador Retomada', 'buyer-resume@cutinapp.test');
        $headers = $this->headersFor($buyer);
        $failed = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertStatus(502)
            ->assertJsonPath('retryable', true);

        $publicId = (string) $failed->json('order_public_id');
        $order = DB::table('commerce_orders')->where('public_id', $publicId)->first();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->status);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'released_at' => null]);
        $this->assertDatabaseMissing('commerce_payments', ['order_id' => $order->id]);

        $second = Mockery::mock(MercadoPagoService::class);
        $second->shouldReceive('createPayment')
            ->once()
            ->withArgs(function (string $token, array $payload, string $idempotencyKey) use (&$idempotencyKeys): bool {
                $idempotencyKeys[] = $idempotencyKey;
                $this->assertSame('platform-access-token', $token);
                $this->assertSame('pix', $payload['payment_method_id']);
                return true;
            })
            ->andReturnUsing(static function (string $token, array $payload, string $idempotencyKey): array {
                return [
                    'id' => 987654321,
                    'status' => 'pending',
                    'external_reference' => $payload['external_reference'],
                    'payment_method_id' => $payload['payment_method_id'],
                    'transaction_amount' => $payload['transaction_amount'],
                    'metadata' => $payload['metadata'],
                    'fee_details' => [],
                    'point_of_interaction' => ['transaction_data' => [
                        'transaction_id' => 'pix-resumed-transaction',
                        'qr_code' => '000201-resumed-pix',
                        'qr_code_base64' => 'dGVzdA==',
                    ]],
                ];
            });
        $this->app->instance(MercadoPagoService::class, $second);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/commerce/orders/' . $publicId . '/payment/retry', ['payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.provider_payment_id', '987654321')
            ->assertJsonPath('payment.qr_code', '000201-resumed-pix');

        $this->assertCount(2, $idempotencyKeys);
        $this->assertSame($idempotencyKeys[0], $idempotencyKeys[1]);
        $this->assertSame('commerce-order-' . $publicId, $idempotencyKeys[0]);
        $this->assertDatabaseCount('commerce_payments', 1);
        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'released_at' => null]);
    }

    public function test_pix_retry_rejects_remote_payment_from_another_order_without_persisting_it(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('platform.applications.cutinapp.commerce.platform_fee_percent', 8);
        config()->set('services.mercadopago.access_token', 'platform-access-token');

        [$producer, $event, $ticket, $productionId] = $this->paidEventFixture('pix-cross-order-retry');
        $this->verifyFinancialRecipient($producer, $productionId);

        $first = Mockery::mock(MercadoPagoService::class);
        $first->shouldReceive('createPayment')->once()->andThrow(new \RuntimeException('timeout simulado'));
        $this->app->instance(MercadoPagoService::class, $first);

        $buyer = $this->user('Comprador Pix Isolado', 'buyer-pix-isolated@cutinapp.test');
        $headers = $this->headersFor($buyer);
        $failed = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertStatus(502)
            ->assertJsonPath('retryable', true);

        $publicId = (string) $failed->json('order_public_id');
        $orderId = DB::table('commerce_orders')->where('public_id', $publicId)->value('id');
        $this->assertNotNull($orderId);

        $second = Mockery::mock(MercadoPagoService::class);
        $second->shouldReceive('createPayment')->once()
            ->andReturnUsing(static function (string $token, array $payload, string $key): array {
                return [
                    'id' => 987654322,
                    'status' => 'pending',
                    'external_reference' => 'outro-pedido',
                    'payment_method_id' => $payload['payment_method_id'],
                    'transaction_amount' => $payload['transaction_amount'],
                    'metadata' => $payload['metadata'],
                ];
            });
        $this->app->instance(MercadoPagoService::class, $second);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/commerce/orders/' . $publicId . '/payment/retry', ['payment_method' => 'pix'])
            ->assertStatus(409);

        $this->assertDatabaseMissing('commerce_payments', ['order_id' => $orderId]);
        $this->assertDatabaseHas('commerce_orders', ['id' => $orderId, 'status' => 'pending']);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $orderId, 'released_at' => null]);
    }

    public function test_asaas_retry_with_existing_payment_and_inconsistent_customer_never_creates_customer(): void
    {
        config()->set('services.asaas.api_key', 'test-asaas-key');
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/customers')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($request->url(), '/payments/')) {
                return Http::response(['id' => 'pay_existing', 'customer' => null], 200);
            }
            return Http::response([
                'data' => [['id' => 'pay_existing', 'customer' => 'cus_existing', 'externalReference' => 'order-existing-asaas']],
                'hasMore' => false,
            ], 200);
        });

        $order = new \App\Models\CommerceOrder();
        $order->forceFill(['public_id' => 'order-existing-asaas', 'total' => 20.00]);
        $buyer = new User();
        $buyer->forceFill(['id' => 42, 'cpf' => '52998224725', 'email' => 'buyer@cutinapp.test']);

        $service = app(CommerceOrderPaymentRetryService::class);
        $method = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'retryAsaas');

        try {
            $method->invoke($service, $order, $buyer, ['payer_cpf_cnpj' => '52998224725'], 'pix', 1, []);
            $this->fail('GET com customer inconsistente deve bloquear a retomada.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_asaas_retry_provider_boundary_rejects_inconclusive_get_without_post(): void
    {
        config()->set('services.asaas.api_key', 'test-asaas-key');
        Http::fake(['*' => Http::response(['errors' => []], 503)]);

        $service = app(CommerceOrderPaymentRetryService::class);
        $method = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'findUniqueAsaasRecoveryCandidate');

        try {
            $method->invoke($service, 'pedido-teste-asaas');
            $this->fail('GET 503 não pode permitir criação de cobrança.');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_asaas_retry_provider_boundary_rejects_empty_get_without_post(): void
    {
        config()->set('services.asaas.api_key', 'test-asaas-key');
        Http::fake(['*' => Http::response(['data' => [], 'hasMore' => false], 200)]);

        $service = app(CommerceOrderPaymentRetryService::class);
        $method = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'findUniqueAsaasRecoveryCandidate');

        try {
            $method->invoke($service, 'pedido-teste-asaas');
            $this->fail('Busca vazia não autoriza nova cobrança sem handoff financeiro.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function verifyFinancialRecipient(User $producer, int $productionId): void
    {
        $cpf = '52998224725';
        $fingerprint = hash_hmac('sha256', $cpf, (string) config('app.key'));
        $beneficiaryId = DB::table('financial_beneficiaries')->insertGetId([
            'user_id' => $producer->id,
            'legal_name' => 'Produtor Teste Verificado',
            'document_type' => 'CPF',
            'document_number' => Crypt::encryptString($cpf),
            'document_number_hash' => $fingerprint,
            'birthdate' => '1990-01-01',
            'status' => 'verified',
            'verification_level' => 'document_face_liveness',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financial_payout_destinations')->insert([
            'beneficiary_id' => $beneficiaryId,
            'source_type' => 'production',
            'source_id' => $productionId,
            'provider' => 'asaas',
            'type' => 'pix',
            'pix_key_type' => 'CPF',
            'pix_key' => Crypt::encryptString($cpf),
            'pix_key_hash' => hash_hmac('sha256', 'CPF:' . $cpf, (string) config('app.key')),
            'pix_key_masked' => '***.982.247-**',
            'holder_name' => 'Produtor Teste Verificado',
            'holder_document_masked' => '***.982.247-**',
            'status' => 'active',
            'verified_at' => now(),
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_curatorial_source_without_extra_flags_blocks_http_checkout_and_passes(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.finance.payment_primary_provider', 'mercadopago');
        config()->set('services.mercadopago.access_token', 'test-payment-token');

        foreach (['event', 'production'] as $entity) {
            [, $event, $ticket, $productionId] = $this->paidEventFixture('curatorial-no-flags-' . $entity);

            // Simula importação incompleta: ownership e extra_info permanecem
            // nulos, mas a origem pública da ficha foi identificada.
            if ($entity === 'event') {
                DB::table('events')->where('id', $event['id'])->update(['listing_source' => 'curated']);
            } else {
                DB::table('establishments')->where('id', $productionId)->update(['listing_source' => 'curated']);
            }

            $buyer = $this->user('Comprador Curadoria', 'buyer-curatorial-' . $entity . '@cutinapp.test');
            $this->withHeaders($this->headersFor($buyer))
                ->postJson('/api/cutinapp/checkout', [
                    'event_id' => $event['id'],
                    'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                    'payment_method' => 'pix',
                ])
                ->assertStatus(403);

            $this->assertDatabaseMissing('commerce_orders', ['event_id' => $event['id']]);
            $this->assertDatabaseMissing('event_passes', ['event_id' => $event['id']]);
        }
    }

    public function test_informational_ticket_cannot_be_sold_even_when_legacy_event_is_verified(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.finance.payment_primary_provider', 'mercadopago');
        config()->set('services.mercadopago.access_token', 'test-payment-token');

        // A ficha do evento é legítima; o ingresso é apenas informativo.
        // Um preço conhecido não pode transformar esse ingresso em venda.
        [, $event, $ticket] = $this->paidEventFixture('informational-ticket-no-sale');
        DB::table('tickets')->where('id', $ticket['id'])->update([
            'curatorial_information_only' => true,
        ]);

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('tickets.0.id', $ticket['id'])
            ->assertJsonPath('tickets.0.informational_only', true)
            ->assertJsonPath('tickets.0.available', false);

        Http::fake();
        $buyer = $this->user('Comprador Informativo', 'buyer-informational-no-sale@cutinapp.test');
        $this->withHeaders($this->headersFor($buyer))
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('commerce_orders', ['event_id' => $event['id']]);
        $this->assertDatabaseCount('commerce_payments', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        Http::assertNothingSent();
    }

    public function test_curatorial_event_cannot_resume_existing_pending_pix_order_or_issue_pass(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.finance.payment_primary_provider', 'mercadopago');
        config()->set('services.mercadopago.access_token', 'test-payment-token');

        [, $event, $ticket] = $this->paidEventFixture('curatorial-retry-pending');
        $mercadoPago = Mockery::mock(MercadoPagoService::class);
        $mercadoPago->shouldReceive('createPayment')->once()->andReturn([
            'id' => 24681099,
            'status' => 'pending',
            'fee_details' => [],
            'point_of_interaction' => [
                'transaction_data' => [
                    'transaction_id' => 'pix-curatorial-retry',
                    'qr_code' => '000201-curatorial-retry',
                    'qr_code_base64' => 'dGVzdA==',
                ],
            ],
        ]);
        $this->app->instance(MercadoPagoService::class, $mercadoPago);

        $buyer = $this->user('Comprador Retomada Curatorial', 'buyer-curatorial-retry@cutinapp.test');
        $headers = $this->headersFor($buyer);
        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertCreated()
            ->assertJsonPath('order.status', 'pending');

        $order = DB::table('commerce_orders')->where('event_id', $event['id'])->first();
        $this->assertNotNull($order);
        $this->assertDatabaseHas('commerce_payments', ['order_id' => $order->id, 'status' => 'pending']);

        // O pedido era legítimo quando criado. Após identificar origem
        // curatorial, nem a retomada de um PIX pendente pode reabrir a venda.
        // Mantemos os demais marcadores legados para testar o fail-closed
        // exclusivamente pela origem curatorial recém-identificada.
        DB::table('events')->where('id', $event['id'])->update(['listing_source' => 'curated']);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/commerce/orders/' . $order->public_id . '/payment/retry', [
                'payment_method' => 'pix',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('commerce_payments', ['order_id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'released_at' => null]);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        // Mockery::once() impede um segundo POST financeiro durante o retry.
    }

    public function test_pix_retry_blocks_existing_pending_payment_when_ticket_becomes_informational(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.finance.payment_primary_provider', 'mercadopago');
        config()->set('services.mercadopago.access_token', 'test-payment-token');

        [, $event, $ticket] = $this->paidEventFixture('informational-ticket-retry');
        $mercadoPago = Mockery::mock(MercadoPagoService::class);
        $mercadoPago->shouldReceive('createPayment')->once()->andReturn([
            'id' => 24681100,
            'status' => 'pending',
            'fee_details' => [],
            'point_of_interaction' => [
                'transaction_data' => [
                    'transaction_id' => 'pix-informational-ticket-retry',
                    'qr_code' => '000201-informational-ticket-retry',
                    'qr_code_base64' => 'dGVzdA==',
                ],
            ],
        ]);
        $this->app->instance(MercadoPagoService::class, $mercadoPago);

        $buyer = $this->user('Comprador Retomada Informativa', 'buyer-ticket-retry@cutinapp.test');
        $headers = $this->headersFor($buyer);
        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.status', 'pending');

        $order = DB::table('commerce_orders')->where('event_id', $event['id'])->first();
        $this->assertNotNull($order);
        $this->assertDatabaseHas('commerce_payments', ['order_id' => $order->id, 'status' => 'pending']);

        // A alteração posterior é no ingresso, não na origem do evento.
        // Um PIX existente não deve ser reexibido como opção de compra.
        DB::table('tickets')->where('id', $ticket['id'])->update(['curatorial_information_only' => true]);
        Http::fake();

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/commerce/orders/' . $order->public_id . '/payment/retry', [
                'payment_method' => 'pix',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('commerce_payments', ['order_id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseCount('commerce_payments', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        Http::assertNothingSent();
    }

    public function test_liquidacao_tardia_informativa_nao_dispara_email_audiencia_nem_ledger_em_replay(): void
    {
        // O webhook Asaas tem verificação remota em suíte própria. Aqui o
        // contrato de liquidação garante que dinheiro recebido não vira venda.
        [, $event, $ticket, $productionId] = $this->paidEventFixture('settlement-mail-blocked');
        $buyer = $this->user('Comprador Liquidação Tardia', 'buyer-settlement-mail@cutinapp.test');
        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        \Illuminate\Support\Facades\Mail::fake();

        $order = \App\Models\CommerceOrder::create([
            'app_id' => $appId,
            'public_id' => 'pedido-liquidacao-tardia-mail-w1',
            'event_id' => $event['id'],
            'production_id' => $productionId,
            'user_id' => $buyer->id,
            'status' => 'pending',
            'currency' => 'BRL',
            'subtotal' => 20.00,
            'platform_fee' => 2.00,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 20.00,
            'producer_net' => 18.00,
            'payment_method' => 'pix',
            'metadata' => ['settlement_mode' => 'platform_collection'],
        ]);
        \App\Models\CommerceOrderItem::create([
            'app_id' => $appId,
            'order_id' => $order->id,
            'type' => 'ticket',
            'ticket_id' => $ticket['id'],
            'name' => 'Inteira',
            'unit_price' => 20.00,
            'quantity' => 1,
            'subtotal' => 20.00,
        ]);
        $payment = \App\Models\CommercePayment::create([
            'app_id' => $appId,
            'order_id' => $order->id,
            'provider' => 'asaas',
            'method' => 'pix',
            'status' => 'pending',
            'provider_payment_id' => 'pay-settlement-mail-w1',
            'idempotency_key' => 'settlement-mail-w1',
            'amount' => 20.00,
            'provider_fee' => 0,
        ]);

        // Ingresso era comercial quando o Pix foi emitido; depois tornou-se
        // somente informativo, antes de o provedor confirmar o recebimento.
        DB::table('tickets')->where('id', $ticket['id'])->update([
            'curatorial_information_only' => true,
        ]);

        $service = app(\App\Services\CommercePaymentSettlementService::class);
        $providerPayload = [
            'id' => 'pay-settlement-mail-w1',
            'externalReference' => $order->public_id,
            'value' => 20.00,
            'status' => 'RECEIVED',
        ];
        $service->confirm($payment, $providerPayload, 0.25);
        $service->confirm($payment->fresh(), $providerPayload, 0.25);

        $this->assertDatabaseHas('commerce_payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('commerce_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
        $metadata = \App\Models\CommerceOrder::findOrFail($order->id)->metadata;
        $this->assertSame('blocked', $metadata['fulfillment_status'] ?? null);
        $this->assertSame('required', $metadata['financial_reconciliation_status'] ?? null);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseMissing('interactions', [
            'entity_type' => 'CommerceOrder',
            'entity_id' => $order->id,
            'interaction_type' => 'payment_paid',
        ]);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }


    public function test_liquidacao_tardia_apos_reclassificar_apenas_producao_nao_vende_ingresso(): void
    {
        // Reclassificar somente a produção deve invalidar a liquidação,
        // mesmo quando evento e ingresso ainda parecem comercializáveis.
        [, $event, $ticket, $productionId] = $this->paidEventFixture('settlement-production-curated');
        $buyer = $this->user('Comprador Produção Curatorial', 'buyer-production-curated@cutinapp.test');
        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        \Illuminate\Support\Facades\Mail::fake();

        $order = \App\Models\CommerceOrder::create([
            'app_id' => $appId,
            'public_id' => 'pedido-producao-curatorial-w1',
            'event_id' => $event['id'],
            'production_id' => $productionId,
            'user_id' => $buyer->id,
            'status' => 'pending',
            'currency' => 'BRL',
            'subtotal' => 20.00,
            'platform_fee' => 2.00,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 20.00,
            'producer_net' => 18.00,
            'payment_method' => 'pix',
            'metadata' => ['settlement_mode' => 'platform_collection'],
        ]);
        \App\Models\CommerceOrderItem::create([
            'app_id' => $appId,
            'order_id' => $order->id,
            'type' => 'ticket',
            'ticket_id' => $ticket['id'],
            'name' => 'Inteira',
            'unit_price' => 20.00,
            'quantity' => 1,
            'subtotal' => 20.00,
        ]);
        $payment = \App\Models\CommercePayment::create([
            'app_id' => $appId,
            'order_id' => $order->id,
            'provider' => 'asaas',
            'method' => 'pix',
            'status' => 'pending',
            'provider_payment_id' => 'pay-production-curated-w1',
            'idempotency_key' => 'production-curated-w1',
            'amount' => 20.00,
            'provider_fee' => 0,
        ]);

        DB::table('establishments')->where('id', $productionId)->update([
            'listing_source' => 'curated',
        ]);
        $this->assertDatabaseHas('events', [
            'id' => $event['id'],
            'listing_source' => 'legacy_organizer',
        ]);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket['id'],
            'curatorial_information_only' => false,
        ]);

        $service = app(\App\Services\CommercePaymentSettlementService::class);
        $payload = [
            'id' => 'pay-production-curated-w1',
            'externalReference' => $order->public_id,
            'value' => 20.00,
            'status' => 'RECEIVED',
        ];
        $service->confirm($payment, $payload, 0.25);
        $service->confirm($payment->fresh(), $payload, 0.25);

        $this->assertDatabaseHas('commerce_payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('commerce_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
        $metadata = \App\Models\CommerceOrder::findOrFail($order->id)->metadata;
        $this->assertSame('blocked', $metadata['fulfillment_status'] ?? null);
        $this->assertSame('required', $metadata['financial_reconciliation_status'] ?? null);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseMissing('interactions', [
            'entity_type' => 'CommerceOrder',
            'entity_id' => $order->id,
            'interaction_type' => 'payment_paid',
        ]);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }

    public function test_liquidacao_tardia_com_evento_apenas_informativo_preserva_pagamento_sem_venda(): void
    {
        // O evento pode perder a autorização comercial via metadados de curadoria,
        // sem alterar a origem legada da produção ou a classificação do ingresso.
        [, $event, $ticket, $productionId] = $this->paidEventFixture('settlement-event-info-only');
        $buyer = $this->user('Comprador Evento Informativo', 'buyer-event-info@cutinapp.test');
        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        \Illuminate\Support\Facades\Mail::fake();

        $order = \App\Models\CommerceOrder::create([
            'app_id' => $appId,
            'public_id' => 'pedido-evento-informativo-w1',
            'event_id' => $event['id'],
            'production_id' => $productionId,
            'user_id' => $buyer->id,
            'status' => 'pending',
            'currency' => 'BRL',
            'subtotal' => 20.00,
            'platform_fee' => 2.00,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 20.00,
            'producer_net' => 18.00,
            'payment_method' => 'pix',
            'metadata' => ['settlement_mode' => 'platform_collection'],
        ]);
        \App\Models\CommerceOrderItem::create([
            'app_id' => $appId,
            'order_id' => $order->id,
            'type' => 'ticket',
            'ticket_id' => $ticket['id'],
            'name' => 'Inteira',
            'unit_price' => 20.00,
            'quantity' => 1,
            'subtotal' => 20.00,
        ]);
        $payment = \App\Models\CommercePayment::create([
            'app_id' => $appId,
            'order_id' => $order->id,
            'provider' => 'asaas',
            'method' => 'pix',
            'status' => 'pending',
            'provider_payment_id' => 'pay-event-info-only-w1',
            'idempotency_key' => 'event-info-only-w1',
            'amount' => 20.00,
            'provider_fee' => 0,
        ]);

        DB::table('events')->where('id', $event['id'])->update([
            'extra_info' => json_encode([
                'curation' => ['sale_mode' => 'information_only', 'ticket_checkout_enabled' => false],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertDatabaseHas('establishments', [
            'id' => $productionId,
            'listing_source' => 'legacy_organizer',
        ]);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket['id'],
            'curatorial_information_only' => false,
        ]);

        $payload = [
            'id' => 'pay-event-info-only-w1',
            'externalReference' => $order->public_id,
            'value' => 20.00,
            'status' => 'RECEIVED',
        ];
        $service = app(\App\Services\CommercePaymentSettlementService::class);
        $service->confirm($payment, $payload, 0.25);
        $firstPaidAt = \App\Models\CommercePayment::findOrFail($payment->id)->paid_at;
        $service->confirm($payment->fresh(), $payload, 0.25);

        $this->assertNotNull($firstPaidAt);
        $this->assertEquals($firstPaidAt, \App\Models\CommercePayment::findOrFail($payment->id)->paid_at);
        $this->assertDatabaseHas('commerce_payments', ['id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'cancelled']);
        $metadata = \App\Models\CommerceOrder::findOrFail($order->id)->metadata;
        $this->assertSame('blocked', $metadata['fulfillment_status'] ?? null);
        $this->assertSame('required', $metadata['financial_reconciliation_status'] ?? null);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseMissing('interactions', [
            'entity_type' => 'CommerceOrder',
            'entity_id' => $order->id,
            'interaction_type' => 'payment_paid',
        ]);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }

    private function paidEventFixture(string $suffix): array
    {
        $producer = $this->user('Produtor', "producer-{$suffix}@cutinapp.test");
        $headers = $this->headersFor($producer);

        $productionId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção ' . $suffix,
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production.id');

        $event = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Evento Pago ' . $suffix,
                'description' => 'Evento pago usado para validar segurança do checkout em produção.',
                'address' => 'Rua Produção, 100',
                'city' => 'São Paulo',
                'uf' => 'SP',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->json('event');

        $ticket = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/tickets', [
                'event_id' => $event['id'],
                'name' => 'Ingresso Inteira',
                'quantity' => 10,
                'price' => 20.00,
                'ticket_type' => 'full',
            ])
            ->assertCreated()
            ->json('ticket');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')
            ->assertOk();

        // Esta fixture representa um organizador legado com proveniência
        // identificada, não uma importação curatorial com campos nulos.
        // Autoria coincidente, isoladamente, não autoriza novas vendas.
        DB::table('establishments')->where('id', $productionId)->update([
            'listing_source' => 'legacy_organizer',
            'verification_status' => 'legacy_owner_record',
        ]);
        DB::table('events')->where('id', $event['id'])->update([
            'listing_source' => 'legacy_organizer',
            'verification_status' => 'legacy_owner_record',
        ]);

        return [$producer, $event, $ticket, $productionId];
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower($name) . '-' . substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
