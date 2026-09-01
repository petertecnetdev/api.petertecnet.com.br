<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappCourtesyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_courtesy_can_be_listed_updated_claimed_and_then_is_protected(): void
    {
        $producer = $this->user('Produtor Cortesia', 'courtesy-owner@cutinapp.test');
        $headers = $this->headersFor($producer);
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();

        $productionId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Cortesia',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production.id');

        $eventId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Evento Cortesia',
                'description' => 'Evento para testar gestão de lotes.',
                'address' => 'Rua Cortesia, 1',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->json('event.id');

        $created = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Lote Inicial',
                'quantity' => 3,
                'description' => 'Primeiro lote.',
            ])
            ->assertCreated()
            ->assertJsonPath('ticket.app_id', $application->id)
            ->assertJsonPath('ticket.price', '0.00');

        $ticketId = (int) $created->json('ticket.id');

        $this->withHeaders($headers)
            ->getJson("/api/cutinapp/events/{$eventId}/courtesies")
            ->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $ticketId)
            ->assertJsonPath('tickets.0.quantity', 3)
            ->assertJsonPath('tickets.0.passes_count', 0);

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/courtesies/{$ticketId}", [
                'name' => 'Lote Atualizado',
                'quantity' => 5,
                'description' => 'Quantidade aumentada.',
            ])
            ->assertOk()
            ->assertJsonPath('ticket.name', 'Lote Atualizado')
            ->assertJsonPath('ticket.quantity', 5);

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/events/{$eventId}/publish")
            ->assertOk();

        $participant = $this->user('Participante Cortesia', 'courtesy-participant@cutinapp.test');
        $participantHeaders = $this->headersFor($participant);

        $this->withHeaders($participantHeaders)
            ->postJson("/api/cutinapp/passes/claim/{$ticketId}")
            ->assertCreated();

        $this->withHeaders($headers)
            ->getJson("/api/cutinapp/events/{$eventId}/courtesies")
            ->assertOk()
            ->assertJsonPath('tickets.0.passes_count', 1);

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/courtesies/{$ticketId}", ['quantity' => 0])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->deleteJson("/api/cutinapp/courtesies/{$ticketId}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Esta cortesia já possui ingressos emitidos e não pode ser excluída.');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'app_id' => $application->id,
            'event_id' => $eventId,
            'quantity' => 5,
        ]);
    }

    public function test_unused_courtesy_can_be_deleted(): void
    {
        $producer = $this->user('Produtor Exclusão', 'courtesy-delete@cutinapp.test');
        $headers = $this->headersFor($producer);

        $productionId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Exclusão',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production.id');

        $eventId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Evento Exclusão',
                'description' => 'Evento de teste.',
                'address' => 'Rua Exclusão, 1',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->json('event.id');

        $ticketId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Pode excluir',
                'quantity' => 2,
            ])
            ->assertCreated()
            ->json('ticket.id');

        $this->withHeaders($headers)
            ->deleteJson("/api/cutinapp/courtesies/{$ticketId}")
            ->assertOk();

        $this->assertDatabaseMissing('tickets', ['id' => $ticketId]);
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
            'user_name' => strtolower(str_replace(' ', '-', $name)) . '-' . substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
