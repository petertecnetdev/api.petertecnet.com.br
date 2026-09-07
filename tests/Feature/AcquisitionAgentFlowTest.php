<?php

namespace Tests\Feature;

use App\Mail\AcquisitionReferralMail;
use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AcquisitionAgentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_onboard_producer_activate_invite_and_track_paid_commission(): void
    {
        Mail::fake();
        $application = $this->application();
        $agent = $this->user('agent@example.test', 'Agente Comercial');
        $agent->applications()->attach($application->id, [
            'role' => 'producer',
            'status' => 'active',
            'metadata' => json_encode(['roles' => ['producer', 'acquisition_agent']]),
            'joined_at' => now(),
        ]);
        $token = auth('api')->login($agent);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apps/cutinapp/acquisition/onboardings', [
                'user' => [
                    'first_name' => 'Produtor',
                    'last_name' => 'Teste',
                    'email' => 'produtor@example.test',
                ],
                'production' => [
                    'name' => 'Produção Teste',
                    'description' => 'Produção criada pelo agente para teste.',
                ],
                'events' => [[
                    'title' => 'Evento Online Teste',
                    'description' => 'Evento criado no onboarding.',
                    'start_date' => now()->addDays(10)->format('Y-m-d H:i:s'),
                    'end_date' => now()->addDays(10)->addHours(4)->format('Y-m-d H:i:s'),
                    'event_format' => 'online',
                    'online_url' => 'https://example.test/live',
                    'commission_percentage' => 5,
                    'tickets' => [[
                        'name' => 'Lote 1',
                        'quantity' => 100,
                        'price' => 50,
                        'ticket_type' => 'standard',
                    ]],
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('email_sent', true)
            ->assertJsonPath('referral.production.name', 'Produção Teste');

        $producer = User::query()->where('email', 'produtor@example.test')->firstOrFail();
        $event = Event::query()->where('title', 'Evento Online Teste')->firstOrFail();

        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $producer->id,
            'role' => 'producer',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('event_acquisition_commissions', [
            'application_id' => $application->id,
            'agent_user_id' => $agent->id,
            'event_id' => $event->id,
            'percentage' => 5.00,
        ]);
        $this->assertDatabaseHas('tickets', [
            'event_id' => $event->id,
            'name' => 'Lote 1',
            'quantity' => 100,
        ]);

        $mail = null;
        Mail::assertSent(AcquisitionReferralMail::class, function (AcquisitionReferralMail $message) use (&$mail) {
            $mail = $message;
            return $message->hasTo('produtor@example.test');
        });
        $this->assertNotNull($mail);

        parse_str((string) parse_url($mail->activationUrl, PHP_URL_QUERY), $query);
        $ref = (string) ($query['ref'] ?? '');
        $this->assertNotSame('', $ref);

        $this->getJson('/api/v1/apps/cutinapp/acquisition/referrals/public/'.$ref)
            ->assertOk()
            ->assertJsonPath('referral.email', 'produtor@example.test')
            ->assertJsonPath('referral.production.name', 'Produção Teste');

        $this->postJson('/api/v1/apps/cutinapp/acquisition/referrals/activate', [
            'token' => $ref,
            'activation_code' => $mail->code,
            'password' => 'NovaSenha123!',
            'password_confirmation' => 'NovaSenha123!',
        ])->assertOk();

        $producer->refresh();
        $this->assertNotNull($producer->email_verified_at);
        $this->assertTrue(Hash::check('NovaSenha123!', $producer->password));
        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $producer->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('acquisition_referrals', [
            'application_id' => $application->id,
            'agent_user_id' => $agent->id,
            'referred_user_id' => $producer->id,
            'status' => 'accepted',
        ]);

        CommerceOrder::create([
            'app_id' => $application->id,
            'public_id' => 'ORDER-PAID-1',
            'event_id' => $event->id,
            'production_id' => $event->production_id,
            'user_id' => $producer->id,
            'status' => 'paid',
            'currency' => 'BRL',
            'subtotal' => 200,
            'platform_fee' => 0,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 200,
            'producer_net' => 200,
            'paid_at' => now(),
        ]);
        CommerceOrder::create([
            'app_id' => $application->id,
            'public_id' => 'ORDER-PENDING-1',
            'event_id' => $event->id,
            'production_id' => $event->production_id,
            'user_id' => $producer->id,
            'status' => 'pending',
            'currency' => 'BRL',
            'subtotal' => 999,
            'platform_fee' => 0,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 999,
            'producer_net' => 999,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/apps/cutinapp/acquisition/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.referrals_accepted', 1)
            ->assertJsonPath('metrics.paid_orders', 1)
            ->assertJsonPath('metrics.gross_sales', 200)
            ->assertJsonPath('metrics.commission_amount', 10);
    }

    public function test_regular_application_user_cannot_access_agent_dashboard(): void
    {
        $application = $this->application();
        $user = $this->user('regular@example.test', 'Usuário Comum');
        $user->applications()->attach($application->id, [
            'role' => 'producer',
            'status' => 'active',
            'metadata' => json_encode(['roles' => ['producer']]),
            'joined_at' => now(),
        ]);
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/apps/cutinapp/acquisition/dashboard')
            ->assertForbidden();
    }

    public function test_assign_agent_command_preserves_global_profile_and_primary_application_role(): void
    {
        $application = $this->application();
        $user = $this->user('existing@example.test', 'Produtor Existente');
        $profileId = $user->profile_id;
        $user->applications()->attach($application->id, [
            'role' => 'producer',
            'status' => 'active',
            'metadata' => json_encode(['roles' => ['producer']]),
            'joined_at' => now(),
        ]);

        $this->artisan('acquisition:assign-agent', [
            'application' => 'cutinapp',
            'email' => 'existing@example.test',
        ])->assertSuccessful();

        $membership = $user->fresh()->applications()->whereKey($application->id)->firstOrFail()->pivot;
        $metadata = json_decode((string) $membership->metadata, true);

        $this->assertSame('producer', $membership->role);
        $this->assertSame($profileId, $user->fresh()->profile_id);
        $this->assertContains('acquisition_agent', $metadata['roles']);
    }

    private function application(): Application
    {
        return $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);
    }

    private function user(string $email, string $name): User
    {
        $profile = Profile::query()->firstOrCreate([
            'name' => 'Cliente',
        ], [
            'permissions' => [],
        ]);

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
