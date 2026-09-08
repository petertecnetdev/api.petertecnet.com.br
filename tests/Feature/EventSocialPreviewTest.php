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

    public function test_public_event_exposes_server_rendered_open_graph_preview(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = User::create([
            'first_name' => 'Preview Owner',
            'email' => 'preview-owner@cutinapp.test',
            'user_name' => 'preview-owner',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
        $production = Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $owner->id,
            'name' => 'Preview Produções',
            'slug' => 'preview-producoes',
            'is_published' => true,
            'is_cancelled' => false,
        ]);
        $event = Event::withoutEvents(fn () => Event::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'production_id' => $production->id,
            'title' => 'Noite da Prévia',
            'slug' => 'noite-da-previa',
            'description' => 'Evento público para validar Open Graph.',
            'image' => 'images/apps/cutinapp/events/preview.webp',
            'venue' => 'Casa Preview',
            'city' => 'Goiânia',
            'uf' => 'GO',
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(2)->addHours(4),
            'is_published' => true,
            'is_cancelled' => false,
            'is_private' => false,
        ]));

        $response = $this->get('/api/v1/apps/cutinapp/events/public/'.$event->slug.'/share-preview');

        $response->assertOk();
        $response->assertSee('property="og:title" content="Noite da Prévia"', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('/share-image.jpg?v=', false);
        $response->assertSee('https://cutinapp.petertecnet.com.br/event/noite-da-previa', false);
    }

    public function test_private_event_has_no_social_preview(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = User::create([
            'first_name' => 'Private Preview Owner',
            'email' => 'private-preview-owner@cutinapp.test',
            'user_name' => 'private-preview-owner',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
        $production = Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $owner->id,
            'name' => 'Private Preview Produções',
            'slug' => 'private-preview-producoes',
            'is_published' => true,
            'is_cancelled' => false,
        ]);
        $event = Event::withoutEvents(fn () => Event::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'production_id' => $production->id,
            'title' => 'Evento Privado',
            'slug' => 'evento-privado-preview',
            'description' => 'Não pode ter prévia pública.',
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(2)->addHours(4),
            'is_published' => true,
            'is_cancelled' => false,
            'is_private' => true,
        ]));

        $this->get('/api/v1/apps/cutinapp/events/public/'.$event->slug.'/share-preview')
            ->assertNotFound();
    }
}
