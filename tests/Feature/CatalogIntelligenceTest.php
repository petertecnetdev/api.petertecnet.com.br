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

class CatalogIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_canonical_product_is_reused_across_applications_without_sharing_offer_data(): void
    {
        $user = $this->user();
        $nexus = $this->application('Nexus', 'nexus');
        $plat = $this->application('Plat', 'plat');
        $nexusEst = $this->establishment($user, $nexus, 'Ferragista A', 'ferragista-a');
        $platEst = $this->establishment($user, $plat, 'Ferragista B', 'ferragista-b');
        $token = auth('api')->login($user);

        $nexusItem = $this->item($user, $nexusEst, $nexus, 'Cobre Tudo', 23.90);
        $platItem = $this->item($user, $platEst, $plat, 'Cobre Tudo', 25.50);

        foreach ([['app' => 'nexus', 'item' => $nexusItem], ['app' => 'plat', 'item' => $platItem]] as $context) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson("/api/v1/apps/{$context['app']}/catalog-intelligence/items/{$context['item']->id}/enrich", [
                    'canonical_name' => 'Cobre Tudo',
                    'brand' => 'Condor',
                    'category' => 'Proteção para pintura',
                    'sale_unit' => 'un',
                    'specifications' => ['length' => '55 cm', 'width' => '20 cm'],
                    'source' => 'manual',
                    'alias' => 'Cola Tudo Condor',
                ])
                ->assertOk();
        }

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('product_aliases', 1);

        $nexusItem->refresh();
        $platItem->refresh();
        $this->assertNotNull($nexusItem->product_variant_id);
        $this->assertSame($nexusItem->product_variant_id, $platItem->product_variant_id);
        $this->assertSame('23.90', (string) $nexusItem->price);
        $this->assertSame('25.50', (string) $platItem->price);
    }

    public function test_import_stages_incomplete_rows_for_review_instead_of_publishing_them(): void
    {
        $user = $this->user();
        $nexus = $this->application('Nexus', 'nexus');
        $establishment = $this->establishment($user, $nexus, 'Ferragista', 'ferragista');
        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/apps/nexus/catalog-intelligence/imports', [
                'establishment_id' => $establishment->id,
                'source_type' => 'csv',
                'filename' => 'catalogo.csv',
                'rows' => [
                    [
                        'Nome' => 'Cobre Tudo',
                        'Preço' => '23,90',
                        'Marca' => 'Condor',
                        'Categoria' => 'Proteção para pintura',
                        'Comprimento' => '55 cm',
                        'Largura' => '20 cm',
                    ],
                    [
                        'Nome' => 'Silicone Acético',
                        'Preço' => '19,90',
                        'Categoria' => 'Silicone',
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertSame(2, $response->json('data.total_rows'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.review_rows'));
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseCount('catalog_import_rows', 2);
    }

    private function user(): User
    {
        $profile = Profile::create(['name' => 'Administrador', 'permissions' => []]);
        return User::create([
            'first_name' => 'Owner',
            'email' => 'catalog-owner@example.test',
            'user_name' => 'catalog-owner',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }

    private function application(string $name, string $slug): Application
    {
        return Application::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
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

    private function item(User $user, Establishment $establishment, Application $app, string $name, float $price): Item
    {
        return Item::create([
            'app_id' => $app->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'name' => $name,
            'type' => 'product',
            'price' => $price,
            'status' => true,
            'category' => 'Proteção para pintura',
            'brand' => 'Condor',
        ]);
    }
}
