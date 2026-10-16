<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BrazilianMunicipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvailableCitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_public_upcoming_events_of_the_selected_app_appear_and_legacy_cities_merge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'America/Sao_Paulo'));
        $app = $this->applicationFixture('cutinapp');
        $other = $this->applicationFixture('rasoio');
        $this->municipality(3106200, 'Belo Horizonte', 'MG');

        $this->event($app->id, 'Publicado com ID', 'Belo Horizonte', 'MG', 3106200);
        $this->event($app->id, 'Publicado legado', 'Belo Horizonte', 'MG');
        $this->event($app->id, 'Privado', 'Belo Horizonte', 'MG', 3106200, ['is_private' => true]);
        $this->event($app->id, 'Cancelado', 'Belo Horizonte', 'MG', 3106200, ['is_cancelled' => true]);
        $this->event($app->id, 'Rascunho', 'Belo Horizonte', 'MG', 3106200, ['is_published' => false]);
        $this->event($app->id, 'Encerrado', 'Belo Horizonte', 'MG', 3106200, ['end_date' => now()->subDay()]);
        $this->event($other->id, 'Outro aplicativo', 'Belo Horizonte', 'MG', 3106200);

        $this->getJson('/api/v1/apps/cutinapp/locations/available-cities?entity=events')
            ->assertOk()->assertJsonCount(1, 'cities')
            ->assertJsonPath('cities.0.id', 3106200)
            ->assertJsonPath('cities.0.city', 'Belo Horizonte')
            ->assertJsonPath('cities.0.total', 2);
        $this->getJson('/api/v1/apps/rasoio/locations/available-cities?entity=events')
            ->assertOk()->assertJsonPath('cities.0.total', 1);
        $this->getJson('/api/v1/apps/cutinapp/events/facets')
            ->assertOk()->assertJsonPath('cities.0.city_id', 3106200)
            ->assertJsonPath('cities.0.total', 2);
        $this->getJson('/api/v1/apps/cutinapp/events?city_id=3106200')
            ->assertOk()->assertJsonCount(2, 'events.data');
        $this->getJson('/api/v1/apps/cutinapp/locations/available-cities?entity=users')
            ->assertStatus(422);
    }

    public function test_city_id_finds_legacy_city_without_accents(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $this->municipality(5208707, 'Goiânia', 'GO');
        $this->event($app->id, 'Legado sem acento', 'Goiania', 'GO');

        $this->getJson('/api/v1/apps/cutinapp/locations/available-cities?entity=events')
            ->assertOk()->assertJsonPath('cities.0.city_id', 5208707);
        $this->getJson('/api/v1/apps/cutinapp/events?city_id=5208707')
            ->assertOk()->assertJsonCount(1, 'events.data');
    }

    public function test_productions_artists_and_items_use_same_city_catalog_without_leaking_other_apps(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $other = $this->applicationFixture('rasoio');
        $this->municipality(5208707, 'Goiânia', 'GO');

        $production = $this->establishment($app->id, 'Produção ativa', 'production', true);
        $this->establishment($app->id, 'Produção não publicada', 'production', false);
        $store = $this->establishment($app->id, 'Loja', 'store', true);
        $this->establishment($other->id, 'Outra loja', 'store', true);
        DB::table('artists')->insert([
            ['app_id' => $app->id, 'stage_name' => 'Artista aberto', 'slug' => 'artista-geo-aberto', 'city' => 'Goiânia', 'uf' => 'GO', 'is_active' => true, 'is_published' => true, 'created_at' => now(), 'updated_at' => now()],
            ['app_id' => $app->id, 'stage_name' => 'Artista oculto', 'slug' => 'artista-geo-oculto', 'city' => 'Goiânia', 'uf' => 'GO', 'is_active' => true, 'is_published' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('items')->insert([
            ['app_id' => $app->id, 'entity_name' => 'establishment', 'entity_id' => $store, 'name' => 'Disponível', 'slug' => 'item-geo-disponivel', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['app_id' => $app->id, 'entity_name' => 'establishment', 'entity_id' => $store, 'name' => 'Oculto', 'slug' => 'item-geo-oculto', 'status' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach (['productions', 'artists', 'items'] as $entity) {
            $this->getJson('/api/v1/apps/cutinapp/locations/available-cities?entity='.$entity)
                ->assertOk()->assertJsonCount(1, 'cities')
                ->assertJsonPath('cities.0.city_id', 5208707)
                ->assertJsonPath('cities.0.total', 1);
        }
        $this->getJson('/api/v1/apps/rasoio/locations/available-cities?entity=productions')
            ->assertOk()->assertJsonCount(0, 'cities');
        $this->assertNotNull($production);
    }

    private function municipality(int $id, string $name, string $uf): void
    {
        BrazilianMunicipality::query()->updateOrCreate(['ibge_code' => $id], [
            'name' => $name, 'normalized_name' => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name)),
            'uf' => $uf, 'slug' => \Illuminate\Support\Str::slug($name),
        ]);
    }

    private function event(int $appId, string $title, string $city, string $uf, ?int $cityId = null, array $values = []): void
    {
        DB::table('events')->insert(array_merge([
            'app_id' => $appId,
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title).'-'.$appId,
            'city_id' => $cityId,
            'city' => $city,
            'uf' => $uf,
            'is_published' => true,
            'is_cancelled' => false,
            'is_private' => false,
            'start_date' => now()->addDay(),
            'end_date' => now()->addDays(2),
            'created_at' => now(), 'updated_at' => now(),
        ], $values));
    }

    private function establishment(int $appId, string $name, string $category, bool $published): int
    {
        return DB::table('establishments')->insertGetId([
            'app_id' => $appId,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.$appId,
            'category' => $category,
            'city_id' => 5208707, 'city' => 'Goiânia', 'uf' => 'GO',
            'is_published' => $published, 'is_cancelled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
