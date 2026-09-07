<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EventSocialPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_event_exposes_open_graph_preview_with_canonical_event_url(): void
    {
        [$app, $production] = $this->base();
        $event = Event::create([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'production_id' => $production->id,
            'title' => 'Noite de Teste Social',
            'slug' => 'noite-de-teste-social',
            'description' => 'Evento público para validar compartilhamento.',
            'venue' => 'Casa Teste',
            'city' => 'Goiânia',
            'uf' => 'GO',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(4),
            'is_published' => true,
            'is_cancelled' => false,
            'is_private' => false,
        ]);

        $response = $this->get('/api/v1/apps/cutinapp/events/public/'.$event->slug.'/share-preview');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, follow');
        $response->assertSee('<meta property="og:title" content="Noite de Teste Social">', false);
        $response->assertSee('https://cutinapp.petertecnet.com.br/event/noite-de-teste-social', false);
        $response->assertSee('/share-image.jpg?v=', false);
        $response->assertSee('<meta property="og:image:type" content="image/jpeg">', false);
    }

    public function test_private_event_social_preview_is_not_exposed(): void
    {
        [$app, $production] = $this->base();
        $event = Event::create([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'production_id' => $production->id,
            'title' => 'Evento Privado Social',
            'slug' => 'evento-privado-social',
            'description' => 'Não deve aparecer em preview.',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(2),
            'is_published' => true,
            'is_cancelled' => false,
            'is_private' => true,
        ]);

        $this->get('/api/v1/apps/cutinapp/events/public/'.$event->slug.'/share-preview')
            ->assertNotFound();
    }

    private function base(): array
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $app->forceFill(['url' => 'https://cutinapp.petertecnet.com.br'])->save();

        $user = User::create([
            'first_name' => 'Social Preview',
            'email' => 'social-preview@cutinapp.test',
            'user_name' => 'social-preview-user',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'user_id' => $user->id,
            'name' => 'Produção Social Preview',
            'slug' => 'producao-social-preview',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        return [$app, $production];
    }
}
