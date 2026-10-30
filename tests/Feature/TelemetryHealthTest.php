<?php

namespace Tests\Feature;

use App\Events\EcosystemUpdated;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TelemetryHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_monitor_telemetry_health_and_reconstruct_a_journey(): void
    {
        Cache::flush();
        Event::fake([EcosystemUpdated::class]);

        $frontendVersion = (string) config('telemetry.frontend_version');

        $profile = Profile::create(['name' => 'Administrador', 'permissions' => []]);
        $admin = User::create([
            'first_name' => 'Admin',
            'email' => 'petertecnet@gmail.com',
            'user_name' => 'telemetry-admin',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $app = $this->applicationFixture('telemetry-health-app', [
            'name' => 'Telemetry Health App',
            'url' => 'https://telemetry-health-app.example',
            'is_active' => true,
        ]);
        $token = auth('api')->login($admin);
        $authorization = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/interactions/batch', [
            'session_id' => 'health-session-001',
            'events' => [
                [
                    'id' => 'health-session-start',
                    'type' => 'session_start',
                    'timestamp' => now()->subSeconds(5)->toIso8601String(),
                    'page' => '/events',
                    'label' => 'Sessão iniciada',
                    'metadata' => ['telemetry_schema' => '3', 'telemetry_version' => $frontendVersion],
                ],
                [
                    'id' => 'health-event-view',
                    'type' => 'event_viewed',
                    'timestamp' => now()->subSeconds(3)->toIso8601String(),
                    'page' => '/events/42',
                    'label' => 'Visualização de Event',
                    'target' => '/api/v1/apps/telemetry-health-app/events/42',
                    'metadata' => [
                        'outcome' => 'success',
                        'status' => 200,
                        'duration_ms' => 31,
                        'resource' => 'events',
                        'action' => 'view',
                        'entity_type' => 'event',
                        'entity_id' => 42,
                    ],
                ],
                [
                    'id' => 'health-screen-view',
                    'type' => 'screen_view',
                    'timestamp' => now()->subSecond()->toIso8601String(),
                    'page' => '/events/42',
                    'label' => 'Detalhes do evento',
                    'metadata' => ['screen' => 'detalhes_do_evento'],
                ],
            ],
        ], array_merge($authorization, [
            'X-Peter-App' => $app->slug,
            'Origin' => $app->url,
            'X-Telemetry-Schema' => '3',
            'X-Peter-Telemetry' => $frontendVersion,
        ]))->assertStatus(202)->assertJson(['accepted' => 3]);

        $health = $this->withHeaders($authorization)
            ->getJson('/api/admin/ecosystem/telemetry/health')
            ->assertOk()
            ->assertJsonPath('expected.schema', '3')
            ->assertJsonPath('expected.version', $frontendVersion);

        $healthApp = collect($health->json('applications'))->firstWhere('slug', $app->slug);
        $this->assertNotNull($healthApp);
        $this->assertSame('healthy', $healthApp['status']);
        $this->assertSame('3', $healthApp['latest_schema']);
        $this->assertSame($frontendVersion, $healthApp['latest_version']);
        $this->assertSame([], $healthApp['alerts']);
        $this->assertSame(3, $healthApp['events_24h']);

        $this->withHeaders($authorization)
            ->getJson('/api/admin/ecosystem/telemetry/journeys?limit=10')
            ->assertOk()
            ->assertJsonPath('journeys.0.session_id', 'health-session-001')
            ->assertJsonPath('journeys.0.events', 3)
            ->assertJsonPath('journeys.0.screens', 1);

        $this->withHeaders($authorization)
            ->getJson('/api/admin/ecosystem/telemetry/journeys/health-session-001')
            ->assertOk()
            ->assertJsonPath('journey.session_id', 'health-session-001')
            ->assertJsonPath('events.1.entity_type', 'Event')
            ->assertJsonPath('events.1.entity_id', 42)
            ->assertJsonPath('events.1.metadata.action', 'view');

        Event::assertDispatched(EcosystemUpdated::class, fn (EcosystemUpdated $event) => in_array('telemetry', $event->modules, true));

        // Uma versão antiga continua sinalizada, sem rebaixar a versão canônica esperada.
        $this->postJson('/api/interactions/batch', [
            'session_id' => 'health-session-outdated',
            'events' => [[
                'id' => 'health-sdk-outdated',
                'type' => 'session_start',
                'timestamp' => now()->toIso8601String(),
                'page' => '/events',
                'label' => 'SDK desatualizado',
                'metadata' => ['telemetry_schema' => '3', 'telemetry_version' => '0.0.1'],
            ]],
        ], array_merge($authorization, [
            'X-Peter-App' => $app->slug,
            'Origin' => $app->url,
            'X-Telemetry-Schema' => '3',
            'X-Peter-Telemetry' => '0.0.1',
        ]))->assertStatus(202)->assertJson(['accepted' => 1]);

        Cache::forget('admin:telemetry:health:v2');
        $outdatedHealth = $this->withHeaders($authorization)
            ->getJson('/api/admin/ecosystem/telemetry/health')
            ->assertOk()
            ->assertJsonPath('expected.version', $frontendVersion);
        $outdatedApp = collect($outdatedHealth->json('applications'))->firstWhere('slug', $app->slug);
        $this->assertNotNull($outdatedApp);
        $this->assertSame('warning', $outdatedApp['status']);
        $this->assertSame('0.0.1', $outdatedApp['latest_version']);
        $this->assertContains('sdk_outdated', array_column($outdatedApp['alerts'], 'code'));
    }
}
