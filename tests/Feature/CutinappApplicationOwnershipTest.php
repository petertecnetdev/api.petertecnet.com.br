<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappApplicationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_id_is_preserved_from_production_to_ticket_and_participant(): void
    {
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $producer = $this->user('Produtor', 'ownership-producer@cutinapp.test');
        $producerHeaders = $this->headersFor($producer);

        $production = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Ownership',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->assertJsonPath('production.app_id', $application->id)
            ->json('production');

        $event = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $production['id'],
                'title' => 'Evento Ownership',
                'description' => 'Evento para confirmar o ownership por aplicação.',
                'address' => 'Rua Ownership, 10',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->assertJsonPath('event.app_id', $application->id)
            ->assertJsonPath('event.city', 'São Paulo')
            ->json('event');

        $ticket = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $event['id'],
                'name' => 'Cortesia Ownership',
                'quantity' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('ticket.app_id', $application->id)
            ->json('ticket');

        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')
            ->assertOk();

        $participant = $this->user('Participante', 'ownership-participant@cutinapp.test');
        $this->withHeaders($this->headersFor($participant))
            ->postJson('/api/cutinapp/passes/claim/' . $ticket['id'])
            ->assertCreated()
            ->assertJsonPath('pass.user_id', $participant->id)
            ->assertJsonPath('pass.event_id', $event['id'])
            ->assertJsonPath('pass.ticket_id', $ticket['id']);

        $this->assertDatabaseHas('productions', ['id' => $production['id'], 'app_id' => $application->id]);
        $this->assertDatabaseHas('events', ['id' => $event['id'], 'app_id' => $application->id]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket['id'], 'app_id' => $application->id]);
        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $producer->id,
            'role' => 'producer',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $participant->id,
            'role' => 'participant',
            'status' => 'active',
        ]);
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
