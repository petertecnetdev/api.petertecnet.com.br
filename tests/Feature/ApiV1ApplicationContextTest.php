<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiV1ApplicationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_application_cannot_be_used_as_api_context(): void
    {
        $this->applicationFixture('inactive', [
            'name' => 'Inactive',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/apps/inactive/establishments')
            ->assertNotFound()
            ->assertJsonPath('code', 'APPLICATION_NOT_AVAILABLE');
    }

    public function test_application_context_accepts_numeric_identifier_without_leaving_generic_routes(): void
    {
        $app = $this->application('Catalog App', 'catalog-app');

        $this->getJson('/api/v1/apps/' . $app->id . '/establishments')
            ->assertOk()
            ->assertHeader('X-Peter-Application', 'catalog-app')
            ->assertHeader('X-Peter-Application-Id', (string) $app->id)
            ->assertJsonPath('success', true);
    }

    public function test_application_context_resolves_canonical_url_alias_during_slug_migration(): void
    {
        $app = $this->applicationFixture('legacy-commerce-directory', [
            'name' => 'Commerce Directory',
            'url' => 'https://catalog-alias.petertecnet.test',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/apps/catalog-alias/establishments')
            ->assertOk()
            ->assertHeader('X-Peter-Application', 'legacy-commerce-directory')
            ->assertHeader('X-Peter-Application-Id', (string) $app->id)
            ->assertJsonPath('success', true);
    }

    public function test_application_context_slug_lookup_is_case_insensitive(): void
    {
        $app = $this->application('Mixed Case', 'mixed-case');

        $this->getJson('/api/v1/apps/MIXED-CASE/establishments')
            ->assertOk()
            ->assertHeader('X-Peter-Application', 'mixed-case')
            ->assertHeader('X-Peter-Application-Id', (string) $app->id)
            ->assertJsonPath('success', true);
    }

    public function test_public_catalog_is_isolated_by_application_slug(): void
    {
        $user = $this->user();
        $rasoio = $this->application('Rasoio', 'rasoio');
        $nexus = $this->application('Nexus', 'nexus');

        $rasoioEstablishment = $this->establishment($user, $rasoio, 'Barbearia', 'barbearia');
        $nexusEstablishment = $this->establishment($user, $nexus, 'Catalogo', 'catalogo');

        Item::create([
            'app_id' => $rasoio->id,
            'entity_name' => 'establishment',
            'entity_id' => $rasoioEstablishment->id,
            'user_id' => $user->id,
            'name' => 'Corte',
            'price' => 40,
            'status' => true,
        ]);

        Item::create([
            'app_id' => $nexus->id,
            'entity_name' => 'establishment',
            'entity_id' => $nexusEstablishment->id,
            'user_id' => $user->id,
            'name' => 'Produto Nexus',
            'price' => 80,
            'status' => true,
        ]);

        $this->assertDatabaseHas('establishments', [
            'id' => $rasoioEstablishment->id,
            'app_id' => $rasoio->id,
            'slug' => 'barbearia',
            'is_published' => 1,
            'is_cancelled' => 0,
        ]);

        $response = $this->getJson('/api/v1/apps/rasoio/catalog/barbearia');

        $response->assertOk()
            ->assertJsonPath('data.application.slug', 'rasoio')
            ->assertJsonFragment(['name' => 'Corte'])
            ->assertJsonMissing(['name' => 'Produto Nexus']);
    }

    public function test_v1_item_creation_rejects_establishment_from_another_application(): void
    {
        $user = $this->user();
        $rasoio = $this->application('Rasoio', 'rasoio');
        $this->application('Nexus', 'nexus');
        $establishment = $this->establishment($user, $rasoio, 'Barbearia', 'barbearia-dois');
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/apps/nexus/items', [
                'establishment_id' => $establishment->id,
                'name' => 'Nao deve criar',
                'price' => 10,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('items', ['name' => 'Nao deve criar']);
    }

    public function test_item_metrics_are_not_serialized_automatically(): void
    {
        $user = $this->user();
        $app = $this->application('Nexus', 'nexus');
        $establishment = $this->establishment($user, $app, 'Catalogo', 'catalogo-metricas');

        Item::create([
            'app_id' => $app->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'user_id' => $user->id,
            'name' => 'Item leve',
            'price' => 20,
            'status' => true,
        ]);

        $response = $this->getJson('/api/v1/apps/nexus/items');

        $response->assertOk();
        $this->assertArrayNotHasKey('metrics', $response->json('data.data.0'));
    }

    private function user(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);

        return User::create([
            'first_name' => 'Owner',
            'email' => 'owner@example.test',
            'user_name' => 'owner-test',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }

    private function application(string $name, string $slug): Application
    {
        return $this->applicationFixture($slug, [
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function establishment(User $user, Application $app, string $name, string $slug): Establishment
    {
        $id = DB::table('establishments')->insertGetId([
            'app_id' => $app->id,
            'name' => $name,
            'slug' => $slug,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Establishment::findOrFail($id);
    }
}
