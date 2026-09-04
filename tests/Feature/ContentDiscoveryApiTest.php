<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContentDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_content_only_exposes_published_or_due_scheduled_entries(): void
    {
        ContentEntry::create([
            'type' => 'article',
            'status' => 'draft',
            'title' => 'Rascunho privado',
            'slug' => 'rascunho-privado',
        ]);
        ContentEntry::create([
            'type' => 'article',
            'status' => 'scheduled',
            'title' => 'Agendado futuro',
            'slug' => 'agendado-futuro',
            'scheduled_at' => now()->addHour(),
        ]);
        ContentEntry::create([
            'type' => 'article',
            'status' => 'scheduled',
            'title' => 'Agendado liberado',
            'slug' => 'agendado-liberado',
            'scheduled_at' => now()->subMinute(),
            'excerpt' => 'Conteúdo já disponível.',
        ]);

        $response = $this->getJson('/api/v1/content')
            ->assertOk()
            ->assertJsonFragment([
                'slug' => 'agendado-liberado',
            ])
            ->assertJsonMissing([
                'slug' => 'rascunho-privado',
            ])
            ->assertJsonMissing([
                'slug' => 'agendado-futuro',
            ]);

        $released = collect($response->json('data.data'))
            ->firstWhere('slug', 'agendado-liberado');

        $this->assertNotNull($released);
        $this->assertSame('/blog/agendado-liberado', data_get($released, 'seo.canonical_path'));
    }

    public function test_admin_can_create_and_publish_generic_content(): void
    {
        [$admin, $token] = $this->admin();
        $application = $this->applicationFixture('peter', ['name' => 'Peter', 'is_active' => true]);
        $admin->applications()->attach($application->id, ['status' => 'active', 'joined_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/content', [
                'application_id' => $application->id,
                'type' => 'article',
                'status' => 'draft',
                'title' => 'Como automatizar processos',
                'excerpt' => 'Guia prático para pequenas empresas.',
                'content' => "# Automação\nMapeie o processo antes de automatizar.",
                'category' => 'Automação',
                'tags' => ['automacao', 'processos'],
                'cluster' => 'automacao-empresarial',
                'search_intent' => 'automação para pequenas empresas',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'como-automatizar-processos');

        $id = (int) $response->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/admin/content/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/content/como-automatizar-processos?application=peter')
            ->assertOk()
            ->assertJsonPath('data.title', 'Como automatizar processos');
    }

    public function test_discovery_builds_local_seo_for_company_category_and_item(): void
    {
        $application = $this->applicationFixture('nexus', ['name' => 'Nexus', 'is_active' => true]);
        $establishmentId = DB::table('establishments')->insertGetId([
            'app_id' => $application->id,
            'name' => 'Cirilo Ferragista LTDA',
            'fantasy' => 'Cirilo Ferragista',
            'slug' => 'cirilo-ferragista',
            'category' => 'Ferragista',
            'description' => 'Ferramentas e materiais para construção.',
            'city' => 'Governador Valadares',
            'uf' => 'MG',
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('items')->insert([
            'app_id' => $application->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishmentId,
            'name' => 'Telha de Fibra',
            'slug' => 'telha-de-fibra',
            'type' => 'product',
            'category' => 'Cobertura',
            'description' => 'Telha leve para cobertura.',
            'price' => 49.90,
            'status' => true,
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/discovery/establishments/cirilo-ferragista?application=nexus')
            ->assertOk()
            ->assertJsonPath('data.seo.location', 'Governador Valadares - MG')
            ->assertJsonPath('data.items.0.seo.canonical_path', '/solucoes/telha-de-fibra');

        $this->getJson('/api/v1/discovery/categories/cobertura?application=nexus')
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Cobertura');

        $this->getJson('/api/v1/discovery/items/telha-de-fibra?application=nexus')
            ->assertOk()
            ->assertJsonPath('data.item.seo.location', 'Governador Valadares - MG');
    }

    public function test_funnel_event_endpoint_drops_unapproved_metadata_and_admin_can_read_summary(): void
    {
        [, $token] = $this->admin();

        $this->postJson('/api/v1/discovery/events', [
            'session_id' => 'session-test-1',
            'event_type' => 'cta_click',
            'entity_type' => 'content',
            'entity_id' => 'automacao',
            'path' => '/blog/automacao',
            'source' => 'google',
            'metadata' => [
                'campaign' => 'seo-organico',
                'unsafe_personal_data' => 'nao-deve-ser-persistido',
            ],
        ])->assertAccepted();

        $this->assertDatabaseHas('discovery_events', [
            'session_id' => 'session-test-1',
            'event_type' => 'cta_click',
            'source' => 'google',
        ]);
        $stored = DB::table('discovery_events')->where('session_id', 'session-test-1')->first();
        $metadata = json_decode((string) $stored->metadata, true);
        $this->assertSame(['campaign' => 'seo-organico'], $metadata);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/discovery/analytics?days=30')
            ->assertOk()
            ->assertJsonPath('data.summary.cta_clicks', 1);
    }

    private function admin(): array
    {
        $profile = Profile::create(['name' => 'Administrador', 'permissions' => []]);
        $user = User::create([
            'first_name' => 'Admin',
            'email' => 'content-admin@example.test',
            'user_name' => 'content-admin',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        return [$user, auth('api')->login($user)];
    }
}
