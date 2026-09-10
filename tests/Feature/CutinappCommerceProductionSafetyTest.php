<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappCommerceProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_treats_legacy_null_privacy_as_public(): void
    {
        [, $event, $ticket] = $this->paidEventFixture('legacy-null-privacy');

        DB::table('events')
            ->where('id', $event['id'])
            ->update(['is_private' => null]);

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('event.id', $event['id'])
            ->assertJsonPath('sales_closed', false)
            ->assertJsonPath('tickets.0.id', $ticket['id'])
            ->assertJsonPath('tickets.0.available', true);
    }

    public function test_paid_sales_are_disabled_until_producer_has_verified_pix_recipient(): void
    {
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('services.mercadopago.access_token', 'platform-access-token');

        [, $event, $ticket] = $this->paidEventFixture('sales-disabled');

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('payment_config.available', false)
            ->assertJsonPath('payment_config.producer_connected', false)
            ->assertJsonPath('payment_config.settlement_mode', 'sales_disabled')
            ->assertJsonPath('payment_config.methods', []);

        $buyer = $this->user('Comprador', 'buyer-disabled@cutinapp.test');
        $this->withHeaders($this->headersFor($buyer))
            ->postJson('/api/cutinapp/checkout', [
                'event_id' => $event['id'],
                'tickets' => [['id' => $ticket['id'], 'quantity' => 1]],
                'payment_method' => 'pix',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta organização ainda não ativou os recebimentos. O responsável precisa verificar a identidade e cadastrar uma chave Pix.');

        $this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
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
            ->assertJsonPath('payment_config.methods', ['pix'])
            ->assertJsonPath('payment_config.public_key', '');

        config()->set('services.mercadopago.public_key', 'APP_USR-platform-public-key');

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('payment_config.available', true)
            ->assertJsonPath('payment_config.methods', ['pix', 'card'])
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
            ->assertJsonPath('message', 'Os recebimentos desta organização estão verificados, mas a plataforma de pagamentos ainda não está habilitada.');
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
