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

class ApiV1LinkedEstablishmentCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_establishment_and_its_source_catalog_are_visible_in_target_application(): void
    {
        $profile = Profile::create(['name' => 'Administrador', 'permissions' => []]);
        $user = User::create([
            'first_name' => 'Owner',
            'email' => 'linked-catalog@example.test',
            'user_name' => 'linked-catalog-owner',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $sourceApp = Application::create([
            'name' => 'Source',
            'slug' => 'source',
            'is_active' => true,
        ]);
        $targetApp = Application::create([
            'name' => 'Target',
            'slug' => 'target',
            'is_active' => true,
        ]);

        $establishmentId = DB::table('establishments')->insertGetId([
            'app_id' => $sourceApp->id,
            'name' => 'Catálogo compartilhado',
            'slug' => 'catalogo-compartilhado',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $establishment = Establishment::findOrFail($establishmentId);
        $establishment->applications()->attach($targetApp->id, ['is_primary' => false]);

        Item::create([
            'app_id' => $sourceApp->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'user_id' => $user->id,
            'name' => 'Item compartilhado',
            'price' => 49.90,
            'status' => true,
        ]);

        $this->getJson('/api/v1/apps/target/establishments')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'catalogo-compartilhado']);

        $this->getJson('/api/v1/apps/target/catalog/catalogo-compartilhado')
            ->assertOk()
            ->assertJsonPath('data.application.slug', 'target')
            ->assertJsonPath('data.establishment.id', $establishment->id)
            ->assertJsonFragment(['name' => 'Item compartilhado']);
    }

    public function test_unlinked_establishment_stays_isolated_from_target_application(): void
    {
        $profile = Profile::create(['name' => 'Administrador', 'permissions' => []]);
        $user = User::create([
            'first_name' => 'Owner',
            'email' => 'unlinked-catalog@example.test',
            'user_name' => 'unlinked-catalog-owner',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $sourceApp = Application::create([
            'name' => 'Source',
            'slug' => 'source',
            'is_active' => true,
        ]);
        Application::create([
            'name' => 'Target',
            'slug' => 'target',
            'is_active' => true,
        ]);

        DB::table('establishments')->insert([
            'app_id' => $sourceApp->id,
            'name' => 'Privado ao source',
            'slug' => 'privado-source',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/apps/target/catalog/privado-source')
            ->assertNotFound();
    }
}
