<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappFreeEventFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_event_can_run_from_production_to_duplicate_checkin_block(): void
    {
        $producer = $this->user('Produtor', 'producer@cutinapp.test');
        $producerHeaders = $this->headersFor($producer);

        $productionResponse = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Teste Cutinapp',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->assertJsonPath('production.app_slug', 'cutinapp')
            ->assertJsonPath('production.user_id', $producer->id)
            ->assertJsonPath('production.uf', 'SP');

        $productionId = $productionResponse->json('production.id');
        $this->assertNotNull($productionId);
        $this->assertDatabaseHas('productions', [
            'id' => $productionId,
            'user_id' => $producer->id,
            'app_slug' => 'cutinapp',
            'uf' => 'SP',
        ]);

        $eventResponse = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Festa Gratuita de Teste',
                'description' => 'Evento gratuito usado para validar o fluxo completo da Cutinapp.',
                'address' => 'Rua do Teste, 100',
                'venue' => 'Espaço Teste',
                'city' => 'São Paulo',
                'uf' => 'SP',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->assertJsonPath('event.app_slug', 'cutinapp')
            ->assertJsonPath('event.is_published', false);

        $eventId = $eventResponse->json('event.id');
        $eventSlug = $eventResponse->json('event.slug');

        $this->withHeaders($producerHeaders)
            ->postJson("/api/cutinapp/events/{$eventId}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Crie ao menos um ingresso disponível antes de publicar o evento.');

        $courtesyResponse = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Cortesia Geral',
                'quantity' => 50,
                'description' => 'Entrada gratuita.',
            ])
            ->assertCreated();

        $ticketId = $courtesyResponse->json('ticket.id');

        $this->withHeaders($producerHeaders)
            ->postJson("/api/cutinapp/events/{$eventId}/publish")
            ->assertOk()
            ->assertJsonPath('event.is_published', true);

        $this->getJson("/api/cutinapp/events/public/{$eventSlug}")
            ->assertOk()
            ->assertJsonPath('event.id', $eventId)
            ->assertJsonPath('tickets.0.id', $ticketId)
            ->assertJsonPath('tickets.0.remaining', 50)
            ->assertJsonPath('tickets.0.available', true);

        $participant = $this->user('Participante', 'participant@cutinapp.test');
        $participantHeaders = $this->headersFor($participant);

        $claimResponse = $this->withHeaders($participantHeaders)
            ->postJson("/api/cutinapp/passes/claim/{$ticketId}")
            ->assertCreated()
            ->assertJsonPath('already_issued', false);

        $passId = $claimResponse->json('pass.id');
        $qrToken = $claimResponse->json('pass.token');
        $this->assertStringStartsWith('CUT-', $qrToken);

        $this->withHeaders($participantHeaders)
            ->postJson("/api/cutinapp/passes/claim/{$ticketId}")
            ->assertOk()
            ->assertJsonPath('already_issued', true)
            ->assertJsonPath('pass.id', $passId);

        $this->assertDatabaseCount('event_passes', 1);

        $this->withHeaders($participantHeaders)
            ->getJson("/api/cutinapp/passes/{$passId}")
            ->assertOk()
            ->assertJsonPath('pass.token', $qrToken);

        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/checkin', [
                'event_id' => $eventId,
                'token' => $qrToken,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Entrada validada com sucesso.');

        $this->assertDatabaseHas('event_passes', [
            'id' => $passId,
            'status' => 'checked_in',
            'checked_in_by' => $producer->id,
        ]);

        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/checkin', [
                'event_id' => $eventId,
                'token' => $qrToken,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este ingresso já foi utilizado anteriormente.');
    }

    public function test_checkin_rejects_ticket_from_another_event(): void
    {
        $producer = $this->user('Produtor', 'producer-two@cutinapp.test');
        $headers = $this->headersFor($producer);

        $productionId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', ['name' => 'Produção B'])
            ->assertCreated()
            ->json('production.id');

        $eventA = $this->createPublishedEventWithCourtesy($headers, $productionId, 'Evento A');
        $eventB = $this->createPublishedEventWithCourtesy($headers, $productionId, 'Evento B');

        $participant = $this->user('Participante', 'participant-two@cutinapp.test');
        $participantHeaders = $this->headersFor($participant);
        $token = $this->withHeaders($participantHeaders)
            ->postJson('/api/cutinapp/passes/claim/' . $eventA['ticket_id'])
            ->assertCreated()
            ->json('pass.token');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/checkin', [
                'event_id' => $eventB['event_id'],
                'token' => $token,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este ingresso pertence a outro evento.');
    }

    private function createPublishedEventWithCourtesy(array $headers, int $productionId, string $title): array
    {
        $event = $this->withHeaders($headers)->postJson('/api/cutinapp/events', [
            'production_id' => $productionId,
            'title' => $title,
            'description' => 'Descrição do evento.',
            'address' => 'Rua Teste, 1',
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');

        $ticket = $this->withHeaders($headers)->postJson('/api/cutinapp/courtesies', [
            'event_id' => $event['id'],
            'name' => 'Cortesia',
            'quantity' => 10,
        ])->assertCreated()->json('ticket');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')
            ->assertOk();

        return ['event_id' => $event['id'], 'ticket_id' => $ticket['id']];
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
