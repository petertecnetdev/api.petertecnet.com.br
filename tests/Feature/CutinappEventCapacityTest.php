<?php

namespace Tests\Feature;

use App\Models\EventPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_capacity_caps_claims_across_different_ticket_batches(): void
    {
        $producer = $this->user('Produtor Capacidade', 'capacity-producer@cutinapp.test');
        $producerHeaders = $this->headersFor($producer);

        $productionId = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Capacidade',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production.id');

        $eventId = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Evento Lotação Controlada',
                'description' => 'Evento com capacidade global menor que a soma dos lotes.',
                'address' => 'Rua da Capacidade, 100',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(3)->format('Y-m-d H:i:s'),
                'max_attendees' => 1,
            ])
            ->assertCreated()
            ->json('event.id');

        $firstTicketId = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Lote A',
                'quantity' => 10,
            ])
            ->assertCreated()
            ->json('ticket.id');

        $secondTicketId = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Lote B',
                'quantity' => 10,
            ])
            ->assertCreated()
            ->json('ticket.id');

        $this->withHeaders($producerHeaders)
            ->postJson("/api/cutinapp/events/{$eventId}/publish")
            ->assertOk();

        $firstParticipant = $this->user('Participante Um', 'capacity-one@cutinapp.test');
        $secondParticipant = $this->user('Participante Dois', 'capacity-two@cutinapp.test');

        $this->withHeaders($this->headersFor($firstParticipant))
            ->postJson("/api/cutinapp/passes/claim/{$firstTicketId}")
            ->assertCreated()
            ->assertJsonPath('already_issued', false);

        $this->withHeaders($this->headersFor($secondParticipant))
            ->postJson("/api/cutinapp/passes/claim/{$secondTicketId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A capacidade máxima deste evento foi atingida.');

        $this->assertSame(1, EventPass::query()->where('event_id', $eventId)->count());

        // A retry from the holder must stay idempotent even after capacity is full.
        $this->withHeaders($this->headersFor($firstParticipant))
            ->postJson("/api/cutinapp/passes/claim/{$firstTicketId}")
            ->assertOk()
            ->assertJsonPath('already_issued', true);

        $this->assertSame(1, EventPass::query()->where('event_id', $eventId)->count());
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
