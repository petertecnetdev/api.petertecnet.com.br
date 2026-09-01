<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CutinappPublicProductionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_production_requires_visibility_and_hides_draft_or_cancelled_past_events(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = User::create([
            'first_name' => 'Public Production Owner',
            'email' => 'public-production-owner@cutinapp.test',
            'user_name' => 'public-production-owner',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $owner->id,
            'name' => 'Produção Visível',
            'slug' => 'producao-visivel',
            'is_published' => false,
            'is_cancelled' => false,
        ]);

        $this->getJson('/api/cutinapp/productions/public/' . $production->slug)->assertNotFound();

        $production->update(['is_published' => true]);

        $visiblePast = $this->event($app->id, $production->id, 'Evento passado publicado', 'past-visible', true, false);
        $draftPast = $this->event($app->id, $production->id, 'Evento passado rascunho', 'past-draft', false, false);
        $cancelledPast = $this->event($app->id, $production->id, 'Evento passado cancelado', 'past-cancelled', true, true);

        foreach ([$visiblePast, $draftPast, $cancelledPast] as $index => $event) {
            DB::table('events')->where('id', $event->id)->update([
                'start_date' => now()->subDays(5 + $index),
                'end_date' => now()->subDays(4 + $index),
            ]);
        }

        $response = $this->getJson('/api/cutinapp/productions/public/' . $production->slug)
            ->assertOk()
            ->assertJsonPath('production.id', $production->id)
            ->assertJsonCount(1, 'past');

        $this->assertSame($visiblePast->id, $response->json('past.0.id'));
        $this->assertNotContains($draftPast->id, collect($response->json('past'))->pluck('id')->all());
        $this->assertNotContains($cancelledPast->id, collect($response->json('past'))->pluck('id')->all());

        $production->update(['is_cancelled' => true]);
        $this->getJson('/api/cutinapp/productions/public/' . $production->slug)->assertNotFound();
    }

    private function event(int $appId, int $productionId, string $title, string $slug, bool $published, bool $cancelled): Event
    {
        return Event::create([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => $title,
            'slug' => $slug,
            'description' => $title,
            'event_format' => 'online',
            'online_url' => 'https://example.test/' . $slug,
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(10)->addHours(2),
            'is_published' => $published,
            'is_cancelled' => $cancelled,
        ]);
    }
}
