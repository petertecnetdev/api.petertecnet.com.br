<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DiscoveryIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_and_safe_local_landing_use_reusable_catalog_data(): void
    {
        $application = $this->applicationFixture('commerce', ['name' => 'Commerce', 'is_active' => true]);
        $owner = $this->userFixture('owner-search@example.test');
        $first = $this->establishmentFixture($application->id, $owner->id, 'Ferragista Centro', 'ferragista-centro');
        $second = $this->establishmentFixture($application->id, $owner->id, 'Ferragista Norte', 'ferragista-norte');

        foreach ([
            [$first, 'Telha de Fibra 2,44m'],
            [$first, 'Telha de Fibra 3,05m'],
            [$second, 'Telha de Fibra Reforçada'],
            [$second, 'Telha de Fibra Translúcida'],
        ] as $index => [$establishmentId, $name]) {
            DB::table('items')->insert([
                'app_id' => $application->id,
                'entity_name' => 'establishment',
                'entity_id' => $establishmentId,
                'name' => $name,
                'slug' => 'telha-' . ($index + 1),
                'description' => 'Telha de fibra para cobertura e construção.',
                'category' => 'Telhas',
                'type' => 'product',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/v1/discovery/search?q=telha&city=Governador%20Valadares')
            ->assertOk()
            ->assertJsonPath('data.totals.items', 4)
            ->assertJsonPath('data.results.items.0.type', 'item');

        $this->getJson('/api/v1/discovery/landing?q=telha&city=Governador%20Valadares')
            ->assertOk()
            ->assertJsonPath('data.indexable', true)
            ->assertJsonPath('data.seo.robots', 'index, follow');
    }

    public function test_web_vital_collection_and_admin_diagnostics_are_available(): void
    {
        $profile = Profile::create(['name' => 'Super Admin', 'permissions' => []]);
        $user = User::create([
            'first_name' => 'Admin',
            'email' => 'petertecnet@gmail.com',
            'user_name' => 'discovery-admin',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $application = $this->applicationFixture('peter-tecnet', ['name' => 'Peter Tecnet', 'is_active' => true]);

        $this->postJson('/api/v1/discovery/web-vitals', [
            'application' => 'peter-tecnet',
            'session_id' => 'session-test',
            'metric_name' => 'LCP',
            'metric_value' => 1820.4,
            'rating' => 'good',
            'path' => '/',
            'device_class' => 'mobile',
        ])->assertAccepted();

        $this->assertDatabaseHas('web_vital_samples', [
            'application_id' => $application->id,
            'session_id' => 'session-test',
            'metric_name' => 'LCP',
            'device_class' => 'mobile',
        ]);

        $token = auth('api')->login($user);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/discovery/web-vitals?days=30')
            ->assertOk()
            ->assertJsonPath('data.metrics.0.name', 'LCP');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/discovery/seo-diagnostics')
            ->assertOk()
            ->assertJsonStructure(['data' => ['score', 'summary', 'issues', 'without_traffic']]);
    }

    private function userFixture(string $email): User
    {
        $profile = Profile::firstOrCreate(['name' => 'Operador'], ['permissions' => []]);
        return User::create([
            'first_name' => 'Owner',
            'email' => $email,
            'user_name' => str($email)->before('@')->replace('.', '-')->toString(),
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }

    private function establishmentFixture(int $applicationId, int $userId, string $name, string $slug): int
    {
        return DB::table('establishments')->insertGetId([
            'app_id' => $applicationId,
            'name' => $name,
            'fantasy' => $name,
            'slug' => $slug,
            'description' => 'Materiais para construção.',
            'city' => 'Governador Valadares',
            'uf' => 'MG',
            'category' => 'Ferragista',
            'user_id' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
