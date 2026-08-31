<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappSlugIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_slug_collision_with_another_app_is_resolved(): void
    {
        $legacyUser = $this->user('Legado', 'legacy-production@example.test');
        Production::create([
            'name' => 'Peter Eventos',
            'slug' => 'peter-eventos',
            'app_slug' => 'rasoio',
            'user_id' => $legacyUser->id,
        ]);

        $producer = $this->user('Produtor', 'producer-slug@example.test');

        $this->withHeaders($this->headersFor($producer))
            ->postJson('/api/cutinapp/productions', ['name' => 'Peter Eventos'])
            ->assertCreated()
            ->assertJsonPath('production.app_slug', 'cutinapp')
            ->assertJsonPath('production.slug', 'peter-eventos-2');

        $this->assertDatabaseHas('productions', [
            'app_slug' => 'cutinapp',
            'slug' => 'peter-eventos-2',
            'user_id' => $producer->id,
        ]);
    }

    public function test_event_slug_collision_with_another_app_is_resolved(): void
    {
        $legacyUser = $this->user('Legado', 'legacy-event@example.test');
        $legacyProduction = Production::create([
            'name' => 'Produção Legada',
            'slug' => 'producao-legada',
            'app_slug' => 'rasoio',
            'user_id' => $legacyUser->id,
        ]);

        Event::create([
            'production_id' => $legacyProduction->id,
            'title' => 'Festival Peter',
            'description' => 'Evento legado.',
            'address' => 'Rua Antiga, 1',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(2),
            'slug' => 'festival-peter',
            'app_slug' => 'rasoio',
        ]);

        $producer = $this->user('Produtor', 'producer-event-slug@example.test');
        $headers = $this->headersFor($producer);

        $productionId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', ['name' => 'Produção Cutinapp'])
            ->assertCreated()
            ->json('production.id');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Festival Peter',
                'description' => 'Evento Cutinapp.',
                'address' => 'Rua Nova, 2',
                'start_date' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'end_date' => now()->addDays(2)->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->assertJsonPath('event.app_slug', 'cutinapp')
            ->assertJsonPath('event.slug', 'festival-peter-2');
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
