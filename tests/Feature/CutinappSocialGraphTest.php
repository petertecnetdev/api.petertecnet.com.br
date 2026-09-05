<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\Artist;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappSocialGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_can_be_created_edited_linked_multiple_times_and_followed(): void
    {
        $producer = $this->user('Produtor Social', 'producer-social@cutinapp.test');
        $participant = $this->user('Participante Social', 'participant-social@cutinapp.test');
        $producerHeaders = $this->headersFor($producer);
        $participantHeaders = $this->headersFor($participant);
        $app = Application::where('slug', 'cutinapp')->firstOrFail();

        $production = $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/productions', ['name' => 'Social Produções'])->assertCreated()->json('production');
        $event = $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/events', [
            'production_id' => $production['id'], 'title' => 'Festival Social', 'description' => 'Festival com line-up.',
            'address' => 'Rua Social, 10', 'city' => 'Belo Horizonte', 'uf' => 'MG', 'category' => 'Eletrônico',
            'start_date' => now()->addDays(4)->format('Y-m-d H:i:s'), 'end_date' => now()->addDays(4)->addHours(6)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');

        $artistOne = $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/artists', [
            'stage_name' => 'DJ Aurora', 'bio' => 'Artista de música eletrônica.', 'city' => 'Belo Horizonte', 'uf' => 'MG', 'genres' => ['House', 'Eletrônico'],
        ])->assertCreated()->assertJsonPath('artist.app_id', $app->id)->json('artist');

        $artistTwo = $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/artists', [
            'stage_name' => 'Luna Live', 'genres' => ['Live'],
        ])->assertCreated()->json('artist');

        $this->withHeaders($producerHeaders)->putJson('/api/cutinapp/artists/' . $artistOne['id'], ['bio' => 'Biografia atualizada.'])
            ->assertOk()->assertJsonPath('artist.bio', 'Biografia atualizada.');

        $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/events/' . $event['id'] . '/artists', [
            'artist_id' => $artistOne['id'], 'participation_type' => 'atração principal', 'is_headliner' => true, 'sort_order' => 1,
        ])->assertOk();
        $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/events/' . $event['id'] . '/artists', [
            'artist_id' => $artistTwo['id'], 'participation_type' => 'abertura', 'sort_order' => 2,
        ])->assertOk()->assertJsonCount(2, 'artists');

        $this->withHeaders($participantHeaders)->postJson('/api/cutinapp/follow', ['target_type' => 'artist', 'target_id' => $artistOne['id']])->assertOk()->assertJsonPath('following', true);
        $this->withHeaders($participantHeaders)->postJson('/api/cutinapp/follow', ['target_type' => 'production', 'target_id' => $production['id']])->assertOk()->assertJsonPath('following', true);

        $this->assertDatabaseHas('follows', ['app_id' => $app->id, 'user_id' => $participant->id, 'target_type' => 'artist', 'target_id' => $artistOne['id']]);
        $this->assertDatabaseHas('follows', ['app_id' => $app->id, 'user_id' => $participant->id, 'target_type' => 'production', 'target_id' => $production['id']]);

        $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/courtesies', ['event_id' => $event['id'], 'name' => 'Cortesia', 'quantity' => 30])->assertCreated();
        $this->withHeaders($producerHeaders)->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')->assertOk();

        $this->getJson('/api/cutinapp/artists/' . $artistOne['slug'])->assertOk()->assertJsonPath('artist.stage_name', 'DJ Aurora')->assertJsonCount(1, 'upcoming_events');
        $this->getJson('/api/cutinapp/productions/public/' . $production['slug'])->assertOk()->assertJsonPath('production.id', $production['id'])->assertJsonCount(1, 'upcoming');
        $this->getJson('/api/cutinapp/events/public/' . $event['slug'])->assertOk()->assertJsonCount(2, 'event.artists');

        $feed = $this->withHeaders($participantHeaders)->getJson('/api/cutinapp/feed')->assertOk();
        $this->assertSame($event['id'], $feed->json('feed.data.0.id'));

        $this->withHeaders($participantHeaders)->deleteJson('/api/cutinapp/follow', ['target_type' => 'artist', 'target_id' => $artistOne['id']])->assertOk()->assertJsonPath('following', false);
        $this->assertDatabaseMissing('follows', ['user_id' => $participant->id, 'target_type' => 'artist', 'target_id' => $artistOne['id']]);
    }

    public function test_artist_link_does_not_grant_event_admin_and_other_app_targets_are_rejected(): void
    {
        $owner = $this->user('Owner', 'owner-social@cutinapp.test');
        $artistUser = $this->user('Artist User', 'artist-user@cutinapp.test');
        $ownerHeaders = $this->headersFor($owner);
        $artistHeaders = $this->headersFor($artistUser);
        $app = Application::where('slug', 'cutinapp')->firstOrFail();

        $production = $this->withHeaders($ownerHeaders)->postJson('/api/cutinapp/productions', ['name' => 'Owner Prod'])->assertCreated()->json('production');
        $event = $this->withHeaders($ownerHeaders)->postJson('/api/cutinapp/events', [
            'production_id' => $production['id'], 'title' => 'Evento Protegido', 'description' => 'Protegido', 'address' => 'Rua 1',
            'city' => 'Goiânia', 'uf' => 'GO', 'start_date' => now()->addDays(3)->format('Y-m-d H:i:s'), 'end_date' => now()->addDays(3)->addHours(2)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');

        $artist = Artist::create(['app_id' => $app->id, 'user_id' => $artistUser->id, 'slug' => 'artist-user', 'stage_name' => 'Artist User', 'is_published' => true]);
        $this->withHeaders($ownerHeaders)->postJson('/api/cutinapp/events/' . $event['id'] . '/artists', ['artist_id' => $artist->id, 'participation_type' => 'show'])->assertOk();

        $this->withHeaders($artistHeaders)->postJson('/api/cutinapp/events/' . $event['id'], ['title' => 'Tentativa indevida'])->assertForbidden();
        $this->withHeaders($artistHeaders)->postJson('/api/cutinapp/events/' . $event['id'] . '/artists', ['artist_id' => $artist->id, 'participation_type' => 'show'])->assertForbidden();

        $otherApp = Application::firstOrCreate(['slug' => 'social-other'], ['name' => 'Social Other', 'is_active' => true]);
        $otherProduction = Production::create(['app_id' => $otherApp->id, 'app_slug' => 'social-other', 'user_id' => $owner->id, 'name' => 'Other', 'slug' => 'other-social', 'is_published' => true, 'is_cancelled' => false]);
        $this->withHeaders($artistHeaders)->postJson('/api/cutinapp/follow', ['target_type' => 'production', 'target_id' => $otherProduction->id])->assertNotFound();
    }

    public function test_favorite_interest_preferences_and_notifications_are_persisted(): void
    {
        $producer = $this->user('Producer Notify', 'notify-producer@cutinapp.test');
        $participant = $this->user('Follower Notify', 'notify-follower@cutinapp.test');
        $ph = $this->headersFor($producer); $uh = $this->headersFor($participant);
        $app = Application::where('slug', 'cutinapp')->firstOrFail();
        $production = $this->withHeaders($ph)->postJson('/api/cutinapp/productions', ['name' => 'Notify Prod'])->assertCreated()->json('production');
        $event = $this->withHeaders($ph)->postJson('/api/cutinapp/events', [
            'production_id' => $production['id'], 'title' => 'Notify Event', 'description' => 'Notify', 'address' => 'Rua 1', 'city' => 'Recife', 'uf' => 'PE',
            'start_date' => now()->addDays(5)->format('Y-m-d H:i:s'), 'end_date' => now()->addDays(5)->addHours(2)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');

        $this->withHeaders($uh)->putJson('/api/cutinapp/preferences', ['preferred_city' => 'Recife', 'preferred_uf' => 'PE', 'radius_km' => 80])->assertOk()->assertJsonPath('preferences.preferred_city', 'Recife');
        $this->withHeaders($uh)->postJson('/api/cutinapp/follow', ['target_type' => 'production', 'target_id' => $production['id']])->assertOk();
        $this->withHeaders($ph)->postJson('/api/cutinapp/courtesies', ['event_id' => $event['id'], 'name' => 'Free', 'quantity' => 5])->assertCreated();
        $this->withHeaders($ph)->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')->assertOk();
        $this->withHeaders($uh)->putJson('/api/cutinapp/events/' . $event['id'] . '/engagement', ['is_favorite' => true, 'is_interested' => true])->assertOk();

        $this->assertDatabaseHas('event_engagements', ['user_id' => $participant->id, 'event_id' => $event['id'], 'is_favorite' => 1, 'is_interested' => 1]);
        $this->assertDatabaseHas('app_notifications', [
            'app_id' => $app->id,
            'user_id' => $producer->id,
            'type' => 'event_interest',
            'reference_type' => 'event',
            'reference_id' => $event['id'],
        ]);

        $this->assertSame(1, AppNotification::query()
            ->where('app_id', $app->id)
            ->where('user_id', $producer->id)
            ->where('type', 'event_interest')
            ->where('reference_type', 'event')
            ->where('reference_id', $event['id'])
            ->count());

        $this->withHeaders($uh)->putJson('/api/cutinapp/events/' . $event['id'] . '/engagement', ['is_favorite' => true, 'is_interested' => true])->assertOk();

        $this->assertSame(1, AppNotification::query()
            ->where('app_id', $app->id)
            ->where('user_id', $producer->id)
            ->where('type', 'event_interest')
            ->where('reference_type', 'event')
            ->where('reference_id', $event['id'])
            ->count());

        $this->withHeaders($ph)->getJson('/api/cutinapp/notifications')->assertOk()
            ->assertJsonPath('notifications.data.0.type', 'event_interest')
            ->assertJsonPath('notifications.data.0.reference_id', $event['id']);
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user), 'X-Peter-App' => 'cutinapp'];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name, 'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)) . '-' . substr(md5($email), 0, 6),
            'password' => Hash::make('Test1234!'), 'email_verified_at' => now(),
        ]);
    }
}
