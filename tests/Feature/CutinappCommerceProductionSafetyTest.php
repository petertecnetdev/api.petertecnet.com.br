<?php

namespace Tests\Feature;

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

    public function test_paid_sales_are_disabled_until_producer_connects_mercado_pago(): void
    {
        config()->set('services.cutinapp.allow_platform_collection', false);

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
            ->assertJsonPath('message', 'As vendas pagas ainda não estão habilitadas. O produtor precisa conectar sua conta Mercado Pago.');

        $this->assertDatabaseCount('cutinapp_orders', 0);
        $this->assertDatabaseCount('cutinapp_inventory_reservations', 0);
    }

    public function test_pix_expiration_matches_inventory_reservation_and_uses_split(): void
    {
        config()->set('services.cutinapp.allow_platform_collection', false);
        config()->set('services.cutinapp.order_expiration_minutes', 30);
        config()->set('services.cutinapp.platform_fee_percent', 8);

        [, $event, $ticket, $productionId] = $this->paidEventFixture('pix-expiration');

        DB::table('cutinapp_producer_payment_accounts')->insert([
            'production_id' => $productionId,
            'provider' => 'mercadopago',
            'status' => 'connected',
            'provider_recipient_id' => 'seller-test',
            'access_token' => Crypt::encryptString('seller-access-token'),
            'refresh_token' => null,
            'token_expires_at' => null,
            'metadata' => json_encode(['public_key' => 'APP_USR-test-public-key'], JSON_THROW_ON_ERROR),
            'connected_at' => now(),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mercadoPago = Mockery::mock(MercadoPagoService::class);
        $mercadoPago->shouldReceive('createPayment')
            ->once()
            ->withArgs(function (string $token, array $payload, string $idempotencyKey): bool {
                $this->assertSame('seller-access-token', $token);
                $this->assertNotSame('', $idempotencyKey);
                $this->assertSame('pix', $payload['payment_method_id']);
                $this->assertSame('automatic_split', $payload['metadata']['settlement_mode']);
                $this->assertEqualsWithDelta(1.60, (float) $payload['application_fee'], 0.001);
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
            ])
            ->assertCreated()
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('payment.status', 'pending');

        $orderId = $response->json('order.id');
        $orderExpiration = DB::table('cutinapp_orders')->where('id', $orderId)->value('expires_at');
        $reservationExpiration = DB::table('cutinapp_inventory_reservations')->where('order_id', $orderId)->value('expires_at');
        $this->assertSame((string) $orderExpiration, (string) $reservationExpiration);
    }

    public function test_catalog_only_exposes_card_when_seller_public_key_exists(): void
    {
        config()->set('services.cutinapp.allow_platform_collection', false);
        [, $event, , $productionId] = $this->paidEventFixture('payment-methods');

        DB::table('cutinapp_producer_payment_accounts')->insert([
            'production_id' => $productionId,
            'provider' => 'mercadopago',
            'status' => 'connected',
            'provider_recipient_id' => 'seller-methods',
            'access_token' => Crypt::encryptString('seller-token'),
            'refresh_token' => null,
            'token_expires_at' => null,
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'connected_at' => now(),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/commerce')
            ->assertOk()
            ->assertJsonPath('payment_config.available', true)
            ->assertJsonPath('payment_config.methods', ['pix'])
            ->assertJsonPath('payment_config.public_key', '');
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
