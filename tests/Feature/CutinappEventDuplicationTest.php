<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_producer_can_duplicate_event_with_invalid_legacy_ticket_deadline(): void
    {
        $this->travelTo(Carbon::create(2026, 9, 7, 12, 0, 0, 'America/Sao_Paulo'));

        try {
            $user = User::create([
                'first_name' => 'Produtor Duplicação',
                'email' => 'event-duplicate@cutinapp.test',
                'user_name' => 'produtor-duplicacao',
                'password' => Hash::make('Test1234!'),
                'email_verified_at' => now(),
            ]);
            $headers = [
                'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
                'X-Peter-App' => 'cutinapp',
            ];

            $productionId = (int) $this->withHeaders($headers)
                ->postJson('/api/cutinapp/productions', ['name' => 'Produção Duplicação'])
                ->assertCreated()
                ->json('production.id');

            $eventId = (int) $this->withHeaders($headers)
                ->postJson('/api/cutinapp/events', [
                    'production_id' => $productionId,
                    'title' => 'Evento para duplicar',
                    'description' => 'Evento usado para validar a duplicação pelo produtor.',
                    'address' => 'Rua da Duplicação, 10',
                    'venue' => 'Espaço Duplicação',
                    'city' => 'São Paulo',
                    'uf' => 'SP',
                    'start_date' => '2026-09-13 22:00:00',
                    'end_date' => '2026-09-14 05:00:00',
                ])
                ->assertCreated()
                ->json('event.id');

            $ticketId = (int) $this->withHeaders($headers)
                ->postJson('/api/cutinapp/courtesies', [
                    'event_id' => $eventId,
                    'name' => 'Lista VIP',
                    'quantity' => 100,
                ])
                ->assertCreated()
                ->json('ticket.id');

            // Simula dado legado que hoje seria rejeitado pelo model: prazo após o evento.
            DB::table('tickets')->where('id', $ticketId)->update([
                'limit_date' => '2026-09-25 22:00:00',
            ]);

            $response = $this->withHeaders($headers)
                ->postJson("/api/v1/apps/cutinapp/events/{$eventId}/duplicate", [
                    'date' => '2026-09-20',
                ])
                ->assertCreated()
                ->assertJsonPath('copied.tickets', 1);

            $copyId = (int) $response->json('event.id');
            $this->assertGreaterThan(0, $copyId);
            $this->assertNotSame($eventId, $copyId);

            $copiedTicket = Ticket::query()->where('event_id', $copyId)->sole();
            $this->assertSame('2026-09-20 22:00:00', $copiedTicket->limit_date?->format('Y-m-d H:i:s'));
        } finally {
            $this->travelBack();
        }
    }
}
