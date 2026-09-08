<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductionOnboardingContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_producer_onboarding_runs_from_minimal_production_to_first_ticket(): void
    {
        Mail::fake();
        $user = $this->user('Produtor Contrato', 'producer-contract@cutinapp.test');
        $headers = $this->headersFor($user);
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();

        $idempotencyKey = 'production-contract-'.str_repeat('a', 24);
        $minimalPayload = ['name' => 'Produção Contrato Canônico'];

        $created = $this->withHeaders(array_merge($headers, ['Idempotency-Key' => $idempotencyKey]))
            ->postJson('/api/v1/apps/cutinapp/organizations', $minimalPayload)
            ->assertCreated()
            ->assertHeader('Idempotency-Status', 'created')
            ->assertJsonPath('organization.name', 'Produção Contrato Canônico')
            ->assertJsonPath('organization.app_id', $application->id);

        $organizationId = (int) $created->json('organization.id');
        $this->assertGreaterThan(0, $organizationId);

        $this->withHeaders(array_merge($headers, ['Idempotency-Key' => $idempotencyKey]))
            ->postJson('/api/v1/apps/cutinapp/organizations', $minimalPayload)
            ->assertCreated()
            ->assertHeader('Idempotency-Status', 'replayed')
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('organization.id', $organizationId);

        $this->assertDatabaseCount('productions', 1);

        $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'agreement-'.str_repeat('b', 28)]))
            ->postJson("/api/v1/apps/cutinapp/organizations/{$organizationId}/agreement/sign", [
                'signer_name' => 'Produtor Contrato',
                'signer_document' => str_repeat('1', 11),
                'signer_role' => 'Responsável',
                'accepted' => true,
            ])
            ->assertOk()
            ->assertJsonPath('agreement.accepted', true);

        $eventPayload = [
            'production_id' => $organizationId,
            'title' => 'Evento Contrato Canônico',
            'description' => 'Evento criado pelo contrato de onboarding ponta a ponta.',
            'start_date' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'end_date' => now()->addDays(2)->addHours(4)->format('Y-m-d H:i:s'),
            'city' => 'Goiânia',
            'uf' => 'GO',
            'address' => 'Endereço de teste do evento',
        ];
        $event = $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'event-'.str_repeat('c', 32)]))
            ->postJson('/api/v1/apps/cutinapp/events', $eventPayload)
            ->assertCreated()
            ->assertJsonPath('event.title', 'Evento Contrato Canônico')
            ->assertJsonPath('event.production_id', $organizationId);

        $eventId = (int) $event->json('event.id');
        $this->assertGreaterThan(0, $eventId);

        $ticket = $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'ticket-'.str_repeat('d', 31)]))
            ->postJson('/api/v1/apps/cutinapp/tickets', [
                'event_id' => $eventId,
                'name' => 'Primeiro lote',
                'quantity' => 100,
                'price' => 0,
                'description' => 'Primeiro lote criado no onboarding.',
            ])
            ->assertCreated()
            ->assertJsonPath('ticket.event_id', $eventId)
            ->assertJsonPath('ticket.quantity', 100);

        $ticketId = (int) $ticket->json('ticket.id');
        $this->assertGreaterThan(0, $ticketId);

        $this->withHeaders($headers)
            ->getJson("/api/v1/apps/cutinapp/events/{$eventId}/tickets")
            ->assertOk()
            ->assertJsonPath('tickets.0.id', $ticketId);

        $this->assertDatabaseHas('productions', ['id' => $organizationId, 'user_id' => $user->id, 'app_id' => $application->id]);
        $this->assertDatabaseHas('events', ['id' => $eventId, 'production_id' => $organizationId]);
        $this->assertDatabaseHas('tickets', ['id' => $ticketId, 'event_id' => $eventId]);
    }

    public function test_minimal_production_contract_rejects_missing_name_but_not_optional_fields(): void
    {
        $user = $this->user('Produtor Minimal', 'producer-minimal@cutinapp.test');
        $headers = $this->headersFor($user);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/organizations', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/organizations', ['name' => 'Somente Nome'])
            ->assertCreated()
            ->assertJsonPath('organization.name', 'Somente Nome');
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
            'X-Frontend-Page' => '/production/create',
            'X-Request-ID' => 'test-request-'.substr(md5($user->email), 0, 16),
        ];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)).'-'.substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
