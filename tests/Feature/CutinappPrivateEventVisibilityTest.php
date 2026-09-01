<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\CutinappArtist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappPrivateEventVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_published_event_stays_out_of_all_public_and_social_surfaces(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Private Owner', 'private-owner@cutinapp.test');
        $participant = $this->user('Private Participant', 'private-participant@cutinapp.test');

        $production = Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $owner->id,
            'name' => 'Private Production',
            'slug' => 'private-production',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $artist = CutinappArtist::create([
            'app_id' => $app->id,
            'user_id' => $owner->id,
            'slug' => 'private-event-artist',
            'stage_name' => 'Private Event Artist',
            'is_published' => true,
        ]);

        $private = Event::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'production_id' => $production->id,
            'title' => 'Secret Private Event',
            'slug' => 'secret-private-event',
            'description' => 'Evento privado que não pode aparecer em superfícies públicas.',
            'category' => 'Secret Category',
            'city' => 'Secret City',
            'uf' => 'SP',
            'event_format' => 'online',
            'online_url' => 'https://example.test/private',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(10)->addHours(2),
            'is_published' => true,
            'is_cancelled' => false,
            'is_private' => true,
        ]);

        $private->artists()->attach($artist->id, [
            'app_id' => $app->id,
            'participation_type' => 'show',
            'sort_order' => 0,
            'is_headliner' => true,
        ]);

        $this->getJson('/api/cutinapp/events?q=Secret%20Private%20Event')
            ->assertOk()
            ->assertJsonCount(0, 'events.data');

        $this->getJson('/api/cutinapp/events/public/' . $private->slug)->assertNotFound();
        $this->getJson('/api/cutinapp/events/public/' . $private->slug . '/artists')->assertNotFound();

        $facets = $this->getJson('/api/cutinapp/discovery/facets')->assertOk();
        $this->assertNotContains('Secret City', collect($facets->json('cities'))->pluck('city')->all());
        $this->assertNotContains('Secret Category', collect($facets->json('categories'))->pluck('category')->all());

        $productionResponse = $this->getJson('/api/cutinapp/productions/public/' . $production->slug)
            ->assertOk();
        $this->assertNotContains($private->id, collect($productionResponse->json('upcoming'))->pluck('id')->all());

        $artistResponse = $this->getJson('/api/cutinapp/artists/' . $artist->slug)->assertOk();
        $this->assertNotContains($private->id, collect($artistResponse->json('upcoming_events'))->pluck('id')->all());

        $headers = $this->headersFor($participant);
        $this->withHeaders($headers)->getJson('/api/cutinapp/feed')
            ->assertOk()
            ->assertJsonCount(0, 'feed.data');

        $this->withHeaders($headers)
            ->putJson('/api/cutinapp/events/' . $private->id . '/engagement', ['is_favorite' => true])
            ->assertNotFound();

        AppNotification::create([
            'app_id' => $app->id,
            'user_id' => $participant->id,
            'type' => 'artist_lineup',
            'title' => 'Should not persist',
            'message' => 'Private event must remain private.',
            'reference_type' => 'event',
            'reference_id' => $private->id,
            'reference_url' => '/event/' . $private->slug,
            'data' => ['artist_id' => $artist->id, 'event_id' => $private->id],
        ]);

        $this->assertDatabaseMissing('app_notifications', [
            'app_id' => $app->id,
            'user_id' => $participant->id,
            'type' => 'artist_lineup',
            'reference_id' => $private->id,
        ]);
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
