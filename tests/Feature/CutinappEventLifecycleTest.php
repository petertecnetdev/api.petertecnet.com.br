<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_can_be_created_edited_published_opened_and_unpublished(): void
    {
        $user = $this->user('Produtor Evento', 'event-owner@cutinapp.test');
        $headers = $this->headersFor($user);
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();

        $production = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', ['name' => 'Produção do Evento'])
            ->assertCreated()
            ->json('production');

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/events/mine')
            ->assertOk()
            ->assertJsonCount(0, 'events.data');

        $created = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $production['id'],
                'title' => 'Evento Inicial',
                'description' => 'Descrição inicial do evento.',
                'address' => 'Rua Inicial, 10',
                'google_maps_url' => 'https://www.google.com/maps?q=Rua+Inicial+10',
                'venue' => 'Espaço Inicial',
                'city' => 'São Paulo',
                'uf' => 'SP',
                'start_date' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'end_date' => now()->addDays(2)->addHours(3)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->assertJsonPath('event.app_id', $application->id)
            ->assertJsonPath('event.production_id', $production['id'])
            ->assertJsonPath('event.google_maps_url', 'https://www.google.com/maps?q=Rua+Inicial+10')
            ->assertJsonPath('event.is_published', false);

        $eventId = (int) $created->json('event.id');
        $originalSlug = $created->json('event.slug');

        $this->withHeaders($headers)
            ->getJson("/api/cutinapp/events/show/{$eventId}")
            ->assertOk()
            ->assertJsonPath('event.title', 'Evento Inicial')
            ->assertJsonPath('event.production.id', $production['id']);

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/events/{$eventId}", [
                'title' => 'Evento Editado',
                'description' => 'Descrição editada e persistida.',
                'address' => 'Rua Editada, 20',
                'google_maps_url' => 'https://maps.google.com/?q=Rua+Editada+20',
                'venue' => 'Espaço Editado',
                'city' => 'Campinas',
                'uf' => 'SP',
                'start_date' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'end_date' => now()->addDays(3)->addHours(4)->format('Y-m-d H:i:s'),
            ])
            ->assertOk()
            ->assertJsonPath('event.title', 'Evento Editado')
            ->assertJsonPath('event.city', 'Campinas')
            ->assertJsonPath('event.google_maps_url', 'https://maps.google.com/?q=Rua+Editada+20')
            ->assertJsonPath('event.app_id', $application->id)
            ->assertJsonPath('event.production_id', $production['id']);

        $edited = $this->withHeaders($headers)
            ->getJson("/api/cutinapp/events/show/{$eventId}")
            ->assertOk()
            ->assertJsonPath('event.title', 'Evento Editado')
            ->assertJsonPath('event.address', 'Rua Editada, 20');

        $editedSlug = $edited->json('event.slug');
        $this->assertNotSame($originalSlug, $editedSlug);

        $this->getJson("/api/cutinapp/events/public/{$editedSlug}")->assertNotFound();

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/events/{$eventId}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Crie ao menos uma cortesia disponível antes de publicar o evento.');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Cortesia Publicação',
                'quantity' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('ticket.app_id', $application->id);

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/events/{$eventId}/publish")
            ->assertOk()
            ->assertJsonPath('event.is_published', true);

        $this->getJson("/api/cutinapp/events/public/{$editedSlug}")
            ->assertOk()
            ->assertJsonPath('event.id', $eventId)
            ->assertJsonPath('event.google_maps_url', 'https://maps.google.com/?q=Rua+Editada+20')
            ->assertJsonPath('event.production.id', $production['id'])
            ->assertJsonPath('tickets.0.remaining', 10);

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/events/mine')
            ->assertOk()
            ->assertJsonPath('events.data.0.id', $eventId)
            ->assertJsonPath('events.data.0.title', 'Evento Editado');

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/events/{$eventId}/unpublish")
            ->assertOk()
            ->assertJsonPath('event.is_published', false);

        $this->getJson("/api/cutinapp/events/public/{$editedSlug}")->assertNotFound();
    }

    public function test_event_rejects_past_start_zero_duration_and_invalid_maps_url(): void
    {
        $user = $this->user('Produtor Datas', 'event-dates@cutinapp.test');
        $headers = $this->headersFor($user);
        $production = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', ['name' => 'Produção Datas'])
            ->assertCreated()
            ->json('production');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $production['id'],
                'title' => 'Evento no passado',
                'description' => 'Não deve ser aceito.',
                'address' => 'Rua Passado, 1',
                'start_date' => now()->subHour()->format('Y-m-d H:i:s'),
                'end_date' => now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');

        $same = now()->addDay()->startOfMinute();
        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $production['id'],
                'title' => 'Evento duração zero',
                'description' => 'Não deve ser aceito.',
                'address' => 'Rua Zero, 1',
                'start_date' => $same->format('Y-m-d H:i:s'),
                'end_date' => $same->format('Y-m-d H:i:s'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $production['id'],
                'title' => 'Evento Maps inválido',
                'description' => 'Não deve ser aceito.',
                'address' => 'Rua Maps, 1',
                'google_maps_url' => 'maps sem url',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('google_maps_url');
    }

    public function test_event_cannot_be_created_with_production_from_another_application(): void
    {
        $user = $this->user('Produtor Isolado', 'event-isolation@cutinapp.test');
        $headers = $this->headersFor($user);
        $otherApp = Application::query()->firstOrCreate(
            ['slug' => 'event-other-app'],
            ['name' => 'Event Other App', 'is_active' => true]
        );

        $otherProduction = Production::create([
            'app_id' => $otherApp->id,
            'app_slug' => 'event-other-app',
            'user_id' => $user->id,
            'name' => 'Produção Externa',
            'slug' => 'producao-externa-evento',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $otherProduction->id,
                'title' => 'Evento Indevido',
                'description' => 'Não deve ser criado.',
                'address' => 'Rua X',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('events', ['title' => 'Evento Indevido']);
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
