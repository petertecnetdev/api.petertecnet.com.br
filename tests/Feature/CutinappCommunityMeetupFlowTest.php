<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappCommunityMeetupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_can_create_and_publish_community_meetup_without_production_or_ticket(): void
    {
        $user = $this->user('Organizador', 'meetup-owner@cutinapp.test');
        $headers = $this->headersFor($user);

        $event = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', $this->meetupPayload('Encontro de tecnologia'))
            ->assertCreated()
            ->assertJsonPath('event.kind', 'community')
            ->assertJsonPath('event.production_id', null)
            ->assertJsonPath('event.created_by_user_id', $user->id)
            ->assertJsonPath('event.is_published', false)
            ->json('event');

        $this->assertDatabaseHas('events', [
            'id' => $event['id'],
            'kind' => 'community',
            'production_id' => null,
            'created_by_user_id' => $user->id,
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events/'.$event['id'].'/publish')
            ->assertOk()
            ->assertJsonPath('message', 'Encontro publicado.')
            ->assertJsonPath('event.is_published', true);

        $this->getJson('/api/cutinapp/events/public/'.$event['slug'])
            ->assertOk()
            ->assertJsonPath('event.kind', 'community')
            ->assertJsonPath('event.creator.id', $user->id)
            ->assertJsonCount(0, 'tickets');
    }

    public function test_meetup_rsvp_respects_capacity_without_consuming_ticket_inventory(): void
    {
        [$owner, $event] = $this->publishedMeetup('Encontro limitado', ['max_attendees' => 1]);

        $first = $this->user('Primeiro', 'meetup-first@cutinapp.test');
        $second = $this->user('Segundo', 'meetup-second@cutinapp.test');

        $this->withHeaders($this->headersFor($first))
            ->putJson('/api/cutinapp/events/'.$event['id'].'/attendance', ['status' => 'going'])
            ->assertOk()
            ->assertJsonPath('counts.going', 1)
            ->assertJsonPath('mine.status', 'going');

        $this->withHeaders($this->headersFor($second))
            ->putJson('/api/cutinapp/events/'.$event['id'].'/attendance', ['status' => 'interested'])
            ->assertOk()
            ->assertJsonPath('counts.interested', 1);

        $this->withHeaders($this->headersFor($second))
            ->putJson('/api/cutinapp/events/'.$event['id'].'/attendance', ['status' => 'going'])
            ->assertStatus(409);

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseHas('event_attendances', [
            'event_id' => $event['id'],
            'user_id' => $first->id,
            'status' => 'going',
        ]);
        $this->assertDatabaseHas('event_attendances', [
            'event_id' => $event['id'],
            'user_id' => $second->id,
            'status' => 'interested',
        ]);
    }

    public function test_participant_can_self_checkin_near_location_and_organizer_can_validate_presence(): void
    {
        [$owner, $event] = $this->publishedMeetup('Encontro com presença', [
            'max_attendees' => 10,
            'latitude' => -23.550520,
            'longitude' => -46.633308,
            'attendance_radius_m' => 250,
        ]);

        $self = $this->user('Presencial', 'meetup-self@cutinapp.test');
        $manual = $this->user('Manual', 'meetup-manual@cutinapp.test');

        $this->withHeaders($this->headersFor($self))
            ->putJson('/api/cutinapp/events/'.$event['id'].'/attendance', ['status' => 'going'])
            ->assertOk();

        $this->withHeaders($this->headersFor($manual))
            ->putJson('/api/cutinapp/events/'.$event['id'].'/attendance', ['status' => 'interested'])
            ->assertOk();

        $this->travelTo(Carbon::parse($event['start_date'])->addMinute());

        $this->withHeaders($this->headersFor($self))
            ->postJson('/api/cutinapp/events/'.$event['id'].'/attendance/checkin', [
                'latitude' => -23.550520,
                'longitude' => -46.633308,
            ])
            ->assertOk()
            ->assertJsonPath('mine.status', 'attended')
            ->assertJsonPath('mine.checkin_method', 'geolocation');

        $this->withHeaders($this->headersFor($owner))
            ->postJson('/api/cutinapp/events/'.$event['id'].'/attendance/'.$manual->id.'/checkin')
            ->assertOk()
            ->assertJsonPath('attendance.status', 'attended')
            ->assertJsonPath('attendance.checkin_method', 'organizer');

        $this->assertDatabaseHas('event_attendances', [
            'event_id' => $event['id'],
            'user_id' => $self->id,
            'status' => 'attended',
            'checkin_method' => 'geolocation',
        ]);
        $this->assertDatabaseHas('event_attendances', [
            'event_id' => $event['id'],
            'user_id' => $manual->id,
            'status' => 'attended',
            'checkin_method' => 'organizer',
            'checked_in_by' => $owner->id,
        ]);

        $this->travelBack();
    }

    public function test_community_meetup_cannot_create_tickets_and_commercial_event_still_requires_production(): void
    {
        [$owner, $event] = $this->publishedMeetup('Encontro sem comércio');

        $this->withHeaders($this->headersFor($owner))
            ->postJson('/api/cutinapp/tickets', [
                'event_id' => $event['id'],
                'name' => 'Ingresso indevido',
                'quantity' => 10,
                'price' => 20,
            ])
            ->assertNotFound();

        $this->withHeaders($this->headersFor($owner))
            ->postJson('/api/cutinapp/events', [
                ...$this->meetupPayload('Evento comercial incompleto'),
                'kind' => 'commercial',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('production_id');
    }

    private function publishedMeetup(string $title, array $overrides = []): array
    {
        $owner = $this->user('Organizador '.$title, strtolower(substr(md5($title), 0, 10)).'@cutinapp.test');
        $headers = $this->headersFor($owner);

        $event = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                ...$this->meetupPayload($title),
                ...$overrides,
            ])
            ->assertCreated()
            ->json('event');

        $event = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events/'.$event['id'].'/publish')
            ->assertOk()
            ->json('event');

        return [$owner, $event];
    }

    private function meetupPayload(string $title): array
    {
        return [
            'kind' => 'community',
            'title' => $title,
            'description' => 'Encontro comunitário gratuito para reunir pessoas com interesses em comum.',
            'category' => 'Comunidade',
            'address' => 'Avenida Paulista, 1000',
            'venue' => 'Ponto de encontro',
            'city' => 'São Paulo',
            'uf' => 'SP',
            'latitude' => -23.550520,
            'longitude' => -46.633308,
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
            'show_attendees' => true,
            'attendance_radius_m' => 250,
        ];
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }

    private function user(string $name, string $email): User
    {
        Application::query()->where('slug', 'cutinapp')->firstOrFail();

        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)).'-'.substr(md5($email), 0, 6),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
