<?php

namespace Tests\Feature;

use App\Models\EcosystemAuditLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCopilotApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_generic_copilot_capabilities(): void
    {
        [, $token] = $this->admin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/ecosystem/copilot/capabilities')
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('safety.destructive_voice_actions', false)
            ->assertJsonFragment(['key' => 'user.invite'])
            ->assertJsonFragment(['key' => 'establishment.create'])
            ->assertJsonFragment(['key' => 'item.create']);
    }

    public function test_preflight_resolves_compound_plan_without_persisting_virtual_dependencies(): void
    {
        [, $token] = $this->admin();
        $application = $this->applicationFixture('nexus', ['name' => 'Nexus', 'is_active' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/ecosystem/copilot/preflight', [
                'actions' => [
                    [
                        'key' => 'user.invite',
                        'payload' => [
                            'name' => 'João Silva',
                            'email' => 'joao.copilot@example.test',
                            'application' => 'Nexus',
                        ],
                    ],
                    [
                        'key' => 'establishment.create',
                        'payload' => [
                            'name' => 'Oficina do João',
                            'owner' => 'joao.copilot@example.test',
                            'application' => 'Nexus',
                        ],
                    ],
                    [
                        'key' => 'item.create',
                        'payload' => [
                            'name' => 'Revisão',
                            'establishment' => 'Oficina do João',
                            'application' => 'Nexus',
                            'price' => 120,
                            'type' => 'service',
                        ],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('compound', true)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('actions.0.payload.application', $application->id)
            ->assertJsonPath('actions.1.resolved.owner.virtual', true)
            ->assertJsonPath('actions.2.resolved.establishment.virtual', true);

        $this->assertDatabaseMissing('users', ['email' => 'joao.copilot@example.test']);
        $this->assertDatabaseMissing('establishments', ['name' => 'Oficina do João']);
        $this->assertDatabaseMissing('items', ['name' => 'Revisão']);
        $this->assertCount(3, $response->json('actions'));
    }

    public function test_onboarding_dry_run_validates_complete_plan_without_writes_or_invitation(): void
    {
        [, $token] = $this->admin();
        $application = $this->applicationFixture('nexus', ['name' => 'Nexus', 'is_active' => true]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/ecosystem/onboarding', [
                'email' => 'dryrun.copilot@example.test',
                'app_id' => $application->id,
                'dry_run' => true,
                'user' => [
                    'first_name' => 'Dry',
                    'last_name' => 'Run',
                ],
                'establishment' => [
                    'name' => 'Empresa Simulada',
                    'city' => 'Linhares',
                    'uf' => 'ES',
                ],
                'items' => [[
                    'name' => 'Serviço Simulado',
                    'type' => 'service',
                    'price' => 99.90,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('items_count', 1);

        $this->assertDatabaseMissing('users', ['email' => 'dryrun.copilot@example.test']);
        $this->assertDatabaseMissing('establishments', ['name' => 'Empresa Simulada']);
        $this->assertDatabaseMissing('items', ['name' => 'Serviço Simulado']);
        $this->assertDatabaseCount('user_invitations', 0);
    }

    public function test_copilot_audit_redacts_credentials_and_tokens(): void
    {
        [$admin, $token] = $this->admin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/ecosystem/copilot/audit', [
                'status' => 'success',
                'session_id' => 'copilot-test-session',
                'transcript' => 'Cadastre usuário seguro',
                'plan' => [[
                    'key' => 'user.invite',
                    'payload' => [
                        'email' => 'safe@example.test',
                        'password' => 'NaoPodePersistir123!',
                        'token' => 'token-secreto',
                    ],
                ]],
                'result' => [
                    'verification_code' => '123456',
                    'temporary_password' => 'OutraSenha123!',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('logged', true);

        $log = EcosystemAuditLog::query()
            ->where('user_id', $admin->id)
            ->where('action', 'admin_copilot.success')
            ->latest('id')
            ->firstOrFail();

        $serialized = json_encode($log->after, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('NaoPodePersistir123!', $serialized);
        $this->assertStringNotContainsString('token-secreto', $serialized);
        $this->assertStringNotContainsString('123456', $serialized);
        $this->assertStringNotContainsString('OutraSenha123!', $serialized);
        $this->assertStringContainsString('[REDACTED]', $serialized);
    }

    private function admin(): array
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => ['user_create', 'application_manage'],
        ]);
        $user = User::create([
            'first_name' => 'Admin',
            'email' => 'admin-copilot@example.test',
            'user_name' => 'admin-copilot',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        return [$user, auth('api')->login($user)];
    }
}
