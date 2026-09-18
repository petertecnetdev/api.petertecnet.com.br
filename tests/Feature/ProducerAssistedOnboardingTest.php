<?php

namespace Tests\Feature;

use App\Mail\AcquisitionReferralMail;
use App\Models\Application;
use App\Models\Event;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProducerAssistedOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_assisted_producer_can_prepare_event_but_cannot_sell_until_contract_and_pix_are_ready(): void
    {
        Mail::fake();
        config()->set('platform.applications.cutinapp.commerce.allow_platform_collection', true);
        config()->set('platform.applications.cutinapp.commerce.require_payout_setup_before_sales', true);
        config()->set('services.mercadopago.access_token', 'platform-test-token');

        $application = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);
        $agent = $this->user('Agente', 'agent-assisted@example.test');
        $agent->applications()->attach($application->id, [
            'role' => 'producer',
            'status' => 'active',
            'metadata' => json_encode(['roles' => ['producer', 'acquisition_agent']]),
            'joined_at' => now(),
        ]);

        $agentToken = auth('api')->login($agent);
        $onboard = $this->withHeader('Authorization', 'Bearer '.$agentToken)
            ->postJson('/api/v1/apps/cutinapp/acquisition/onboardings', [
                'authorization' => [
                    'confirmed' => true,
                    'channel' => 'whatsapp',
                    'note' => 'Produtor autorizou o cadastro assistido.',
                ],
                'user' => [
                    'first_name' => 'Novo',
                    'last_name' => 'Produtor',
                    'email' => 'new-producer-assisted@example.test',
                ],
                'production' => [
                    'name' => 'Produção Assistida',
                    'description' => 'Produção cadastrada pelo agente.',
                ],
                'events' => [[
                    'title' => 'Primeiro Evento Assistido',
                    'description' => 'Evento inicial preparado pelo agente.',
                    'start_date' => now()->addDays(10)->format('Y-m-d H:i:s'),
                    'end_date' => now()->addDays(10)->addHours(4)->format('Y-m-d H:i:s'),
                    'event_format' => 'online',
                    'online_url' => 'https://example.test/evento',
                    'commission_percentage' => 5,
                    'tickets' => [[
                        'name' => 'Ingresso',
                        'quantity' => 100,
                        'price' => 25,
                        'ticket_type' => 'standard',
                    ]],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('email_sent', true);

        $productionId = (int) $onboard->json('referral.production.id');
        $event = Event::query()->where('production_id', $productionId)->firstOrFail();
        $this->assertFalse((bool) $event->is_published);

        $referralMetadata = json_decode((string) DB::table('acquisition_referrals')
            ->where('production_id', $productionId)
            ->value('metadata'), true);
        $this->assertSame('assisted', $referralMetadata['onboarding_mode'] ?? null);
        $this->assertSame('whatsapp', $referralMetadata['authorization_channel'] ?? null);
        $this->assertNotEmpty($referralMetadata['authorized_at'] ?? null);

        $mail = null;
        Mail::assertSent(AcquisitionReferralMail::class, function (AcquisitionReferralMail $message) use (&$mail) {
            if (! $message->hasTo('new-producer-assisted@example.test')) return false;
            $mail = $message;
            return true;
        });
        $this->assertNotNull($mail);
        parse_str((string) parse_url($mail->activationUrl, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/apps/cutinapp/acquisition/referrals/activate', [
            'token' => (string) ($query['ref'] ?? ''),
            'activation_code' => $mail->code,
            'password' => 'NovaSenha123!',
            'password_confirmation' => 'NovaSenha123!',
        ])->assertOk()
            ->assertJsonPath('onboarding_url', 'https://cutinapp.example.test/producer/onboarding?productionId='.$productionId);

        $producer = User::query()->where('email', 'new-producer-assisted@example.test')->firstOrFail();
        $producerToken = auth('api')->login($producer);
        $headers = ['Authorization' => 'Bearer '.$producerToken];

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/cutinapp/organizations/'.$productionId.'/onboarding')
            ->assertOk()
            ->assertJsonPath('onboarding.status', 'awaiting_agreement')
            ->assertJsonPath('onboarding.can_sell_tickets', false);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/events/'.$event->id.'/publish')
            ->assertStatus(428)
            ->assertJsonPath('message', 'Assine o contrato vigente da plataforma antes de iniciar as vendas.');

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/organizations/'.$productionId.'/agreement/sign', [
                'signer_name' => 'Novo Produtor',
                'signer_document' => '52998224725',
                'signer_role' => 'Responsável pela produção',
                'accepted' => true,
            ])
            ->assertOk()
            ->assertJsonPath('onboarding.status', 'awaiting_payout');

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/events/'.$event->id.'/publish')
            ->assertStatus(428)
            ->assertJsonPath('message', 'Conclua a verificação financeira e cadastre sua chave Pix antes de iniciar as vendas.');

        $this->verifyFinancialRecipient($producer, $productionId);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/cutinapp/organizations/'.$productionId.'/onboarding')
            ->assertOk()
            ->assertJsonPath('onboarding.status', 'ready_to_sell')
            ->assertJsonPath('onboarding.can_sell_tickets', true);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/events/'.$event->id.'/publish')
            ->assertOk()
            ->assertJsonPath('event.is_published', true);
    }

    public function test_agent_cannot_onboard_without_explicit_producer_authorization(): void
    {
        $application = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);
        $agent = $this->user('Agente', 'agent-no-authorization@example.test');
        $agent->applications()->attach($application->id, [
            'role' => 'producer',
            'status' => 'active',
            'metadata' => json_encode(['roles' => ['producer', 'acquisition_agent']]),
            'joined_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.auth('api')->login($agent))
            ->postJson('/api/v1/apps/cutinapp/acquisition/onboardings', [
                'user' => ['first_name' => 'Sem', 'email' => 'no-auth@example.test'],
                'production' => ['name' => 'Sem Autorização'],
                'events' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['authorization.confirmed', 'authorization.channel']);
    }

    private function verifyFinancialRecipient(User $producer, int $productionId): void
    {
        $cpf = '52998224725';
        $fingerprint = hash_hmac('sha256', $cpf, (string) config('app.key'));
        $beneficiaryId = DB::table('financial_beneficiaries')->insertGetId([
            'user_id' => $producer->id,
            'legal_name' => 'Novo Produtor',
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
            'pix_key_hash' => hash_hmac('sha256', 'CPF:'.$cpf, (string) config('app.key')),
            'pix_key_masked' => '***.982.247-**',
            'holder_name' => 'Novo Produtor',
            'holder_document_masked' => '***.982.247-**',
            'status' => 'active',
            'verified_at' => now(),
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(string $name, string $email): User
    {
        $profile = Profile::query()->firstOrCreate(['name' => 'Cliente'], ['permissions' => []]);

        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => str_replace('@example.test', '', $email),
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'email_verified_at' => now(),
        ]);
    }
}
