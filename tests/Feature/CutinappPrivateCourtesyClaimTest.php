<?php

namespace Tests\Feature;

use App\Models\EventPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappPrivateCourtesyClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_event_courtesy_cannot_be_claimed_through_public_claim_endpoint(): void
    {
        $producer = $this->user('Produtor Privado', 'private-producer@cutinapp.test');
        $producerHeaders = $this->headersFor($producer);

        $productionId = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Privada',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production.id');

        $eventId = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Evento Privado',
                'description' => 'Evento que não pode distribuir cortesia por endpoint público.',
                'address' => 'Rua Privada, 10',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
                'is_private' => true,
            ])
            ->assertCreated()
            ->json('event.id');

        $ticketId = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Convite privado',
                'quantity' => 10,
            ])
            ->assertCreated()
            ->json('ticket.id');

        $this->withHeaders($producerHeaders)
            ->postJson("/api/cutinapp/events/{$eventId}/publish")
            ->assertOk();

        $participant = $this->user('Participante Sem Convite', 'private-participant@cutinapp.test');

        $this->withHeaders($this->headersFor($participant))
            ->postJson("/api/cutinapp/passes/claim/{$ticketId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este evento não está disponível para retirada pública de cortesias.');

        $this->assertSame(0, EventPass::query()->where('event_id', $eventId)->count());
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
