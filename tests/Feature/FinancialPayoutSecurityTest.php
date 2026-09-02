<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AsaasPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class FinancialPayoutSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_flight_payout_reserves_balance_and_done_webhook_is_authenticated_and_idempotent(): void
    {
        config()->set('services.finance.payout_hold_hours', 0);
        config()->set('services.finance.payout_reserve_percent', 0);
        config()->set('services.finance.step_up_amount', 0);
        config()->set('services.asaas.webhook_token', 'webhook-secret-test-with-more-than-32-characters');
        config()->set('services.asaas.withdrawal_auth_token', 'withdrawal-secret-test-with-more-than-32-characters');

        [$producer, $productionId] = $this->productionFixture('reserve');
        $this->verifiedRecipient($producer, $productionId);
        $this->credit($productionId, 100.00);

        $asaas = Mockery::mock(AsaasPayoutService::class);
        $asaas->shouldReceive('transferPix')
            ->once()
            ->withArgs(function (string $reference, float $amount, string $key, string $type): bool {
                $this->assertNotSame('', $reference);
                $this->assertEqualsWithDelta(80.00, $amount, 0.001);
                $this->assertSame('52998224725', $key);
                $this->assertSame('CPF', $type);
                return true;
            })
            ->andReturn(['id' => 'transfer-security-1', 'status' => 'PENDING']);
        $this->app->instance(AsaasPayoutService::class, $asaas);

        $headers = $this->headersFor($producer);
        $response = $this->withHeaders($headers)
            ->postJson("/api/finance/productions/{$productionId}/payouts", ['amount' => 80])
            ->assertCreated()
            ->assertJsonPath('payout.status', 'processing')
            ->assertJsonPath('balance.available', 20);
        $reference = (string) $response->json('payout.reference');

        // The first request is already reserved even before the provider confirms it.
        $this->withHeaders($headers)
            ->postJson("/api/finance/productions/{$productionId}/payouts", ['amount' => 30])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'O valor solicitado é maior que o saldo disponível.');

        $this->assertDatabaseCount('financial_payouts', 1);
        $this->assertDatabaseHas('financial_payouts', [
            'provider_transfer_id' => 'transfer-security-1',
            'status' => 'processing',
        ]);

        $withdrawalValidation = [
            'type' => 'TRANSFER',
            'transfer' => [
                'id' => 'transfer-security-1',
                'status' => 'PENDING',
                'operationType' => 'PIX',
                'value' => 80.00,
                'externalReference' => $reference,
            ],
        ];

        $this->postJson('/api/finance/webhooks/asaas/withdrawal-validation', $withdrawalValidation)
            ->assertUnauthorized();

        $this->withHeader('asaas-access-token', 'withdrawal-secret-test-with-more-than-32-characters')
            ->postJson('/api/finance/webhooks/asaas/withdrawal-validation', $withdrawalValidation)
            ->assertOk()
            ->assertJsonPath('status', 'APPROVED');

        $unknownWithdrawal = $withdrawalValidation;
        $unknownWithdrawal['transfer']['id'] = 'transfer-not-created-by-peter';
        $this->withHeader('asaas-access-token', 'withdrawal-secret-test-with-more-than-32-characters')
            ->postJson('/api/finance/webhooks/asaas/withdrawal-validation', $unknownWithdrawal)
            ->assertOk()
            ->assertJsonPath('status', 'REFUSED');

        $webhook = [
            'id' => 'evt-transfer-done-1',
            'event' => 'TRANSFER_DONE',
            'transfer' => [
                'id' => 'transfer-security-1',
                'status' => 'DONE',
            ],
        ];

        $this->postJson('/api/finance/webhooks/asaas', $webhook)
            ->assertUnauthorized();

        $this->withHeader('asaas-access-token', 'webhook-secret-test-with-more-than-32-characters')
            ->postJson('/api/finance/webhooks/asaas', $webhook)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $paidAt = DB::table('financial_payouts')->where('provider_transfer_id', 'transfer-security-1')->value('paid_at');
        $this->assertNotNull($paidAt);
        $this->assertDatabaseHas('financial_payouts', [
            'provider_transfer_id' => 'transfer-security-1',
            'status' => 'paid',
        ]);

        // Replaying the same event must be a no-op.
        $this->withHeader('asaas-access-token', 'webhook-secret-test-with-more-than-32-characters')
            ->postJson('/api/finance/webhooks/asaas', $webhook)
            ->assertOk();

        $this->assertDatabaseCount('financial_webhook_events', 1);
        $this->assertSame(
            (string) $paidAt,
            (string) DB::table('financial_payouts')->where('provider_transfer_id', 'transfer-security-1')->value('paid_at')
        );
    }

    public function test_pix_destination_in_cooling_period_blocks_payout(): void
    {
        config()->set('services.finance.payout_hold_hours', 0);
        config()->set('services.finance.payout_reserve_percent', 0);
        config()->set('services.finance.step_up_amount', 0);

        [$producer, $productionId] = $this->productionFixture('cooling');
        $this->verifiedRecipient($producer, $productionId, 'cooling', now()->addHours(12));
        $this->credit($productionId, 100.00);

        $asaas = Mockery::mock(AsaasPayoutService::class);
        $asaas->shouldNotReceive('transferPix');
        $this->app->instance(AsaasPayoutService::class, $asaas);

        $this->withHeaders($this->headersFor($producer))
            ->postJson("/api/finance/productions/{$productionId}/payouts", ['amount' => 10])
            ->assertStatus(422)
            ->assertJsonPath('errors.pix_key.0', 'A nova chave Pix está em período de segurança. Aguarde a liberação indicada na tela.');

        $this->assertDatabaseCount('financial_payouts', 0);
    }

    public function test_pix_key_owned_by_different_document_is_rejected(): void
    {
        [$producer, $productionId] = $this->productionFixture('holder-mismatch');
        $this->verifiedBeneficiary($producer);

        $asaas = Mockery::mock(AsaasPayoutService::class);
        $asaas->shouldReceive('normalizePixKey')
            ->once()
            ->with('EMAIL', 'financeiro@terceiro.test')
            ->andReturn('financeiro@terceiro.test');
        $asaas->shouldReceive('lookupPixKey')
            ->once()
            ->with('EMAIL', 'financeiro@terceiro.test')
            ->andReturn([
                'name' => 'Terceiro Não Autorizado',
                'cpfCnpj' => '***.111.111-**',
                'financialInstitution' => 'Instituição Teste',
            ]);
        $this->app->instance(AsaasPayoutService::class, $asaas);

        $this->withHeaders($this->headersFor($producer))
            ->putJson("/api/finance/productions/{$productionId}/pix", [
                'pix_key_type' => 'EMAIL',
                'pix_key' => 'financeiro@terceiro.test',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.pix_key.0', 'A chave Pix informada não pertence ao CPF verificado deste produtor.');

        $this->assertDatabaseCount('financial_payout_destinations', 0);
    }

    private function productionFixture(string $suffix): array
    {
        $producer = $this->user('Produtor Financeiro', "producer-finance-{$suffix}@cutinapp.test");
        $productionId = $this->withHeaders($this->headersFor($producer))
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Financeira ' . $suffix,
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production.id');

        return [$producer, (int) $productionId];
    }

    private function verifiedBeneficiary(User $producer): int
    {
        $cpf = '52998224725';
        return DB::table('financial_beneficiaries')->insertGetId([
            'user_id' => $producer->id,
            'legal_name' => 'Produtor Financeiro Verificado',
            'document_type' => 'CPF',
            'document_number' => Crypt::encryptString($cpf),
            'document_number_hash' => hash_hmac('sha256', $cpf, (string) config('app.key')),
            'birthdate' => '1990-01-01',
            'status' => 'verified',
            'verification_level' => 'document_face_liveness',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function verifiedRecipient(User $producer, int $productionId, string $status = 'active', $coolingUntil = null): void
    {
        $cpf = '52998224725';
        $beneficiaryId = $this->verifiedBeneficiary($producer);

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
            'holder_name' => 'Produtor Financeiro Verificado',
            'holder_document_masked' => '***.982.247-**',
            'status' => $status,
            'verified_at' => now(),
            'cooling_until' => $coolingUntil,
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function credit(int $productionId, float $amount): void
    {
        DB::table('cutinapp_ledger_entries')->insert([
            'production_id' => $productionId,
            'type' => 'producer_credit',
            'status' => 'posted',
            'amount' => $amount,
            'description' => 'Crédito de teste para repasse Pix',
            'metadata' => json_encode([
                'provider' => 'mercadopago',
                'settlement_mode' => 'platform_collection',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
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
            'user_name' => 'finance-' . substr(md5($email), 0, 12),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
