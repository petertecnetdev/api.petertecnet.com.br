<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogContractTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider publicApplicationSlugs */
    public function test_generic_discovery_contract_is_consistent_across_applications(string $slug): void
    {
        config(['public_catalog.cache_ttl_seconds' => 0]);
        $app = $this->applicationFixture($slug, ['is_active' => true]);
        $other = $this->applicationFixture('contract-other-'.$slug, ['is_active' => true]);

        $nativeId = $this->insertPublicEstablishment($app, "Native {$slug}", "native-{$slug}");
        $foreignId = $this->insertPublicEstablishment($other, "Foreign {$slug}", "foreign-{$slug}");
        $sharedId = $this->insertPublicEstablishment($other, "Shared {$slug}", "shared-{$slug}");

        DB::table('application_establishment')->insert([
            'application_id' => $app->id,
            'establishment_id' => $sharedId,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('items')->insert([
            'app_id' => $app->id,
            'entity_id' => $nativeId,
            'entity_name' => 'establishment',
            'name' => "Item {$slug}",
            'slug' => "item-{$slug}",
            'price' => 12.50,
            'stock' => 5,
            'status' => true,
            'limited_by_user' => false,
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/apps/{$slug}/discovery?per_page=10");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('scope.application_id', $app->id)
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonStructure([
                'pagination' => ['per_page', 'next_cursor', 'previous_cursor', 'has_more'],
                'locations',
                'establishments',
                'items',
            ]);

        $ids = collect($response->json('establishments'))->pluck('id');
        $this->assertTrue($ids->contains($nativeId));
        $this->assertTrue($ids->contains($sharedId));
        $this->assertFalse($ids->contains($foreignId));
        $this->assertArrayNotHasKey('metrics', $response->json('establishments.0'));
        $this->assertSame("Item {$slug}", $response->json('items.0.name'));
    }

    public function test_cursor_pagination_is_stable_and_non_overlapping(): void
    {
        config(['public_catalog.cache_ttl_seconds' => 0]);
        $app = $this->applicationFixture('cursor-contract', ['is_active' => true]);

        foreach (range(1, 5) as $number) {
            $this->insertPublicEstablishment($app, "Empresa {$number}", "empresa-{$number}");
        }

        $first = $this->getJson('/api/v1/apps/cursor-contract/discovery?per_page=2')
            ->assertOk()
            ->assertJsonPath('pagination.has_more', true);

        $cursor = $first->json('pagination.next_cursor');
        $this->assertNotEmpty($cursor);
        $firstIds = collect($first->json('establishments'))->pluck('id');

        $second = $this->getJson('/api/v1/apps/cursor-contract/discovery?per_page=2&cursor='.urlencode($cursor))
            ->assertOk();
        $secondIds = collect($second->json('establishments'))->pluck('id');

        $this->assertCount(2, $firstIds);
        $this->assertCount(2, $secondIds);
        $this->assertTrue($firstIds->intersect($secondIds)->isEmpty());
    }

    public function test_public_catalog_cache_is_invalidated_when_establishment_changes(): void
    {
        config(['public_catalog.cache_ttl_seconds' => 300]);
        $app = $this->applicationFixture('cache-contract', ['is_active' => true]);
        $this->insertPublicEstablishment($app, 'Primeira', 'primeira');

        $first = $this->getJson('/api/v1/apps/cache-contract/discovery?per_page=20')->assertOk();
        $this->assertCount(1, $first->json('establishments'));

        Establishment::query()->create([
            'app_id' => $app->id,
            'name' => 'Segunda',
            'slug' => 'segunda',
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
        ]);

        $second = $this->getJson('/api/v1/apps/cache-contract/discovery?per_page=20')->assertOk();
        $this->assertCount(2, $second->json('establishments'));
    }

    public function test_establishment_metrics_are_not_implicitly_serialized(): void
    {
        $app = $this->applicationFixture('metrics-contract', ['is_active' => true]);
        $id = $this->insertPublicEstablishment($app, 'Sem métricas implícitas', 'sem-metricas');

        $serialized = Establishment::query()->findOrFail($id)->toArray();

        $this->assertArrayNotHasKey('metrics', $serialized);
    }

    public static function publicApplicationSlugs(): array
    {
        return [
            ['nexus'],
            ['rasoio'],
            ['cutinapp'],
            ['plat'],
            ['inkap'],
            ['payflow'],
            ['laora'],
        ];
    }

    private function insertPublicEstablishment(Application $app, string $name, string $slug): int
    {
        return DB::table('establishments')->insertGetId([
            'app_id' => $app->id,
            'name' => $name,
            'slug' => $slug,
            'city' => 'Belo Horizonte',
            'uf' => 'MG',
            'is_featured' => false,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
