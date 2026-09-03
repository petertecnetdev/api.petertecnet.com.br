<?php

namespace Tests\Feature;

use App\Models\Interaction;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CatalogSharedApplicationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_establishment_keeps_origin_items_and_private_preview_is_owner_only(): void
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);
        $owner = User::create([
            'first_name' => 'Owner',
            'email' => 'shared-catalog-owner@example.test',
            'user_name' => 'shared-catalog-owner',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $visitor = User::create([
            'first_name' => 'Visitor',
            'email' => 'shared-catalog-visitor@example.test',
            'user_name' => 'shared-catalog-visitor',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        $origin = $this->applicationFixture('origin-catalog', [
            'name' => 'Origin Catalog',
            'is_active' => true,
        ]);
        $nexus = $this->applicationFixture('nexus', [
            'name' => 'Nexus',
            'is_active' => true,
        ]);

        $establishmentId = DB::table('establishments')->insertGetId([
            'app_id' => $origin->id,
            'name' => 'Empresa compartilhada',
            'fantasy' => 'Empresa compartilhada',
            'slug' => 'empresa-compartilhada',
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
            'application_id' => $nexus->id,
            'establishment_id' => $establishmentId,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('items')->insertGetId([
            'user_id' => $owner->id,
            'app_id' => $origin->id,
            'name' => 'Produto de origem',
            'slug' => 'produto-de-origem',
            'type' => 'product',
            'price' => 19.90,
            'status' => true,
            'entity_id' => $establishmentId,
            'entity_name' => 'establishment',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/apps/nexus/catalog/empresa-compartilhada')
            ->assertOk()
            ->assertJsonPath('availability.status', 'public')
            ->assertJsonPath('data.establishment.id', $establishmentId)
            ->assertJsonPath('data.items.0.id', $itemId)
            ->assertJsonPath('data.items.0.app_id', $origin->id);

        DB::table('establishments')->where('id', $establishmentId)->update([
            'is_published' => false,
            'updated_at' => now(),
        ]);

        $visitorToken = auth('api')->login($visitor);
        $this->withHeader('Authorization', 'Bearer ' . $visitorToken)
            ->getJson('/api/v1/apps/nexus/catalog/empresa-compartilhada?preview=1')
            ->assertNotFound()
            ->assertJsonPath('availability.status', 'restricted')
            ->assertJsonPath('availability.reason', 'not_public')
            ->assertJsonMissingPath('data.establishment');

        $ownerToken = auth('api')->login($owner);
        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->getJson('/api/v1/apps/nexus/catalog/empresa-compartilhada?preview=1')
            ->assertOk()
            ->assertJsonPath('availability.status', 'preview')
            ->assertJsonPath('availability.indexable', false)
            ->assertJsonPath('data.establishment.id', $establishmentId)
            ->assertJsonPath('data.items.0.id', $itemId);

        $this->getJson('/api/v1/apps/nexus/discovery?limit=20')
            ->assertOk()
            ->assertJsonMissing(['slug' => 'empresa-compartilhada']);

        $this->assertSame(
            1,
            Interaction::query()
                ->where('entity_type', 'Establishment')
                ->where('entity_id', $establishmentId)
                ->where('interaction_type', 'restricted_access')
                ->count()
        );
    }
}
