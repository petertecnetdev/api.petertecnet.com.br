<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappLineupNotificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_followers_are_not_notified_until_event_is_public_and_are_not_duplicated_on_republish(): void
    {
        $producer = $this->user('Lineup Producer', 'lineup-producer@cutinapp.test');
        $participant = $this->user('Lineup Participant', 'lineup-participant@cutinapp.test');
        $producerHeaders = $this->headersFor($producer);
        $participantHeaders = $this->headersFor($participant);
        $app = Application::where('slug', 'cutinapp')->firstOrFail();

        $production = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/productions', ['name' => 'Safe Lineup Production'])
            ->assertCreated()
            ->json('production');

        $event = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $production['id'],
                'title' => 'Safe Lineup Event',
                'description' => 'Evento para validar que line-up em rascunho não vaza por notificações.',
                'address' => 'Rua Lineup, 100',
                'city' => 'São Paulo',
                'uf' => 'SP',
                'start_date' => now()->addDays(5)->format('Y-m-d H:i:s'),
                'end_date' => now()->addDays(5)->addHours(4)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->json('event');

        $artist = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/artists', [
                'stage_name' => 'Safe Lineup Artist',
                'is_published' => true,
            ])
            ->assertCreated()
            ->json('artist');

        $this->withHeaders($participantHeaders)
            ->postJson('/api/cutinapp/follow', ['target_type' => 'artist', 'target_id' => $artist['id']])
            ->assertOk();

        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/artists', [
                'artist_id' => $artist['id'],
                'participation_type' => 'show',
                'sort_order' => 0,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('app_notifications', [
            'app_id' => $app->id,
            'user_id' => $participant->id,
            'type' => 'artist_lineup',
            'reference_type' => 'event',
            'reference_id' => $event['id'],
        ]);

        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $event['id'],
                'name' => 'Ingresso gratuito',
                'quantity' => 100,
            ])
            ->assertCreated();

        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'app_id' => $app->id,
            'user_id' => $participant->id,
            'type' => 'artist_lineup',
            'reference_type' => 'event',
            'reference_id' => $event['id'],
        ]);

        $this->assertSame(1, $this->lineupNotificationCount($app->id, $participant->id, $event['id']));

        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/unpublish')
            ->assertOk();
        $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')
            ->assertOk();

        $this->assertSame(1, $this->lineupNotificationCount($app->id, $participant->id, $event['id']));
    }

    private function lineupNotificationCount(int $appId, int $userId, int $eventId): int
    {
        return DB::table('app_notifications')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('type', 'artist_lineup')
            ->where('reference_type', 'event')
            ->where('reference_id', $eventId)
            ->count();
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user), 'X-Peter-App' => 'cutinapp'];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)) . '-' . substr(md5($email), 0, 6),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
