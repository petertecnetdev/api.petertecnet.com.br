<?php

namespace Tests\Feature;

use App\Events\EcosystemUpdated;
use App\Models\Interaction;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SemanticTelemetryTest extends TestCase
{
    public function test_schema_three_accepts_semantic_events_and_preserves_outcomes(): void
    {
        Event::fake([EcosystemUpdated::class]);

        $app = $this->applicationFixture('telemetry-schema-test', [
            'name' => 'Telemetry Schema Test',
            'url' => 'https://telemetry-schema-test.example',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/interactions/batch', [
            'session_id' => 'schema-3-session',
            'events' => [
                [
                    'id' => 'schema-3-screen',
                    'type' => 'screen_view',
                    'timestamp' => now()->toIso8601String(),
                    'page' => '/portfolio',
                    'label' => 'Portfólio',
                    'target' => 'portfolio',
                    'metadata' => ['screen' => 'portfolio', 'previous_duration_ms' => 1200],
                ],
                [
                    'id' => 'schema-3-action',
                    'type' => 'risk_profile_saved',
                    'timestamp' => now()->toIso8601String(),
                    'page' => '/risk',
                    'label' => 'Política de risco atualizada',
                    'target' => '/v1/apps/telemetry-schema-test/risk-profile',
                    'metadata' => [
                        'outcome' => 'success',
                        'status' => 200,
                        'duration_ms' => 84,
                        'value' => 'must-not-be-stored',
                    ],
                ],
                [
                    'id' => 'schema-3-error',
                    'type' => 'portfolio_position_failed',
                    'timestamp' => now()->toIso8601String(),
                    'page' => '/portfolio',
                    'label' => 'Falha ao criar posição',
                    'target' => '/v1/apps/telemetry-schema-test/positions',
                    'metadata' => ['outcome' => 'error', 'status' => 422, 'duration_ms' => 101],
                ],
            ],
        ], [
            'X-Peter-App' => $app->slug,
            'Origin' => $app->url,
            'X-Telemetry-Schema' => '3',
        ]);

        $response->assertStatus(202)->assertJson(['accepted' => 3]);

        $screen = Interaction::query()->where('request_id', 'schema-3-screen')->firstOrFail();
        $action = Interaction::query()->where('request_id', 'schema-3-action')->firstOrFail();
        $failed = Interaction::query()->where('request_id', 'schema-3-error')->firstOrFail();

        $this->assertSame('frontend_screen_view', $screen->interaction_type);
        $this->assertSame('success', $action->outcome);
        $this->assertSame(200, $action->content['status']);
        $this->assertSame('[REDACTED]', $action->content['metadata']['value']);
        $this->assertArrayHasKey('ip_hash', $action->content);
        $this->assertNotEmpty($action->content['ip_hash']);
        $this->assertArrayNotHasKey('ip', $action->content);
        $this->assertSame('error', $failed->outcome);
        $this->assertSame('attention', $failed->severity);
        $this->assertSame(422, $failed->content['status']);
        $this->assertSame('3', $failed->content['telemetry_schema']);

        Event::assertDispatched(EcosystemUpdated::class);
    }

    public function test_semantic_event_type_must_use_safe_machine_name(): void
    {
        $app = $this->applicationFixture('telemetry-schema-invalid', [
            'url' => 'https://telemetry-schema-invalid.example',
            'is_active' => true,
        ]);

        $this->postJson('/api/interactions/batch', [
            'session_id' => 'invalid-schema-session',
            'events' => [[
                'id' => 'invalid-event',
                'type' => 'Risk/Profile Saved',
                'timestamp' => now()->toIso8601String(),
            ]],
        ], [
            'X-Peter-App' => $app->slug,
            'Origin' => $app->url,
        ])->assertStatus(422)->assertJsonValidationErrors('events.0.type');
    }
}
