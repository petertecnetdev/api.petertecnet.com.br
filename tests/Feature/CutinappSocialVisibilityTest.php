<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CutinappArtist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappSocialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_counts_and_mutations_only_accept_public_cutinapp_resources(): void
    {
        $producer = $this->user('Producer Visibility', 'producer-visibility@cutinapp.test');
        $participant = $this->user('Participant Visibility', 'participant-visibility@cutinapp.test');
        $producerHeaders = $this->headersFor($producer);
        $participantHeaders = $this->headersFor($participant);
        $app = Application::where('slug', 'cutinapp')->firstOrFail();

        $production = $this->withHeaders($producerHeaders)
            ->postJson('/api/cutinapp/productions', ['name' => 'Visibility Production'])
            ->assertCreated()
            ->json('production');

        $visible = $this->createEvent($producerHeaders, $production['id'], 'Visible Social Event', 4);
        $draft = $this->createEvent($producerHeaders, $production['id'], 'Draft Social Event', 5);
        $cancelled = $this->createEvent($producerHeaders, $production['id'], 'Cancelled Social Event', 6);

        Event::whereKey($visible['id'])->update(['is_published' => true, 'is_cancelled' => false]);
        Event::whereKey($cancelled['id'])->update(['is_published' => true, 'is_cancelled' => true]);

        $artist = CutinappArtist::create([
            'app_id' => $app->id,
            'user_id' => $producer->id,
            'slug' => 'visibility-artist',
            'stage_name' => 'Visibility Artist',
            'is_published' => true,
        ]);

        foreach ([$visible['id'], $draft['id'], $cancelled['id']] as $eventId) {
            Event::findOrFail($eventId)->artists()->attach($artist->id, [
                'app_id' => $app->id,
                'participation_type' => 'show',
                'sort_order' => 0,
                'is_headliner' => false,
            ]);
        }

        $this->getJson('/api/cutinapp/artists?q=Visibility%20Artist')
            ->assertOk()
            ->assertJsonPath('artists.data.0.id', $artist->id)
            ->assertJsonPath('artists.data.0.total_events_count', 1)
            ->assertJsonPath('artists.data.0.upcoming_events_count', 1);

        $hiddenArtist = CutinappArtist::create([
            'app_id' => $app->id,
            'user_id' => $producer->id,
            'slug' => 'hidden-artist',
            'stage_name' => 'Hidden Artist',
            'is_published' => false,
        ]);

        $hiddenProduction = Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $producer->id,
            'name' => 'Hidden Production',
            'slug' => 'hidden-production',
            'is_published' => false,
            'is_cancelled' => false,
        ]);

        $cancelledProduction = Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $producer->id,
            'name' => 'Cancelled Production',
            'slug' => 'cancelled-production',
            'is_published' => true,
            'is_cancelled' => true,
        ]);

        $this->withHeaders($participantHeaders)
            ->postJson('/api/cutinapp/follow', ['target_type' => 'artist', 'target_id' => $hiddenArtist->id])
            ->assertNotFound();
        $this->withHeaders($participantHeaders)
            ->postJson('/api/cutinapp/follow', ['target_type' => 'production', 'target_id' => $hiddenProduction->id])
            ->assertNotFound();
        $this->withHeaders($participantHeaders)
            ->postJson('/api/cutinapp/follow', ['target_type' => 'production', 'target_id' => $cancelledProduction->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('cutinapp_follows', [
            'app_id' => $app->id,
            'user_id' => $participant->id,
            'target_type' => 'artist',
            'target_id' => $hiddenArtist->id,
        ]);

        $this->withHeaders($participantHeaders)
            ->putJson('/api/cutinapp/events/' . $draft['id'] . '/engagement', ['is_favorite' => true])
            ->assertNotFound();
        $this->withHeaders($participantHeaders)
            ->putJson('/api/cutinapp/events/' . $cancelled['id'] . '/engagement', ['is_interested' => true])
            ->assertNotFound();
        $this->withHeaders($participantHeaders)
            ->putJson('/api/cutinapp/events/' . $visible['id'] . '/engagement', ['is_favorite' => true, 'is_interested' => true])
            ->assertOk();

        $this->assertDatabaseMissing('cutinapp_event_engagements', ['user_id' => $participant->id, 'event_id' => $draft['id']]);
        $this->assertDatabaseMissing('cutinapp_event_engagements', ['user_id' => $participant->id, 'event_id' => $cancelled['id']]);
        $this->assertDatabaseHas('cutinapp_event_engagements', [
            'app_id' => $app->id,
            'user_id' => $participant->id,
            'event_id' => $visible['id'],
            'is_favorite' => 1,
            'is_interested' => 1,
        ]);
    }

    private function createEvent(array $headers, int $productionId, string $title, int $days): array
    {
        return $this->withHeaders($headers)->postJson('/api/cutinapp/events', [
            'production_id' => $productionId,
            'title' => $title,
            'description' => 'Evento usado para validar os limites sociais da Cutinapp.',
            'address' => 'Rua Visibilidade, 10',
            'city' => 'São Paulo',
            'uf' => 'SP',
            'start_date' => now()->addDays($days)->format('Y-m-d H:i:s'),
            'end_date' => now()->addDays($days)->addHours(3)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');
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
