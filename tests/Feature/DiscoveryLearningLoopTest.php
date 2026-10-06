<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DiscoveryLearningLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranked_index_search_supports_typo_tolerance_and_recommendations(): void
    {
        [, $token] = $this->admin();
        $application = $this->applicationFixture('catalog', ['name' => 'Catálogo', 'is_active' => true]);
        $establishment = DB::table('establishments')->insertGetId([
            'app_id' => $application->id, 'name' => 'Casa Central', 'fantasy' => 'Casa Central', 'slug' => 'casa-central',
            'category' => 'Ferragista', 'description' => 'Materiais e ferramentas para construção e manutenção.',
            'city' => 'Governador Valadares', 'uf' => 'MG', 'is_published' => true, 'is_approved' => true, 'is_cancelled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('items')->insert([
            'app_id' => $application->id, 'entity_name' => 'establishment', 'entity_id' => $establishment,
            'name' => 'Telha de Fibra', 'slug' => 'telha-de-fibra', 'type' => 'product', 'category' => 'Cobertura',
            'description' => 'Telha leve e resistente para cobertura residencial.', 'price' => 49.90, 'status' => true, 'is_featured' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rebuild = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/discovery/growth/search-index/rebuild')
            ->assertOk();
        $this->assertGreaterThanOrEqual(3, (int) $rebuild->json('data.documents'));

        $this->getJson('/api/v1/discovery/ranked-search?q=telha+fibra')
            ->assertOk()->assertJsonPath('data.results.item.0.title', 'Telha de Fibra');

        $this->postJson('/api/v1/discovery/events', [
            'session_id' => 'recommend-session', 'event_type' => 'search', 'entity_type' => 'search', 'entity_id' => 'telha',
            'metadata' => ['term' => 'cobertura'],
        ])->assertAccepted();
        $this->getJson('/api/v1/discovery/recommendations?session_id=recommend-session')
            ->assertOk()->assertJsonPath('data.0.title', 'Telha de Fibra');
    }

    public function test_experiment_assignment_is_stable_and_records_conversion(): void
    {
        [, $token] = $this->admin();
        $payload = [
            'key' => 'landing-hero-copy', 'name' => 'Hero principal', 'surface' => 'landing.hero', 'status' => 'running',
            'goal_event' => 'conversion', 'allocation_percent' => 100,
            'variants' => [
                ['key' => 'control', 'payload' => ['headline' => 'Soluções digitais']],
                ['key' => 'challenger', 'payload' => ['headline' => 'Encontre a solução certa']],
            ],
        ];
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/admin/discovery/growth/experiments', $payload)->assertCreated();
        $experimentId = (int) $response->json('data.id');

        $first = $this->getJson('/api/v1/discovery/experiments/resolve?surface=landing.hero&session_id=stable-session')->assertOk()->json('data');
        $second = $this->getJson('/api/v1/discovery/experiments/resolve?surface=landing.hero&session_id=stable-session')->assertOk()->json('data');
        $this->assertSame($first['variant_key'], $second['variant_key']);

        $this->postJson('/api/v1/discovery/experiments/events', [
            'experiment_id' => $experimentId, 'variant_key' => $first['variant_key'], 'session_id' => 'stable-session', 'event_type' => 'exposure',
        ])->assertAccepted();
        $this->postJson('/api/v1/discovery/experiments/events', [
            'experiment_id' => $experimentId, 'variant_key' => $first['variant_key'], 'session_id' => 'stable-session', 'event_type' => 'conversion', 'conversion_value' => 120,
        ])->assertAccepted();

        $overview = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/admin/discovery/growth?days=30')->assertOk();
        $variants = collect($overview->json('data.experiments.0.variants'));
        $assigned = $variants->firstWhere('key', $first['variant_key']);
        $this->assertNotNull($assigned);
        $this->assertEquals(1, $assigned['conversions']);
    }

    public function test_search_performance_import_creates_opportunities_and_accessibility_summary(): void
    {
        [, $token] = $this->admin();
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/admin/discovery/growth/search-performance/import', [
            'provider' => 'google',
            'rows' => [[
                'date' => now()->subDay()->toDateString(), 'query' => 'sistema de agendamento', 'page' => 'https://example.test/agenda',
                'clicks' => 1, 'impressions' => 300, 'ctr' => 0.0033, 'position' => 8.2,
            ]],
        ])->assertOk()->assertJsonPath('data.imported', 1);

        $this->postJson('/api/v1/discovery/accessibility', [
            'session_id' => 'a11y-session', 'path' => '/buscar', 'score' => 82, 'device_class' => 'mobile',
            'issues' => [['code' => 'missing_alt', 'count' => 2]],
        ])->assertAccepted();

        $overview = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/admin/discovery/growth?days=30')->assertOk();
        $this->assertSame('google', $overview->json('data.search_performance.0.provider'));
        $this->assertNotEmpty($overview->json('data.opportunities'));
        $this->assertEquals(1, $overview->json('data.accessibility.samples'));
        $this->assertEquals(82, $overview->json('data.accessibility.average_score'));
    }

    private function admin(): array
    {
        $profile = Profile::create(['name' => 'Super Admin', 'permissions' => []]);
        $user = User::create([
            'first_name' => 'Admin', 'email' => 'petertecnet@gmail.com', 'user_name' => 'learning-admin',
            'password' => Hash::make('Test1234!'), 'profile_id' => $profile->id,
        ]);
        return [$user, auth('api')->login($user)];
    }
}
