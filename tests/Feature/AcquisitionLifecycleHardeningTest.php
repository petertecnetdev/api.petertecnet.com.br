<?php

namespace Tests\Feature;

use App\Mail\AcquisitionReferralMail;
use App\Models\AcquisitionReferral;
use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AcquisitionLifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_rejects_invalid_event_invariants(): void
    {
        $application = $this->application();
        [, $token] = $this->agent($application, 'validator-agent@example.test');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/apps/cutinapp/acquisition/onboardings', [
                'user' => [
                    'first_name' => 'Produtor',
                    'email' => 'invalid-event@example.test',
                ],
                'production' => [
                    'name' => 'Produção Inválida',
                ],
                'events' => [[
                    'title' => 'Evento Inválido',
                    'description' => 'Evento usado para validar invariantes.',
                    'start_date' => now()->addDays(10)->format('Y-m-d H:i:s'),
                    'end_date' => now()->addDays(9)->format('Y-m-d H:i:s'),
                    'event_format' => 'online',
                    'commission_percentage' => 10,
                    'tickets' => [[
                        'name' => 'Lote 1',
                        'quantity' => 10,
                        'price' => 20,
                    ]],
                ]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'events.0.end_date',
                'events.0.online_url',
            ]);

        $this->assertDatabaseMissing('productions', ['name' => 'Produção Inválida']);
    }

    public function test_blocked_membership_cannot_be_reactivated_by_invitation(): void
    {
        Mail::fake();
        $application = $this->application();
        [, $agentToken] = $this->agent($application, 'security-agent@example.test');

        $this->withHeader('Authorization', 'Bearer '.$agentToken)
            ->postJson(
                '/api/v1/apps/cutinapp/acquisition/onboardings',
                $this->validOnboardingPayload('blocked-producer@example.test', 'Produção Bloqueada')
            )
            ->assertCreated();

        $producer = User::query()->where('email', 'blocked-producer@example.test')->firstOrFail();
        $referral = AcquisitionReferral::query()->where('referred_user_id', $producer->id)->firstOrFail();
        $mail = $this->referralMailFor('blocked-producer@example.test');
        parse_str((string) parse_url($mail->activationUrl, PHP_URL_QUERY), $query);

        DB::table('application_user')
            ->where('application_id', $application->id)
            ->where('user_id', $producer->id)
            ->update(['status' => 'blocked']);

        $this->postJson('/api/v1/apps/cutinapp/acquisition/referrals/activate', [
            'token' => (string) ($query['ref'] ?? ''),
            'activation_code' => $mail->code,
            'password' => 'NovaSenha123!',
            'password_confirmation' => 'NovaSenha123!',
        ])->assertStatus(409);

        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $producer->id,
            'status' => 'blocked',
        ]);
        $this->assertDatabaseHas('acquisition_referrals', [
            'id' => $referral->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('productions', [
            'id' => $referral->production_id,
            'is_published' => false,
        ]);
    }

    public function test_direct_activation_marks_expired_referral_and_returns_gone(): void
    {
        Mail::fake();
        $application = $this->application();
        [, $agentToken] = $this->agent($application, 'expiry-agent@example.test');

        $this->withHeader('Authorization', 'Bearer '.$agentToken)
            ->postJson(
                '/api/v1/apps/cutinapp/acquisition/onboardings',
                $this->validOnboardingPayload('expired-producer@example.test', 'Produção Expirada')
            )
            ->assertCreated();

        $producer = User::query()->where('email', 'expired-producer@example.test')->firstOrFail();
        $referral = AcquisitionReferral::query()->where('referred_user_id', $producer->id)->firstOrFail();
        $referral->forceFill(['expires_at' => now()->subMinute()])->save();

        $mail = $this->referralMailFor('expired-producer@example.test');
        parse_str((string) parse_url($mail->activationUrl, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/apps/cutinapp/acquisition/referrals/activate', [
            'token' => (string) ($query['ref'] ?? ''),
            'activation_code' => $mail->code,
            'password' => 'NovaSenha123!',
            'password_confirmation' => 'NovaSenha123!',
        ])->assertStatus(410);

        $this->assertDatabaseHas('acquisition_referrals', [
            'id' => $referral->id,
            'status' => 'expired',
        ]);
    }

    public function test_dashboard_reconciles_expired_referral_and_agent_can_resend_it(): void
    {
        Mail::fake();
        $application = $this->application();
        [, $agentToken] = $this->agent($application, 'resend-agent@example.test');

        $this->withHeader('Authorization', 'Bearer '.$agentToken)
            ->postJson(
                '/api/v1/apps/cutinapp/acquisition/onboardings',
                $this->validOnboardingPayload('resend-producer@example.test', 'Produção Reenvio')
            )
            ->assertCreated();

        $producer = User::query()->where('email', 'resend-producer@example.test')->firstOrFail();
        $referral = AcquisitionReferral::query()->where('referred_user_id', $producer->id)->firstOrFail();
        $referral->forceFill([
            'expires_at' => now()->subMinute(),
            'status' => 'pending',
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$agentToken)
            ->getJson('/api/v1/apps/cutinapp/acquisition/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.referrals_pending', 0)
            ->assertJsonPath('recent_referrals.0.status', 'expired');

        $this->withHeader('Authorization', 'Bearer '.$agentToken)
            ->postJson('/api/v1/apps/cutinapp/acquisition/referrals/'.$referral->id.'/resend')
            ->assertOk()
            ->assertJsonPath('email_sent', true);

        $referral->refresh();
        $this->assertSame('pending', $referral->status);
        $this->assertTrue($referral->expires_at->isFuture());
    }

    private function application(): Application
    {
        return $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);
    }

    private function agent(Application $application, string $email): array
    {
        $agent = $this->user($email, 'Agente Comercial');
        $agent->applications()->attach($application->id, [
            'role' => 'producer',
            'status' => 'active',
            'metadata' => json_encode(['roles' => ['producer', 'acquisition_agent']]),
            'joined_at' => now(),
        ]);

        return [$agent, auth('api')->login($agent)];
    }

    private function validOnboardingPayload(string $email, string $productionName): array
    {
        return [
            'user' => [
                'first_name' => 'Produtor',
                'last_name' => 'Teste',
                'email' => $email,
            ],
            'production' => [
                'name' => $productionName,
                'description' => 'Produção criada em teste de aquisição.',
            ],
            'events' => [[
                'title' => 'Evento '.str_replace('@example.test', '', $email),
                'description' => 'Evento criado em teste de aquisição.',
                'start_date' => now()->addDays(10)->format('Y-m-d H:i:s'),
                'end_date' => now()->addDays(10)->addHours(4)->format('Y-m-d H:i:s'),
                'event_format' => 'online',
                'online_url' => 'https://example.test/live',
                'commission_percentage' => 10,
                'tickets' => [[
                    'name' => 'Lote 1',
                    'quantity' => 100,
                    'price' => 50,
                    'ticket_type' => 'standard',
                ]],
            ]],
        ];
    }

    private function referralMailFor(string $email): AcquisitionReferralMail
    {
        $mail = null;
        Mail::assertSent(AcquisitionReferralMail::class, function (AcquisitionReferralMail $message) use ($email, &$mail) {
            if (! $message->hasTo($email)) {
                return false;
            }

            $mail = $message;
            return true;
        });

        $this->assertNotNull($mail);

        return $mail;
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
