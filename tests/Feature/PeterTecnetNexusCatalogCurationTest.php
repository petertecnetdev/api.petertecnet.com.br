<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PeterTecnetNexusCatalogCurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_curates_the_catalog_and_archives_legacy_items_without_touching_orders(): void
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);

        $owner = User::create([
            'first_name' => 'Peter',
            'email' => 'petertecnet@gmail.com',
            'user_name' => 'peter-tecnet-owner',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        $origin = $this->applicationFixture('payflow', [
            'name' => 'Payflow',
            'is_active' => true,
        ]);
        $nexus = $this->applicationFixture('nexus', [
            'name' => 'Nexus',
            'is_active' => true,
        ]);

        $peterId = DB::table('establishments')->insertGetId([
            'app_id' => $origin->id,
            'name' => 'Peter Tecnet',
            'fantasy' => 'Peter Tecnet',
            'slug' => 'peter-tecnet',
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherId = DB::table('establishments')->insertGetId([
            'app_id' => $nexus->id,
            'name' => 'Outra empresa',
            'fantasy' => 'Outra empresa',
            'slug' => 'outra-empresa',
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('application_establishment')->insert([
            [
                'application_id' => $nexus->id,
                'establishment_id' => $peterId,
                'is_primary' => false,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'application_id' => $nexus->id,
                'establishment_id' => $otherId,
                'is_primary' => true,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
        ]);

        $legacyId = DB::table('items')->insertGetId([
            'user_id' => $owner->id,
            'app_id' => $nexus->id,
            'name' => 'Serviço antigo',
            'slug' => 'servico-antigo',
            'type' => 'service',
            'price' => 99,
            'status' => true,
            'entity_id' => $peterId,
            'entity_name' => 'establishment',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orphanId = DB::table('items')->insertGetId([
            'user_id' => $owner->id,
            'app_id' => $nexus->id,
            'name' => 'Item órfão',
            'slug' => 'item-orfao',
            'type' => 'service',
            'price' => 1,
            'status' => true,
            'entity_id' => 999999,
            'entity_name' => 'establishment',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('catalog:curate', [
            'manifest' => 'database/catalogs/peter-tecnet-nexus.json',
            '--archive-orphans' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(
            12,
            DB::table('items')
                ->where('entity_id', $peterId)
                ->where('entity_name', 'establishment')
                ->where('status', true)
                ->whereNull('archived_at')
                ->count()
        );

        $this->assertDatabaseHas('items', [
            'entity_id' => $peterId,
            'slug' => 'automacao-de-processos-com-ia',
            'category' => 'Automação & IA',
            'pricing_model' => 'starting_at',
            'status' => 1,
        ]);
        $this->assertNotNull(DB::table('items')->where('id', $legacyId)->value('archived_at'));
        $this->assertNotNull(DB::table('items')->where('id', $orphanId)->value('archived_at'));

        $this->assertDatabaseHas('application_establishment', [
            'application_id' => $nexus->id,
            'establishment_id' => $peterId,
            'is_primary' => 1,
        ]);
        $this->assertDatabaseHas('application_establishment', [
            'application_id' => $nexus->id,
            'establishment_id' => $otherId,
            'is_primary' => 0,
        ]);

        $this->assertGreaterThanOrEqual(
            12,
            DB::table('item_catalog_versions')->where('app_id', $nexus->id)->count()
        );
    }
}
