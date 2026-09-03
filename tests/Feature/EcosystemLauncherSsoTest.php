<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcosystemLauncherSsoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $profile = Profile::create(['name' => 'Cliente', 'permissions' => []]);
        $this->user = User::create([
            'first_name' => 'Conta',
            'last_name' => 'Ecossistema',
            'email' => 'ecosystem@example.test',
            'user_name' => 'ecosystem-user',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $this->token = auth('api')->login($this->user);
    }

    public function test_ecosystem_returns_only_visible_apps_in_launcher_order(): void
    {
        $later = $this->application('Rasoio', 'rasoio', 30);
        $first = $this->application('Nexus', 'nexus', 10, status: 'degraded');
        $hidden = $this->application('Interna', 'interna', 1, visible: false);

        $this->user->applications()->attach([$later->id, $first->id, $hidden->id], [
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->getJson('/api/account/ecosystem');

        $response->assertOk()
            ->assertJsonPath('data.sdk.version', '2.0.0')
            ->assertJsonPath('data.applications.0.slug', 'nexus')
            ->assertJsonPath('data.applications.0.operational_status', 'degraded')
            ->assertJsonPath('data.applications.1.slug', 'rasoio')
            ->assertJsonMissing(['slug' => 'interna']);
    }

    public function test_handoff_is_one_time_and_does_not_return_jwt_before_exchange(): void
    {
        $source = $this->application('Nexus', 'nexus', 10);
        $destination = $this->application('Cutinapp', 'cutinapp', 20);
        $this->user->applications()->attach([$source->id, $destination->id], [
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $handoff = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->postJson('/api/account/sso/handoff', ['application' => 'cutinapp']);

        $handoff->assertOk()
            ->assertJsonPath('data.application.slug', 'cutinapp')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.token');

        $code = $handoff->json('data.handoff_code');
        $this->assertIsString($code);
        $this->assertSame(64, strlen($code));

        $exchange = $this->withHeader('X-Peter-App', 'cutinapp')
            ->postJson('/api/account/sso/exchange', [
                'handoff_code' => $code,
                'application' => 'cutinapp',
            ]);

        $exchange->assertOk()
            ->assertJsonPath('data.application.slug', 'cutinapp')
            ->assertJsonStructure(['data' => ['access_token']]);

        $this->withHeader('X-Peter-App', 'cutinapp')
            ->postJson('/api/account/sso/exchange', [
                'handoff_code' => $code,
                'application' => 'cutinapp',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SSO_HANDOFF_INVALID');
    }

    public function test_account_can_navigate_nexus_to_cutinapp_to_rasoio_without_reauthentication(): void
    {
        $nexus = $this->application('Nexus', 'nexus', 10);
        $cutinapp = $this->application('Cutinapp', 'cutinapp', 20);
        $rasoio = $this->application('Rasoio', 'rasoio', 30);
        $this->user->applications()->attach([$nexus->id, $cutinapp->id, $rasoio->id], [
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $firstHandoff = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->postJson('/api/account/sso/handoff', ['application' => 'cutinapp']);
        $firstHandoff->assertOk();

        $cutinappSession = $this->withHeader('X-Peter-App', 'cutinapp')
            ->postJson('/api/account/sso/exchange', [
                'handoff_code' => $firstHandoff->json('data.handoff_code'),
                'application' => 'cutinapp',
            ]);
        $cutinappSession->assertOk()
            ->assertJsonPath('data.user.id', $this->user->id);

        $secondHandoff = $this->withHeaders([
            'Authorization' => 'Bearer ' . $cutinappSession->json('data.access_token'),
            'X-Peter-App' => 'cutinapp',
        ])->postJson('/api/account/sso/handoff', ['application' => 'rasoio']);
        $secondHandoff->assertOk()
            ->assertJsonPath('data.application.slug', 'rasoio')
            ->assertJsonMissingPath('data.access_token');

        $rasoioSession = $this->withHeader('X-Peter-App', 'rasoio')
            ->postJson('/api/account/sso/exchange', [
                'handoff_code' => $secondHandoff->json('data.handoff_code'),
                'application' => 'rasoio',
            ]);
        $rasoioSession->assertOk()
            ->assertJsonPath('data.application.slug', 'rasoio')
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_launcher_handoff_refuses_application_in_maintenance(): void
    {
        $destination = $this->application(
            'Plat',
            'plat',
            20,
            status: 'maintenance',
            message: 'Atualização programada em andamento.'
        );
        $this->user->applications()->attach($destination->id, [
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Peter-App' => 'nexus',
        ])->postJson('/api/account/sso/handoff', ['application' => 'plat'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Atualização programada em andamento.');
    }

    private function application(
        string $name,
        string $slug,
        int $order,
        bool $visible = true,
        string $status = 'operational',
        ?string $message = null
    ): Application {
        $application = Application::query()->firstOrNew(['slug' => $slug]);
        $application->fill([
            'name' => $name,
            'url' => "https://{$slug}.petertecnet.com.br",
            'logo' => "https://{$slug}.petertecnet.com.br/logo.png",
            'is_active' => true,
            'is_visible' => $visible,
            'launcher_order' => $order,
            'operational_status' => $status,
            'maintenance_message' => $message,
            'ecosystem_sdk_version' => '2.0.0',
        ]);
        $application->save();

        return $application;
    }
}
